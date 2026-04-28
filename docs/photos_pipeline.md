# Pipeline photos Ocre Immo

## Engine
- **Imagick** : préféré si dispo (HEIC + qualité supérieure). Actuellement non installé sur ce VPS.
- **GD** : fallback (utilisé). Supporte JPEG / PNG / WebP. Pas HEIC.

## Compression
- Largeur max : **1920px** (resize proportionnel).
- Qualité WebP : **80** (full), **75** (thumb).
- Thumb : **400×400** crop centre carré.

## Cas testés (M/2026/04/29/5)

| Cas | Résultat | Note |
|---|---|---|
| PNG transparent 800×600 | ratio 11.5% | Transparence préservée |
| Square 4000×4000 | resize → 1920×1920, ratio 97.2% | OK |
| Panorama 6000×2000 | resize → 1920×640, ratio 98.8% | Aspect ratio préservé |
| Tiny 400×300 (2.5Ko) | ratio 89% | Compression efficace même tiny |
| Corrupt JPEG | rejeté (compressed=NO) | Erreur loggée dans `photo_compression_stats.error_message` |
| Oversized 30MB | rejeté 413 | Limite `UPLOAD_MAX_BYTES=8MB` côté upload.php |
| HEIC iPhone | **SKIP** | libheif non installé. Action requise admin : `apt install libheif1 imagemagick` |

## Stats
Table `photo_compression_stats` (tenant DB) :
- `original_size`, `compressed_size`, `thumb_size`, `ratio_pct`, `duration_ms`, `engine`, `success`, `error_message`

Helper `photo_pipeline_stats_7d()` agrège les 7 derniers jours pour Dashboard super-admin.

## Limites connues
1. **HEIC non supporté** tant que libheif pas installé.
2. **Imagick absent** : ratio compression ~10-15% inférieur à Imagick théorique.
3. **Photos uploadées avant M/2026/04/29/3** : non compressées, backfill optionnel par mission ultérieure.
