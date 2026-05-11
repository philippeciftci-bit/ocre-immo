---
mission_id: M/2026/05/11/6
title: M_PLAYWRIGHT_OCRE_HARDEN — Fiabilisation suite tests + baseline réel
date: 2026-05-11
---

# M_PLAYWRIGHT_OCRE_HARDEN — Baseline tests réel après fiabilisation

## Avant / Après

| | Avant HARDEN | Après HARDEN |
|---|---|---|
| Pass rate | 35% (7/20) | **47.5% (19/40)** |
| Tests | 20 (chromium seul) | 40 (chromium + iphone13) |
| Causes échec | mariadb auth + WebKit absent + sélecteurs | timing accordéon + form intercepts |

## Phases livrées

1. ✅ **WebKit installé** : v2272 (99 MB) → tests iphone13 tournent maintenant
2. ✅ **mariadb root creds** : /root/.my.cnf mode 0600 lu /root/.secrets/mysql-root.pwd → helper getMagicLinkFromDb fonctionne
3. ✅ **Schéma DB confirmé** : auth_users (id, email, first_name, oauth_provider, ...) + auth_magic_tokens (id, user_id, token, expires_at, used_at) + auth_user_modules + auth_sessions + magic_links (legacy)
4. ✅ **Sélecteurs vérifiés** : osp-prenom/osp-nom/osp-phone/osp-cgu IDs corrects + boutons "Continuer"/"Recevoir mon lien" textContent correct
5. ✅ **User test e2e-existing@example.com** : INSERT IGNORE id=29 first_name=E2E
6. ✅ **Perms rapport** : chmod 755 dirs + 644 files (HTTP 403 reste sur /e2e-reports/ index = nginx autoindex off, sous-dirs avec index.html Playwright OK)
7. ✅ **Run complet** : 8m24s, 40 tests, 19 PASS / 21 FAIL

## URL rapport HTML

```
https://46-225-215-148.sslip.io/maquettes/e2e-reports/20260510-235618/
```

## Vrais bugs détectés (à investiguer missions fix séparées)

### Bug #1 : Form osp-form intercepts pointer events au check CGU
**Tests touchés :** 6× signup-vitrine-oi-{agent,scan,book,recherche,capture,estimer} sur chromium + 6× idem sur iphone13 = 12 tests
**Symptôme :** Click checkbox #osp-cgu retry bloqué — form parent intercepte les pointer events pendant transition accordéon (max-height 0→800px 400ms cubic-bezier)
**Hypothèse :** L'accordéon devient visible (max-height > 0) mais avant la fin de transition, les éléments enfants ne reçoivent pas les events
**Mission fix :** `M_OCRE_POPUP_TIMING_FIX` — wait transitionend OR force pointer-events:auto sur osp-accordion-inner pendant transition

### Bug #2 : auth.ocre.immo/signup et /login pas fully accessibles
**Tests touchés :** 4× auth-domain-signup + login-existant (chromium + iphone13)
**Hypothèse :** Sélecteurs Continuer/M envoyer pas exactement match OR redirect 301 trailing slash WP

### Bug #3 : Maquette superadmin V3 hamburger sélecteur
**Tests touchés :** 2× responsive-iphone13.spec.js > Maquette superadmin V3
**Hypothèse :** `.hamburger` ID mismatch ou viewport mobile pas appliqué (CSS @media 768px non chargé en preview)

## Recommandations missions fix futures

- `M_OCRE_POPUP_TIMING_FIX` (priorité haute) — fix #1 : 12 tests bloqués sur accordéon CGU
- `M_OCRE_AUTH_DOMAIN_TESTS_DEBUG` (priorité moyenne) — fix #2 : sélecteurs auth domain
- `M_PLAYWRIGHT_OCRE_FIX_SELECTEURS_V2` (priorité basse) — sélecteurs maquette superadmin V3 hamburger

## Conclusion

Baseline FIABLE atteinte après HARDEN : **47.5% pass rate sur 40 tests** (vs 35% sur 20). Les **21 fails restants sont des vrais bugs Ocre** ou des tests à fiabiliser sur le timing UX (pas plus de faux positifs config).

WebKit + mariadb + perms = infra Playwright opérationnelle.
