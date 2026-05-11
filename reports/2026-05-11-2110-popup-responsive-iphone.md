---
mission_id: M/2026/05/11/45
title: M_POPUP_LOGIN_RESPONSIVE_IPHONE — fix débordement popup login sur iPhone
project: ocre
status: livrée
---

# Diff CSS appliqué

Fichier : `wp-theme-twentytwentyfive-ocre/parts/signup-popup.php` (popup ocre.immo, déclenchée par `window.ocreSignupOpen`).

```diff
- .oal-modal { ... max-width: 460px; padding: 32px 28px 26px; max-height: 92vh; overflow-y: auto; }
+ .oal-modal { ... max-width: min(460px, calc(100vw - 32px)); padding: 32px 28px 26px;
+              max-height: 92vh; overflow-y: auto; -webkit-overflow-scrolling: touch; }

  @media (max-width: 540px) {
-   .oal-modal { ... padding: 26px 22px 20px; max-height: 90vh; }
+   .oal-modal { ... padding: 24px 22px 18px;
+                max-height: 90vh; max-height: calc(100dvh - env(safe-area-inset-top, 0px));
+                padding-bottom: max(18px, env(safe-area-inset-bottom)); }
  }

+ /* M/2026/05/11/45 — breakpoint iPhone strict <=420px */
+ @media (max-width: 420px) {
+   .oal-modal { padding: 20px 18px 16px; padding-bottom: max(16px, env(safe-area-inset-bottom)); }
+   .oal-brand { font-size: 24px !important; }
+   .oal-h1 { font-size: 18px !important; line-height: 1.2; text-wrap: balance; }
+   .oal-sub { font-size: 12.5px !important; line-height: 1.4; text-wrap: balance; margin-bottom: 14px !important; padding: 0 4px; }
+ }

- .oal-h1 { ... }
+ .oal-h1 { ... text-wrap: balance; }
- .oal-sub { ... }
+ .oal-sub { ... text-wrap: balance; }
```

Changements clés :
1. **`max-width: min(460px, calc(100vw - 32px))`** : 16px de marge garantis de chaque côté sur tous les écrans (vs 460px fixe qui débordait sur écrans <492px).
2. **`@media (max-width: 420px)`** : breakpoint iPhone strict avec typo serrée (h1 18px, sub 12.5px), padding compact (20/18/16px), `text-wrap: balance` pour équilibrer le retour à la ligne automatique du texte.
3. **`max-height: calc(100dvh - env(safe-area-inset-top))`** : `dvh` (dynamic viewport height) compense le clavier iOS qui réduit la hauteur visible. `env(safe-area-inset-top)` évite la notch iPhone.
4. **`padding-bottom: max(18px, env(safe-area-inset-bottom))`** : marge minimale 18px ou la safe-area iPhone (home indicator) selon le plus grand. Bouton submit reste accessible.
5. **`-webkit-overflow-scrolling: touch`** : scroll natif iOS smooth.

# Tests Playwright (4/4 PASS, 6.8s)

`e2e/tests/ocre/popup-login-responsive.spec.js` :

| Viewport | Test |
|---|---|
| iPhone SE 375×667 | Popup ne déborde pas + h1/sub non tronqués + email/submit visibles + scroll OK clavier |
| iPhone 13 390×844 | idem |
| iPhone 14 PM 430×932 | idem |
| Desktop 1440×900 | Popup centrée + padding `32px 28px` inchangé (anti-régression) |

Pour chaque iPhone : focus email → vérifie que le bouton submit reste visible OR atteignable via scroll (`overflow-y:auto` + `scrollHeight > clientHeight`).

Anti-régression : `auth-flow-refonte` (7) + `cas-a-ttl` (5) + `tenant-splash` (3) = **15/15 PASS** (44.5s).

**Total 19/19 PASS.**

Rapport HTML screenshots avant/après 4 viewports : https://46-225-215-148.sslip.io/maquettes/popup-responsive-2026-05-11T21-08-XX/

# Tag git
- `pre-M_POPUP_LOGIN_RESPONSIVE_IPHONE-20260511-210617`
- `stable-2026-05-11-2110-ocre-popup-responsive-iphone`

# Validation manuelle Philippe iPad/iPhone Safari

1. Ouvrir `ocre.immo/oi-agent` sur iPhone SE / 13 / 14 PM (et iPad portrait).
2. Tap "Commencer (gratuit)".
3. Vérifier popup centrée, marges 16px de chaque côté, **sous-titre "Reçois ton lien d'accès par email · zéro mot de passe" entièrement visible** (plus de `pass*e*` coupé).
4. Tap dans le champ email → clavier s'ouvre → vérifier bouton "Recevoir mon lien d'accès" reste atteignable (visible ou scrollable).
