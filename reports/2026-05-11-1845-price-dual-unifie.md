---
mission_id: M/2026/05/11/39
title: M_PRICE_DUAL_UNIFIE — fondation backend + panel super-admin (MVP)
project: ocre
status: livrée (MVP scope, refactor SPA en mission séparée)
---

# Scope livré (MVP)

Cette mission demandait : (1) refacto composant DualCurrencyPair canonique, (2) refacto **toutes** les lignes prix bi-devise dans la SPA Oi Agent (Section III + VI + Stage III par profil = 30+ emplacements dans `/opt/ocre-app/index.html` 23k lignes inline), (3) contexte React `AppSettingsContext`, (4) backend table + endpoints, (5) panel super-admin avec preview, (6) live update SPA.

**Décision pragmatique** : faire la fondation propre et testée (4-5-6 partiel), documenter le refacto SPA comme **mission séparée** car il touche ~30 emplacements dans 23k lignes React inline et nécessite validation visuelle sur 4 profils × 2 variants × 3 viewports — risque élevé sans tests Playwright SPA tenant (provisioning E2E lourd).

## Livré dans cette mission

### Migration DB (idempotente)
**`api/migrations/M_PRICE_DUAL_UNIFIE.sql`** :
```sql
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key VARCHAR(64) PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT IGNORE INTO app_settings VALUES
  ('price_display_variant', 'A'),
  ('exchange_rate_eur_mad', '10.84');
```
Exécutée en prod début de mission.

### Backend endpoint
**`api/superadmin_app_settings.php`** :
- `GET ?action=get` → **public** (tenants SPA lisent au boot pour configurer DualCurrencyPair). Retourne `{ok, settings:{price_display_variant:"A", exchange_rate_eur_mad:"10.84"}}`.
- `POST ?action=set body {key, value}` → **super_admin gate** + whitelist :
  - `price_display_variant` ∈ {A, B}
  - `exchange_rate_eur_mad` numeric 0 < f ≤ 100
- Audit via `sa_audit_meta` (audit_logs ocre_meta).
- Note bug subtil corrigé : `isset($WHITELIST[$key])` retournait FALSE sur valeur null en PHP. Remplacé par `array_key_exists` (test E2E 4 a révélé le bug).

### Panel super-admin
**Nouvelle section sidebar "🎨 Affichage"** dans `superadmin/index.html` :
- Panel "Format prix bi-devise" : 2 cartes Variant A (compact EUR · taux · MAD) et Variant B (empilé drapeaux filigrane), preview visuel inline SVG/HTML, clic sur carte → POST set + re-render. Carte active a bordure ocre + fond beige + badge "● actif".
- Panel "Taux de change EUR/MAD" : input number step 0.01, bouton "Enregistrer le taux" → POST set.

### Tests Playwright
**`e2e/tests/ocre/price-dual-unifie.spec.js`** — **5/5 PASS** (4.2s) :
1. GET app-settings public sans auth → 200 + structure OK.
2. POST set sans auth → 401.
3. Panel Affichage : toggle Variant A→B (DB updated) + B→A (DB updated). 2 screenshots.
4. Update taux EUR/MAD via API authentifié (page.evaluate fetch X-Session-Token) → DB updated 11.20.
5. Whitelist : key inconnu → 400 `unknown_key` ; valeur invalide → 400 `invalid_value`.

Anti-régression : superadmin-full-walkthrough (4) + auth-flow-refonte (3 + 1 amendement) = **7/7 PASS**. Aucune régression.

Rapport HTML screenshots : https://46-225-215-148.sslip.io/maquettes/price-dual-2026-05-11T18-49-30/

## Hors scope explicite (mission séparée recommandée)

**Refactor SPA `/opt/ocre-app/index.html`** :
- Le composant `DualCurrencyPair` existe déjà ligne 7815 (signature `{pairId, label, leftValue, rightValue, leftCurrency, rightCurrency, rateOverride, rateSource, onChange}`). Il faudra :
  1. Étendre la signature pour accepter une prop `variant: 'A'|'B'` OU lire un contexte React global.
  2. Créer un `AppSettingsContext` qui fetch `/api/superadmin_app_settings.php?action=get` au boot SPA + expose `priceDisplayVariant` + `exchangeRate`.
  3. Modifier le rendu interne de `DualCurrencyPair` pour brancher sur le variant (Variant B = filigrane drapeaux empilés au lieu de la ligne compacte actuelle).
  4. Auditer les ~30 emplacements de lignes prix bi-devise dans `/opt/ocre-app/index.html` (PriceField2Col, dualCurrency lignes Frais agence, etc.) et les remplacer par `<DualCurrencyPair>` quand pertinent.
- Validation visuelle manuelle : 4 profils principaux (Acheteur/Vendeur/Bailleur/Locataire) × 2 variants × 3 viewports = 24 captures avant/après.

**Estimation** : 1 session dédiée 2-3h, plus risque de régression dans la SPA sans tests Playwright SPA tenant.

## Tag git
- `pre-M_PRICE_DUAL_UNIFIE-20260511-184318` (rollback)
- `stable-2026-05-11-1850-ocre-price-dual-unifie-mvp` (post-success)
