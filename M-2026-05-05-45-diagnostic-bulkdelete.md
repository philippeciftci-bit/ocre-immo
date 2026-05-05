---
mission_id: M/2026/05/05/45
title: M-Diagnostic-Profond-Bulk-Delete-Photos
mode: read-only diagnostic
sha_head: 3b7649e (M/31 base 8997056 + cherry-pick M/33 + bump SW v341)
created_at: 2026-05-05T15:35:00Z
---

# Diagnostic profond bulkDelete photos — M/45

## 1. État DB et FS capturés

### Tenant `ocre_wsp_zefk` (prod)

| ID | Prénom | Nom | Projet | photos JSON | photos preview | updated_at |
|---|---|---|---|---|---|---|
| 160 | Nnnnnnnnn | (vide) | Locataire | **0** | `[]` | 2026-05-05 15:07:04 |
| 155 | Philippe | Ciftci | Acheteur | 0 | `[]` | 2026-05-05 15:04:33 |
| 162 | valérie | pateau | Bailleur | 0 | `[]` | 2026-05-05 14:43:20 |

FS d160 (3 paths candidats) : `/opt/ocre-app/uploads/160` = 0 fichiers · `/var/lib/ocre/uploads/dossier_160` = 0 · `/var/lib/ocre/uploads/160` = 0.

### Tenant `ocre_wsp_zefk_test` (Fatima Benhima)

ID 145, **Fatima Benhima**, projet **Bailleur**, **photos JSON = 5** :

```json
[
 "https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=600&auto=format",
 "https://images.unsplash.com/photo-1505691938895-1758d7feb511?w=600&auto=format",
 "https://images.unsplash.com/photo-1586023492125-27b2c045efd7?w=600&auto=format",
 "https://images.unsplash.com/photo-1567016526105-22da7c13161a?w=600&auto=format",
 "https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=600&auto=format"
]
```

⚠️ Les 5 photos sont des **strings URL Unsplash** (pas des objets `{name, url}`). Données seed/demo, **pas uploadées** via `upload.php`. Aucun fichier sur le FS pour d145.

(Note : Fatima Benhima existe aussi dans `mehdi_test` 145, `ophelie_test` 145, `ozkan_test` 114, mêmes 5 photos URLs Unsplash — c'est un fixture seed multi-tenant.)

## 2. Flow bulkDelete reconstitué (index.html @ 8997056)

### 2.1. PhotoUpload component (line 4262)

```
state : selectMode (bool), selectedUuids (string[]), bulkDeleteRemaining (int)
prop  : dossierId, photos (array), onPhotosChange, showToast
list  = Array.isArray(photos) ? photos : []
```

### 2.2. photoKey(p, idx) (line 4279) — clé d'identification d'une photo

```js
if (!p)              return 'idx-' + idx;     // null/undefined
if (typeof p === 'string') return p;          // string URL = clé directe
return p.name || p.url || ('idx-' + idx);     // objet : name prio, url fallback
```

### 2.3. Sélection (line 4286 toggleUuid)

```js
toggleUuid(key) -> selectedUuids = prev includes(key) ? remove : add
```

Tap thumbnail en mode select (line 4457) : `onClick={selectMode ? () => toggleUuid(key) : undefined}` → `selectedUuids` remplie de keys.

### 2.4. Bouton "Supprimer (N)" (line 4442)

```jsx
<button onClick={bulkDelete} disabled={noneSelected || bulkDeleteRemaining > 0} ...>
  Supprimer ({selectedUuids.length})
</button>
```

### 2.5. bulkDelete async (line 4294)

1. `if (selectedUuids.length === 0) return;`
2. `await askConfirm({title:'Supprimer N photos ?', danger:true, confirmLabel:'Supprimer'})` → bool
   - Si **false** → return (pas d'action). L'utilisateur a annulé OU le modal n'a pas reçu de réponse.
3. `setBulkDeleteRemaining(n)` → spinner overlay s'affiche
4. **Boucle séquentielle** sur `selectedUuids[]` :
   - `key = selectedUuids[i]`
   - `p = list.find((it, idx) => photoKey(it, idx) === key)`
   - `name = p && typeof p === 'object' ? (p.name || '') : (typeof p === 'string' ? p : '')`
   - **Si `!name` → `continue` sans add à succeeded** ⚠️
   - Sinon `await fetch(API + '/upload.php?action=delete', body:{dossier_id, name})`
   - `succeeded.add(key)` (peu importe HTTP status retourné — fetch ne throw que sur erreur réseau)
   - decrement bulkDeleteRemaining
5. `setBulkDeleteRemaining(0)` → spinner off
6. `onPhotosChange(list.filter((p, idx) => !succeeded.has(photoKey(p, idx))))` → propage state filtré au parent
7. `exitSelectMode()` → sort du mode select
8. Toast résultat

### 2.6. Backend `case 'delete'` (api/upload.php:284)

```php
$user = requireAuth();
$dossier_id = (int)$input['dossier_id'];
$name = basename((string)$input['name']);  // ⚠️ basename() sur URL Unsplash garde le slug
if (!$dossier_id || !$name) jsonError('dossier_id et name requis');
if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $name))
    jsonError('Nom de fichier invalide');                        // ⚠️ rejet HTTP 200 + ok=false
checkOwnership($dossier_id, $user);
$path = dossierDir($dossier_id) . '/' . $name;
if (!file_exists($path)) jsonError('Fichier introuvable', 404);   // ⚠️ rejet HTTP 404
if (!@unlink($path)) jsonError('Suppression impossible', 500);
// + suppression sous-produits .webp / _thumb.webp
clearstatcache(true);
jsonOk(['deleted' => $deleted, 'count_after' => count(listPhotos($dossier_id))]);
```

**Le backend ne touche JAMAIS `data.bien.photos[]` JSON** — il supprime uniquement le fichier FS. La mise à jour JSON est entièrement déléguée au client via `onPhotosChange` + auto-save M103 / M129 (`setB('photos', v, {commit:true})`).

### 2.7. Aucune persistance client

```bash
grep "localStorage.*photo|photo.*localStorage" index.html → 0 hit
```

Le composant n'utilise PAS localStorage/IndexedDB pour les photos. L'état provient de `photos` prop, qui vient de `d.bien.photos` (state React `data` du dossier), lui-même chargé depuis `clients.php?action=get`.

### 2.8. Site de rendu PhotoUpload (line 21756, post cherry-pick M/33)

```jsx
<PhotoUpload
  dossierId={editing && editing.id && !String(editing.id).startsWith('tmp-') ? editing.id : null}
  showToast={showToast}
  photos={Array.isArray(d.bien && d.bien.photos) ? d.bien.photos : []}
  onPhotosChange={v => setB('photos', v, {commit: true})}
/>
```

`setB('photos', v, {commit:true})` → setD(prev => {...prev, bien: {...bien, photos: v}}) + flip userBlurredRef → trigger auto-save M103 debounce 800ms → POST `clients.php?action=save` avec `bien.photos = v` → DB mise à jour.

## 3. ConfirmModal (line 18173) — pierre angulaire askConfirm

- zIndex **200** (relativement bas vs autres overlays jusqu'à 9999, mais pas bloquant en pratique car pas d'overlay actif au moment du modal photo).
- Backdrop click → `onCancel`. Bouton "Supprimer" → `onConfirm`.
- Définition globale `window.__ocreAsk` dans `App` useEffect (line 10843).

`askConfirm` helper (line 18208) : si `window.__ocreAsk` est fonction → l'appelle, sinon fallback `window.confirm` natif (qui sur Safari iOS marche aussi).

**Conclusion intermédiaire** : aucune raison structurelle pour que le modal n'apparaisse pas.

## 4. Hypothèses ranked par probabilité

### Bug A — d160 "30 blocs vides" rien ne se passe au tap "Supprimer (30)"

| Rank | Hypothèse | Détail |
|---|---|---|
| **1** ★★★ | **State React stale + photos tronquées sans `name`** | DB=0 et FS=0 confirmés. Si Philippe voit 30 blocs, le state React `d.bien.photos[]` contient 30 entrées non vides. Origine probable : photos uploadées dans une session antérieure (avant le reset emergency M/43), reste dans le state local non rechargé. Si ces entrées sont des **objets sans `name`** valide (ex: `{url: '/uploads/160/foo.jpg', size: ...}` mais pas `name`), alors bulkDelete : `name = ''` → `if (!name) continue` (line 4306) → **pas d'add à succeeded** → `onPhotosChange(list.filter(...))` retire **0 entrée** car succeeded est vide → 30 blocs persistent. ★ explication la plus cohérente. |
| 2 ★★ | Photos seed `null` ou `undefined` dans le tableau | Si le tableau a 30 slots avec `null`, photoKey(null, idx) = `idx-N` → unique key. bulkDelete : `name = ''` → `continue`. Même résultat : 0 photos retirées. |
| 3 ★ | Auto-save M103 écrase juste après filter | `onPhotosChange(filtered)` → setB → save. Si le save renvoie l'ancien state DB (mais DB=0…), peu probable. |
| 4 | DELETE fetch rejette CORS / 401 / token expiré | Le `try/catch` n'attrape pas HTTP 4xx/5xx (fetch ne throw que sur réseau). Mais si CORS / 401 réseau, le catch ajoute à failedCount sans add à succeeded. Toast "30 échec(s)" devrait apparaître — Philippe ne mentionne pas de toast. |

### Bug B — Fatima 5 photos "tap Supprimer (1) ne fait rien"

| Rank | Hypothèse | Détail |
|---|---|---|
| **1** ★★★ | **Modal askConfirm non visible / non confirmé par Philippe** | Le code envoie `askConfirm({title:'Supprimer 1 photo ?', danger:true})`. Si la modal apparaît mais que Philippe ne tape pas le bouton "Supprimer" rouge du modal (et la masque ailleurs / tap-through iOS bleed → cancel), bulkDelete sort early ligne 4297. Visuellement "rien ne se passe". |
| 2 ★★ | **Save M103 écrase l'optimistic update** | bulkDelete fait : (a) DELETE fetch → backend retourne 'Nom de fichier invalide' HTTP 200 ok:false (URL Unsplash ≠ regex), succeeded.add(key) ok, (b) onPhotosChange(filter retire la photo URL), (c) setB({commit:true}) trigger auto-save M103 800ms. Le save POST `clients.php?action=save` avec `bien.photos` = 4 URLs (la photo retirée). DB devrait être mise à jour à 4. **À vérifier** : Philippe re-clique sur le dossier après tap, voit 5 photos toujours → soit le save n'a pas été déclenché, soit la modal askConfirm a été annulée. |
| 3 ★★ | **iOS Safari tap-through cancel le modal** | Tap "Supprimer (1)" → click event sur bouton ocre → bulkDelete → setConfirmReq → modal apparaît immédiatement. Si la coordonnée du tap initial est **au centre de l'écran** (où le modal s'affiche), un 2ème "ghost click" iOS Safari (300ms delay legacy) tape sur le backdrop du modal → onCancel. C'est un cas connu Safari mobile. |
| 4 ★ | Photos strings URL passent regex backend → erreur silencieuse | Confirmé (regex 4 fail). Mais comme noté en rank 2, le client retire localement. Donc Philippe verrait 4 photos après save. Si non, le save lui-même n'a pas eu lieu. |
| 5 | Bouton onClick non wired | Faux : line 4442 onClick={bulkDelete} bien présent. |

## 5. Hypothèse principale Bug A (d160)

**Le state React `d.bien.photos[]` contient 30 entrées avec `name` manquant ou vide** (probablement objets `{url}` sans `name`, héritage d'une session pre-reset emergency). `bulkDelete` skip silencieusement chaque entrée (`if (!name) continue` line 4306) sans les ajouter à `succeeded`, donc `onPhotosChange` filtre 0 élément. Les 30 blocs restent.

**Pour confirmer** : Philippe peut ouvrir DevTools sur Safari iOS connecté Mac, console, taper :

```js
// dans le contexte de la page dossier d160
JSON.stringify(window.__ocreLastDossier?.bien?.photos || 'NA').slice(0, 1000)
```

OU plus simple : ouvrir d160 puis dans Stage V mode select, tap "Tout sélectionner" → tap "Supprimer (30)" → modal apparaît → tap "Supprimer" → si rien ne se passe, regarder `console.error('[PhotoUpload] bulkDelete failed for', name, ':', e)` — si aucun log, c'est que `name=''` skip silencieux a touché les 30. Si logs apparaissent avec name "X.jpg", c'est piste 4.

## 6. Hypothèse principale Bug B (Fatima)

**Photos = strings URL Unsplash, pas d'objet** : la chaîne théorique fonctionne (string URL → `name = URL` → DELETE fetch → backend rejette 'Nom de fichier invalide' HTTP 200 ok:false → fetch ne throw pas → `succeeded.add(key)` → `onPhotosChange(filtered)` → save trigger → DB photos passe de 5 à 4).

**Si Philippe constate "rien ne se passe"** → le modal askConfirm n'a probablement pas reçu confirmation (cancel via tap-through iOS, ou modal masqué par un autre overlay). Le code `await askConfirm(...)` retourne `false` → `return` early ligne 4297. **Pas de DELETE fetch, pas de save, état inchangé**.

**Pour confirmer** : Philippe tape le bouton, observe : (a) le modal "Supprimer 1 photo ?" apparaît-il une fraction de seconde puis disparaît ? (b) Reste-t-il visible et il doit explicitement taper "Supprimer" ? Si (a) → tap-through. Si (b) sans effet visible derrière → Save M103 silent fail.

## 7. Recommandations (1 phrase chacune, NO PATCH)

### Bug A (d160 30 blocs vides)

> Faire reload dur du dossier (kill app iPhone + force SW v341 invalidation) pour que React recharge `d.bien.photos[]` depuis DB (=0) et le state local stale disparaisse — pas un bug code, un bug de cache état React.

Si le bug persiste après reload dur, **alors** patch chirurgical à prévoir : dans `bulkDelete` line 4306, remplacer `if (!name) { ... continue; }` par `if (!name) { succeeded.add(key); ... continue; }` pour que les entrées sans `name` soient quand même retirées de la liste optimistiquement (auto-save M103 propagera ensuite).

### Bug B (Fatima 5 photos URL Unsplash)

> Ajouter handler côté client pour photos strings : autoriser le retrait local SANS DELETE fetch (DELETE inutile car fichier non hébergé), via `if (typeof p === 'string') { succeeded.add(key); continue; }` AVANT le fetch DELETE. La DB sera mise à jour via auto-save M103.

Alternative plus propre : detect dans `bulkDelete` que `name` est une URL externe (`name.startsWith('http')`) et skip le fetch backend tout en marquant succeeded.

---

## Annexe — captures brutes

### bulkDelete code (4294-4322)

```js
async function bulkDelete() {
  if (selectedUuids.length === 0) return;
  const n = selectedUuids.length;
  if (!(await askConfirm({title:'Supprimer ' + n + ' photo' + (n > 1 ? 's' : '') + ' ?', message:'Cette action est definitive.', danger:true, confirmLabel:'Supprimer'}))) return;
  setBulkDeleteRemaining(n);
  const succeeded = new Set();
  let failedCount = 0;
  for (let i = 0; i < selectedUuids.length; i++) {
    const key = selectedUuids[i];
    const p = list.find((it, idx) => photoKey(it, idx) === key);
    const name = p && typeof p === 'object' ? (p.name || '') : (typeof p === 'string' ? p : '');
    if (!name) { setBulkDeleteRemaining(n - i - 1); continue; }
    try {
      await fetch(API + '/upload.php?action=delete', {method:'POST', headers:{'Content-Type':'application/json','X-Session-Token':token()}, body: JSON.stringify({dossier_id: dossierId, name})});
      succeeded.add(key);
    } catch (e) {
      failedCount++;
      console.error('[PhotoUpload] bulkDelete failed for', name, ':', e);
    }
    setBulkDeleteRemaining(n - i - 1);
  }
  setBulkDeleteRemaining(0);
  onPhotosChange && onPhotosChange(list.filter((p, idx) => !succeeded.has(photoKey(p, idx))));
  exitSelectMode();
  if (failedCount > 0) showToast && showToast(succeeded.size + ' photo(s) supprimee(s), ' + failedCount + ' echec(s)');
  else showToast && showToast(succeeded.size + ' photo' + (succeeded.size > 1 ? 's supprimees' : ' supprimee'));
}
```

### Conclusion globale

Les 2 bugs sont liés à la **chaîne fragile entre `name` exploitable côté client et `regex strict côté backend`**. Le backend est conçu pour des fichiers locaux uploadés via `case 'upload'` (objets `{name: '<sha>.jpg', url, size}`). Pour des photos seed strings ou des entrées sans `name`, le mécanisme delete saute silencieusement.

Aucun patch dans cette mission. Décision Philippe.
