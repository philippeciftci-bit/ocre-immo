---
mission_id: M/2026/05/12/1
title: Fix responsive hero ocre.immo (100vh → 100svh + max-height 820)
project: ocre
status: livrée
---

# Cause racine (diag M/2026/05/11/49 validé Philippe)

`.hv-hero { height: 100vh; min-height: 600px }` (front-page.php) et `.op-hero` (template-outil.php) noyaient le contenu central sur iPad portrait (1024px) et incluaient la barre URL Safari mobile (le hero faisait 844px iPhone 13 = quasi tout l'écran).

# Diff appliqué (strict)

## `wp-theme-twentytwentyfive-ocre/front-page.php` (lignes 44-50)
```diff
 .hv-hero {
-  height: 100vh;
-  min-height: 600px;
+  height: 100svh;
+  min-height: 480px;
+  max-height: 820px;
+  padding-block: clamp(40px, 6vh, 80px);
   position: relative;
```

## `wp-theme-twentytwentyfive-ocre/template-outil.php` (ligne 79-80)
```diff
 .op-hero {
-  height: 100vh; min-height: 600px; position: relative;
+  height: 100svh; min-height: 480px; max-height: 820px; padding-block: clamp(40px, 6vh, 80px); position: relative;
```

**`git diff --stat`** : 2 files changed, 5 insertions(+), 3 deletions(-). **ZÉRO autre touch.**

Aucun duplicate `.hv-hero` / `.op-hero` ailleurs dans le thème (vérifié par grep — pas d'override media-query à ajuster).

# Mesures Playwright avant/après

`e2e/tests/ocre/fix-hero-responsive.spec.js` — **3/3 PASS** (6.3s).

| Viewport | hero height **avant** | hero height **après** | CTA "Voir les outils" rect.top | Above-the-fold ? |
|---|---|---|---|---|
| iPad portrait 820×1180 | 1180px (= 100vh, sortait du viewport) | **820px** (= max-height) | < 820 | ✓ |
| iPhone 13 390×844 | 844px (= 100vh, sortait avec barre URL) | **820px** | 476 | ✓ |
| Desktop 1440×900 | 900px (= 100vh) | **820px** | < 820 | ✓ |

Tous les hero plafonnés à 820px → contenu interne respiré (padding-block clamp 40-80px) → CTA "Voir les outils" entièrement visible above-the-fold sur les 3 viewports.

# Anti-régression

`iphone-no-shrink.spec.js` (7) + `theme-mobile-first.spec.js` (6) + `vitrine-responsive.spec.js` (4) = **17/17 PASS** (46.7s). **Total 20/20 PASS chromium.**

# Branche + commits + tags

- Branche : `feature/fix-hero-responsive-m-2026-05-12-1` (push origin OK).
- Commit : `eba5546` `fix(ocre) [M/2026/05/12/1]: hero 100svh + max-height 820px pour iPad portrait`.
- Fast-forward merge `main` → push origin main OK.
- Tag rollback : `pre-fix-hero-responsive-20260511-221500`.
- Workflow GitHub Actions : repo a un `safe-commit` hook → commit + push direct main. Pas de pipeline GH Actions séparé pour le thème WordPress (sync prod via copie directe `/var/www/ocre-wp/`).

# Déploiement prod

- Copie `front-page.php` + `template-outil.php` → `/var/www/ocre-wp/wp-content/themes/twentytwentyfive-ocre/` OK.
- HTTP 200 confirmé `curl -sI https://ocre.immo/`.
- CSS servie confirmé : `curl -s https://ocre.immo/ | grep "max-height: 820px"` → match.

# Rollback prêt

```bash
cd /root/workspace/ocre-immo && git revert eba5546 --no-edit && git push origin main
# puis cp prod inverse
git checkout pre-fix-hero-responsive-20260511-221500 -- wp-theme-twentytwentyfive-ocre/{front-page,template-outil}.php
cp wp-theme-twentytwentyfive-ocre/{front-page,template-outil}.php /var/www/ocre-wp/wp-content/themes/twentytwentyfive-ocre/
```

# Screenshots
https://46-225-215-148.sslip.io/maquettes/fix-hero-responsive-2026-05-11T22-XX-XX/
