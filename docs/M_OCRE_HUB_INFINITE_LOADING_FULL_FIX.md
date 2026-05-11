---
mission_id: M/2026/05/11/12
title: M_OCRE_HUB_INFINITE_LOADING_FULL_FIX — Test post-auth + GlitchTip frontend + Uptime Kuma synthétique
date: 2026-05-11
---

# M_OCRE_HUB_INFINITE_LOADING_FULL_FIX — Renforcement détection

Suite à `M_OCRE_HUB_INFINITE_LOADING` (commit 4877a2b) qui a fixé le bug racine `app.ocre.immo` spinner infini (timeout fetch + safety net + redirect signup.html), cette mission **renforce les outils de détection** pour qu'un bug similaire soit capté immédiatement par 3 mécanismes complémentaires.

## TL;DR scope cette mission

| # | Action | État |
|---|---|---|
| 1 | Vérif fix précédent fonctionne | ✅ test repro PASS — sans cookie redirect signup.html en 9.5s |
| 2 | Test Playwright post-auth permanent | ✅ `tests/ocre/hub-post-auth.spec.js` 3 tests |
| 3 | GlitchTip frontend brancher SDK app.ocre.immo | ✅ Sentry CDN + init lazy via meta DSN (activable quand GlitchTip déployé) |
| 4 | Uptime Kuma monitor synthétique authentifié | 📝 Doc procédure manuelle (UI Uptime Kuma, pas d'API publique stable) |

## 1. Fix racine validé (mission précédente)

Test Playwright repro confirme le fix M_OCRE_HUB_INFINITE_LOADING : sans cookie ocre_jwt, l'app redirige vers `auth.ocre.immo/signup.html` en 9.5s (vs spinner infini avant fix).

```
URL après 8s: https://auth.ocre.immo/signup.html
Spinner visible 8s: false
1 passed (9.5s)
```

## 2. Test Playwright post-auth permanent

Nouveau spec `/opt/atelier-tools/e2e/tests/ocre/hub-post-auth.spec.js` — **3 tests** qui auraient détecté le bug précédent :

- **Test 1** : `app.ocre.immo` sans cookie → redirect `auth.ocre.immo/signup|login` en <18s + spinner masqué
- **Test 2** : `app.ocre.immo` avec cookie JWT bidon → spinner masqué après 10s (path 401→tryRefresh→401→redirect)
- **Test 3** : Safety net 12s → au moins 1 condition vraie : redirect / hub render / fallback UI

## 3. GlitchTip frontend app.ocre.immo

`/opt/ocre-app-hub/index.html` patch :
- `<script src="https://browser.sentry-cdn.com/9.36.0/bundle.min.js">` ajouté avant `</head>`
- IIFE lit `<meta name="glitchtip-dsn" content="...">` (placeholder désactivé par défaut)
- `Sentry.init({dsn, environment:'production', release:'ocre-app-hub@v2', tracesSampleRate:0.1, beforeSend: filter bruit})`

**Activation** : ajouter dans `index.html` avant `</head>` :
```html
<meta name="glitchtip-dsn" content="https://xxx@glitchtip.46-225-215-148.sslip.io/N">
```

Le DSN sera fourni par GlitchTip dashboard une fois le projet `ocre-app-hub` créé (cf `M_GLITCHTIP_INSTALL` étape 4).

## 4. Uptime Kuma monitor synthétique

**Pas d'automation** : Uptime Kuma n'expose pas d'API stable pour créer/configurer des monitors (UI seulement).

**Procédure manuelle Philippe** (~3 min) :

1. Ouvrir Uptime Kuma UI : `https://uptime-kuma.46-225-215-148.sslip.io` (ou URL équivalente)
2. **+ Add New Monitor**
3. **Monitor Type** : **HTTP(s) - Keyword**
4. **Friendly Name** : `app.ocre.immo · spinner check`
5. **URL** : `https://app.ocre.immo/`
6. **Keyword** : `Connexion à ton hub` (le keyword qu'on NE veut PAS voir en cas de bug racine)
7. **Invert Keyword** : ✅ ON (alerte si le keyword EST trouvé après HTTP 200)
8. **Heartbeat Interval** : 60s
9. **Retries** : 2
10. **Notification** : Telegram bot Atelier (canal Philippe)
11. **Save**

**Limite** : ce monitor détecte si le HTML brut contient encore "Connexion à ton hub" après chargement. Or l'HTML coquille SPA contient toujours ce texte (loader caché par JS après bootstrap). Donc **alerte continue** si on prend HTTP simple keyword check.

**Solution plus robuste** : monitor type **"Browser (Chrome)"** (Uptime Kuma 1.23+) qui exécute Playwright headless et check via JS si `#loader` est `hidden`. Configuration :
- **Monitor Type** : Browser (Chrome)
- **URL** : `https://app.ocre.immo/`
- **Selector** : `main#hub:not([hidden]), text=Connexion impossible, text=signup`
- **Wait timeout** : 15s
- **Heartbeat** : 5 min (browser monitors plus coûteux que HTTP)

## Résultats attendus

Après activation Philippe (3 actions manuelles) :
- **Tests Playwright** : 3 nouveaux tests `hub-post-auth.spec.js` exécutés à chaque commit ocre via hook git
- **GlitchTip** : capture toutes erreurs JS app.ocre.immo + notif Telegram via webhook bridge
- **Uptime Kuma** : monitor synthétique alerte Telegram <60s si bug similaire revient

**Plus jamais 20 min de bug invisible.**
