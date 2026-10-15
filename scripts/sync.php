#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// GLPI 12 no longer bootstraps the application from inc/includes.php.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

if (!Plugin::isPluginActive('ssomicrosoft')) {
    fwrite(STDERR, "SSO Microsoft must be installed and active.\n");
    exit(1);
}

$_SESSION['glpiname'] = 'cli';
PluginSsomicrosoftSync::syncAll();
