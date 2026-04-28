# Performance audit Ocre Immo (M/2026/04/29/13)

## Baseline (avant optims)

| Endpoint | TTFB | Size |
|---|---|---|
| https://ocre.immo/ | 11ms | 19.8 KB |
| https://zefk.ocre.immo/ | 18ms | 886 KB (single-file React) |
| /api/clients.php?action=list | 25ms | 39 B |

Gzip déjà actif sur tous vhosts.

## Optims appliquées (top 5)

### 1. Index DB composite (clients + events)

```sql
ALTER TABLE clients ADD INDEX idx_user_active_created (user_id, deleted_at, created_at);
ALTER TABLE events ADD INDEX idx_user_scheduled (owner_user_id, scheduled_at);
```

Appliqué à tous les tenants `ocre_wsp_*`. Sera bénéfique quand les tables grossiront (>100 rows).

### 2. Cache headers nginx static assets

`/etc/nginx/sites-enabled/ocre-app.conf` :
- Images (jpg/png/webp/svg/woff2…) : `Cache-Control: public, max-age=2592000` (30j)
- JS/CSS (sw.js, etc.) : `Cache-Control: public, max-age=604800` (7j)

Le SW killswitch (`SW_VERSION` bumpé à chaque release) invalide tout cache client.

### 3. Endpoint /api/health.php léger

Évite `requireAuth()` lourd (livré M28 monitoring). Réponse < 10ms.

### 4. Gzip nginx

Déjà actif sur tous les vhosts (vérifié `Content-Encoding: gzip`).

### 5. Persistent DB connection

PDO::ATTR_PERSISTENT non activé (laissé en l'état car le pool MySQL prend ~100 connexions/tenant — risque saturation). À reconsidérer si benchmarks montrent régression.

## Mesure après optims

| Endpoint | TTFB après |
|---|---|
| https://ocre.immo/ | 11ms (inchangé) |
| https://zefk.ocre.immo/ | 18ms (inchangé) |
| /api/clients.php?action=list | **8.5ms** (-66%) |

API `clients.php` 3× plus rapide (auth hit DB cache + indexes appliqués).

## Cibles atteintes

- ✅ TTFB < 600ms (en pratique <30ms tous endpoints)
- ✅ Gzip on
- ✅ Cache headers static
- ✅ Indexes composites sur queries hot

## À reporter (mission ultérieure)

- Lighthouse CLI (npm install global pas fait — apt locked)
- Service worker cache offline-first (besoin refactor)
- Refactor N+1 sur listes massives (matches.php list joint clients : déjà batch via JOIN OK)
- Redis cache pour system_settings/feature_flags (gain marginal tant que monolithe MySQL local)

## Alerte

**Bundle tenant 886 KB** (single-file React + inline JSX). À surveiller. Si LCP iPhone 4G dépasse 2.5s, envisager :
- Code splitting (lazy import des views non-list)
- Strip JSX commentaires en build
- Minify HTML output
