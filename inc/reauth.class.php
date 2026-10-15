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
      return 'GET';
   }

   public function isAvailable(int $users_id, int $entities_id = 0): bool {
      global $DB;

      if (($_SESSION['glpiauthtype'] ?? Auth::NOT_YET_AUTHENTIFIED) !== Auth::EXTERNAL
          || $users_id <= 0
          || !$DB->tableExists('glpi_plugin_ssomicrosoft_connections')) {
         return false;
      }

      return count($DB->request([
         'FROM'  => 'glpi_plugin_ssomicrosoft_connections',
         'WHERE' => ['active' => 1, 'sso_enabled' => 1],
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
