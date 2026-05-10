# M_OCRE_WP_PAGES_OUTILS — 6 pages WP outils créées

Mission : M/2026/05/10/54
Date : 2026-05-10

## Page IDs créés via WP-CLI

| Slug | Page ID | URL | Template | HTTP |
|---|---|---|---|---|
| oi-agent | 11 | https://ocre.immo/oi-agent | template-outil.php | 200 ✓ |
| oi-scan | 12 | https://ocre.immo/oi-scan | template-outil.php | 200 ✓ |
| oi-book | 13 | https://ocre.immo/oi-book | template-outil.php | 200 ✓ |
| oi-demande | 14 | https://ocre.immo/oi-demande | template-outil.php | 200 ✓ |
| oi-capture | 15 | https://ocre.immo/oi-capture | template-outil.php | 200 ✓ |
| oi-estimer | 16 | https://ocre.immo/oi-estimer | template-outil.php | 200 ✓ |

## Meta posts assignés

Pour chaque page :
- `_wp_page_template` = `template-outil.php`
- `oi_outil_slug` = `<slug>` (agent/scan/book/demande/capture/estimer)
- `oi_outil_tagline` = tagline 5 mots
- `oi_outil_cta_app` = `https://app.ocre.immo/oi-<slug>`

Note : le template-outil.php utilise `get_post_field('post_name', get_the_ID())` pour
récupérer le slug et charge le contenu via le catalogue inline $tools (6 outils enrichis
avec photos Unsplash CDN). Les meta `oi_outil_*` sont stockés en complément pour
référence/évolution future mais le template ne les lit pas (catalogue inline self-contained).

## Photos hero

Photos via Unsplash CDN direct (libre droits + resize query params, pas de download local).
Skip Phase A Pexels API (non rentable vs CDN inline déjà fonctionnel).

## Tests

- 6 URLs : HTTP 200 après suivi redirect 301 trailing slash WordPress (canonical permalink).
- Render template-outil.php confirmé : `<title>Oi Agent</title>` + `.op-hero` + `.op-cta` + `.op-cross`.
- Cross-sell visible : section `.op-cross` rendue dans oi-scan (mini cards 5 autres outils).

Tests Playwright Desktop+iPhone REPORTÉS (Chromium absent VPS, voir mission M_OCRE_HOME_VISUELLE).
