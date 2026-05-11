---
mission_id: M/2026/05/11/35
title: M_SIGNUP_DIRECT redirect direct PWA + suppression hub
project: ocre
status: livrée
---

# Flow avant/après

**Avant** :
```
ocre.immo → CTA signup → modale ou wizard
  → email magic link
  → /api/magic-link/validate.php?token=...&app=agent
  → redirect app.ocre.immo/oi-agent (HUB)
  → user clique tile Oi Agent dans le hub
  → agent.ocre.immo (landing) → ??? doit ouvrir l'app
```

**Après** :
```
ocre.immo → CTA "Créer mon compte" data-signup-trigger="agent"
  → redirect auth.ocre.immo/signup?app=agent
  → form unifié (subtitle "Pour Oi Agent")
  → email magic link
  → /api/magic-link/validate.php?token=...&app=agent
  → redirect direct agent.ocre.immo/?activated=1
  → popup PWA install (custom iOS / beforeinstallprompt Android+Desktop)
```

ZÉRO hub intermédiaire. Suppression de /opt/ocre-app-hub/ (archivé).

# Fichiers modifiés / créés

## Modifiés
- `auth-root/api/magic-link/validate.php` : `$appUrls['agent']` → `https://agent.ocre.immo/?activated=1` (avant : `https://app.ocre.immo/oi-agent`). Fallback aussi vers agent.ocre.immo. Les autres apps (scan/book/demande/...) restent sur app.ocre.immo/oi-* temporairement (sous-domaines pas déployés).
- `auth-root/signup.html` : capture `?app=<slug>` au load via `applyAppSubtitle()`, affiche subtitle "Pour Oi Agent" si app whitelist (agent/scan/book/demande/capture/estimer), fallback silencieux sinon.
- `etc/app-hub-ocre.conf` : ancien hub (50+ lignes location/cache/CSP) → 30 lignes avec un seul `return 301 https://ocre.immo/` pour TOUTES les routes (préserve liens externes).
- `agent-landing/index.html` : ajout popup PWA install (custom iOS path avec instructions visuelles "Partager → Sur l'écran d'accueil" / Android+Desktop via beforeinstallprompt + Service Worker register).

## Créés
- `agent-landing/sw.js` : service worker minimal Oi Agent (cache-first assets statiques + network-first HTML). Requis pour rendre la PWA installable sur Chrome/Edge.
- `e2e/tests/ocre/signup-direct-pwa.spec.js` : 5 tests Playwright.

## Archivés (rollback)
- `/opt/ocre-app-hub` → `/opt/ocre-app-hub.ARCHIVED-M_SIGNUP_DIRECT-<TS>`
- `/etc/nginx/sites-enabled/app-hub-ocre.conf` original → `.ARCHIVED-M_SIGNUP_DIRECT-<TS>`

# Tests Playwright (5/5 PASS, 11.4s)

1. **Flow signup → magic link → redirect direct** : visite `auth.ocre.immo/signup?app=agent`, subtitle "Pour Oi Agent" visible, remplit form (Jean Direct + tel + 2 checkboxes), submit, récupère token via mariadb, visite validate.php → URL finale `agent.ocre.immo/?activated=1` ✓.
2. **PWA manifest valid** : link[rel=manifest] présent + fetch retourne `name: Oi Agent`, `display: standalone`, icons 192+512 ; `sw.js` 200 OK.
3. **Popup install iOS path** : contexte avec UA iPhone Safari → goto `/?activated=1` → popup apparaît après 2s, body contient "Partager" + "écran d'accueil", dismiss pose localStorage flag, reload ne ré-apparaît pas.
4. **Hub redirect 301** : `app.ocre.immo/oi-agent` → HTTP 301 → Location `https://ocre.immo/`.
5. **Param ?app invalide** : `?app=evil` → subtitle reste hidden (fallback silencieux).

Anti-régression : `superadmin-full-walkthrough.spec.js` (4 tests) PASS, `signup-unifie.spec.js` (3 viewports) PASS. Aucune régression.

Rapport HTML screenshots : https://46-225-215-148.sslip.io/maquettes/signup-direct-2026-05-11T16-29-50/

# Vitrine

Sections par outil (hv-tile Oi Agent/Scan/Book) existent déjà dans `front-page.php`. Le CTA "Créer mon compte" générique (`data-signup-trigger="agent"`) appelle `window.ocreSignupOpen()` qui redirige vers `auth.ocre.immo/signup?app=agent` (chaîne héritée de M/34).

Pour des CTAs distincts par outil (4 boutons), il suffit de poser `data-signup-trigger="scan"` etc. sur les tiles. Non fait dans cette mission (chacune des pages outil `/oi-agent`, `/oi-scan`, `/oi-book` est une page WP individuelle, modif à faire dans le contenu WP côté Philippe). Le mécanisme est en place dès qu'un `data-signup-trigger` est posé.

# Tag git

- `pre-M_SIGNUP_DIRECT-20260511-162401` (rollback)
- `stable-2026-05-11-1630-ocre-signup-direct` (post-success)

# Hors scope volontaire
- Provisioning auto tenant `<slug>.ocre.immo` au validate.php : le redirect cible `agent.ocre.immo` (sous-domaine global Oi Agent landing, pas un tenant slug). Pour un parcours futur "1 tenant par user", à brancher quand la convention de slug user sera arrêtée.
- Oi Scan / Oi Book / Oi Demande : sous-domaines pas déployés, restent sur le hub `app.ocre.immo/oi-*` qui maintenant redirect 301 vers ocre.immo. Quand ces apps auront leur sous-domaine dédié, modifier `$appUrls` dans validate.php.
- PWA icons dédiés par outil : manifest agent.ocre.immo réutilise les icons Ocre génériques (vu en M/24, manifest déjà bien fait avec 192+512 maskable). À personnaliser par outil quand assets prêts.
