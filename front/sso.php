<?php

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

global $CFG_GLPI;

$action = $_GET['action'] ?? '';

if (isset($_GET['code']) || isset($_GET['error'])) {
    // Callback from Entra ID.
    PluginSsomicrosoftSso::handleCallback();
} elseif ($action === 'login' && isset($_GET['connection_id'])) {
    PluginSsomicrosoftSso::startLogin((int) $_GET['connection_id']);
} else {
    Html::redirect($CFG_GLPI['root_doc'] . '/');
}
