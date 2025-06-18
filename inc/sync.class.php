<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

class PluginSyncaadSync {
   public static function syncAll() {
      global $DB;

      foreach ($DB->request('glpi_plugin_syncaad_connections') as $conn) {
         if ($conn['active']) {
            self::syncConnection($conn);
         }
      }
   }

   private static function syncConnection(array $conn) {
      $users = self::fetchUsersFromEntra($conn);

      foreach ($users as $user) {
         self::syncUser($user);
      }
   }

   private static function fetchUsersFromEntra(array $conn) {
      $token = self::getAccessToken($conn);
      if (!$token) {
         return [];
      }

      $url = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,mail,userPrincipalName,givenName,surname,accountEnabled';

      if (!empty($conn['email_filter'])) {
         $filter = urlencode("endsWith(mail,'".$conn['email_filter']."')");
         $url .= "&%24filter=" . $filter;
      }

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
         'Authorization: Bearer ' . $token,
         'Content-Type: application/json'
      ]);
      $response = curl_exec($ch);
      curl_close($ch);

      if (!$response) {
         return [];
      }

      $data = json_decode($response, true);
      return $data['value'] ?? [];
   }

   private static function getAccessToken(array $conn) {
      $url = 'https://login.microsoftonline.com/' . $conn['tenant_id'] . '/oauth2/v2.0/token';
      $params = [
         'client_id'     => $conn['client_id'],
         'client_secret' => $conn['client_secret'],
         'scope'         => 'https://graph.microsoft.com/.default',
         'grant_type'    => 'client_credentials'
      ];

      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
      $resp = curl_exec($ch);
      curl_close($ch);

      if (!$resp) {
         return false;
      }

      $token = json_decode($resp, true);
      return $token['access_token'] ?? false;
   }

   private static function syncUser(array $user) {
      global $DB;

      if (empty($user['mail'])) {
         return;
      }

      $iterator = $DB->request([
         'SELECT' => 'id',
         'FROM'   => 'glpi_users',
         'WHERE'  => ['email' => $user['mail']]
      ]);

      if ($iterator && $iterator->numrows()) {
         $row = $iterator->next();
         $DB->update('glpi_users', [
            'name'      => $user['userPrincipalName'],
            'realname'  => $user['surname'] ?? '',
            'firstname' => $user['givenName'] ?? '',
            'is_active' => $user['accountEnabled'] ? 1 : 0
         ], [
            'id' => $row['id']
         ]);
      } else {
         $DB->insert('glpi_users', [
            'name'      => $user['userPrincipalName'],
            'realname'  => $user['surname'] ?? '',
            'firstname' => $user['givenName'] ?? '',
            'email'     => $user['mail'],
            'password'  => '',
            'is_active' => $user['accountEnabled'] ? 1 : 0
         ]);
      }
   }
}
