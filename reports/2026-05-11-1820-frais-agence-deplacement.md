---
mission_id: M/2026/05/11/38
title: M_FRAIS_AGENCE_DEPLACEMENT bloc Section III → Section VI
project: ocre
status: livrée
---

# Découverte au diagnostic

Le bloc Frais d'agence **existe déjà en Section VI** dans `/opt/ocre-app/index.html` ligne 23292 (`<V55Bloc title="06b · Frais d'agence">`), rendu pour TOUS les profils via `activeProfil || 'Vendeur'`. Les commentaires montrent que les profils Vendeur/Promoteur/MarchandBiens/Investisseur/Curieux ont déjà été migrés en M/2026/05/06/80.2.A.

**3 occurrences résiduelles** subsistaient en Section III :
- Acheteur (ligne 8833) : `<V55Bloc title="Frais d'agence">` autonome contenant `<M53FraisAgenceMulti profil="Acheteur"/>` + dropdown "Honoraires à charge"
- Locataire (ligne 9065) : `<FraisAgenceMultiActeurs profil="Locataire"/>` imbriqué dans `<V55Bloc title="Honoraires location (estimation)">`
- Bailleur (ligne 9178) : `<M53FraisAgenceMulti profil="Bailleur"/>` imbriqué dans `<V55Bloc title="Honoraires de location">`

# Fix appliqué

**1. Section VI ligne 23290 — `baseAmount` adapté par profil + `forceUpdate` réel :**
```jsx
<V55Bloc title="06b · Frais d'agence" theme="agence">
  {(() => {
    const _ap = activeProfil || 'Vendeur';
    let _base = Number(d.prix_affiche) || 0;
    if (_ap === 'Acheteur' || _ap === 'Investisseur') _base = Number(d.prix_max_total || d.budget_max || d.prix_max) || 0;
    else if (_ap === 'Locataire') _base = Number(d.loyer_max) || 0;
    else if (_ap === 'Bailleur') _base = Number(d.loyer_demande) || 0;
    return (
      <M53FraisAgenceMulti d={d} set={set} baseAmount={_base} profil={_ap}
        forceUpdate={typeof forceUpdate === 'function' ? forceUpdate : () => {}}/>
    );
  })()}
</V55Bloc>
```

**2. Section III — 3 occurrences supprimées** :
- Acheteur ligne 8833 : tout le `<V55Bloc title="Frais d'agence">…</V55Bloc>` retiré (dropdown "Honoraires à charge" inclus).
- Locataire ligne 9065 : la `<div>…FraisAgenceMultiActeurs profil="Locataire"…</div>` retirée du bloc "Honoraires location (estimation)". Champs "Honoraires locataire estimés" + "Frais de dossier" conservés.
- Bailleur ligne 9178 : `<M53FraisAgenceMulti profil="Bailleur"/>` retiré du bloc "Honoraires de location". Le reste du bloc (Mode, % loyer, Honoraires partagés) conservé.

Chaque suppression remplacée par commentaire de traçabilité `{/* M/2026/05/11/38 — Frais d'agence <Profil> RETIRE de Section III, migre vers Section VI 6b. */}`.

# Validation

- JSX balance OK : `<V55Bloc>` 33 = `</V55Bloc>` 33 ; `<Stage>` 7 = `</Stage>` 7. Aucune balise orpheline.
- 1 seul caller restant `<M53FraisAgenceMulti>` ligne 23290 (Section VI 6b).
- HTTP smoke : `agent.ocre.immo/` 200 OK, SPA index.html 1538284 bytes (~6 lignes nettes en moins).

# Hors scope explicite

- **Tests Playwright sur 8 profils** : impossible sans provisioning E2E de 8 tenants `ocre_wsp_<slug>` + logins via magic link valide. Le test demanderait ~30-45 min de setup E2E pour 1 run sur 1 profil. Non couvert.
- **Vérification visuelle sur 8 profils** : à effectuer manuellement par Philippe sur la SPA tenant (`<slug>.ocre.immo`) :
  1. Acheteur : ouvrir fiche, déplier III → pas de bloc Frais d'agence ; déplier VI → bloc présent.
  2. Idem Vendeur, Bailleur, Locataire, Investisseur, Promoteur, MarchandBiens, Curieux.
  3. Tester un workflow : ajouter agence, saisir %, calcul OK, supprimer ligne.

# Tag git
- `pre-M_FRAIS_AGENCE_DEPLACEMENT-20260511-181903` (rollback)
- `stable-2026-05-11-1820-ocre-frais-agence-deplacement` (post-success)
