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
    && PluginSsomicrosoftSso::isReauthenticationCallback()) {
    // If GLPI's session cookie is SameSite=Strict, browsers omit it on the
    // first navigation back from Microsoft. Bounce through a same-site page
    // before checking the session so the original authenticated session is
    // sent on the resumed callback.
    if (!isset($_GET['_ssomicrosoft_resume'])) {
        $callback_params = [
            'state'              => (string) ($_GET['state'] ?? ''),
            'code'               => (string) ($_GET['code'] ?? ''),
            'error'              => (string) ($_GET['error'] ?? ''),
            'error_description' => (string) ($_GET['error_description'] ?? ''),
            '_ssomicrosoft_resume' => '1',
        ];
        $callback_params = array_filter($callback_params, static fn($value) => $value !== '');
        $resume_url = $CFG_GLPI['root_doc'] . '/plugins/ssomicrosoft/front/sso.php?'
                    . http_build_query($callback_params);

        Html::nullHeader(__('SSO Microsoft', 'ssomicrosoft'));
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($resume_url) . '">';
        echo '<script>window.location.replace(' . json_encode($resume_url) . ');</script>';
        echo '<a href="' . htmlspecialchars($resume_url) . '">' . __('Continuer', 'ssomicrosoft') . '</a>';
        Html::nullFooter();
        exit;
    }

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
