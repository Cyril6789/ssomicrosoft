<?php
function plugin_version_syncaad() {
    return [
        'name'           => __('Synchro AAD', 'syncaad'),
        'version'        => '1.0.0',
        'author'         => __('Your Name', 'syncaad'),
        'license'        => 'GPLv2+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => '10.0',
                'max' => '',
            ]
        ]
    ];
}

function plugin_init_syncaad() {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['syncaad'] = true;
}

function plugin_install_syncaad() {
    return true;
}

function plugin_uninstall_syncaad() {
    return true;
}
