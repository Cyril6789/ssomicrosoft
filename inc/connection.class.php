<?php

if (!defined('GLPI_ROOT')) {
   die("Cannot access directly");
}

class PluginSyncaadConnection extends CommonDBTM {
   public static $rightname = 'syncaad';

   static function getTypeName($nb = 0) {
      return _n('Connexion Entra ID', 'Connexions Entra ID', $nb, 'syncaad');
   }
}
