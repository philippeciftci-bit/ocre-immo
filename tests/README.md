# Smoke tests Ocre Immo

Tests bash + curl, zéro dépendance framework.

## Lancer manuellement

```bash
bash /root/workspace/ocre-immo/tests/run_all.sh
```

## Fichiers

- `helpers.sh` — `assert_eq`, `assert_contains`, `api()`, `print_summary()`
- `01_auth.sh` — auth.me, sans token, token invalide
- `02_clients.sh` — list, search
- `03_matching.sh` — list, count, stats
- `04_events.sh` — events.list_all, notifications.count_unread
- `05_documents.sh` — endpoint répond
- `06_edit_consent.sh` — list_pending
- `07_export.sh` — CSV/XLSX/notifications/calendar/preferences
- `08_admin.sh` — overview/users/clients_xt/audit_log/recycle_bin/feature_flags + 401 sans token

## Token long-lived

`/root/.secrets/test_admin_token` (Philippe super_admin uid=2, valide 365 jours).

## Cron quotidien

`ocre-smoke-tests.timer` lance `run_all.sh` chaque jour à 6h UTC.

## Hook post-deploy

`ocre-deploy.sh` exécute `run_all.sh` après chaque rsync (best-effort, ne bloque pas).

## Ajouter un test

Créer `smoke/NN_domain.sh` (NN entre 09 et 99), `source helpers.sh`, et terminer par `print_summary "domain"`.
