# Synchro AAD

Plugin GLPI "Synchro AAD" permettant de synchroniser les utilisateurs depuis Entra ID.

## Installation

1. Copier le dossier `syncaad` dans le dossier `plugins` de votre installation GLPI.
2. Depuis l'interface GLPI, aller dans **Configuration > Plugins** et installer le plugin "Synchro AAD".

## Configuration

- Créez vos connexions Entra ID dans le menu du plugin (tenant, client ID, secret et filtre de domaine).
- Utilisez le script `scripts/sync.php` pour lancer une synchronisation via une tâche cron.
- Vous pouvez lancer manuellement une synchronisation complète ou sélectionner un utilisateur pour le mettre à jour.
