<?php

if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

global $CFG_GLPI;

$action = $_GET['action'] ?? '';

if ($action === 'reauth_start') {
    Session::checkLoginUser();
    $manager = Glpi\Security\ReAuth\ReAuthManager::getInstance();
    if (!$manager->isSelectedStrategy(PluginSsomicrosoftReAuth::class)) {
        throw new Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Html::redirect(PluginSsomicrosoftSso::startReauthentication(Session::getLoginUserID()));
} elseif ((isset($_GET['code']) || isset($_GET['error']))
    && PluginSsomicrosoftSso::hasPendingReauthentication()) {
    Session::checkLoginUser();
    $manager = Glpi\Security\ReAuth\ReAuthManager::getInstance();
    if (!$manager->isSelectedStrategy(PluginSsomicrosoftReAuth::class)) {
        throw new Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $state = (string) ($_GET['state'] ?? '');
    $code  = (string) ($_GET['code'] ?? '');
    $verified = PluginSsomicrosoftSso::completeReauthentication(
        Session::getLoginUserID(),
        $state,
        !empty($_GET['error']) ? '' : $code
    );
    if (!$verified) {
        Html::redirect($CFG_GLPI['root_doc'] . '/ReAuth/Prompt?failed=1');
    }
    $manager->authenticate();
    echo Glpi\Application\View\TemplateRenderer::getInstance()->render(
        'pages/redirect_post.html.twig',
        [
            'http_method' => $manager->getRequestedMethod(),
            'url'         => $manager->getRequestedURL(),
            'replay_data' => $manager->getReplayData(),
        ]
    );
} elseif (isset($_GET['code']) || isset($_GET['error'])) {
    // Callback from Entra ID for a normal login.
    PluginSsomicrosoftSso::handleCallback();
} elseif ($action === 'login' && isset($_GET['connection_id'])) {
    PluginSsomicrosoftSso::startLogin((int) $_GET['connection_id']);
} else {
    Html::redirect($CFG_GLPI['root_doc'] . '/');
}
