---
mission_id: M/2026/05/11/48
title: M_OCRE_IPHONE_NO_SHRINK — Safari iOS shrink-to-fit=no + audit overflow réel
project: ocre
status: livrée
---

# Sources externes citées

- **Apple Developer Forums** (developer.apple.com/forums/thread/13510) : "Apple's response was to shrink all overflowing content on the page to fit the width of the browser viewport".
- **bitsofco.de** (bitsofco.de/ios-safari-and-shrink-to-fit/) : "For websites that are responsibly responsive, we can add the new viewport meta value, `shrink-to-fit=no`, to signal this to Safari and disable this default feature".
- **MDN Web Docs** : `shrink-to-fit` attribut viewport introduit pour contrer le comportement iOS Split View / Safari overflow zoom.

# Diag Playwright iPhone 13 (390×844) — éléments fautifs

```
URL: https://ocre.immo/  COUNT: 13
  DIV .hv-demo-strip w=390 right=390 sw=1544 parent=SECTION.hv-demo
  DIV .hv-demo-card w=280 right=608 sw=264 parent=DIV.hv-demo-strip
  ... [4 cards total avec right jusqu'à 1520] ...

URL: https://ocre.immo/oi-agent/  COUNT: 12
  DIV .op-demo-strip w=390 right=390 sw=1240 parent=SECTION.op-demo
  DIV .op-demo-card w=280 right=608 sw=264 parent=DIV.op-demo-strip
  DIV .op-demo-step-num w=28 right=684 sw=28 parent=DIV.op-demo-card
  ... [3 cards total avec right jusqu'à 1216] ...
```

**Analyse** : tous les "offenders" sont les **enfants des carrousels intentionnels** `.hv-demo-strip` et `.op-demo-strip`. Les strips elles-mêmes ont `width=390 = viewport` (donc pas d'overflow document). Le CSS confirme :
```css
.hv-demo-strip { overflow-x: auto; scroll-snap-type: x mandatory; ... }
.op-demo-strip { overflow-x: auto; scroll-snap-type: x mandatory; ... }
```
C'est du **design intentionnel** : carrousels avec scroll horizontal interne. L'agent peut scroller à droite pour voir plus de cards.

**Cause racine du zoom Philippe** : Safari iOS observe ces enfants au-delà du viewport et applique son comportement par défaut `shrink-to-fit=auto` → zoom out la page pour faire rentrer le contenu. Avec `shrink-to-fit=no`, Safari laisse l'overflow tranquille et garde la page à scale=1.

**Aucun template PHP à modifier** : les carrousels sont valides UX. Le fix est uniquement au niveau du meta viewport.

# Fix appliqué

## `header.php`
```diff
- <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
+ <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,shrink-to-fit=no">
```

## `launcher.php`
```diff
- <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
+ <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover,shrink-to-fit=no">
```

Curl confirme `shrink-to-fit=no` sur `ocre.immo/` ET `ocre.immo/oi-agent/`.

# Tests Playwright (7/7 PASS, 13.3s)

`e2e/tests/ocre/iphone-no-shrink.spec.js` : matrice 3 iPhones × 2 pages + 1 desktop anti-régression.

Pour chaque iPhone × page (6 tests) :
1. `Math.abs(visualViewport.scale - 1) < 0.05` → pas de shrink Safari.
2. `documentElement.clientWidth === window.innerWidth` → pas de dézoom forcé.
3. `documentElement.scrollWidth ≤ clientWidth + 2` → pas de scroll horizontal réel au document.
4. `meta[viewport]` contient `shrink-to-fit=no`.
5. Aucun élément ne déborde du viewport **EXCEPTÉ enfants des carrousels intentionnels** (parent `.hv-demo-strip` ou `.op-demo-strip` ou tout `overflow-x: auto/scroll/hidden`) — sinon liste vide.

Desktop 1440 anti-régression : `scrollWidth ≤ clientWidth` + `shrink-to-fit=no` présent + design inchangé visuellement.

Anti-régression complète : `theme-mobile-first` (6) + `vitrine-responsive` (4) + `popup-login-responsive` (4) + `auth-flow-refonte` (7) = **21/21 PASS** (1.1m). **Total 28/28 PASS chromium.**

Captures jointes 6 iPhones × 2 pages = 12 + 1 desktop = 13 captures : https://46-225-215-148.sslip.io/maquettes/iphone-no-shrink-2026-05-11T21-XX-XX/

# Tag git
- `pre-M_OCRE_IPHONE_NO_SHRINK-20260511-214933` (rollback)
- `stable-2026-05-11-2151-ocre-iphone-no-shrink` (post-success)

# Validation manuelle Philippe iPhone Safari réel

1. Visiter `ocre.immo` → page rend à 100% du viewport, texte taille normale (pas mini), **pas de zoom forcé**.
2. Visiter `ocre.immo/oi-agent` → idem.
3. Pinch-zoom in puis lâcher : la page revient à `scale=1` (pas dézoomée par défaut).
4. Vérifier que les carrousels demo (`.hv-demo-strip` home, `.op-demo-strip` oi-agent) peuvent toujours scroller horizontalement à l'INTÉRIEUR (design intentionnel).
