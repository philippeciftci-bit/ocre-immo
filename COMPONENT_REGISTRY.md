# Composants SSOT Ocre Immo

Source de vérité unique. **Tout nouveau composant React doit être listé ici AVANT création.** Violation = rollback automatique (pre-commit hook + smoke test).

Référence : M/2026/05/13/53 — 4 verrous SSOT anti-récidive doublons.

## Usage : Currency pair (input EUR + taux + input MAD)

- ✅ `PriceField2Col` (Variant B, M40-M44) — édition
- ✅ `PriceField2ColDisplay` — read-only (créé M52 après nettoyage RateBadgeInline doublon M51)
- ❌ Toute autre implémentation = **INTERDITE**

Historique : `DualCurrencyPair` (M/2026/05/06/77) supprimé franche en M/2026/05/13/59 (0 usage actif, 167 lignes retirées d'`index.html`).

## Usage : Rate popup (taux change AUTO/MANUEL)

- ✅ `RatePopup2Col` (M40 + M44)
- ❌ Toute autre = **INTERDITE**

## Usage : Frais d'agence multi-lignes

- ✅ `M53FraisAgenceMulti`
- ❌ Toute autre = **INTERDITE**

## Usage : Frais notaire bloc

- ✅ `FraisNotaireBlock2Col`
- ❌ Toute autre = **INTERDITE**

## Usage : Acteur row (ligne agent/notaire)

- ✅ `S3ActeurRow`
- ❌ Toute autre = **INTERDITE**

## Usage : Sélecteur de devise (picker)

- ✅ `CurrencyPicker` — sélecteur inline
- ✅ `CurrencyPickerSheet` — bottom-sheet mobile
- ✅ `CurrencyBar3Pills` — barre 3 devises (M/2026/05/06/77 a relativisé son rôle global mais decla + 1 usage encore actifs)
- ❌ Toute autre = **INTERDITE**

## Procédure d'ajout d'un nouveau composant

1. **Justifier dans une issue Git** : pourquoi pas un composant existant ?
2. **Validation explicite Philippe** via Telegram avant écriture du code
3. **Ajouter ligne au registre** dans le MÊME commit que la création
4. **Bypass exceptionnel** (one-shot, à documenter) : `ALLOW_NEW_CURRENCY_COMPONENT=1 git commit ...`

## Verrous techniques en place (M53)

- **V1** : ce fichier — registre déclaratif
- **V2** : `.githooks/pre-commit` — bloque commit avec composant Currency/Rate/Price/Amount/Devise/Taux/EUR/MAD non listé
- **V3** : `tests/check_ssot.sh` — bloque smoke + déploiement si composant currency hors registre détecté dans `index.html`
- **V4** : `mission_queue` MCP — injection automatique préambule SSOT au début du brief pour `project=ocre`
