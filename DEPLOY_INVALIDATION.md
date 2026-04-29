# Auto-invalidation du cache — Ocre Immo

Mécanisme garantissant que toute version déployée est visible chez l'agent en moins
de 30 secondes après ouverture/refresh, **sans aucune manipulation utilisateur**.

Référence : M/2026/04/29/47 (renforcement) + M/2026/04/29/27 (mise en place initiale).

## Les 4 couches actives

### Couche 1 — Headers HTTP no-cache (nginx)

Configuration : `/etc/nginx/sites-enabled/ocre-app.conf`.

3 emplacements `location =` exact-match (priorité sur les regex) avec `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` + `Pragma: no-cache` + `Expires: 0` :

- `/index.html`
- `/sw.js`
- `/v20-bridge.js`

Test : `curl -I https://app.ocre.immo/index.html | grep cache-control` → doit retourner `no-store, no-cache, must-revalidate, max-age=0`.

### Couche 2 — Service Worker en mode killswitch

Fichier : `sw.js`.

Comportement :

- `install` : `caches.delete()` sur toutes les keys + `self.skipWaiting()` immédiat
- `activate` : purge totale `caches.delete()` + `registration.unregister()` + `clients.matchAll().forEach(c => c.navigate(c.url))` (force tous les onglets ouverts à reload)
- `fetch` : NetworkOnly pur — aucune mise en cache (`event.respondWith(fetch(event.request).catch(...))`)

Bumper `SW_VERSION` à chaque déploiement déclenche un nouveau cycle install→activate.

### Couche 3 — Version-check synchrone au boot

Script inline dans `<head>` de `index.html` (avant tout autre script).

Logique :

1. Lit `APP_VERSION` (token `__BUILD_VERSION__` remplacé au deploy par `<SHA>-<timestamp>`)
2. Compare avec `localStorage.ocre_app_version`
3. Si différent : `caches.delete()` + `serviceWorker.unregister()` + `localStorage.setItem` + `location.reload(true)`
4. Si pas stocké : enregistre la version courante (premier accès)

### Couche 4 — registration.update() forcé + check périodique

Sur le même script inline, étend la couche 3 avec :

- Au `DOMContentLoaded` : `navigator.serviceWorker.getRegistration().update()` — force Safari iOS à re-fetch `sw.js` malgré son cache de page interne
- Endpoint `/api/version.php` retourne le `BUILD_VERSION` serveur (texte brut, headers no-cache)
- Fetch `/api/version.php?_=<ts>` au load + `setInterval` 30s + `visibilitychange` quand l'app revient au premier plan
- Si `serverVersion !== APP_VERSION` : déclenche `purgeAndReload()` (couche 3)

C'est cette couche qui rattrape les cas où le SW iOS Safari refuserait de se mettre à jour spontanément.

## Workflow de déploiement

`ocre-deploy.sh` :

1. `rsync -a --delete` du repo vers `/opt/ocre-app/`
2. Calcul `BUILD_VERSION="${SHA}-$(date +%s)"` (unique par deploy)
3. `sed` remplace `__BUILD_VERSION__` dans `index.html` et `api/version.php`
4. Notif Telegram info

Aucun cache server-side ne survit au rsync (les fichiers sont écrasés).

## Garantie

Le pire cas (Safari iOS PWA en mode hors connexion + SW v52 cached) :

- Au prochain accès en ligne, le `update()` au `DOMContentLoaded` force Safari à check `sw.js` malgré son cache interne
- Le nouveau `sw.js` install→activate purge tout et navigate
- En parallèle, le fetch `/api/version.php` détecte le mismatch et déclenche reload

Si malgré tout l'agent voit une ancienne version > 30 secondes après ouverture, c'est un bug à signaler.

## Tests post-deploy

```bash
# Couche 1
curl -I https://app.ocre.immo/index.html | grep -i cache-control
curl -I https://app.ocre.immo/sw.js | grep -i cache-control

# Couche 2
curl -sk https://app.ocre.immo/sw.js | grep "SW_VERSION"

# Couche 3
curl -sk https://app.ocre.immo/index.html | grep -oE "APP_VERSION = '[^']+'"

# Couche 4
curl -sk https://app.ocre.immo/api/version.php
# → retourne <SHA>-<timestamp> identique à APP_VERSION dans index.html
```

## Règle absolue

Philippe ne touche **jamais** son cache iPad. Pas de Réglages iOS, pas de "Données de sites web", pas de fermeture/réouverture d'onglet, pas de hard reload. Les 4 couches ci-dessus sont la seule source acceptable d'invalidation.

## Garde-fous commit (M/2026/04/29/49)

Pour empêcher le scénario "commit dans le mauvais repo" qui a coûté 30 minutes le 29/04 :

1. **`/root/bin/safe-commit <project> "<msg>"`** : wrapper unique. Refuse commit si `git rev-parse --show-toplevel` ≠ `/root/workspace/<project>`. Refuse commit vide. Affiche `COMMIT_HASH=` pour traçabilité.

2. **Hook `pre-commit` local** dans `.git/hooks/pre-commit` de chaque repo : refuse commit hors `/root/workspace`. Alerte (sans bloquer) si nom du dossier ≠ remote URL.

3. **`/root/bin/verify-deployed <project> <hash>`** : vérifie post-deploy que le commit annoncé est bien dans le repo concerné ET (pour `ocre-immo`) que `/api/version.php` retourne le bon hash. Sinon exit non-zéro.

4. **CLAUDE.md `atelier-philippe`** : workflow obligatoire documenté. Commit "à la main" `git add -A && git commit` interdit, `safe-commit` obligatoire.

Toute mission CC qui annonce "livré" sans `verify-deployed OK` doit être marquée FAIL et réexécutée.
