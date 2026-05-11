---
mission_id: M/2026/05/11/37
title: M_AUTH_FLOW_REFONTE login popup unifié + accordéon cas C + TTL configurable
project: ocre
status: livrée
---

# Flow popup unifié sur ocre.immo/oi-agent (et tous les CTAs `data-signup-trigger`)

```
Tap "Commencer (gratuit)" → popup overlay (page WP, ZERO redirect)
  champ Email + bouton "Recevoir mon lien d'accès"
  ↓ submit
  POST auth.ocre.immo/api/login.php { email, app }
  ↓
  ├── Cas A — email connu + session valide + tenant OK
  │   → message "Tu es déjà connecté…" + redirect <slug>.ocre.immo 1.2s
  ├── Cas B — email connu + pas de session
  │   → message vert "✓ Lien envoyé"  + bouton "Renvoyer (30s)" cooldown
  └── Cas C — email inconnu (AMENDEMENT : accordéon, ZERO redirect)
      → popup s'étend (max-height 0→720px, 350ms ease-out)
      → champs prenom/nom/société + téléphone (sélecteur 10 pays) + cgu + rgpd
      → bouton change : "Créer mon compte et recevoir mon lien"
      → submit POST /api/magic-link/request.php avec full profile
      → user créé status pending_activation + magic link envoyé
      → message "✓ Compte créé, lien envoyé"
```

# Fichiers modifiés / créés

## Backend
- **`auth-root/api/login.php`** : refactor complet endpoint login-or-signup unifié. 3 cas (direct / link_sent / signup_required). Anti-enumeration. Plus de `auth_get_or_create_user` (l'ancien créait silencieusement → break cas C). TTL custom user respecté.
- **`auth-root/api/magic-link/request.php`** : lecture `magic_link_ttl_hours` du user (fallback 24h), INSERT avec `DATE_ADD(NOW(), INTERVAL ? HOUR)` dynamique.
- **`api/superadmin_auth_users.php`** : nouveau action `update_auth_settings` POST `{user_id, magic_link_ttl_hours, session_idle_timeout_hours}` whitelist 24/168/720h. Query list retourne aussi `magic_link_ttl_hours` + `session_idle_timeout_hours` pour pré-remplir la modale.

## Frontend
- **`wp-theme-.../parts/signup-popup.php`** : 30 lignes (redirect M/34) → 220 lignes : popup overlay complet, champ email + accordéon caché (`.oal-extra` max-height 0 → 720px), 3 cas dispatché côté JS, PhoneInput simplifié (10 pays principaux). ZERO redirect.
- **`superadmin/index.html`** : table Utilisateurs gagne bouton "🔑 Auth" par ligne → `openAuthSettings(userId, ttl, idle)` → modale "Paramètres auth" avec 2 selects (24h / 7j / 30j) + save → endpoint `update_auth_settings`.

## Migration DB (idempotente)
**`auth-root/migrations/M_AUTH_FLOW_REFONTE.sql`** :
```sql
ALTER TABLE auth_users ADD COLUMN IF NOT EXISTS magic_link_ttl_hours INT NOT NULL DEFAULT 24;
ALTER TABLE auth_users ADD COLUMN IF NOT EXISTS session_idle_timeout_hours INT NOT NULL DEFAULT 24;
ALTER TABLE auth_sessions ADD COLUMN IF NOT EXISTS last_activity_at DATETIME NULL;
```
Exécutée en prod début de mission. Idempotente (IF NOT EXISTS).

## Tests
**`e2e/tests/ocre/auth-flow-refonte.spec.js`** : 5 tests PASS 13.6s
1. Popup s'ouvre via `window.ocreSignupOpen({app:'agent'})` (CTAs auto-bind data-signup-trigger).
2. Cas B : email Philippe → message "Lien envoyé" + bouton "Renvoyer (30s)" disabled.
3. Cas C accordéon : email inconnu → accordéon s'ouvre, URL inchangée (ZERO redirect), champs prenom/nom/tel/cgu/rgpd visibles, bouton label "Créer mon compte…", disabled initial.
4. Cas C complet : remplit tous champs + cgu + rgpd → user créé en DB + magic link en DB + message "Compte créé, lien envoyé".
5. TTL custom : user `magic_link_ttl_hours=168` (7j) → POST request.php → `expires_at` ~ NOW+604800s (tolérance 120s).

Anti-régression : superadmin-full-walkthrough (4) + agent-landing-reelle (6) PASS, total 7/7 (8.5s).

# Hors scope volontaire / explicite
- **Idle timeout middleware** : colonne `session_idle_timeout_hours` + `auth_sessions.last_activity_at` ajoutées en DB, modale superadmin permet la config. Mais le middleware applicatif qui UPDATE `last_activity_at` à chaque requête API + check `now - last_activity_at > idle` n'est PAS implémenté (complexe, demande hook sur tous les endpoints API auth). Le système actuel respecte `expires_at` strict. À brancher dans une mission dédiée si besoin.
- **PhoneInput full M86 (21 pays + validation E.164 stricte)** : popup utilise un select simplifié 10 pays principaux + input tel libre. Pour la validation E.164 stricte par pays, le user peut compléter via le form standalone `auth.ocre.immo/signup` (toujours dispo, hors scope mission, voir mission M/34).
- **Page erreur magic link expiré** : reste sur `auth.ocre.immo/error.html?reason=token_invalid` (existant). Pour customiser le wording "Lien expiré, demande un nouveau lien", mission cosmétique séparée.

# Tag git
- `pre-M_AUTH_FLOW_REFONTE-20260511-174108`
- `stable-2026-05-11-1750-ocre-auth-flow-refonte`
