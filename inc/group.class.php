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
    *                           id, displayName and onPremisesSamAccountName.
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
      // Graph exposes group names and sAMAccountName, but not the full AD DN.
      // Reuse the configured LDAP DN on each matched GLPI group for legacy
      // RuleRight MemberOf criteria.
      $memberof_dns = self::mappedGroupDns($group_ids);
      // New SSO criterion: expose Entra group names directly, without needing
      // the on-premises OU/DN that Microsoft Graph does not return.
      $sso_group_names = self::entraGroupNames($entraGroups);

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

      // 2. Preview the rules with the Entra-mapped groups so rule-assigned group
      //    links are preserved while stale Entra memberships are removed.
      $preview_rules = self::newRuleCollection($memberof_dns, $sso_group_names);
      $result = $preview_rules->processAllRules($group_ids, $user->fields, [
         'type'  => $user->fields['authtype'],
         'login' => $user->fields['name'],
         'email' => UserEmail::getDefaultForUser($users_id),
      ]);
      $rule_groups = (array) ($result['_ldap_rules']['groups_id'] ?? []);

      self::syncGroupLinks($users_id, $group_ids, $rule_groups, self::managedGroupIds());

      // 3. Re-run the rules against the persisted GLPI memberships, then use
      //    GLPI's normal apply methods without re-opening the user's LDAP
      //    connection. SSO group rules do not require an LDAP bind.
      $groups = Group_User::getUserGroups($users_id);
      $groups_id = array_column($groups, 'id');
      $rules = self::newRuleCollection($memberof_dns, $sso_group_names);
      $result = $rules->processAllRules($groups_id, $user->fields, [
         'type'  => $user->fields['authtype'],
         'login' => $user->fields['name'],
         'email' => UserEmail::getDefaultForUser($users_id),
      ]);

      $user->input = $result;
      $user->willProcessRuleRight();
      $user->applyGroupsRules();
      $user->applyRightRules();

      self::log(sprintf(
         'Règles pour %s (user #%d) : groupes transmis [%s], %d DN MemberOf issus des liaisons GLPI ; résultat _ldap_rules=%s ; profils GLPI après application [%s].',
         (string) ($user->fields['name'] ?? '?'),
         $users_id,
         implode(', ', array_map('intval', $groups_id)),
         count($memberof_dns),
         json_encode($result['_ldap_rules'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
         implode(', ', array_map('intval', Profile_User::getUserProfiles($users_id)))
      ));
   }

   /**
    * Build a RuleRightCollection that exposes known AD group DNs as the LDAP
    * `memberof` attribute. This lets existing LDAP MemberOf criteria continue
    * to work during SSO without opening an LDAP connection.
    */
   private static function newRuleCollection(array $memberof, array $ssoGroupNames): RuleRightCollection {
      $rules = new class extends RuleRightCollection {
         /** @var string[] */
         public array $memberOfDns = [];
         /** @var string[] */
         public array $ssoGroupNames = [];

         public function prepareInputDataForProcess($input, $params) {
            $prepared = parent::prepareInputDataForProcess($input, $params);
            if (is_array($prepared)) {
               $prepared['memberof'] = $this->memberOfDns;
               $prepared['memberOf'] = $this->memberOfDns;
               $prepared['ssomicrosoft_memberof'] = $this->ssoGroupNames;
            }
            return $prepared;
         }
      };

      $rules->memberOfDns = $memberof;
      $rules->ssoGroupNames = $ssoGroupNames;
      return $rules;
   }

   /** @return string[] Unique Entra group display names and legacy short names. */
   private static function entraGroupNames(array $entraGroups): array {
      $names = [];
      foreach ($entraGroups as $group) {
         foreach (['displayName', 'onPremisesSamAccountName'] as $key) {
            $name = trim((string) ($group[$key] ?? ''));
            if ($name !== '') {
               $names[] = $name;
            }
         }
      }
      return array_values(array_unique($names));
   }

   /** @return string[] Full LDAP DNs configured on matched GLPI groups. */
   private static function mappedGroupDns(array $group_ids): array {
      if (!$group_ids) {
         return [];
      }

      global $DB;
      $dns = [];
      foreach ($DB->request([
         'SELECT' => 'ldap_group_dn',
         'FROM'   => 'glpi_groups',
         'WHERE'  => ['id' => array_map('intval', $group_ids)],
      ]) as $group) {
         $dn = trim((string) ($group['ldap_group_dn'] ?? ''));
         if ($dn !== '') {
            $dns[] = $dn;
         }
      }
      return array_values(array_unique($dns));
   }

   /**
    * Map the user's Entra groups to GLPI group ids using each group's LDAP
    * linkage, the same way User::getFromLDAPGroupVirtual() does for LDAP:
    *   - ldap_group_dn : matched against the CN extracted from the configured
    *     DN and compared to the Entra group's displayName / sAMAccountName.
    *     Entra exposes the group name rather than its full on-premises DN, so a
    *     GLPI group configured with "CN=NAME,OU=...,DC=..." matches on "NAME".
    *     The configured full DN is reused for legacy MemberOf rules;
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
         foreach (['displayName', 'onPremisesSamAccountName', 'id'] as $key) {
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
         $gid = (int) $group['id'];

         // DN linkage: try the configured value as-is (full DN) and its CN
         // component, so a full AD DN matches an Entra group exposed by name.
         $dn_raw = trim((string) ($group['ldap_group_dn'] ?? ''));
         if ($dn_raw !== '') {
            $dn_candidates = [strtolower($dn_raw)];
            $cn = self::extractCn($dn_raw);
            if ($cn !== '') {
               $dn_candidates[] = strtolower($cn);
            }
            if (array_intersect($dn_candidates, $identifiers_lc)) {
               $matched[$gid] = true;
               continue;
            }
         }

         // field/value linkage.
         $value = trim((string) ($group['ldap_value'] ?? ''));
         if (($group['ldap_field'] ?? '') !== '' && $value !== '') {
            foreach ($identifiers as $candidate) {
               if (self::likeMatch($candidate, $value)) {
                  $matched[$gid] = true;
                  break;
               }
            }
         }
      }

      return array_keys($matched);
   }

   /**
    * Ids of every GLPI group that carries an Entra/LDAP linkage, i.e. the groups
    * this plugin is responsible for. Dynamic memberships of groups outside this
    * set (e.g. assigned by the native LDAP login or added by hand) are never
    * touched by the sync, so an SSO login does not wipe them.
    *
    * @return int[]
    */
   private static function managedGroupIds(): array {
      global $DB;

      $ids = [];
      foreach ($DB->request([
         'SELECT' => 'id',
         'FROM'   => 'glpi_groups',
         'WHERE'  => [
            'OR' => [
               ['ldap_group_dn' => ['<>', '']],
               ['ldap_field' => ['<>', '']],
            ],
         ],
      ]) as $g) {
         $ids[] = (int) $g['id'];
      }
      return $ids;
   }

   /**
    * Extract the CN (first RDN) from a distinguished name such as
    * "CN=My Group,OU=Apps,DC=example,DC=local" → "My Group".
    */
   private static function extractCn(string $dn): string {
      if (preg_match('/^\s*cn=((?:[^,\\\\]|\\\\.)+)/i', $dn, $m)) {
         return trim(str_replace(['\\,', '\\='], [',', '='], $m[1]));
      }
      return '';
   }

   /**
    * Reflect the resolved membership into dynamic Group_User links, mirroring
    * User::syncLdapGroups(): keep links that still apply, drop dynamic links
    * that no longer match (unless an authorization rule still grants them), and
    * add the missing ones as dynamic.
    *
    * Deletions are restricted to "managed" groups (those carrying an Entra/LDAP
    * linkage): a dynamic membership of any other group is left untouched, so an
    * SSO login can never wipe groups the plugin is not responsible for.
    *
    * @param int   $users_id
    * @param int[] $wanted_ids  GLPI groups the user must belong to.
    * @param int[] $rule_ids    GLPI groups granted by authorization rules.
    * @param int[] $managed_ids GLPI groups this plugin manages.
    */
   private static function syncGroupLinks(int $users_id, array $wanted_ids, array $rule_ids, array $managed_ids): void {
      global $DB;

      $wanted   = array_fill_keys(array_map('intval', $wanted_ids), true);
      $rule_set = array_fill_keys(array_map('intval', $rule_ids), true);
      $managed  = array_fill_keys(array_map('intval', $managed_ids), true);

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
         } elseif (!empty($link['is_dynamic']) && isset($managed[$gid]) && !isset($rule_set[$gid])) {
            // Managed dynamic link no longer backed by a membership or a rule.
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
   * log: the display name, capped so the log stays compact.
    */
   private static function describeEntraGroups(array $entraGroups): string {
      if (empty($entraGroups)) {
         return 'aucun';
      }

      $labels = [];
      foreach ($entraGroups as $g) {
         $labels[] = trim((string) ($g['displayName'] ?? ''))
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
