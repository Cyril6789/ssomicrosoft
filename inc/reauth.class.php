<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

use Glpi\Security\ReAuth\InPlaceReAuthStrategy;
use Symfony\Component\HttpFoundation\Request;

/**
 * Re-authenticate GLPI 12 sessions opened through an Entra SSO connection.
 * The Microsoft identity is checked out of band by the existing OAuth callback.
 */
class PluginSsomicrosoftReAuth extends InPlaceReAuthStrategy {

   public function verify(int $users_id, Request $request): bool {
      // The OAuth round trip verifies the identity through our own callback.
      // A direct submission to the core endpoint must always fail closed.
      return false;
   }

   public function getVerifyUrl(): string {
      global $CFG_GLPI;

      return $CFG_GLPI['root_doc'] . '/plugins/ssomicrosoft/front/sso.php?action=reauth_start';
   }

   public function getVerifyHttpMethod(): string {
      // The verify URL carries action=reauth_start in its query string.
      // HTML GET forms replace that query with their form fields, dropping
      // the action and sending the user back to GLPI's default landing page.
      return 'POST';
   }

   public function isAvailable(int $users_id, int $entities_id = 0): bool {
      global $DB;

      $sso_connection_id = (int) ($_SESSION['plugin_ssomicrosoft_sso_connection_id'] ?? 0);
      $is_external_session = ($_SESSION['glpiauthtype'] ?? Auth::NOT_YET_AUTHENTIFIED) === Auth::EXTERNAL;

      if ((!$is_external_session && $sso_connection_id <= 0)
          || $users_id <= 0
          || !$DB->tableExists('glpi_plugin_ssomicrosoft_connections')) {
         return false;
      }

      $where = ['active' => 1, 'sso_enabled' => 1];
      if ($sso_connection_id > 0) {
         // A stored connection ID binds this authenticated session to the SSO
         // provider selected at login and keeps it available on the callback,
         // even when GLPI records the underlying user as LDAP-authenticated.
         $where['id'] = $sso_connection_id;
      }

      return count($DB->request([
         'FROM'  => 'glpi_plugin_ssomicrosoft_connections',
         'WHERE' => $where,
      ])) > 0;
   }

   public function getLabel(): string {
      return __('Compte Microsoft', 'ssomicrosoft');
   }

   public function getPromptTemplate(): string {
      return '@ssomicrosoft/reauth/prompt.html.twig';
   }

   public function getPriority(): int {
      // Keep GLPI's TOTP strategy (100) ahead of Entra; prefer Entra over the
      // native password, LDAP and mail strategies (50) for our SSO sessions.
      return 60;
   }
}
