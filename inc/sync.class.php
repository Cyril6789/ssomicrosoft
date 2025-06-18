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
      // TODO: implement real calls to Entra ID API
      return [];
   }

   private static function syncUser(array $user) {
      // TODO: implement synchronization with GLPI users
   }
}
