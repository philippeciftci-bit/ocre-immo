# M/2026/05/05/38 — Diagnostic profond upload photos

**Date** : 2026-05-05 14:45 UTC
**Phase** : 2 — read-only audit
**Cleanup phase 1** : zefk d160 + d162 reset OK (json=0, fs=0)

---

## 1. Flux upload séquence détaillée

### Frontend `index.html`

| Étape | Ligne | Fonction | Action |
|---|---|---|---|
| 1 | 4654-4665 | render thumbnail "+" | `<button onClick={() => inputRef.current && inputRef.current.click()}>` ouvre file picker iOS natif |
| 2 | 4683 | `<input type="file" multiple ...>` | `onChange={e => handleFiles(Array.from(e.target.files \|\| []))}` |
| 3 | 4573-4622 | `async function handleFiles(fl)` | (a) `files.length === 0` → exit silencieux. (b) `currentCount >= PHOTOS_HARD_CAP` (31 défaut) → toast "Limite atteinte". (c) `remaining = HARD_CAP - currentCount`, slice(0, remaining), skipped = files.length - toUpload.length. (d) **boucle SÉQUENTIELLE for...await**, chaque `uploadOne(f)` push résultat dans `succeeded[]`. (e) **`onPhotosChange([...list, ...succeeded])`** = setB('photos', v, {commit:true}) côté FormView. Auto-save M103 propage via debounce. (f) `inputRef.current.value=''` reset. (g) toasts skipped/failed. |
| 4 | 4548-4571 | `async function uploadOne(f)` | FormData + fetch POST `/api/upload.php?action=upload` headers `X-Session-Token`. Parse JSON. Si `d.ok && d.photo` → return d.photo (object {name, url, webp_url, thumb_url, size, mtime}). Sinon throw avec error verbose. Logging détaillé console.error. |
| 5 | 21994-21996 | `<PhotoUpload>` instance unique | Stage V Photos, photos={d.bien.photos}, onPhotosChange={v => setB('photos', v, {commit:true})}. |

### Backend `api/upload.php case 'upload'` (lignes 207-260+)

| Étape | Ligne | Action |
|---|---|---|
| 1 | 208 | `requireAuth()` → user obj |
| 2 | 209-211 | `(int)$_POST['dossier_id']` → checkOwnership |
| 3 | 213-220 | `$_FILES['file']` validation (size, mime) |
| 4 | 222-228 | **`purgeUnreferenced($dossier_id)` ⚠️** — supprime fichiers fs non référencés dans `data.bien.photos[]` JSON. |
| 5 | 229-233 | `listPhotos($dossier_id)` count fs originals (filtre _thumb + .webp siblings depuis M/21) |
| 6 | 233 | si `count($existing) >= getMaxPhotos()` → `jsonError('Limite atteinte (count_fs=X, limit=Y)', 409)` |
| 7 | 237-238 | `checkPhotoQuota` quota global 500MB user |
| 8 | 240-251 | génère nom `<prefix><Ymd-His>-<random>.<ext>`, `move_uploaded_file()` → écriture fs |
| 9 | 254-258 | `photo_pipeline_compress` génère `<base>.webp` + `<base>_thumb.webp` (best-effort) |
| 10 | ~260+ | `jsonOk(['photo' => {name, url, size, mtime, webp_url, thumb_url, compression_ratio}])` |

**❌ BACKEND NE MET JAMAIS À JOUR `data.bien.photos[]` EN DB.** Aucun `UPDATE clients SET data = JSON_ARRAY_APPEND(...)` dans `case 'upload'`. Le JSON DB est mis à jour UNIQUEMENT via auto-save M103 côté client (POST /api/clients.php?action=save avec data complète debounced 800ms).

---

## 2. Points de défaillance ranked (probabilité décroissante)

### **HYPOTHÈSE 1 (CRITIQUE) — Race auto-save vs purgeUnreferenced**

**Mécanisme** :
1. User upload photo 1 → backend écrit fs[0]. JSON DB pas à jour (auto-save côté client en attente debounce ~800ms).
2. Avant que l'auto-save propage, user upload photo 2 séquentiellement.
3. `purgeUnreferenced(dossier_id)` exécuté en début de upload 2 (ligne 222) :
   - lit `data.bien.photos[]` DB → count = 0 (auto-save pas encore propagé)
   - safety : `count(referenced) === 0 && fs_count < UPLOAD_MAX_PER_DOSSIER` → **SKIP** (pas saturé)
4. Pour upload 2 jusqu'à upload 30 → fs croît, JSON aussi (auto-save propage progressivement)
5. **À l'upload 31** (ou si Philippe a accumulé fs > LIMIT lors des sessions précédentes) :
   - fs_count = 31 (saturé)
   - JSON contient les photos précédentes mais peut être en retard de quelques entries
   - `purgeUnreferenced` itère fs, pour chaque fichier fs check `referenced[name]`
   - si la photo `name` est PAS dans `referenced` (auto-save pas encore propagé) → **SUPPRIMÉE par la purge** (avec ses variants `.webp` + `_thumb.webp`)

**Résultat user** : photos uploadées avec succès côté serveur sont **silencieusement supprimées** par la purge avant le upload suivant. Le client n'est pas notifié. UI affiche les thumbs un instant (response.photo) puis l'auto-save propage la liste vide → thumbs disparaissent à la fin.

**Reproduction probable scenario Philippe** : valérie d162 où Philippe a ajouté plusieurs photos rapidement, certaines uploadées dans le timespan où `purgeUnreferenced` dans un upload suivant les considère orphelines.

### **HYPOTHÈSE 2 — Hard cap 30 strict rejette silencieusement**

`getMaxPhotos()` retourne 30 par défaut (file `/var/lib/ocre/uploads/_settings/photos_max.txt`). À l'upload N=31, backend rejette `jsonError('Limite atteinte (count_fs=31, limit=30)', 409)`. Le client reçoit error et `succeeded` n'inclut pas la photo. Toast affiche "X photos ajoutées, Y échecs".

Mais Philippe dit "ça commence bien mais les photos sont perdues à la fin" → pas typique d'un rejet 31. Plus typique d'une race silencieuse.

### **HYPOTHÈSE 3 — Auto-save M103 conflit avec re-render**

`setB('photos', newList, {commit:true})` debounced. Si `editing` change entre temps (autre formField modifié), le state photos peut être écrasé. Mais auto-save bien testé, donc unlikely.

### **HYPOTHÈSE 4 — Quota global 500MB user dépassé**

`checkPhotoQuota` ligne 237-238 envoie 413 si dépassé. Mais 30 photos × ~3MB = 90MB, loin de 500MB. Unlikely.

### **HYPOTHÈSE 5 — Double rendu PhotoUpload**

**INFIRMÉ** par grep : `<PhotoUpload>` apparaît UNE SEULE FOIS ligne 21994 dans Stage V. Aucun autre call site. La section "Glisser des photos…" visible image 2 valérie est probablement la zone dropzone interne à PhotoUpload (lignes ~4640-4683), PAS un 2e composant.

`<PhotosGroupedView>` (composant ajouté en M/32) est rendu en complément (ligne 21999), mais il est **désactivé en selectMode** (M/35) et n'a PAS de file input — il ne peut pas générer d'uploads concurrents.

---

## 3. Confirmation/infirmation double rendu

**Infirmé.** `<PhotoUpload>` = 1 instance unique. `<PhotosGroupedView>` = 1 instance complémentaire (vue groupée par catégorie, pas d'upload). Pas de conflit d'instances.

L'image 2 valérie pateau montre 2 zones :
- Zone du HAUT : grille thumbnails + dropzone "Glisser des photos…" (PhotoUpload natif)
- Zone du BAS : "Organisation par catégorie" (PhotosGroupedView, vue groupée read+meta-edit)

C'est intentionnel et non-conflictuel.

---

## 4. Race conditions identifiées

**Oui, race CRITIQUE** :
- `purgeUnreferenced()` côté serveur (ligne 222 de upload.php) compare fs vs `data.bien.photos[]` JSON DB
- `data.bien.photos[]` est mis à jour UNIQUEMENT par l'auto-save M103 côté client (debounce ~800ms via `setB(commit:true)`)
- Entre l'upload backend (file écrit fs) et la propagation client→DB du JSON, les fichiers viennent d'être uploadés mais ne sont PAS dans le JSON
- Le upload suivant exécute `purgeUnreferenced` qui voit ces fichiers comme orphelins → les supprime

**Symptôme réel** : Philippe upload N photos. Côté UI elles apparaissent. Auto-save propage progressivement. Mais à un moment donné, le purge d'un upload suivant (ou un reload) déclenche la safety qui supprime les fs orphelins. Photos perdues.

---

## 5. Hypothèse principale

**Race condition `purgeUnreferenced` vs auto-save M103** — Le backend supprime les fichiers fs uploadés très récemment parce que l'auto-save côté client (debounce 800ms) n'a pas eu le temps de propager `data.bien.photos[]` à la DB JSON. Le purge considère ces fichiers comme orphelins et les supprime, faisant disparaître les photos juste uploadées.

---

## 6. Recommandation de fix (1 phrase)

**Backend `case 'upload'` doit lui-même UPDATE `data.bien.photos[]` en DB (JSON_ARRAY_APPEND) APRÈS le `move_uploaded_file` réussi** — élimine la race en garantissant que le JSON est toujours sync avec fs avant le prochain upload, et `purgeUnreferenced` ne peut plus considérer les fichiers récents comme orphelins.

**Variante moins invasive** : retirer `purgeUnreferenced` de `case 'upload'` (laisser uniquement endpoint manuel `purge_unreferenced` admin). Acceptable si l'orphelin accumulation reste rare et est déclenché par défragmentation manuelle.

**Décision Philippe requise** entre les deux variantes avant tout patch.

---

## Notes diverses

- **Bug évident trivial trouvé** : aucun. Le code est cohérent côté unitaire, le bug vient de l'architecture (split JSON DB côté client + fs côté serveur sans sync atomique).
- **Cleanup phase 1 OK** : zefk d160 + d162 reset à json=0 fs=0. Philippe peut retester sur état propre.
- **Logging diag existant** : `console.log('[bulkDelete] ...')` (M/35), `console.error('[PhotoUpload] ...')` (M/20) → utilisable Safari Web Inspector côté Philippe.

**Aucun patch appliqué. Aucun commit. Aucun deploy. Aucun bump SW.**
