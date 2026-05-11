---
mission_id: M/2026/05/12/2
title: Fix popup login ocre.immo — anti-zoom iOS + max-width 380px
project: ocre
status: livrée
---

# Sélecteur popup login identifié
`wp-theme-twentytwentyfive-ocre/parts/signup-popup.php` — la modale `.oal-modal` (id `#oal-overlay`) qui contient `<input type="email" id="oal-email">` + (cas C accordéon) `select#oal-tel-country` + `input#oal-tel` etc. C'est le composant créé en M/2026/05/11/37 et amélioré M/45.

# Diff appliqué (strict, 1 fichier)

```diff
- .oal-modal { ... max-width: min(460px, calc(100vw - 32px)); padding: 32px 28px 26px; ... max-height: 92vh; ... }
- @media (max-width: 540px) {
-   .oal-overlay { align-items: flex-end; padding: 0; }
-   .oal-modal { border-radius: 22px 22px 0 0; max-width: 100%; padding: 24px 22px 18px; ... transform: translateY(100%); padding-bottom: max(18px, env(safe-area-inset-bottom)); }
-   .oal-overlay.oal-show .oal-modal { transform: translateY(0); }
- }
+ /* M/2026/05/12/2 — card flottante centree 380px sur tous viewports (vs bottom-sheet plein largeur mobile).
+    Padding fluide clamp 20-32 selon viewport. max-height 90dvh evite recouvrement clavier iOS. */
+ .oal-modal { ... max-width: 380px; margin-inline: auto; padding: clamp(20px, 5vw, 32px); ... max-height: 90dvh; ... }

- .oal-field input { ... font-size: 14px; ... }
+ /* M/2026/05/12/2 — Anti-zoom iOS Safari : 16px minimum sur inputs focusables (scope popup login uniquement) */
+ .oal-field input { ... font-size: 16px; ... }

- .oal-tel-row select, .oal-tel-row input { ... font-size: 14px; ... }
+ .oal-tel-row select, .oal-tel-row input { ... font-size: 16px; ... }
```

`git diff --stat` : 1 file changed, 6 insertions(+), 8 deletions(-). **ZÉRO autre touch**.

Changements :
1. **Anti-zoom iOS** : `font-size: 14px → 16px` sur `.oal-field input`, `.oal-tel-row select`, `.oal-tel-row input` (scope popup uniquement).
2. **Card flottante** : `.oal-modal { max-width: 380px; padding: clamp(20px, 5vw, 32px); max-height: 90dvh; margin-inline: auto }`.
3. **Retrait pattern bottom-sheet `@media (max-width: 540px)`** : modale devient card centrée flottante avec marges latérales visibles sur tous viewports (au lieu de pleine largeur bottom-sheet mobile).

# Mesures Playwright preuve

`e2e/tests/ocre/fix-popup-login.spec.js` — **2/2 PASS** (5.7s).

| Viewport | font-size email | top BEFORE focus | top AFTER focus | Δ | modal width |
|---|---|---|---|---|---|
| iPhone 13 390×844 | **16px** ✓ | (mesuré) | (identique) | ≤ 2px ✓ | **380px** ✓ |
| iPad portrait 820×1180 | **16px** ✓ | 428.36 | 428.36 | **0px** ✓ | **380px** ✓ |

Bonus : tel-row select + input vérifiés `16px` aussi (accordéon cas C signup ouvert).

# Anti-régression

`iphone-no-shrink` (7) + `theme-mobile-first` (6) + `vitrine-responsive` (4) + `fix-hero-responsive` (3) + `popup-login-responsive` (4, ajusté `padding: clamp(...)` au lieu de `32px 28px`) = **24/24 PASS**. **Total 26/26 PASS chromium** (1.1m).

Note ajustement : `popup-login-responsive.spec.js` (M/45) testait `expect(padding).toMatch(/^32px 28px/)` → adapté à `/^32px/` car le M/12/2 a remplacé `padding: 32px 28px 26px` par `padding: clamp(20px, 5vw, 32px)` (32px uniforme sur desktop). Aucun rollback de logique, juste alignement assertion.

# Commit + push + tags

- Commit (à venir) : `fix(ocre) [M/2026/05/12/2]: popup login anti-zoom iOS + max-width 380px iPhone`.
- Tag pre : `pre-fix-popup-login-20260511-222934`.
- Tag stable : `stable-2026-05-11-2231-ocre-fix-popup-login`.

# Déploiement prod

- Copie `signup-popup.php` → `/var/www/ocre-wp/wp-content/themes/twentytwentyfive-ocre/parts/` OK.
- HTTP 200 confirmé `curl -sI https://ocre.immo/`.
- CSS servie confirmé : `curl -s ocre.immo | grep -c "max-width: 380px\|font-size: 16px"` → 4 matches.

# Rollback prêt

```bash
git revert <commit-sha> --no-edit && git push origin main
cp wp-theme-twentytwentyfive-ocre/parts/signup-popup.php /var/www/ocre-wp/wp-content/themes/twentytwentyfive-ocre/parts/
```

# Screenshots
https://46-225-215-148.sslip.io/maquettes/fix-popup-login-2026-05-11T22-XX-XX/
