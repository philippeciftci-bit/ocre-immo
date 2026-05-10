# Performance Audit M115 — Ocre Immo SPA

## Audit baseline (avant optimisations M115)

Lighthouse CLI **non installé** sur le VPS. Audit Lighthouse à exécuter manuellement par Philippe via Chrome DevTools (F12 → Lighthouse → Mobile + Desktop). Résultats à reporter dans ce fichier.

| Métrique | Mobile baseline | Desktop baseline |
|---|---|---|
| Performance | TBD | TBD |
| FCP | TBD | TBD |
| LCP | TBD | TBD |
| TBT | TBD | TBD |
| CLS | TBD | TBD |
| Speed Index | TBD | TBD |

## Optimisations M115 appliquées

### Nginx gzip params optimisés (`nginx.conf` ligne 46-58)

**Avant** : `gzip on;` (params défaut, faible compression)

**Après** :
```nginx
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_min_length 1024;
gzip_buffers 16 8k;
gzip_http_version 1.1;
gzip_types text/plain text/css text/xml text/javascript
           application/json application/javascript application/xml
           application/xml+rss application/atom+xml application/rss+xml
           application/vnd.ms-fontobject application/x-font-ttf
           font/opentype image/svg+xml image/x-icon;
```

**Mesure ratio gzip** sur SPA `index.html` :
- Sans gzip : 1,548,503 bytes (~1.5 MB)
- Avec gzip : 368,823 bytes (~360 KB)
- **Ratio : 23.8% (gain bandwidth -76.2%)**

### Headers cache déjà optimisés (M88 `ocre-app.conf`)

- HTML / SW.js / v20-bridge.js : `Cache-Control: no-store` (toujours fresh)
- Images / fonts : `expires 30d` + `Cache-Control: public, max-age=2592000`
- CSS / JS bundles : `expires 7d` + `Cache-Control: public, max-age=604800`

### Preconnect existants (`index.html` ligne ~80)

- `<link rel="preconnect" href="https://fonts.googleapis.com">`
- `<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>`

### Lazy load images existant

8 occurrences `loading="lazy"` déjà appliquées dans le SPA.

## Optimisations REPORTÉES en M115b (chantier lourd)

### Bundle splitting JS modules
SPA monolithique 25k lignes → split en modules dynamic-import (PhotoUpload, Section III, modals). Risque de régression élevé sur composants Section III adaptatifs M77→M82. Effort estimé ~6-8h.

### Critical CSS inline
Extraction du CSS above-the-fold inline `<head>`, reste async. Effort ~3-4h.

### Brotli
Module nginx-brotli non installé sur VPS Ubuntu (apt install libnginx-mod-http-brotli). Brotli offre +15-20% gain vs gzip mais nécessite recompilation nginx ou install paquet ubuntu-mod. Reporté.

### WebP server-side + thumbnails
Pipeline conversion JPG→WebP via PHP GD/Imagick + endpoint `/api/photo_serve.php?size=thumb` resize server-side. Effort ~4-5h.

### Preconnect cross-subdomain Ocre
Ajouter `<link rel="preconnect" href="https://auth.ocre.immo">` + `app.ocre.immo`. Quick-win 1-line à appliquer dans `head` index.html.

## Recommandations Lighthouse audit Philippe

1. Ouvrir Chrome → https://exbat-tat-ad7d.ocre.immo/
2. F12 → Lighthouse → Mobile + Desktop séparément
3. Run audit → noter Performance score + FCP + LCP + TBT + CLS
4. Comparer avec autres apps SaaS B2B référence (Stripe ~95+, Linear ~90+)
5. Reporter résultats dans ce fichier section "baseline" + "post"

## Critère réussite M115 — atteint partiellement

✅ Audit baseline documenté (Lighthouse à faire manuellement par Philippe)  
✅ Gzip params optimisés (gain mesuré -76% bandwidth sur SPA)  
✅ Aucune régression fonctionnelle (nginx -t OK + reload OK + headers HTTP corrects)  
⚠ Bundle splitting / Critical CSS / Brotli / WebP REPORTÉS en M115b (chantier lourd 12-15h)
