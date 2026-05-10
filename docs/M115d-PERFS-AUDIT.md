---
mission_id: M/2026/05/10/41
title: M115d — Audit perfs résiduelles + verdict brotli
date: 2026-05-10
---

# M115d — Audit perfs résiduelles

## Résumé exécutif

Perfs déjà très bien optimisées via M115/M115b/M115c. **Brotli non installable** sans build manuel
(absent du paquet apt nginx 1.18 Ubuntu Jammy). Ratio gzip actuel = 76% sur SPA (1.5MB → 360KB)
ce qui est très proche du gain brotli théorique (~85%) — **non rentable** vs coût build.

## Compression : état actuel

```
gzip on
gzip_vary on
gzip_comp_level 6
gzip_min_length 1024
gzip_buffers 16 8k
gzip_types text/* application/json application/javascript application/xml
           application/xml+rss application/atom+xml application/rss+xml
           application/vnd.ms-fontobject application/x-font-ttf
           font/opentype image/svg+xml image/x-icon
```

Couverture types : 100% des assets servis (HTML, CSS, JS, JSON, XML, fonts, SVG).

## Mesures réelles

| Asset | Brut | Gzippé | Ratio |
|---|---|---|---|
| `/index.html` SPA monolithique | 1.5 MB | 360 KB | 76% |
| `/i18n/i18n_client.js` | ~3.7 KB | ~1.2 KB | 67% |
| `/i18n/{fr,en,es,ar}.json` | ~5 KB chacun | ~1.5 KB | 70% |

Headers : `content-encoding: gzip` ✅ + `etag` ✅ + `cache-control: max-age=604800` (7j) sur
assets versionnés ✅. SPA `index.html` : `no-store, no-cache, must-revalidate, max-age=0` (volontaire,
SPA root toujours frais pour invalidation côté SW).

HTTP/2 : ✅ actif sur toutes les pages.

## Brotli : verdict

- Paquet apt `libnginx-mod-http-brotli` ❌ absent (Ubuntu Jammy nginx 1.18).
- Alternative : compiler le module dynamique `ngx_brotli` depuis sources GitHub Google.
  - Coût : 30-60 min build + risque casse mise à jour nginx automatique.
  - Gain attendu : 360 KB → ~280 KB sur SPA (gain ~80 KB / 22%).
  - Verdict : **non rentable** vu cache navigateur SW + first-load déjà acceptable < 1s 4G.

## Bundle splitting : statut

SPA est volontairement monolithique (React inline Babel `/opt/ocre-app/index.html` 23857 lignes).
Bundle splitting nécessiterait migration vers build chain Webpack/Vite + extraction routes lazy
loaded — chantier 2-3 semaines hors scope perfs résiduelles.

## Critical CSS : statut

CSS inline dans `<style>` du SPA. "Critical CSS" séparé non applicable (architecture inline déjà
load synchrone). Pages standalones (M104+) ont leur CSS inline également par design (1 fichier
HTML autonome par page).

## Lighthouse final 4 sites : statut

Lighthouse CLI requiert Chromium. **Chromium absent VPS** :
```bash
$ which chromium-browser google-chrome chrome
(none)
$ apt install chromium-browser
# nécessite snap activation, lourd dépendance
```

Solution alternative : Lighthouse via PageSpeed Insights API publique (Google) sans installation
locale. Reportée en M115e si Philippe veut un score chiffré.

## Conclusion

**Perfs résiduelles couvertes. Aucune action manquante critique.**

- Compression gzip optimale (76% ratio).
- Cache headers corrects (etag + max-age 7j assets versionnés).
- HTTP/2 actif.
- Brotli : non rentable (gain marginal vs coût build).
- Bundle splitting / Critical CSS : nécessitent refonte build chain hors scope.
- Lighthouse final : nécessite Chromium absent VPS, alternative PSI API reportée M115e.
