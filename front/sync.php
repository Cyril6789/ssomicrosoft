<?php

include('../../../inc/includes.php');

Session::checkRight('plugin_ssomicrosoft', UPDATE);

$connection_id = (int) ($_REQUEST['connection_id'] ?? 0);
$user_id       = (int) ($_REQUEST['user_id'] ?? 0);

/**
 * Show a per-connection result on screen so the administrator immediately sees
 * how many accounts Microsoft returned and how many were kept after the domain
 * filter — no need to dig into log files.
 */
$report = function (string $name, array $result): void {
    $message = sprintf(
        __('Connexion « %1$s » : %2$d compte(s) reçu(s) de Microsoft, %3$d traité(s) après filtre de domaine.', 'ssomicrosoft'),
        $name,
        (int) $result['fetched'],
        (int) $result['scoped']
    );

    if ((int) $result['fetched'] === 0) {
        Session::addMessageAfterRedirect($message, false, ERROR);

        // Show the exact error returned by Microsoft, when we have it.
        if (!empty($result['error'])) {
            Session::addMessageAfterRedirect(
                sprintf(__('Erreur renvoyée par Microsoft : %s', 'ssomicrosoft'), $result['error']),
                false,
                ERROR
            );
        }

        Session::addMessageAfterRedirect(
            __('Aucun compte reçu de Microsoft : vérifiez la permission Application « User.Read.All » (avec consentement administrateur), ainsi que le tenant / client / secret de la connexion.', 'ssomicrosoft'),
            false,
            ERROR
        );
    } else {
        Session::addMessageAfterRedirect($message, false, INFO);
    }
};

if ($user_id) {
    PluginSsomicrosoftSync::syncSingleUser($user_id, $connection_id);
    Session::addMessageAfterRedirect(__('Synchronisation de l\'utilisateur terminée.', 'ssomicrosoft'));
} elseif ($connection_id) {
    $conn = new PluginSsomicrosoftConnection();
    if ($conn->getFromDB($connection_id)) {
        $result = PluginSsomicrosoftSync::runConnection($conn->fields);
        $report((string) $conn->fields['name'], $result);
    }
} else {
    $summaries = PluginSsomicrosoftSync::syncAllSummaries();
    if (empty($summaries)) {
        Session::addMessageAfterRedirect(
            __('Aucune connexion active à synchroniser.', 'ssomicrosoft'),
            false,
            WARNING
        );
    } else {
        foreach ($summaries as $summary) {
            $report($summary['name'], $summary);
        }
    }
}

Html::back();
