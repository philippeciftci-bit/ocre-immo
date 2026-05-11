---
mission_id: M/2026/05/11/46
title: M_OCRE_VITRINE_RESPONSIVE — pas d'overflow horizontal iPhone sur ocre.immo
project: ocre
status: livrée
---

# Diagnostic PRIMO (Playwright iPhone 13)

Playwright Chromium `viewport: 390×844` sur `ocre.immo/` et `ocre.immo/oi-agent` :

```
docWidth=390, scrollWidth=390, hasOverflow=false
viewport meta: width=device-width,initial-scale=1 (sans viewport-fit=cover)
```

**Document body ne déborde PAS** sur Chromium (`scrollWidth === docWidth`). Mais des éléments enfants sont détectés au-delà du viewport :
- `.hv-demo-strip` (home) : `offsetWidth=390 / scrollWidth=1544` (carousel demo avec `overflow-x: auto` interne → OK, c'est du scroll horizontal intentionnel à l'intérieur du strip).
- `.op-demo-card` (oi-agent) : positionnés à `left=656..1216` (au-delà du viewport 390px). Cards visibles uniquement via scroll horizontal du strip parent.

**Cause racine sur Safari iOS** (non reproductible Chromium) : `overflow-x: hidden` sur `<body>` est INSUFFISANT si `<html>` n'a pas la même règle. Sur Safari iOS 16+, les enfants positionnés (`position: absolute`/`fixed`) ou les strips horizontaux peuvent **leak** hors du `<body>` overflow-hidden et causer un scroll horizontal du `<html>`. Pattern bien connu (cf MDN + Stack Overflow 2024-2025).

Le `front-page.php` et `template-outil.php` ont chacun leur `body { overflow-x: hidden }` mais aucun ciblage `html`. C'est la racine.

# Fix appliqué

## `wp-theme/style.css` (filet de sécurité global)
```css
/* M/2026/05/11/46 — filet overflow horizontal iPhone Safari */
html, body {
  overflow-x: hidden;
  max-width: 100vw;
  position: relative;
}
img, video, iframe { max-width: 100%; height: auto; }
```
Pose `overflow-x: hidden` **aussi sur `<html>`** + double sécurité `max-width: 100vw`. `position: relative` sur `body` est nécessaire pour que `overflow:hidden` containerise correctement les enfants `position: absolute`. La règle `img/video/iframe` empêche les médias trop grands de provoquer un overflow.

## `wp-theme/header.php` (meta viewport)
```diff
- <meta name="viewport" content="width=device-width,initial-scale=1">
+ <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
```
`viewport-fit=cover` permet à l'app de prendre tout l'écran iPhone (sous la notch) et expose les `env(safe-area-inset-*)` utilisés par la popup login (M/45).

# Tests Playwright (4/4 PASS, 12.5s)

`e2e/tests/ocre/vitrine-responsive.spec.js` :

| Viewport | Test |
|---|---|
| iPhone SE 375×667 | `scrollWidth ≤ docWidth` sur home + oi-agent + popup width ≤ viewport |
| iPhone 13 390×844 | idem |
| iPhone 14 PM 430×932 | idem |
| Desktop 1440×900 | Anti-régression : `scrollWidth ≤ docWidth` reste vrai, design inchangé |

Pour chaque viewport iPhone, vérifications :
- `document.documentElement.scrollWidth === clientWidth`
- `document.body.scrollWidth ≤ clientWidth + 1`
- `getComputedStyle(html).overflowX === 'hidden'` ✓ (nouveau, posé par notre filet)
- `getComputedStyle(body).overflowX === 'hidden'` ✓
- `meta[name=viewport]` inclut `viewport-fit=cover` ✓
- Popup `boundingBox.width ≤ viewport.width + 1`

Anti-régression : **19/19 PASS** (52.2s) sur popup-login-responsive (4) + auth-flow-refonte (7) + cas-a-ttl (5) + tenant-splash (3). **Total 23/23 PASS.**

Rapport HTML : https://46-225-215-148.sslip.io/maquettes/vitrine-responsive-2026-05-11T21-XX-XX/

# Tag git
- `pre-M_OCRE_VITRINE_RESPONSIVE-20260511-212002` (rollback)
- `stable-2026-05-11-2120-ocre-vitrine-responsive` (post-success)

# Validation manuelle Philippe iPhone Safari réel

1. `ocre.immo/` → tenter de **glisser la page à droite** (gesture scroll horizontal) → ne doit RIEN faire (pas de scroll latéral).
2. Idem `ocre.immo/oi-agent`.
3. Tap "Commencer (gratuit)" → popup entièrement visible, sous-titre non tronqué.
4. Tap dans champ email → clavier ouvre → bouton "Recevoir mon lien" accessible.

# Hors scope respecté
- Design desktop strictement inchangé (anti-régression vérifié sur 1440×900).
- Sections individuelles (hero, features, footer) non modifiées : le diag a montré qu'aucune section précise débordait, le bug Safari iOS est structurel (overflow-x:hidden manquant sur html).
- Popup déjà traitée par M/45 (M_POPUP_LOGIN_RESPONSIVE_IPHONE) — celle-ci s'attaque à la cause amont.
- Aucun flow auth touché.
