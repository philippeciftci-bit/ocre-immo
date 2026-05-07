# CHARTER Ocre Immo (Oi Agent)

> Source de vérité figée des règles du projet. Lue par `/root/bin/quality-gate` à chaque mission technique. Versionnée append-only via git. Tag à chaque modif majeure.
>
> **Mission de référence** : M/2026/05/08/14 (Autopilot + Quality Gate + Project Charter).

## 1. Design

### Palette canonique (tokens.css)

Source : `/opt/ocre-app/styles/tokens.css` (DESIGN-TOKENS.1 + 2.x).

**Couleurs sémantiques** :
- `--color-bg-page: #FCFAF6` (Albâtre, fond Oi Agent — décision Philippe FOND-ALBATRE)
- `--color-bg-card: #FFFFFF`
- `--color-bg-input: #FDFAF7`
- `--color-bg-tint-warm: #FBF1E4` (selected/active)
- `--color-bg-tint-hover: #FFF3EC`
- `--color-bg-tint-cream: #F4EEE6`
- `--color-bg-tint-soft: #FFFCF5`
- `--color-text-primary: #2A2018`
- `--color-text-secondary: rgba(42,32,24,0.7)`
- `--color-text-muted: rgba(42,32,24,0.5)`
- `--color-text-brown: #5C3B1E`
- `--color-text-taupe: #5A4E3D`
- `--color-accent: #8B5E3C` (ocre signature)
- `--color-accent-hover: #5C3E27`
- `--color-ocre-deep: #A06B45` (gradient stop)
- `--color-ocre-flamboyant: #B26D3A`
- `--color-border-subtle: #E8DDD0`
- `--color-border-cream: #EDE6DC`
- `--color-border-light: #D9C9A8`
- `--color-border-medium: #C9B79A`
- `--color-border-strong: #BBA88B`
- `--color-success: #2D7A4F`
- `--color-error: #C73E3E`
- `--color-warning: #B8801E`
- `--color-info: #1D4E89`

**Profils métier** (non substituables, exception documentée) :
- `--profil-acheteur: #8B5E3C` (ocre)
- `--profil-vendeur: #1D4E89` (navy)
- `--profil-bailleur: #6B7B5E` (sauge)
- `--profil-locataire: #B8801E` (jaune ocre)
- `--profil-investisseur: #7E5C9E` (lavande)
- `--profil-promoteur: #455B6B` (bleu ardoise)
- `--profil-marchand: #6E5340` (brun)
- `--profil-curieux: #8B7F6E` (gris taupe)

### Typographie

- **Cormorant Garamond** (serif) : titres `<h1>`, wordmark, sections importantes
- **DM Sans** (sans-serif) : corps texte, labels, helpers, boutons

### Exceptions préservées

1. **Badge BROUILLON** `#B91C1C` (M91 design signature, pattern Notion/Linear/Figma)
2. **`--profil-*`** métier (couleurs business non-substituables)
3. **`<meta name="theme-color">`** hex direct (var() non supporté navigateur)
4. **Ternaires JS dynamiques** `? '#XXX' : '#YYY'` (expressions runtime)
5. **`--border-subtle` legacy** ligne 172 + **`--ocre-primary` legacy** ligne 173 (defs CSS vars conservées)

## 2. Architecture

### Bases de données

- **Meta** : `ocre_meta` (1 DB centrale, table `users`, `sessions`, `super_admin_events`, `audit_log`, `nominatim_cache`, `notif_events`, `workspaces`, etc.)
- **Workspaces** : `ocre_wsp_<slug>` (1 DB par agent, mode unique M84, charset utf8mb4_unicode_ci, 4 tables minimales : `clients`, `settings`, `sessions`, `logs`)
- **Convention MySQL** : user master `ocre_app@localhost` avec wildcard `GRANT ALL PRIVILEGES ON ocre\_%.*` (couvre toutes les DBs ocre_*)

### Naming colonnes `users` (figé M5+)

| Colonne | Type | Notes |
|---------|------|-------|
| `id` | INT UNSIGNED PK | auto_increment |
| `email` | VARCHAR(255) UNIQUE | |
| `role` | ENUM('agent','super_admin') | |
| `slug` | VARCHAR(50) | sluggified de `agence_nom` ou `prenom-nom` + suffixe random hex |
| `status` | ENUM('pending_activation','active','suspended','deleted') | **PAS** `signup_status` |
| `activation_token` | VARCHAR(64) | bin2hex(random_bytes(32)) |
| `activation_token_expires_at` | DATETIME | NOW() + INTERVAL 48 HOUR |
| `cgu_accepted` | TINYINT(1) | + `_at`, `_version`, `_ip`, `_user_agent` |
| `rgpd_accepted` | TINYINT(1) | idem |
| `pays` | VARCHAR(2) | distinct de `country_code` (souscription) |

### Endpoints API canoniques

- `agents_register.php` (POST signup, conformité M86 strict CGU+RGPD)
- `agents_activate.php` (GET token, redirect /login/?activated=1)
- `_provision.php` (helper privé, regex slug strict /^[a-z0-9-]{3,40}$/)
- `clients.php` (CRUD dossiers, avec deleted_at IS NULL filter)
- `nominatim.php` (proxy autocomplete, **PUBLIC** sans requireAuth depuis M10)
- `auth_v20.php` (POST login)
- `telegram_link.php` (link/disconnect/test_notify, action=webhook public)

### URLs canoniques (OI_VISION figée 7 mai 2026)

- `ocre.immo` → vitrine corporate
- `agent.ocre.immo` → Oi Agent landing + login
- `app.ocre.immo` → app tenant (multi-tenant via wildcard) + `/signup/` alias
- `superadmin.ocre.immo` → console super-admin
- `signup.ocre.immo` → wizard inscription (parallèle alias retro-compat)
- `<slug>.ocre.immo` → workspace tenant agent

### PHP FPM limits (post-M11)

```ini
upload_max_filesize = 25M
post_max_size = 25M
```
Cohérent avec `client_max_body_size 25M` nginx.

## 3. Produit

### Wizard fiche client v55

- **8 profils** : Acheteur, Vendeur, Locataire, Bailleur, Investisseur, Promoteur, MarchandBiens, Curieux
- **7 sections** TOUTES visibles tous profils (M94 non-négociable, M99 fix régression)
- **4 stages collapsibles** Section II Le bien
- **Profil = TAG informatif PAS un filtre UI** (M94)

### Flow signup

1. `signup.ocre.immo/inscription/` ou `app.ocre.immo/signup/` (alias)
2. Wizard 4 étapes (CGU + RGPD distincts M86 obligatoire)
3. POST `/api/agents_register.php` → 201 user pending_activation + email activation
4. Click lien email → GET `/api/agents_activate.php?token=...`
5. Provisioning DB workspace `ocre_wsp_<slug>` + flip status='active'
6. Redirect `/login/?activated=1`

### Brouillons / Auto-save

- Auto-save debounced 800ms, retry x3 backoff sur 1er commit
- **Détection 401** : stop retry + clear localStorage + redirect `/login/?expired=1` (M9 fix)
- Pattern V20 anti-doublon : `editingIdRef` + `savePromiseRef` série les saves

### Notifications Telegram

- Bot `@ocreimmo_bot` (token `/etc/ocre/telegram-bot.env` mode 0640)
- 3 templates : `notify_match_found`, `notify_pdf_ready`, `notify_reminder_relance`
- Idempotence via `event_id`
- Webhook public `/api/telegram_link.php?action=webhook`

## 4. Règles strictes (non-négociables)

1. **Mode unique** depuis M84 : pas de `OCRE_MODE`, pas de `isTestMode`, pas de `mode === 'test'`, pas de `seed_clients`. Prod direct.
2. **Pas de rename naming** sans validation Niveau 2 (leçon M5/M6 status vs signup_status).
3. **Pas d'inclusion silencieuse hors-périmètre** (leçon M48-M56 dérive). Mission strictement scopée. Si scope flou → STOP + ⚠️ blocked + question.
4. **Versions entières uniquement** : V5, V6, V7. PAS V5.2.3, PAS V6.1-hotfix.
5. **Commits Conventional** : `feat(ocre)` / `fix(ocre)` / `chore(atelier)` etc. + `[M/AAAA/MM/JJ/N]` mission_id.
6. **`safe-commit` obligatoire** : `safe-commit ocre-immo "message"`. Jamais `git commit` direct.
7. **`ocre-push` / `ocre-deploy.sh` obligatoires** : jamais `cp` manuel vers `/opt/ocre-app/`.
8. **Notifs `--phase`** : `start|blocked|done|error` strict. Bypass `--priority success/info` avec `--mission-id` REJETÉ par `/root/bin/notify` depuis M/2026/05/07/4.
9. **Tag stable obligatoire** : `stable-AAAA-MM-JJ-HHMM-ocre-<slug-mission>` à chaque livraison.
10. **Rapport obligatoire** : `/root/workspace/reports/AAAA-MM-JJ-HHMM-ocre-<slug>.md` avec status READY + tag + commit + tests E2E.

## 5. Anti-patterns interdits

- `OCRE_MODE`, `isTestMode`, `mode === 'test'`, `seed_clients_v1` (mode unique M84)
- Hardcodes couleurs métier `#8B5E3C`, `#2A2018`, `#8B7F6E` hors exceptions documentées
- Refacto silencieux hors périmètre mission
- `git push --force` sur main sans validation Niveau 3
- DELETE / DROP DATABASE sans backup + validation Niveau 2
- Cleanup global `WHERE name IS NULL` (risque catastrophique)
- Rename de colonne sans Niveau 2 + ALTER + refactor cohérent
- Bypass `--priority success` au lieu de `--phase done`

## 6. Parcours utilisateur critiques (smoke tests E2E quality-gate)

1. **Auto-save création dossier** : POST `clients.php?action=save` user fictif → 1 seul enregistrement final (idempotence). Si 401 → redirect login (M9).
2. **Autocomplete adresse FR** : GET `nominatim.php?q=18+Rue+de+Rivoli&countrycodes=fr` → 5 résultats (M10).
3. **Upload photos** : POST `photo_upload.php` avec JPG 2.5MB → pas d'erreur 413 (M11).
4. **Signup E2E** : POST `agents_register.php` (CGU+RGPD true) → 201 + email activation. GET `agents_activate.php?token=...` → 302 + DB workspace créée + status='active' (M5/M7).
5. **Fond Albâtre** : `tokens.css` sert `--color-bg-page: #FCFAF6` (FOND-ALBATRE).
6. **7 stages tous profils** : `STAGES.Acheteur` length === 7 (M94 + M99).
7. **Badge BROUILLON** : `#B91C1C` présent dans index.html (M91 signature design).

## 7. Versioning charter

- Append-only : modif majeure = ajouter section ou amender, jamais supprimer historique.
- Tag git à chaque modif majeure : `charter-vN`.
- `mission_id` de référence en frontmatter.

---

**Version 1.0** — Construit M/2026/05/08/14. Sources : userMemories Philippe + CONVENTIONS.md + OI_VISION.md + 30 derniers rapports missions Ocre.
