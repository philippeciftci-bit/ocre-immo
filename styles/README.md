# Design Tokens — Oi Agent (un produit Ocre)

Source de vérité unique pour tous les paramètres visuels de Oi Agent et futurs modules Oi.

## Fichiers

- **`tokens.css`** — variables CSS root chargées en premier dans tous les vhosts. Source utilisée par le navigateur.
- **`tokens.json`** — même structure exportée en JSON. Servie par `/api/design_tokens.php` pour le futur dashboard no-code (vision Philippe : modifier l'identité visuelle live sans toucher au code).

## Architecture en 3 couches (pattern Stripe / Vercel / Linear)

1. **Primitive tokens** — valeurs brutes (`#8B5E3C`, `16px`, `1.5`).
2. **Semantic tokens** — rôles applicatifs (`--color-bg-page`, `--font-size-base`, `--space-md`).
3. **Component tokens** — spécialisés (`--button-padding-y`, `--card-shadow`, `--modal-radius`).

Toujours référencer le niveau le plus haut disponible dans le code applicatif :
- ✅ `background: var(--color-bg-page);`
- ❌ `background: var(--color-cream-100);` (sauf cas où la primitive est intentionnelle)
- ❌ `background: #F5EFE6;` (hardcode interdit)

## Procédure ajout / modification d'un token

1. Editer `tokens.css` (CSS officiel) ET `tokens.json` (export structuré).
2. Vérifier la cohérence des deux fichiers (mêmes noms, mêmes valeurs).
3. Si le token est utilisé dans `index.html` ou ailleurs, refactor les call-sites pour utiliser `var(--token-name)`.
4. Tester : reload la page → rendu identique. Modifier la valeur dans `tokens.css` → vérifier que la propagation est globale.
5. Commit : `feat(ocre-immo) [M/.../...]: design-tokens add --token-name (raison)`.

## Exceptions intentionnelles documentées

Certaines couleurs sont intentionnellement hardcodées car non substituables :

- **Profils métier** (badges clients) : `--profil-acheteur`, `--profil-vendeur`, etc. dans `tokens.css`. Ne jamais les fusionner avec les sémantiques.
- **Sections fiche dossier M108** : 7 teintes douces par section (sable / sauge / champagne / lavande / terre rosée / ocre signature / gris perle). Constante JS `SECTION_PALETTE_M108` reste dans `index.html` mais référencée également dans `--color-section-i-*` à `--color-section-vii-*` pour usage CSS pur.
- **Badge BROUILLON** rouge M91 : couleur signature design (pattern Notion/Linear), conservée même si M105 sémantique-couleurs a déjà été appliquée.

## Endpoint backend pour dashboard no-code

`/api/design_tokens.php` :
- `GET ?action=read` — renvoie `tokens.json` brut (auth super_admin).
- `POST ?action=draft` — modifie un token en mode brouillon (`tokens.draft.json`).
- `POST ?action=apply` — bascule `tokens.draft.json` en `tokens.json` officiel (auth super_admin, audit_log).

L'UI dashboard sera développée en mission ultérieure. L'endpoint est prêt côté backend.

## Refactor progressif des hardcodes

DESIGN-TOKENS.1 (livré) pose les fondations sans refactor des hardcodes existants.

DESIGN-TOKENS.2 (à venir) refactor progressif :
- Phase 3 : app tenant `index.html` (~21000 lignes, ~600+ hardcodes potentiels)
- Phase 4 : wizard signup `inscription/index.html`
- Phase 5 : landing `agent.ocre.immo` + super-admin
- Phase 7 : tests E2E non-régression visuelle stricte
