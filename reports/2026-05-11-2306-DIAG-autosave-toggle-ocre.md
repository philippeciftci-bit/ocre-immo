---
mission_id: M/2026/05/12/4
title: DIAG complet bug auto-save doublons + toggle recopie OiAgent (READ-ONLY)
project: ocre
type: diag — AUCUN FIX appliqué
status: terminé, solution proposée à valider Philippe
---

# Section A — Certif TLS

| Host | Subject CN | Issuer | Expiration | SAN |
|---|---|---|---|---|
| `exbattat-a312.ocre.immo` | `*.ocre.immo` | Let's Encrypt R13 | **2026-07-25** | `*.ocre.immo`, `ocre.immo` |
| `app.ocre.immo` | idem | idem | idem | idem |
| `ocre.immo` | idem | idem | idem | idem |

`curl -vI https://exbattat-a312.ocre.immo/` :
- `SSL certificate verify ok`
- `subjectAltName: host "exbattat-a312.ocre.immo" matched cert's "*.ocre.immo"`
- HTTP/2 200

**Verdict : certif TLS valide, wildcard couvre le tenant. L'icône ⚠️ rouge Safari Philippe vient d'autre chose** (peut-être un avertissement local cache, ou mixed content historique). Pas de problème serveur.

# Section B — git log 7 jours (commits suspects)

Commits récents touchant `index.html` (zone save/draft) :

| SHA | Mission | Risque autosave |
|---|---|---|
| `fbe7e2d` | docs M/12/3 (rapport+test) | aucun |
| `852efcd` | M/12/3 toggle recopie | minime (handler toggle, pas save) |
| `06f0b3f` | M/11/38 frais agence Section III | nul (déplacement bloc CSS) |
| `178e5f0` | M113d helper i18n | nul |
| `4bd7223` | M110 décommission gallery | nul |
| `3b5709e` | **M99 SSO migration JWT+legacy** | **possible** : mapping auth_users<->tenants |
| `8294531` | M90 Aide refondue | nul |
| `3b0f97e` | M88 PWA push backend | nul |
| `a394633` | M87 Réglages refondue | nul |

**Pas de commit qui touche directement `handleSaveLocal` ou `apiSaveClient` dans les 7 jours**. Le bug autosave n'est pas une régression récente du code, mais une **interaction historique** entre frontend et backend qui se déclenche dans des conditions précises (cf Section F).

# Section C — Bug 1 Playwright + LOGS BACKEND

## Reproduction Playwright (boot tenant exbattat-a312)
- Login via `mt_token` SSO insert sessions DB → boot SPA OK
- `M/12/3 code present in DOM? **true**` — diff servi correctement
- API responses : `auth.php?action=me` 200 OK (user id=180), `clients.php?action=list` 200 OK
- **Aucune erreur 500 reproduite en Playwright headless** (Philippe a rencontré 51 × 500 sur device réel)

## **JACKPOT logs nginx access log (preuve réelle device Philippe)**

```
88.166.125.4 - - [11/May/2026:22:58:14 +0000] "POST /api/clients.php?action=save HTTP/2.0" 500 0 "https://exbattat-a312.ocre.immo/" "Mozilla/5.0 (iPhone; CPU iPhone OS 26_4_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) GSA/419.4.905781065 Mobile/15E148 Safari/604.1"
[... 8 autres POST 500 entre 22:58:14 et 22:58:28 ...]
```

**Stats totales `POST /api/clients.php?action=save` sur tenant exbattat-a312** :
- 27 succès (200)
- 6 bad request (400)
- 1 unauthorized (401)
- **51 server errors (500)** ← le bug

## **JACKPOT log PHP error**

```
[11-May-2026 22:58:28 UTC] PHP Fatal error:  Uncaught PDOException:
SQLSTATE[23000]: Integrity constraint violation: 1048
Column 'projet' cannot be null in /opt/ocre-app/api/clients.php:476
```

# Section D — Bug 2 Playwright (toggle recopie)

```
M/12/3 code present in DOM? true
```

Bundle servi contient bien le diff M/12/3 (lignes 22942-22945) :
```js
if (!d.representant_prenom && d.prenom) set('representant_prenom', d.prenom);
```

Et le caller `Field label="Prénom contact" onChange={v => set('representant_prenom', v)}` ligne 23111 utilise bien le bon champ.

**Conclusion Bug 2** : code déployé et servi correctement. Si Philippe ne voit pas la recopie sur device, hypothèses (par ordre de probabilité) :
- (a) **PWA SW v478 cache un index.html antérieur à M/12/3** sur le device Philippe (SW network-first par défaut mais peut servir cache ancien si network slow). Hard reload Safari iOS résoudrait.
- (b) Philippe n'a pas blur l'input prenom avant de tap Société (focus ne perd pas → onChange n'a pas re-trigge re-render → state d.prenom encore stale au moment du toggle). Mais analyse code : `onChange={e => onChange(e.target.value)}` keystroke direct, état immédiat.
- (c) Philippe a saisi prenom en Particulier puis a flush la fiche entre temps → state d.prenom='' au toggle.

# Section E — DB ocre_wsp_exbattat-a312.clients

```
SELECT id, prenom, nom, created_at FROM clients WHERE created_at > NOW()-INTERVAL 24 HOUR
```

| id | prenom | nom | created_at |
|---|---|---|---|
| 1 | rrrr | nbbh | 2026-05-11 18:11:03 |
| 2 | ttttt | ttttt | 2026-05-11 22:39:27 |

**SEULEMENT 2 LIGNES en DB**, pas de doublons. Philippe voyait 3 cards "bbbbbbbb" en UI mais c'était du **state local React orphelin** (cards optimistes tmpId pas filtrées correctement après saveError 500). Le filter `setClients(prev => prev.filter(c => c.id !== tmpId))` côté `handleSaveLocal` ligne 11878 devrait nettoyer les tmpId, mais sur 9 erreurs successives en 14 secondes, race condition possible → cards orphelines visibles.

Aucun doublon réel en DB → backend a correctement rejeté les 9 INSERT NULL via la contrainte. **La DB est propre.**

# Section F — Cause racine diagnostiquée

## Bug 1 (autosave 500 → cards orphelines UI)

**Cause racine** : la colonne `clients.projet VARCHAR(40) NOT NULL DEFAULT 'Acheteur'` reçoit `NULL` au premier `POST /api/clients.php?action=save` quand l'agent saisit prénom/nom **AVANT d'avoir choisi un profil** (Acheteur/Vendeur/...).

Le frontend (`handleSaveLocal` ligne 21963) construit `incoming = {...d, ...data, projet: activeProfil, ...}` sans fallback. Si `activeProfil === null` → `projet: null` envoyé → backend INSERT échoue avec `1048 Column 'projet' cannot be null` (le DEFAULT MySQL n'est appliqué que si la colonne est OMISE de l'INSERT, pas si elle est explicitement `NULL`).

Frontend reçoit 500 → dispatch `ocre-draft-error` "Echec sauvegarde" → retire la card optimiste → user re-saisit → re-POST 500 → boucle.

## Bug 2 (toggle recopie pas visible)

**Cause racine** : code M/12/3 correctement déployé et servi. Bug **non reproduit en Playwright**. Hypothèse forte : **cache PWA SW v478** sur device Philippe servant un index.html antérieur. Bump SW version → force refresh des clients.

# Section G — Solution pérenne proposée (PAS encore appliquée)

## Bug 1 — Fix backend (1 ligne, idempotent, low-risk)

`/opt/ocre-app/api/clients.php` ligne ~470 (avant `$stmt->execute([... $projet ...])`) :
```php
if (!$projet) $projet = 'Acheteur';  // M_FIX_AUTOSAVE_NULL_PROJET — guard backend default explicite
```

Avantages :
- Idempotent (n'écrase qu'une valeur falsy).
- Cohérent avec le DEFAULT 'Acheteur' du schema.
- Élimine les 51× 500 → autosave fluide.
- Pas de modif frontend, pas de cache à invalider.

Risque : nul. Si frontend envoie `projet: 'Vendeur'`, le guard ne s'applique pas.

**Bonus côté frontend (optionnel)** : `handleSaveLocal` ligne 21963 :
```js
projet: activeProfil || 'Acheteur',  // ← fallback explicit
```

## Bug 2 — Fix bump SW + cache invalidation (1 ligne)

`/opt/ocre-app/sw.js` ligne 5 :
```diff
- const SW_VERSION = 'ocre-sw-v478.0-m110-decommission-photosgalleryv28';
+ const SW_VERSION = 'ocre-sw-v479.0-m12-3-toggle-recopie-prenom-nom';
```

Le SW change de version → install new → activate (purge tous caches via `caches.delete(k)`) → claim clients → SPA reload bundle index.html frais.

Avantages :
- Force refresh device Philippe au prochain visite.
- Pattern déjà utilisé sur le projet (M88, M110, etc.).

Risque : nul. SW network-first total, pas de cache offline (sauf offline.html fallback).

## Tests post-fix proposés
- Bug 1 : Playwright simule POST `/api/clients.php?action=save` avec `projet=null` → attendu 200 (au lieu de 500).
- Bug 2 : Playwright vérifie `SW_VERSION` du SW servi contient `v479`.
- Validation manuelle Philippe : saisir prenom+nom sans choisir profil → attendu 1 fiche en DB avec `projet='Acheteur'` (pas de "Echec sauvegarde"). Toggle Société → champs Représentant remplis.

## Rollback
- Bug 1 : `git revert <commit>` ou retirer la ligne du guard PHP.
- Bug 2 : SW version revert v479 → v478.

# Cleanup test data

- Sessions e2e supprimées : `DELETE FROM sessions WHERE user_agent='e2e-diag'` ✓
- Spec test supprimée : `/opt/atelier-tools/e2e/tests/ocre/diag/diag-autosave-toggle.spec.js` ✓
- Aucune fiche `DiagTest`/`ToggleTest` créée en DB (Playwright n'a pas atteint l'UI création fiche).

# AUCUN FIX APPLIQUÉ. Solution à valider par Philippe avant code.
