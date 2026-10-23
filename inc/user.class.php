<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Helper that maps an Entra ID (Microsoft Graph) user object to a GLPI user.
 *
 * It is shared by the synchronisation engine and the SSO login flow so that
 * both create/update GLPI accounts the same way (including e-mail handling
 * through glpi_useremails and default profile assignment).
 */
class PluginSsomicrosoftUser {

   /**
    * Normalise a Microsoft Graph user object to the fields we care about.
    *
    * @param array $aad Raw Graph user object.
    * @return array{login:string, upn:string, email:string, firstname:string, realname:string, jobtitle:string, active:int}
    */
   public static function normalize(array $aad): array {
      $upn = trim((string) ($aad['userPrincipalName'] ?? ''));
      $login = $upn !== '' ? $upn : (string) ($aad['mail'] ?? '');
      // GLPI login uses the UPN's local part (e.g. "pnom" from
      // "pnom@example.com"). Keep the full UPN separately for matching
      // accounts that were created before this mapping was used.
      $separator = strpos($login, '@');
      if ($separator !== false) {
         $login = substr($login, 0, $separator);
      }
      $email = $aad['mail'] ?? ($aad['userPrincipalName'] ?? '');

      return [
         'login'     => trim((string) $login),
         'upn'       => $upn,
         'email'     => trim((string) $email),
         'firstname' => (string) ($aad['givenName'] ?? ''),
         'realname'  => (string) ($aad['surname'] ?? ''),
         'jobtitle'  => trim((string) ($aad['jobTitle'] ?? '')),
         // accountEnabled is not always returned (e.g. delegated /me); default to active.
         'active'    => array_key_exists('accountEnabled', $aad)
                         ? (!empty($aad['accountEnabled']) ? 1 : 0)
                         : 1,
      ];
   }

   /**
    * Find an existing GLPI user matching the login, previous full UPN, or e-mail.
    */
   public static function find(string $login, string $email, string $upn = ''): ?User {
      return self::resolveIdentity($login, $email, $upn)['user'];
   }

   /**
    * Resolve all available identifiers together, avoiding GLPI's
    * getFromDBbyName() exception when a short login exists more than once.
    *
    * @return array{user:User|null, ambiguous:bool}
    */
   private static function resolveIdentity(string $login, string $email, string $upn): array {
      global $DB;

      $identifier_sets = [];
      if ($email !== '') {
         $email_ids = [];
         foreach ($DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['email' => $email],
         ]) as $row) {
            $email_ids[] = (int) $row['users_id'];
         }
         $identifier_sets[] = array_values(array_unique($email_ids));
      }

      foreach (array_unique(array_filter([$upn, $login])) as $name) {
         $name_ids = [];
         foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => ['name' => $name],
         ]) as $row) {
            $name_ids[] = (int) $row['id'];
         }
         $identifier_sets[] = array_values(array_unique($name_ids));
      }

      // Ignore identifiers that do not match an existing account, then
      // intersect the remaining results. Conflicting identities are unsafe.
      $identifier_sets = array_values(array_filter($identifier_sets));
      if (!$identifier_sets) {
         return ['user' => null, 'ambiguous' => false];
      }

      $candidate_ids = array_shift($identifier_sets);
      foreach ($identifier_sets as $ids) {
         $candidate_ids = array_values(array_intersect($candidate_ids, $ids));
      }
      if (count($candidate_ids) !== 1) {
         return ['user' => null, 'ambiguous' => true];
      }

      $user = new User();
      if (!$user->getFromDB($candidate_ids[0])) {
         return ['user' => null, 'ambiguous' => false];
      }
      return ['user' => $user, 'ambiguous' => false];
   }

   /**
    * Create or update a GLPI user from an Entra ID user object.
    *
    * @param array $aad          Raw Graph user object.
    * @param array $conn         The connection row (provides defaults).
    * @param bool  $allow_create Whether a missing user may be created.
    * @return User|null The persisted user, or null on failure / skip.
    */
   public static function upsert(array $aad, array $conn, bool $allow_create = true): ?User {
      $data = self::normalize($aad);

      if ($data['login'] === '' && $data['email'] === '') {
         return null;
      }

      $identity = self::resolveIdentity($data['login'], $data['email'], $data['upn']);
      $user = $identity['user'];

      if ($user === null) {
         if ($identity['ambiguous']) {
            Toolbox::logInFile(
               'ssomicrosoft',
               sprintf(
                  "Synchronisation ignorée pour l'identité %s : plusieurs comptes GLPI correspondent aux identifiants reçus.\n",
                  $data['login'] !== '' ? $data['login'] : $data['email']
               )
            );
            return null;
         }
         if (!$allow_create) {
            return null;
         }

         $input = [
            'name'      => $data['login'] !== '' ? $data['login'] : $data['email'],
            'realname'  => $data['realname'],
            'firstname' => $data['firstname'],
            'is_active' => $data['active'],
            'authtype'  => Auth::EXTERNAL,
            '_no_history' => true,
         ];
         if ($data['jobtitle'] !== '') {
            $input['usertitles_id'] = Dropdown::importExternal('UserTitle', $data['jobtitle']);
         }
         if ($data['email'] !== '') {
            $input['_useremails'] = [-1 => $data['email']];
         }
         $entity = (int) ($conn['entities_id'] ?? 0);
         if ($entity > 0) {
            $input['entities_id'] = $entity;
         }

         $user = new User();
         $id = $user->add($input);
         if (!$id) {
            return null;
         }

         // Note: the default profile is NOT assigned here. It is applied by the
         // caller (SSO login / sync) only as a last resort, after the group and
         // habilitation rules have run, so a rule-based profile always wins and
         // the plugin never forces a default over the rules' decision.
         $user->getFromDB((int) $id);

         return $user;
      }

      // Existing user: refresh identity fields without invoking User::update().
      // In GLPI 12 that method reprocesses authorization rules and can attempt
      // an LDAP bind from the user's stored auths_id/user_dn, even though this
      // update came from Microsoft SSO. The SSO flow applies the rules itself
      // after synchronizing Entra groups in PluginSsomicrosoftGroup::apply().
      global $DB;
      $DB->update('glpi_users', [
         'realname'  => $data['realname'],
         'firstname' => $data['firstname'],
         'is_active' => $data['active'],
         ...($data['jobtitle'] !== '' ? [
            'usertitles_id' => Dropdown::importExternal('UserTitle', $data['jobtitle']),
         ] : []),
      ], [
         'id' => $user->getID(),
      ]);
      $user->getFromDB($user->getID());

      if ($data['email'] !== '') {
         self::ensureEmail($user->getID(), $data['email']);
      }

      return $user;
   }

   /**
    * Make sure the user has at least one profile so it can actually log in.
    */
   public static function ensureProfile(int $users_id, array $conn): void {
      if ($users_id <= 0) {
         return;
      }

      if (countElementsInTable('glpi_profiles_users', ['users_id' => $users_id]) > 0) {
         return;
      }

      $profile_id = (int) ($conn['default_profiles_id'] ?? 0);
      if ($profile_id <= 0) {
         $profile_id = (int) Profile::getDefault();
      }
      if ($profile_id <= 0) {
         return;
      }

      $entity = (int) ($conn['entities_id'] ?? 0);

      $pu = new Profile_User();
      $pu->add([
         'users_id'     => $users_id,
         'profiles_id'  => $profile_id,
         'entities_id'  => $entity,
         'is_recursive' => 1,
         'is_dynamic'   => 0,
      ]);
   }

   /**
    * Add an e-mail to the user if it is not already registered.
    */
   public static function ensureEmail(int $users_id, string $email): void {
      if ($users_id <= 0 || $email === '') {
         return;
      }

      if (countElementsInTable('glpi_useremails', ['users_id' => $users_id, 'email' => $email]) > 0) {
         return;
      }

      $useremail = new UserEmail();
      $useremail->add([
         'users_id'   => $users_id,
         'email'      => $email,
         'is_default' => countElementsInTable('glpi_useremails', ['users_id' => $users_id]) === 0 ? 1 : 0,
      ]);
   }
}
