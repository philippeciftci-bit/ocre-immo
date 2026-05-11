---
mission_id: M/2026/05/11/25
title: M_SUPERADMIN_REBUILD_PROPER — rebuild superadmin.ocre.immo (architecture propre Stripe/Vercel)
project: ocre
status: livrée
created_at: 2026-05-11T13:48:00+02:00
---

# M_SUPERADMIN_REBUILD_PROPER — rapport

## Ce qui était cassé
- Panneau "Utilisateurs en DB" ajouté en M/24 mal placé dans Vue d'ensemble, Philippe ne le trouvait pas.
- Architecture accumulée par patches (1858 lignes inline HTML+CSS+JS), pas de séparation claire des responsabilités.
- Lien "Atelier Live" rapporté comme pointant vers Oi Agent (vérification : pointait déjà vers sslip.io/live/, faux signal, mais rebuild propre intègre cette adresse explicitement).
- Reset Total : confirmation simple "tape RESET TOTAL" non assez sécurisée par UX.

## Ce qui a été livré (commit à venir)

### Frontend (rebuild complet)
- `/opt/ocre-app/superadmin/index.html` ré-écrit (1858 lignes → 530 lignes propres). Ancien archivé en `index.legacy.html.ARCHIVED-20260511`.
- Sidebar fixe 240px (drawer hamburger en mobile <900px) avec 8 sections claires :
  1. 📊 Vue d'ensemble (KPI users / sessions / magic / signups 24h)
  2. 👥 Utilisateurs (DataTable + recherche live + delete cascade)
  3. 🔑 Sessions actives (table + revoke par session)
  4. ✉️ Magic links (KPI pending/consumed/expired + revoke pending)
  5. 🧩 Modules Oi (7 modules · toggle 3 états ACTIF/BIENTÔT/DÉSACTIVÉ)
  6. 📜 Audit log (200 dernières actions super_admin_events)
  7. 🔧 Atelier (Live View → sslip.io/live/, Grafana, Backups, Telegram, GitHub, Maquettes)
  8. ⚠️ Zone danger (Reset Total + Reset partiel · "tape DELETE" double confirmation)
- Composants réutilisables : `openConfirm`, `openTypeToConfirm`, helper `api()` unique, DataTable inline.
- Routing hash `#section` avec replaceState (deep-link OK).
- Design : DM Sans + Playfair Display, palette ocre/champagne, fond blanc, sidebar gris très clair. Mobile-first iPad portrait OK.

### Backend (endpoints créés)
- `/opt/ocre-app/api/superadmin_magic_links.php` (GET list / POST revoke)
- `/opt/ocre-app/api/superadmin_modules.php` (GET list / POST set_state, persiste `/var/lib/atelier/ocre_modules_state.json`)
- `/opt/ocre-app/api/superadmin_audit_log.php` (GET list, lecture super_admin_events)
- `superadmin_cleanup.php` étendu : action `reset_partial` (TRUNCATE auth_magic_tokens + auth_sessions + auth_refresh_tokens, comptes auth_users intacts)

Réutilise `superadmin_auth_users.php` (M/24), `superadmin_sessions.php`, `superadmin_cleanup.php`, `superadmin_session_check.php`, `superadmin_send_magic_link.php`.

### Sécurité
- Toutes les routes super-admin gated par `current_user_or_401()` + `role === 'super_admin'`.
- Philippe (`philippe.ciftci@gmail.com` ou `is_super_admin=1`) protégé sur Delete user (refus 403).
- Reset Total et Reset partiel : modal "tape DELETE" + confirmation_word backend (`RESET TOTAL` / `RESET PARTIAL`).
- Tag git `pre-M_SUPERADMIN_REBUILD_PROPER` pour rollback rapide.

## Tests
- PHP lint OK 4 fichiers.
- HTTP smoke : `/api/superadmin_magic_links.php`, `/superadmin_modules.php`, `/superadmin_audit_log.php` → 401 sans auth (gate OK).
- HTML superadmin.ocre.immo → 200.

## Test Philippe
1. Ouvre `https://superadmin.ocre.immo` → form magic-link.
2. Saisis `philippe.ciftci@gmail.com` → email reçu → clic.
3. Dashboard charge sur Vue d'ensemble · sidebar 8 sections visibles.
4. Clic "Utilisateurs" → table avec tous les users + bouton Supprimer (protégé sur Philippe).
5. Clic "Atelier" → 6 cards · "Live View CC" ouvre `sslip.io/live/` (target _blank).
6. Clic "Zone danger" → "Reset Total" → modal "tape DELETE" → bouton activé seulement après "DELETE" tapé.

## Hors scope (volontaire)
- Pas de pagination si >50 users (faux problème pour l'instant : 19 users en DB).
- Pas de filtres avancés (super-admin only, actifs 7j) : décision = ajouter quand le besoin se manifeste.
- Erreurs JS 24h (GlitchTip) : module GlitchTip pas activé (scaffolding seul), KPI sauté.
