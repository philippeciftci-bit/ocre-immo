# STANDARDS UI Ocre Immo — Référence permanente

> Source de vérité unique pour les conventions visuelles et comportementales
> communes à TOUS les composants de l'app. À consulter avant chaque mission UI
> et à mettre à jour quand une nouvelle règle est validée par Philippe.

Mission origine : M/2026/05/02/10 (M115 + M121).
Dernière révision : 2026-05-02.

## 1. Popovers compacts (CountryPicker, PhonePicker, OptionPicker)

### Largeur proportionnelle au contenu (M121)

Tous les popovers (dropdowns custom) DOIVENT utiliser une largeur proportionnelle
au contenu, pas une largeur fixe arbitraire.

```css
.popover-compact {
  width: max-content;
  min-width: 160px;
  max-width: min(280px, calc(100vw - 24px));
}
```

Justification : Philippe a explicitement validé 3 fois cette règle. Un popover
de 320px pour afficher "🇫🇷 France · €" gaspille l'espace. Un popover de 150px
pour afficher "Hispano-mauresque" tronque. La règle `max-content` règle les deux.

### Position et dimensions

- Position : `absolute`, `top: calc(100% + 4px)`, `left: 0`, juste sous le bouton.
- Max-height : `280px` (scroll interne au-delà).
- Background : `#fff`, border `1px solid #D9C9A8`, border-radius `10px`.
- Box-shadow : `0 8px 32px rgba(107,79,53,0.12)`.
- z-index : `1000`.
- Pas d'overlay sombre (popover ≠ modale critique).
- Tap en dehors → ferme + reset query.

## 2. CountryPicker / PhonePicker / OptionPicker

### Comportement universel

- **Recherche en haut** dès que le nombre d'options dépasse 8 (filtre live).
- **Pays favoris** (CountryPicker, PhonePicker) : `MA / FR / ES / IT / BE` (ordre fixe).
  - Algérie (DZ) et Tunisie (TN) restent disponibles dans "Tous les pays" mais ne sont plus
    en favoris UI depuis M120.
- Tous les autres pays affichés en alphabétique français en dessous.
- Sélection ferme le popover et reset le champ recherche.

### Différenciation par mode

- `mode="country"` (par défaut) : bouton affiche `🇫🇷 FR`.
- `mode="phone"` : bouton affiche `🇫🇷 +33`. Utilisé par PhoneField pour aligner
  le PhonePicker sur le CountryPicker (M121).

### Composants

- `PaysCompactButton(value, onChange, label, mode)` — pays/téléphone (ISO + dial).
- `OptionPicker(label, value, onChange, options, placeholder, searchable)` — selects métier
  (Standing, État, Style architectural, etc.). Réutilisable.

## 3. Densité visuelle

- Padding entre champs verticaux : `marginBottom: 10px` (V55Field standard).
- Gap entre sous-blocs (sections logiques dans une carte) : `gap: 12px` ou `marginBottom: 12px`.
- Hauteur input/bouton standard : `40px`.
- Border-radius standard : `8px` (inputs/boutons), `10–12px` (popovers/cards).
- Padding popover items : `8px 12px` (OptionPicker), `6px 10px` (CountryPicker compact M120).

## 4. Couleurs — Palette ocre

- Fond input vide : `#FDFAF7`
- Fond input rempli : `#FFFCF5`
- Border vide : `#E8DDD0`
- Border rempli : `#BBA88B`
- Border focus / selected : `#B26D3A` (ocre fort)
- Texte principal : `#2A2018`
- Label uppercase petit : `#8B7F6E` (font-size 11, font-weight 600, letter-spacing .4)
- Background sélectionné dans liste : `#FBF1E4`
- Hover liste : `rgba(107,79,53,0.04)`
- Séparateur soft : `#EDE6DC` ou `#F4EEE6`
- Erreur (numéro invalide) : `#B73E3E` / `#D64545`
- Succès (numéro valide) : `#2D6B3F` / `#4BB77B`
- Warning (incomplet) : `#D4A437`

## 4.bis Bloc adresse standardisé (M122)

Tous les blocs adresse de l'app suivent la même convention pour cohérence
visuelle universelle.

### Layout cible

- **Ligne 1** : `[PAYS PaysCompactButton] [ADRESSE input avec autocomplete]`
  - Grid : `auto 1fr` (Pays largeur naturelle, Adresse flex).
  - Sur très petits écrans (<400px) : empilage vertical autorisé.
- **Ligne 2** : `[VILLE] [CP] [QUARTIER]` (Section II) ou `[VILLE] [CP]` (Section I).
  - Grid Section II : `2fr 1fr 1.5fr` (Ville plus large, CP au milieu, Quartier à droite).
  - Grid Section I : `2fr 1fr` (Ville prioritaire, CP compact à droite).

### Ordre des champs

L'ordre `Ville / CP / Quartier` est volontaire (CP au milieu = équilibre visuel
car CP plus court que Ville et Quartier). Décision Philippe M122.

### Pré-remplissage geoIP — retiré (M122)

`detect_country.php` ne pré-remplit plus `pays_residence` ni `bien.pays` côté UI.
Cas réel Philippe : Ophélie agente au Maroc / client résident France / bien en
Espagne — geoIP ne devine rien. L'agent choisit le pays explicitement.

`detect_country.php` reste disponible backend pour analytics. Si besoin futur
de suggestion contextuelle, c'est une mission séparée (modale "On dirait que
tu es au Maroc, créer en MAD ?" plutôt que fill silencieux).

### Auto-fill CP via ville (reporté en mission future)

Spec définie M122 mais non livrée :
- FR : `geo.api.gouv.fr/communes?nom={query}&fields=codesPostaux,nom`.
- MA : tableau JSON embarqué `data/villes_ma.json` (~50 villes).
- ES, IT, BE : Nominatim OpenStreetMap (proxy `/api/nominatim.php` existant).
- Autres pays : input libre.

Logique : auto-fill CP au pick d'une ville sauf si CP déjà saisi manuellement
(respect saisie manuelle prime).

### Migration progressive

M122 livre uniquement Section I (Particulier + Société) et Section II.
Audit secondaire reporté : profil agent (paramètres), adresse facturation,
adresse pièce d'identité (lieu de délivrance). Composant `AdresseBlock`
unifié réutilisable est aussi reporté en mission future (refacto plus large).

## 4.ter Comportement universel popovers (M123)

Tous les popovers de l'app (`PaysCompactButton`, `OptionPicker`, `DistrictPicker`,
et futurs) suivent 3 règles d'auto-fermeture :

1. **Tap sur un autre popover ouvert → ferme le précédent** : un seul popover
   ouvert à la fois. Implémentation : event global `ocre:popover-open` dispatché
   à chaque ouverture, listener via le hook `usePopoverGroup(open, setOpen, id)`.
2. **Sélection (single-select) → ferme automatiquement** après le tap.
3. **Multi-select** : pas de fermeture auto à chaque tap. Bouton "Valider" en
   footer + tap "✕" pour fermer. (Cas rare dans l'app actuelle, à conserver
   comme exception documentée.)

Compactage QuartierPicker (DistrictPicker) M123 : modale plein écran `.bsheet-overlay`
remplacée par popover `position:absolute` `width:max-content` `min-width:200px`
`max-width:min(320px, calc(100vw - 24px))` `max-height:360px`. Style cohérent
avec OptionPicker M115.

## 5. Principes UX (rappels Philippe)

- **Zéro emoji décoratif.** Drapeaux pays acceptés (sémantique). Aucun autre
  emoji dans le formulaire métier (pas de ✨, 🎉, 💡, etc.).
- **Pré-remplissage geoIP retiré (M122).** Tous les pays sont VIDE par défaut.
  L'agent choisit explicitement (pays_residence, bien.pays, pays_naissance, id_country).
  `detect_country.php` reste backend-only pour analytics.
- **CountryPicker partout** pour les champs pays. Plus jamais d'input texte libre
  ou d'autocomplete custom pour un pays (incohérence Section I → corrigée M120).
- **Auto-save sur blur** (M103) : pas de bouton "Enregistrer" cosmétique. Le bouton
  ✓ Valider sticky footer M108 reste pour la promotion brouillon → dossier valide.
- **TVA / IVA pays-conditional** : jamais hardcoded "France 20%". Utiliser le
  référentiel pays (FR, MA, ES, BE, IT, ...) — voir M117.
- **Modale changement profil** (M118) : ne s'affiche QUE si previousProfil non null
  ET différent ET au moins un champ rôle-spécifique rempli.

## 6. Versioning Service Worker

À chaque déploiement modifiant index.html ou sw.js : `SW_VERSION` bump entier.
Pas de v178.1, pas de v178-hotfix. v178 → v179 → v180.

## 7. Workflow commit

Voir `CLAUDE.md` racine repo. Toujours via `safe-commit ocre-immo "..."`.
Toujours `verify-deployed ocre-immo <hash>` après deploy.

## 8. Anti-régression cumulative

Avant `STATUS: READY`, vérifier mentalement :

1. Parcours golden mobile (iPhone, navigation privée).
2. Parcours golden desktop.
3. Cas vide (dossier vierge, base sans données).
4. Cas erreur (réseau down, input invalide).

Si une mission précédente (M88-M120) régresse, c'est pas livrable.
