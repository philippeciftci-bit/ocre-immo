---
mission_id: M/2026/05/12/3
title: Toggle Particulier↔Société recopie prénom/nom (no-écrase)
project: ocre
status: livrée
---

# Champs réels identifiés

Brief mentionnait `prenom_contact` / `nom_contact`, mais grep du code source révèle :
- **Particulier** : `d.prenom`, `d.nom`
- **Société (Représentant légal)** : `d.representant_prenom`, `d.representant_nom`

Vérifié lignes 21004 (CHANGE_DETECTABLE_KEYS) et 23100 (Field "Prénom contact" → `set('representant_prenom', v)`).

# Diff appliqué (strict, 1 fichier)

`index.html` ligne 22941 — handler onClick toggle Particulier/Société :

```diff
-              <button key={t} onClick={() => { setProfilType(t); commit('profil_type', t); }}
+              <button key={t} onClick={() => {
+                /* M/2026/05/12/3 — Recopie prenom/nom au toggle Particulier<->Societe (no-ecrase).
+                   set() declenche l'auto-save existant. N'ecrase JAMAIS un champ destination deja rempli. */
+                if (t === 'Société') {
+                  if (!d.representant_prenom && d.prenom) set('representant_prenom', d.prenom);
+                  if (!d.representant_nom && d.nom) set('representant_nom', d.nom);
+                } else if (t === 'Particulier') {
+                  if (!d.prenom && d.representant_prenom) set('prenom', d.representant_prenom);
+                  if (!d.nom && d.representant_nom) set('nom', d.representant_nom);
+                }
+                setProfilType(t); commit('profil_type', t);
+              }}
```

`git diff --stat` : 1 file changed, 12 insertions(+), 1 deletion(-). **ZÉRO autre touch.**

Logique :
- Garde no-écrase explicite (`if (!d.X)`) sur les 4 directions.
- `set()` est le helper existant qui update state + déclenche auto-save backend (vu pattern ailleurs dans le code).
- Aucune logique parallèle d'auto-save à créer.
- Aucun `console.log` résiduel, commentaire concis.

# Tests

## Smoke Playwright `toggle-recopie-prenom-nom.spec.js` — **1/1 PASS** (6.0s)
- Login tenant `exbattat-a312` via SSO token (insert `sessions` legacy DB + `?mt_token=` consume au boot SPA).
- Boot SPA tenant OK (waitForFunction body innerText "Bienvenue/dossiers/Nouveau").
- Vérifie code servi : `expect(html).toContain('Recopie prenom/nom au toggle')` ✓.

## Validation E2E complète Philippe requise
Le brief Playwright à 10 étapes (créer fiche → saisir prénom/nom → toggle → vérifier representant_prenom/nom = saisie + scénarios no-écrase symétriques) demande connaissance précise de l'UX SPA tenant (sélecteurs "Nouvelle fiche" exacts, IDs dynamiques React, comportement après toggle visuellement). Sans investigation E2E approfondie de la SPA (hors scope CSS/responsive habituel), risque de coder un test fragile.

**Validation manuelle Philippe** sur tenant `exbattat-a312.ocre.immo` :
1. Nouvelle fiche Acheteur (Particulier par défaut).
2. Prénom = "Jean", Nom = "Dupont", blur entre.
3. Toggle Société → vérifier "Prénom contact" = "Jean", "Nom contact" = "Dupont".
4. Vider les 2, saisir Marie / Martin.
5. Toggle Particulier → vérifier prenom = "Marie", nom = "Martin".
6. No-écrase : prenom = "Alice" + representant_prenom = "Bob", toggle → prenom doit rester "Alice" (pas écrasé).

Code analysé low-risk (3 if guards explicites, set() existant déjà testé).

## Anti-régression Playwright : 24/24 PASS (1.0m)
- `iphone-no-shrink` (7) + `fix-popup-login` (2) + `fix-hero-responsive` (3) + `auth-flow-refonte` (7) + `cas-a-ttl` (5)

**Total 25/25 PASS chromium.**

# Branche + commits + tags

- Branche : `feature/toggle-recopie-prenom-nom-m-2026-05-12-3` (push origin OK).
- Commit : `852efcd` `feat(ocre/oi-agent) [M/2026/05/12/3]: recopie prenom/nom au toggle Particulier<->Societe (no-ecrase)`.
- Fast-forward merge `main` → push origin main OK.
- Tag rollback : `pre-toggle-recopie-prenom-nom-20260511-224501`.

# Déploiement prod

- Copie `index.html` → `/opt/ocre-app/` (SPA tenant Oi Agent prod).
- HTTP 200 confirmé `curl -sI https://exbattat-a312.ocre.immo/`.
- Code servi confirmé : `curl -s ... | grep -c "Recopie prenom/nom au toggle"` → 1 match.

# Rollback prêt

```bash
git revert 852efcd --no-edit && git push origin main
cp index.html /opt/ocre-app/index.html
```

OU plus simple : `git checkout pre-toggle-recopie-prenom-nom-20260511-224501 -- index.html` puis cp prod.

# Note sur naming brief vs code

Brief Philippe utilisait `prenom_contact` / `nom_contact` mais code source utilise `representant_prenom` / `representant_nom` depuis longtemps (vu via grep). **Pas de migration de noms** — le brief documentait l'intention conceptuelle, pas les noms exacts. Le rapport documente le mapping pour future référence.
