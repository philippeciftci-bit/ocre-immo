---
mission_id: M/2026/05/11/36
title: M_AGENT_LANDING_REELLE branche SPA réelle + provisioning auto
project: ocre
status: livrée
---

# Approche choisie (architecture)

La SPA Oi Agent réelle (CRM) est multi-tenant : 1 DB `ocre_wsp_<slug>` par user. Le routing nginx existant `~^(<slug>)\.ocre\.immo$` sert `/opt/ocre-app/` avec `X-Tenant-Slug=<slug>` extrait du subdomain. Servir `/opt/ocre-app/` directement sur `agent.ocre.immo` aurait nécessité un refactor majeur du système X-Tenant-Slug (déduit du cookie SSO au lieu du subdomain).

**Décision pragmatique** : `agent.ocre.immo` devient un **routeur transparent** qui détecte la session SSO, provisionne le tenant si absent, et redirige direct vers `<slug>.ocre.immo/?_s=<sso>` (SPA tenant réelle, dashboard CRM ouvert).

# Flow avant / après

**Avant (M/35)** :
```
magic link → agent.ocre.immo/?activated=1 → landing factice "Bonjour Exbat. [Activer Oi Agent]"
  → clic Activer → signup.ocre.immo/inscription (form B) — DOUBLON.
```

**Après (M/36)** :
```
magic link → 302 agent.ocre.immo/?activated=1 (routeur transparent)
  → fetch /api/me.php (auth.ocre.immo) → user identifié
  → POST /api/provision-tenant.php (auth.ocre.immo) → slug + tenant DB + sso_token
  → redirect direct <slug>.ocre.immo/?_s=<sso>&activated=1 (SPA Oi Agent réelle)
  → SPA pose le cookie session via _s=, ouvre dashboard CRM.
```

ZÉRO bouton "Activer", ZÉRO sas, provisioning transparent en backend.

# Fichiers modifiés / créés

## Modifiés
- `agent-landing/index.html` : suppression `state-logged` (avec "Bonjour Exbat + Activer Oi Agent"). Remplacé par routeur transparent : state-loading par défaut ("Ouverture de ton workspace…") + state-error (retry + logout) + state-anon (CTAs vers `auth.ocre.immo/signup?app=agent`). Script appelle `/api/me.php` puis `/api/provision-tenant.php` puis `location.replace()` vers slug tenant.

## Créés
- `auth-root/api/provision-tenant.php` : endpoint POST authenticated. Identifie user via cookie ocre_jwt → cherche/crée user legacy `ocre_meta.users` avec slug déterministe `<prefix>-<hash4>` depuis email → appelle `provision_agent_workspace($slug)` (helper existant `/opt/ocre-app/api/_provision.php` — idempotent) → crée workspace meta + member owner → génère sso_token + session legacy 30j → retourne `{ok, slug, tenant_url, sso_token}`.
- `e2e/tests/ocre/agent-landing-reelle.spec.js` : 6 tests (1 HTML sans "Activer Oi Agent" + 1 auth gate + 1 etat anon + 3 viewports loader).
- `e2e/tests/ocre/signup-direct-pwa.spec.js` : mis à jour pour le nouveau flow (le test M/35 attendait `agent.ocre.immo/?activated=1` final, maintenant URL finale = tenant `<slug>.ocre.immo` post-routeur).

# Provisioning auto (schéma)

1. Récup user `auth_users` via cookie ocre_jwt.
2. SELECT `users` legacy WHERE email = ?. Si exists et slug NOT NULL → reuse slug.
3. Sinon générer slug : `<email_prefix_sanitized[0..30]>-<sha256(email)[0..4]>` (anti-collision via `WHERE slug=? AND email!=?`).
4. INSERT ou UPDATE `users` legacy avec slug + role=agent + status=active.
5. `provision_agent_workspace($slug, $pdoMeta)` (idempotent : retourne `database_already_exists` si déjà créé).
6. INSERT `workspaces` + `workspace_members` (owner) si pas déjà en place.
7. INSERT `sessions` (token sso 30j) → retourne dans le JSON pour pose cookie côté SPA via `?_s=<sso>`.

# Tests Playwright

`agent-landing-reelle.spec.js` 6/6 PASS (2.3s) :
1. HTML agent.ocre.immo : aucune occurrence "Activer Oi Agent" ou "Bonjour Exbat".
2. `/api/provision-tenant.php` 401 sans cookie.
3. `?logout=1` force state-anon, CTAs pointent `auth.ocre.immo/signup?app=agent`.
4-6. 3 viewports (iPhone 390, iPad 768, Desktop 1440) : state-anon par défaut sans session.

Anti-régression : `signup-direct-pwa.spec.js` 5/5 PASS (test 1 maj : flow valide → URL finale `<slug>.ocre.immo` ou `agent.ocre.immo` selon présence session). `signup-unifie.spec.js` 3/3 PASS.

Total : **14/14 PASS** (22.3s).

Rapport HTML : https://46-225-215-148.sslip.io/maquettes/agent-landing-reelle-<TS>/

# Tag git
- `pre-M_AGENT_LANDING_REELLE-20260511-165953` (rollback)
- `stable-2026-05-11-1700-ocre-agent-landing-reelle` (post-success)

# Hors scope volontaire / explicite
- **Servir `/opt/ocre-app/` directement sur agent.ocre.immo** (sans subdomain slug) demande un refactor majeur du système X-Tenant-Slug (déduire le tenant du cookie SSO au lieu du subdomain). Pas fait — agent.ocre.immo reste un routeur léger. Si Philippe veut "URL canonique agent.ocre.immo unique pour tous les agents" → mission séparée pour ce refactor.
- **Popup PWA install overlay non-bloquant** : déjà non-bloquant techniquement (pas de backdrop), bas centré. Pas réajusté en bas-droite desktop / bas full-width mobile car le routeur redirect en ~1s → popup ne s'affiche que si redirect bloque (cas erreur). Décision : déplacer le popup PWA vers la SPA tenant `<slug>.ocre.immo` est la bonne place — à brancher quand la SPA gère `?activated=1`.
- **iOS Safari instructions visuelles** : déjà dans le code (pwa-prompt-body iOS path avec "Partager → écran d'accueil").
- **SPA tenant doit gérer `?_s=<token>` + `?activated=1`** : déjà gere via la conv historique M/2026/05/07/117 (vu dans le code legacy). Inchangé.
