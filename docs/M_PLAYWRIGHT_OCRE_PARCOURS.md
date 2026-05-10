---
mission_id: M/2026/05/11/2
title: M_PLAYWRIGHT_OCRE_PARCOURS — Suite tests E2E + 3 étapes Philippe activation
date: 2026-05-10
---

# M_PLAYWRIGHT_OCRE_PARCOURS — Tests E2E Playwright Ocre

## TL;DR

**12 fichiers de tests + helpers + runner livrés** dans `/opt/atelier-tools/e2e/`.
Couverture : signup vitrine 6 outils + login existant + auth.ocre.immo + launcher PWA + erreurs validation + responsive iPhone 13.

**3 étapes Philippe** (~5 min) pour activer + premier run.

## Pourquoi pas premier run par moi

**Prérequis bloquants absents** :
- ❌ Playwright npm package pas installé (`/opt/atelier-tools/e2e/node_modules` vide)
- ❌ Chromium pas installé (déjà documenté missions précédentes — `which chromium` → not found, `apt install chromium` → no candidate, snap requis)
- ❌ MariaDB CLI accès root pour helper DB (à valider)

`npm install + npx playwright install chromium` = 700+ MB téléchargement + déploiement Chromium = action infra significative à valider Philippe.

## 12 fichiers livrés `/opt/atelier-tools/e2e/`

```
e2e/
├── package.json                    (Playwright 1.45+)
├── playwright.config.js            (chromium + iphone13 projects + html reporter + trace/video on failure)
├── run-ocre-suite.sh               (runner CLI + report HTML + notif Telegram OK/échec)
└── tests/ocre/
    ├── helpers/common.js           (getMagicLinkFromDb + cleanupTestUser + ensureExistingUser + genTestEmail + collectConsoleErrors)
    ├── signup-vitrine-oi-agent.spec.js     (parcours complet signup → form → magic link → app)
    ├── signup-vitrine-oi-scan.spec.js      (variante slug)
    ├── signup-vitrine-oi-book.spec.js
    ├── signup-vitrine-oi-recherche.spec.js
    ├── signup-vitrine-oi-capture.spec.js
    ├── signup-vitrine-oi-estimer.spec.js
    ├── login-existant.spec.js              (email reconnu → entrée directe sans accordéon : popup vitrine + auth.ocre.immo)
    ├── auth-domain-signup.spec.js          (parcours alternatif depuis auth.ocre.immo + check ZÉRO SSO + max redirects)
    ├── launcher-pwa.spec.js                (sans cookie → redirect home + avec cookie simulé → grid 6 tuiles)
    ├── erreurs-validation.spec.js          (email invalide blur + CGU décochée bouton disabled + token magic-link inexistant + tel invalide)
    └── responsive-iphone13.spec.js         (vitrine grid 2 cols + CTA tactile 44px + popup bottom-sheet + maquette superadmin V3 hamburger)
```

## 3 étapes Philippe

### 1. Installer Playwright + Chromium
```bash
cd /opt/atelier-tools/e2e
npm install
npx playwright install chromium
npx playwright install-deps chromium
# Vérifier
npx playwright --version
```

### 2. Premier run
```bash
/opt/atelier-tools/e2e/run-ocre-suite.sh
# Rapport HTML : https://46-225-215-148.sslip.io/maquettes/e2e-reports/<TIMESTAMP>/
```

### 3. Hook git post-commit (optionnel, déclenche tests auto sur commit ocre)
```bash
cat > /root/workspace/atelier-philippe/.git/hooks/post-commit <<'EOF'
#!/bin/bash
if git log -1 --pretty=%B | grep -qiE "(ocre|oi-agent|magic-link|launcher|auth)"; then
  nohup /opt/atelier-tools/e2e/run-ocre-suite.sh > /tmp/e2e-last.log 2>&1 &
fi
EOF
chmod +x /root/workspace/atelier-philippe/.git/hooks/post-commit
```

## Tests couverts (résumé)

| Fichier | Tests | Couverture |
|---|---|---|
| `signup-vitrine-oi-{6 outils}.spec.js` | 6 × 1 | Parcours complet nouveau user vitrine → tuile → popup → form 5 champs → magic link DB → app |
| `login-existant.spec.js` | 2 | Email reconnu via popup vitrine + via auth.ocre.immo → redirect direct (pas accordéon résiduel) |
| `auth-domain-signup.spec.js` | 2 | Signup direct auth.ocre.immo + check ZÉRO bouton SSO + max 5 redirects + login simplifié 1 champ |
| `launcher-pwa.spec.js` | 2 | Sans cookie → redirect home + avec JWT simulé → grid 6 tuiles + tuiles "en cours" grisées |
| `erreurs-validation.spec.js` | 4 | Email format invalide blur + CGU décochée bouton disabled + token magic-link inexistant 4xx + tel invalide pas vert |
| `responsive-iphone13.spec.js` | 4 | Vitrine grid no-overflow + CTA tactile 44px + popup bottom-sheet + maquette superadmin V3 hamburger drawer |

**Total : ~20 tests automatisés** couvrant les chemins critiques.

## Helpers communs

`tests/ocre/helpers/common.js` :
- `getMagicLinkFromDb(email)` → query MariaDB pour récupérer le dernier token + URL validate
- `cleanupTestUser(email)` → DELETE auth_users + auth_magic_tokens + auth_user_modules
- `ensureExistingUser(email, firstName)` → INSERT IGNORE pour pré-condition tests login
- `genTestEmail(suffix)` → email unique par run avec timestamp
- `collectConsoleErrors(page)` → capture errors JS pour assert zéro pendant le test

## Runner script

`run-ocre-suite.sh` :
- Vérif prérequis (`node_modules/@playwright` exists)
- `npx playwright test tests/ocre/ --reporter=html,line`
- Copie rapport HTML vers `/opt/atelier-tools/maquettes/e2e-reports/<TS>/`
- Notif Telegram `--project ocre --priority info` si OK / `warning` si échec avec count fails
- URL rapport accessible : `https://46-225-215-148.sslip.io/maquettes/e2e-reports/<TS>/`

## Workflow recommandé Philippe

1. Modif code Ocre + commit + push
2. Hook post-commit lance `run-ocre-suite.sh` en background si commit message contient ocre/oi-agent/magic-link/launcher/auth
3. Notif Telegram OK ou échec avec URL rapport
4. Si échec → ouvrir rapport HTML → trace viewer Playwright pour debug

## Limites documentées

- Phase 12 "premier run" REPORTÉE car npm install + Chromium install non exécutés (action infra Philippe)
- Phase 8 smoke tests apps Oi Agent (Section III + ReglagesPage + upload photo) NON ÉCRITE car nécessite session authentifiée Cookie JWT réel + dataset test → reportable mission séparée M_PLAYWRIGHT_OCRE_APP_SMOKE
- DB helper `getMagicLinkFromDb` requiert accès `mariadb ocre_meta` CLI sans password (à valider socket/env)
- Tests exécutent contre prod `https://ocre.immo` — alternative future : env staging dédié
