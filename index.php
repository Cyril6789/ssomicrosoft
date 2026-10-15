<?php

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

global $CFG_GLPI;

// The plugin entry point simply leads to the connections management page.
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/ssomicrosoft/front/connection.php');
