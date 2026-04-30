# Sauvegardes Ocre Immo — état réel

Document factuel pour expliquer le système de sauvegarde aux utilisateurs (Philippe, Ophélie, Mehdi).
Audit M/2026/04/30/24.

## Niveau 1 — Base de données MySQL OVH (temps réel)

- DB MySQL hébergée sur cluster121 OVH.
- Toute modification dans l'app est écrite immédiatement dans la base (formulaires Section I/II/III/IV/V, photos, matches, etc.).
- Aucune perte possible entre 2 actions utilisateur — la persistance est synchrone.
- Statut : OK (en production depuis V1).

## Niveau 2 — Pull backup quotidien OVH → VPS atelier-philippe

- Cron : tous les jours 04:30 UTC (06:30 Paris été).
- Script : `/root/bin/ocre-pull-backup-from-ovh.sh`.
- Source : DB MySQL cluster121 OVH.
- Destination : `/root/workspace/_prod_backup_<date>/` sur le VPS.
- Logs : `/var/log/ocre-pull-backup.log`.
- Permet une restauration complète de la DB en cas de panne ou corruption OVH.
- Statut : OK (cron actif).

## Niveau 3 — Sauvegarde Google Sheets quotidienne par utilisateur

- Timer systemd : `ocre-sheet-backup.timer` quotidien 02:00 UTC.
- Service : `/usr/bin/python3 /root/bin/ocre-sheet-backup.py --all`.
- Logs : `/var/log/ocre-sheet-backup.log`.
- Pour chaque utilisateur ayant `users.sync_enabled = 1` ET un `sheet_id` configuré :
  l'app pousse les dossiers du jour dans le Google Sheet partagé avec le service account
  `ocre-vps-sync@my-project-test-400021.iam.gserviceaccount.com`.
- Setup utilisateur (Philippe, Ophélie, Mehdi) :
  1. Créer un Google Sheet dans son Drive personnel.
  2. Le partager en éditeur avec `ocre-vps-sync@my-project-test-400021.iam.gserviceaccount.com`.
  3. Coller l'URL du Sheet dans Préférences → Synchronisation Google Sheets.
  4. Le sync s'exécute automatiquement chaque nuit à 02:00 UTC.
- Statut : OK pour les utilisateurs ayant configuré le partage Sheet + service account.

## Export manuel CSV (Excel-compatible)

- Bouton « Télécharger .csv (Excel-compatible) » dans la modale Sauvegarde.
- Endpoint : `/api/export_xlsx.php`.
- Format : CSV UTF-8 avec BOM (lecture native Excel/Numbers/LibreOffice).
- Contenu : tous les dossiers non archivés, non-staged, non-supprimés de l'utilisateur.
- Téléchargement direct dans Fichiers / iCloud Drive (iOS) ou dossier Téléchargements (autre).
- Note : l'export en `.xlsx` natif via PhpSpreadsheet n'est pas déployé sur cluster121 OVH.
  Le CSV BOM utf-8 est ouvert directement par Excel sans erreur (extension `.csv` conservée
  pour fidélité du contenu réel).
- Statut : OK.

## Connecter Google Drive (sync auto hebdo)

- Bouton « Connecter Google Drive » dans la modale Sauvegarde.
- Endpoint OAuth : `/api/drive_oauth.php?action=connect|callback|disconnect|status`.
- Endpoint sync : `/api/drive_sync.php?action=now`.
- Timer hebdo : `ocre-drive-sync.timer` lundi 04:00 UTC.
- **État actuel (audit M/2026/04/30/24)** : credentials OAuth Google
  (`/root/.secrets/google_oauth_client_id` + `_secret`) absents sur le serveur.
  Le bouton est donc affiché en mode « Bientôt disponible » côté frontend (statut
  `oauth_configured: false` retourné par l'endpoint `?action=status`).
- Le mode Drive direct (différent du Sheet partagé Niveau 3) reste **à finaliser** :
  création projet Google Cloud Console + dépôt des secrets dans `/root/.secrets/`.
- Statut : non fonctionnel actuellement (Bientôt). Le Niveau 3 (Sheet partagé via service
  account) couvre déjà la sauvegarde automatique sur compte Google de l'utilisateur.

## Procédure de récupération

### Perte d'un dossier
1. Restaurable depuis la corbeille de l'app pendant 90 jours (mission 14).
2. Au-delà : récupération depuis le backup MySQL OVH le plus proche (`_prod_backup_<date>`).

### Panne complète OVH
1. Restauration DB depuis le backup VPS le plus récent (Niveau 2).
2. Re-déploiement de l'app via `/root/bin/ocre-deploy.sh` depuis le repo Git.

### Vérification utilisateur (sanity check)
- Bouton « Télécharger CSV » dans la modale Sauvegarde → ouvre le fichier dans Excel,
  vérifier que la liste des dossiers correspond à l'écran.
- Vue Sheet partagé Google : ouvrir le Sheet personnel → la dernière ligne doit dater
  d'au plus 24 h (sync 02:00 UTC).

## Recommandation utilisateur

Tes données sont protégées par 3 niveaux automatiques (DB temps réel + backup VPS quotidien
+ sync Google Sheets quotidien si configuré) plus l'export CSV manuel à la demande.
La sync Drive directe est en cours de configuration ; en attendant, la sync via Google Sheet
partagé (Niveau 3) couvre le besoin équivalent.
