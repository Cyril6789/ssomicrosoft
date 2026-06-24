<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Reflects an Entra ID user's group memberships into GLPI, mirroring what the
 * native LDAP/AD authentication does.
 *
 * GLPI's LDAP login does two things with a user's groups (see
 * User::getFromLDAP()):
 *   1. it links the user to the GLPI groups configured in their "Liaison
 *      annuaire LDAP" tab (fields ldap_group_dn, or ldap_field + ldap_value),
 *      creating dynamic Group_User entries;
 *   2. it feeds the resulting GLPI group ids to the "Règles d'affectation
 *      d'habilitations" engine (RuleRightCollection), which assigns profiles
 *      and entities.
 *
 * This helper reproduces both behaviours from the groups returned by Microsoft
 * Graph, so an Entra account gets the same automatic habilitations as an LDAP
 * one. No GLPI group is ever created: only existing groups carrying a linkage
 * are matched — which also makes the whole feature opt-in (it is a no-op until
 * an administrator fills the "Liaison annuaire LDAP" fields of a group).
 */
class PluginSsomicrosoftGroup {

   /**
    * Does at least one GLPI group carry an LDAP linkage we can match against?
    *
    * When none do, there is nothing to map: callers can skip the (costly)
    * Microsoft Graph group lookup entirely and leave habilitations untouched,
    * preserving the plugin's previous behaviour.
    */
   public static function hasMappings(): bool {
      return countElementsInTable('glpi_groups', [
         'OR' => [
            ['ldap_group_dn' => ['<>', '']],
            ['ldap_field'    => ['<>', '']],
         ],
      ]) > 0;
   }

   /**
    * Apply an Entra user's group memberships to their GLPI account.
    *
    * @param int   $users_id    GLPI user id (already provisioned).
    * @param array $entraGroups List of Graph group objects, each typically with
    *                           id, displayName, onPremisesDistinguishedName and
    *                           onPremisesSamAccountName.
    */
   public static function apply(int $users_id, array $entraGroups): void {
      if ($users_id <= 0) {
         return;
      }

      $user = new User();
      if (!$user->getFromDB($users_id)) {
         return;
      }

      // 1. Resolve the GLPI groups the user should belong to, from the linkage
      //    configured on each group (DN match or field/value match).
      $group_ids = self::matchGlpiGroupIds($entraGroups);

      // Diagnostic line: tells whether groups were received from Graph at all
      // (0 received => GroupMember.Read.All probably missing / not consented)
      // and whether any GLPI linkage matched (received > 0 but matched 0 =>
      // the "DN du groupe" / ldap_value does not match the listed identifiers).
      self::log(sprintf(
         'Groupes pour %s (user #%d) : %d reçu(s) d\'Entra [%s] → %d groupe(s) GLPI rapproché(s)%s.',
         (string) ($user->fields['name'] ?? '?'),
         $users_id,
         count($entraGroups),
         self::describeEntraGroups($entraGroups),
         count($group_ids),
         $group_ids ? ' (groups_id: ' . implode(', ', $group_ids) . ')' : ''
      ));

      // 2. Run the authorization rules ("Règles d'affectation d'habilitations")
      //    with those group ids, exactly like User::getFromLDAP() does. Groups
      //    assigned by a rule are returned in _ldap_rules['groups_id'] and must
      //    be preserved by the Group_User sync below.
      $rules  = new RuleRightCollection();
      $result = $rules->processAllRules($group_ids, $user->fields, [
         'type'  => $user->fields['authtype'],
         'login' => $user->fields['name'],
         'email' => UserEmail::getDefaultForUser($users_id),
      ]);

      $rule_groups = (array) ($result['_ldap_rules']['groups_id'] ?? []);

      // 3. Reflect the membership into dynamic Group_User links.
      self::syncGroupLinks($users_id, $group_ids, $rule_groups);

      // 4. Persist the habilitations (dynamic Profile_User entries) computed by
      //    the rule engine.
      $user->input = $result;
      $user->willProcessRuleRight();
      $user->applyRightRules();
   }

   /**
    * Map the user's Entra groups to GLPI group ids using each group's LDAP
    * linkage, the same way User::getFromLDAPGroupVirtual() does for LDAP:
    *   - ldap_group_dn : the GLPI group DN must equal one of the user's group
    *     identifiers (Entra onPremisesDistinguishedName for AD-synced groups,
    *     and — for cloud-only groups with no DN — displayName / id);
    *   - ldap_field + ldap_value : ldap_value is matched (SQL LIKE semantics)
    *     against the user's group identifiers, ldap_field being the membership
    *     attribute (e.g. "memberof").
    *
    * @param array $entraGroups
    * @return int[] De-duplicated GLPI group ids.
    */
   public static function matchGlpiGroupIds(array $entraGroups): array {
      global $DB;

      // Flatten every identifier an Entra group can be recognised by.
      $identifiers = [];
      foreach ($entraGroups as $g) {
         foreach (['onPremisesDistinguishedName', 'displayName', 'onPremisesSamAccountName', 'id'] as $key) {
            $val = trim((string) ($g[$key] ?? ''));
            if ($val !== '') {
               $identifiers[] = $val;
            }
         }
      }
      if (empty($identifiers)) {
         return [];
      }
      $identifiers_lc = array_map('strtolower', $identifiers);

      $matched = [];
      foreach ($DB->request([
         'SELECT' => ['id', 'ldap_group_dn', 'ldap_field', 'ldap_value'],
         'FROM'   => 'glpi_groups',
         'WHERE'  => [
            'OR' => [
               ['ldap_group_dn' => ['<>', '']],
               ['ldap_field' => ['<>', '']],
            ],
         ],
      ]) as $group) {
         $dn = strtolower(trim((string) ($group['ldap_group_dn'] ?? '')));
         if ($dn !== '' && in_array($dn, $identifiers_lc, true)) {
            $matched[(int) $group['id']] = true;
            continue;
         }

         $value = trim((string) ($group['ldap_value'] ?? ''));
         if (($group['ldap_field'] ?? '') !== '' && $value !== '') {
            foreach ($identifiers as $candidate) {
               if (self::likeMatch($candidate, $value)) {
                  $matched[(int) $group['id']] = true;
                  break;
               }
            }
         }
      }

      return array_keys($matched);
   }

   /**
    * Reflect the resolved membership into dynamic Group_User links, mirroring
    * User::syncLdapGroups(): keep links that still apply, drop dynamic links
    * that no longer match (unless an authorization rule still grants them), and
    * add the missing ones as dynamic.
    *
    * @param int   $users_id
    * @param int[] $wanted_ids  GLPI groups the user must belong to.
    * @param int[] $rule_ids    GLPI groups granted by authorization rules.
    */
   private static function syncGroupLinks(int $users_id, array $wanted_ids, array $rule_ids): void {
      global $DB;

      $wanted   = array_fill_keys(array_map('intval', $wanted_ids), true);
      $rule_set = array_fill_keys(array_map('intval', $rule_ids), true);

      $group_user = new Group_User();
      foreach ($DB->request([
         'SELECT' => ['id', 'groups_id', 'is_dynamic'],
         'FROM'   => 'glpi_groups_users',
         'WHERE'  => ['users_id' => $users_id],
      ]) as $link) {
         $gid = (int) $link['groups_id'];
         if (isset($wanted[$gid])) {
            // Already linked: nothing to add for this group.
            unset($wanted[$gid]);
         } elseif (!empty($link['is_dynamic']) && !isset($rule_set[$gid])) {
            // Dynamic link no longer backed by a membership or a rule: remove it.
            $group_user->delete(['id' => (int) $link['id']]);
         }
      }

      foreach (array_keys($wanted) as $gid) {
         $group_user->add([
            'users_id'   => $users_id,
            'groups_id'  => $gid,
            'is_dynamic' => 1,
         ]);
      }
   }

   /**
    * Case-insensitive SQL-LIKE match (supports the % and _ wildcards, plus the
    * shell-style * as an alias for %), used to compare a group identifier
    * against a glpi_groups.ldap_value pattern.
    */
   private static function likeMatch(string $value, string $pattern): bool {
      $regex = '';
      $len   = strlen($pattern);
      for ($i = 0; $i < $len; $i++) {
         $c = $pattern[$i];
         if ($c === '%' || $c === '*') {
            $regex .= '.*';
         } elseif ($c === '_') {
            $regex .= '.';
         } else {
            $regex .= preg_quote($c, '/');
         }
      }
      return (bool) preg_match('/^' . $regex . '$/i', $value);
   }

   /**
    * Build a short, readable summary of the Entra groups for the diagnostic
    * log: the DN when present (what "DN du groupe" must match for AD-synced
    * groups), otherwise the display name. Capped so the log stays compact.
    */
   private static function describeEntraGroups(array $entraGroups): string {
      if (empty($entraGroups)) {
         return 'aucun';
      }

      $labels = [];
      foreach ($entraGroups as $g) {
         $labels[] = trim((string) ($g['onPremisesDistinguishedName'] ?? ''))
                  ?: trim((string) ($g['displayName'] ?? ''))
                  ?: trim((string) ($g['id'] ?? '?'));
      }

      $shown = array_slice($labels, 0, 15);
      $more  = count($labels) - count($shown);

      return implode(' | ', $shown) . ($more > 0 ? sprintf(' | … (+%d)', $more) : '');
   }

   /** Write a diagnostic line to the plugin log (files/_log/ssomicrosoft.log). */
   private static function log(string $message): void {
      Toolbox::logInFile('ssomicrosoft', $message . "\n");
   }
}
