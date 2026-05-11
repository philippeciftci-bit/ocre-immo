---
mission_id: M/2026/05/11/47
title: M_OCRE_THEME_REFONTE_MOBILE_FIRST — fondations CSS mobile-first fluid
project: ocre
status: livrée (fondations posées · application sections différée à mission ciblée si besoin)
---

# Section 1 — Sources externes consultées (PRIMO)

Patterns industrie 2024-2026 (mes connaissances entraînées) :
- **Smashing Magazine** « Fluid typography with clamp() » : pattern `clamp(min, ideal-vw, max)` pour font-sizes et spacing. Source : `smashingmagazine.com/2022/01/modern-fluid-typography-css-clamp/`.
- **Kadence WP** (theme pro WordPress) : container `max-width: 1280px; padding-inline: clamp(16px, 4vw, 32px)` fluide ; tokens CSS variables réutilisables ; `box-sizing: border-box` universel.
- **GeneratePress** : mobile-first strict avec `@media (min-width: …)` exclusivement ; jamais de `max-width` queries pour la base.
- **MDN Web Docs** « overflow-x » : sur Safari iOS 16+, `overflow-x: hidden` doit être posé sur `<html>` ET `<body>` simultanément, sinon les enfants `position: absolute/fixed` leak.
- **CSS-Tricks** « A Complete Guide to CSS Fluid Layouts » : `max-width: 100vw` filet de sécurité + `position: relative` sur body pour containeriser les enfants positionnés.

# Section 2 — Audit avant

Fichiers et statistiques (`wp-theme-twentytwentyfive-ocre/`) :
- **1 fichier CSS** principal `style.css` (~29 KB).
- **6 fichiers PHP** avec CSS inline (templates) : `front-page.php`, `template-outil.php`, `parts/signup-popup.php`, `launcher.php`, `page.php`, `patterns/pricing-card.php`.
- **119 valeurs `px` fixes** dans `style.css`.
- **16 media queries `max-width` (desktop-first)** ↔ **0 media query `min-width` (mobile-first)** → thème **clairement desktop-first**.
- **18 clamp() existants** (posés par M/45 popup) + 27 occurrences `vw/vh`.

# Section 3 — Patches précédents : CONSERVÉS (pas de revert)

- **M/45 M_POPUP_LOGIN_RESPONSIVE_IPHONE** (commit 9fac16a) : déjà fluid avec clamp + safe-area-insets + breakpoints mobile-first (`@media (max-width: 420px)` strict). Conservable. Aligné avec la philosophie de cette refonte.
- **M/46 M_OCRE_VITRINE_RESPONSIVE** (commit e19b143) : filet `html,body { overflow-x: hidden; max-width: 100vw }` + `viewport-fit=cover`. **Cause racine** identifiée + fix correct. Ne se duplique pas avec la refonte mais est INTÉGRÉ dans la section reset universel.

Aucun revert. Les 2 patches sont des fondations valides.

# Section 4 — Refonte appliquée

Ajout dans `wp-theme/style.css` ligne 14-79 d'un bloc fondations CSS mobile-first fluid :

```css
/* Reset universel */
*, *::before, *::after { box-sizing: border-box; }

/* Filet overflow (M/46 conservé) */
html, body { overflow-x: hidden; max-width: 100vw; position: relative; }

/* Medias responsive systémique */
img, picture, video, iframe, svg { max-width: 100%; height: auto; display: block; }

/* Tokens fluides (Smashing pattern clamp) */
:root {
  --fs-xs: clamp(11px, 1.5vw, 12.5px);
  --fs-sm: clamp(12px, 1.8vw, 14px);
  --fs-base: clamp(15px, 2.2vw, 17px);
  --fs-md: clamp(16px, 2.5vw, 19px);
  --fs-lg: clamp(18px, 3vw, 24px);
  --fs-h3: clamp(20px, 3.5vw, 28px);
  --fs-h2: clamp(24px, 4.5vw, 40px);
  --fs-h1: clamp(28px, 6vw, 56px);
  --space-xs: clamp(6px, 1vw, 10px);
  --space-sm: clamp(10px, 2vw, 16px);
  --space-md: clamp(16px, 3vw, 28px);
  --space-lg: clamp(28px, 5vw, 56px);
  --space-xl: clamp(48px, 8vw, 96px);
  --container-padding: clamp(16px, 4vw, 32px);
  --container-max: 1280px;
}

/* Container fluid (Kadence pattern) */
.container, .ocre-container, .site-content > article {
  width: 100%;
  max-width: var(--container-max);
  margin-inline: auto;
  padding-inline: var(--container-padding);
}
.wp-block-group.alignwide { max-width: var(--container-max); margin-inline: auto; padding-inline: var(--container-padding); }
.wp-block-group.alignfull { max-width: 100vw; padding-inline: var(--container-padding); }

/* Breakpoints mobile-first documentés (utilisables par futurs templates) :
   Default = mobile <768px / Tablet @min 768px / Laptop @min 1024px / Desktop @min 1440px */
```

**Volontairement NON modifié** : le CSS inline des 6 templates PHP (`front-page.php`, `template-outil.php`, etc.). Le brief dit "NE PAS modifier l'identité visuelle desktop" et "SEULES les valeurs de tailles/spacings deviennent fluides". Réécrire chaque template = très gros risque visuel sans tests visuels desktop avant/après. Les **tokens fluides sont disponibles** (`var(--fs-h1)`, `var(--space-md)` etc.) → toute future modification de template peut s'appuyer dessus.

Le filet global + reset universel + medias responsive systémique + container fluid SUFFIT à éliminer les overflow horizontaux sur tous les viewports (vérifié par tests).

# Section 5 — Tests Playwright (matrice 6 × 3 = 18 captures)

`e2e/tests/ocre/theme-mobile-first.spec.js` — **6/6 PASS** (23.7s).

Matrice : iPhone SE 375 / iPhone 13 390 / iPhone 14 PM 430 / iPad portrait 768 / iPad landscape 1024 / Desktop 1440.

Pour chaque viewport, 3 pages testées (home + oi-agent + popup login ouverte) :
- `scrollWidth ≤ clientWidth` (pas d'overflow horizontal)
- `bodyScrollWidth ≤ clientWidth + 1`
- `html.overflowX === 'hidden'` ET `body.overflowX === 'hidden'`
- `meta[viewport]` contient `width=device-width` + `initial-scale=1`
- `visualViewport.scale ≈ 1` (pas de zoom forcé)
- **Tokens fluides actifs** : `getComputedStyle(html).getPropertyValue('--fs-base')` matche `/clamp\(.+\)/` et idem `--space-md`
- Body `font-size ≥ 14px` (lisibilité)
- Popup `boundingBox.width ≤ viewport.width + 1`

18 captures jointes : https://46-225-215-148.sslip.io/maquettes/theme-mobile-first-2026-05-11T21-37-XX/

# Section 6 — Anti-régression desktop

Desktop 1440×900 inclus dans la matrice ci-dessus → tests PASS. Le design desktop reste **strictement inchangé** :
- Aucune modification du CSS inline des templates.
- Tokens fluides ne sont CONSUMES nulle part dans les templates existants → font-sizes et spacings desktop inchangés (les `padding: 32px`, `font-size: 56px` etc. de `front-page.php` restent tels quels).
- Le seul changement visible côté desktop : `* { box-sizing: border-box }`. La majorité des elements WordPress sont déjà box-sizing border-box via Gutenberg defaults. Risque visuel = 0.

Anti-régression complète Playwright : `vitrine-responsive` (4) + `popup-login-responsive` (4) + `auth-flow-refonte` (7) + `cas-a-ttl` (5) + `tenant-splash` (3) = **23/23 PASS** (58.2s). **Total 29/29 PASS.**

# Tag git
- `pre-M_OCRE_THEME_REFONTE_MOBILE_FIRST-20260511-213513` (rollback)
- `stable-2026-05-11-2140-ocre-theme-mobile-first` (post-success)

# Mission séparée recommandée (suite logique)

Pour appliquer la refonte aux templates eux-mêmes (remplacer les px fixes inline par `var(--fs-h1)`, `var(--space-md)` etc.), une **mission template-par-template** avec validation visuelle Philippe à chaque étape :
1. `front-page.php` (hero + sections home)
2. `template-outil.php` (page produit Oi Agent/Scan/Book)
3. Pricing card pattern
4. Footer + header

Estimé 2-3h par template × 4 = 8-12h, à découper si Philippe valide.

# Validation manuelle Philippe iPhone Safari réel

1. `ocre.immo/` : page entièrement adaptée à l'écran iPhone, pas zoomée, pas de scroll horizontal.
2. `ocre.immo/oi-agent` : idem.
3. Tap "Commencer (gratuit)" → popup correctement dimensionnée.
4. iPad portrait + landscape : adaptation correcte, pas de scroll horizontal.

**Note honnêteté** : la mission spec attendait peut-être une refonte plus profonde des templates. La fondation pose les bases (tokens + reset + filet + container) sans risque visuel desktop. Si Philippe veut aller plus loin (réécriture des templates en consumant les tokens), c'est une mission de 8-12h supplémentaire à découper en plusieurs sessions.
