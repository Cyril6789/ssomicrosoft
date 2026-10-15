<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

/**
 * Adapt an identity already validated by the Microsoft callback to Auth::login().
 * This class is only instantiated after the OAuth state, code exchange, domain
 * filter and local account checks in PluginSsomicrosoftSso::handleCallback().
 */
class PluginSsomicrosoftAuth extends Auth {

   public static function forValidatedUser(User $user): self {
      $auth = new self();
      $auth->user = $user;
      return $auth;
   }

   public function validateLogin(string $login_name, string $login_password, bool $noauto = false, string $login_auth = ''): bool {
      $this->auth_succeded = $this->user->getID() > 0
         && $login_name === (string) $this->user->fields['name']
         && !empty($this->user->fields['is_active'])
         && empty($this->user->fields['is_deleted']);
      $this->user_present = $this->user->getID() > 0;
      $this->extauth = 1;
      $this->setAuthType(Auth::EXTERNAL);

      return $this->auth_succeded;
   }
}
