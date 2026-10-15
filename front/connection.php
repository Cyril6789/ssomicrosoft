<?php

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

global $CFG_GLPI;

Session::checkRight('plugin_ssomicrosoft', READ);

Html::header(
    PluginSsomicrosoftConnection::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    'PluginSsomicrosoftConnection'
);

if (Session::haveRight('plugin_ssomicrosoft', UPDATE)) {
    echo '<div class="center mb-3">';
    echo '<a class="btn btn-primary" href="' . $CFG_GLPI['root_doc'] . '/plugins/ssomicrosoft/front/sync.php">';
    echo '<i class="ti ti-refresh me-1"></i>' . __('Synchroniser toutes les connexions', 'ssomicrosoft');
    echo '</a>';
    echo '</div>';
}

Search::show('PluginSsomicrosoftConnection');

Html::footer();
