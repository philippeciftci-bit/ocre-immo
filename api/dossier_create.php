<?php
// V18.37 — Crée un "nouveau dossier casquette" pour un client existant : copie les coordonnées
// du dossier source (identité, tels, emails, adresse, résidence, canal, langue) vers un
// nouveau dossier avec un profil différent, et lie les deux dossiers bidirectionnellement
// via d.linked_dossiers (v17.15).
//
// Endpoint :
//   POST /api/dossier_create.php?action=add_casquette  (auth user)
//     body JSON : {source_dossier_id, new_profil}

require_once __DIR__ . '/db.php';
setCorsHeaders();

const VALID_PROFILS = ['Acheteur','Vendeur','Investisseur','Bailleur','Locataire','Curieux'];

function copyIdentityFields(array $src): array {
    // Champs de coordonnées/identité à dupliquer dans la nouvelle casquette.
    $keys = [
        'profil_type', 'prenom', 'nom', 'societe_nom', 'siret', 'representant',
        'tel', 'email', 'tels', 'emails',
        'adresse', 'ville', 'pays_residence', 'nationalite', 'code_postal',
        'langue', 'canal', 'origine',
    ];
    $out = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $src)) $out[$k] = $src[$k];
    }
    return $out;
}

$user = requireAuth();
$action = $_GET['action'] ?? '';
$input = getInput();

switch ($action) {

    case 'add_casquette': {
        $srcId = (int) ($input['source_dossier_id'] ?? 0);
        $newProfil = (string) ($input['new_profil'] ?? '');
        if (!$srcId) jsonError('source_dossier_id requis', 400);
        if (!in_array($newProfil, VALID_PROFILS, true)) jsonError('new_profil invalide', 400);

        // 1) Charger source.
        $stmt = db()->prepare("SELECT id, data, projet FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$srcId, $user['id']]);
        $srcRow = $stmt->fetch();
        if (!$srcRow) jsonError('Dossier source introuvable', 404);

        if (($srcRow['projet'] ?? '') === $newProfil) {
            jsonError('Ce dossier est déjà en profil ' . $newProfil, 400);
        }
        $srcData = json_decode($srcRow['data'] ?? '{}', true) ?: [];

        // 2) Vérifier qu'une casquette de ce profil n'existe pas déjà dans le groupe linked.
        $linkedIds = array_map('intval', $srcData['linked_dossiers'] ?? []);
        if (!empty($linkedIds)) {
            $placeholders = implode(',', array_fill(0, count($linkedIds), '?'));
            $chk = db()->prepare("SELECT id, projet FROM clients WHERE id IN ($placeholders) AND user_id = ?");
            $chk->execute(array_merge($linkedIds, [$user['id']]));
            foreach ($chk->fetchAll() as $r) {
                if (($r['projet'] ?? '') === $newProfil) {
                    jsonError('Casquette ' . $newProfil . ' déjà créée (dossier #' . $r['id'] . ')', 409, ['existing_id' => (int) $r['id']]);
                }
            }
        }

        // 3) Construire le nouveau dossier avec coordonnées copiées.
        $newData = copyIdentityFields($srcData);
        $newData['projet'] = $newProfil;
        $newData['is_investisseur'] = ($newProfil === 'Investisseur');
        $newData['linked_dossiers'] = array_values(array_unique(array_merge(
            [$srcId],
            $linkedIds
        )));
        $newData['created_from_casquette'] = $srcId;
        $newData['created_at'] = date('c');

        $prenom = substr(trim((string) ($newData['prenom'] ?? '')), 0, 100);
        $nom = substr(trim((string) ($newData['nom'] ?? '')), 0, 100);
        $societe_nom = substr(trim((string) ($newData['societe_nom'] ?? '')), 0, 150);
        $tel = substr(trim((string) ($newData['tel'] ?? '')), 0, 30);
        $email = substr(trim((string) ($newData['email'] ?? '')), 0, 150);
        $hasContact = ($tel !== '' || $email !== ''
                    || (is_array($newData['tels'] ?? null) && count($newData['tels']))
                    || (is_array($newData['emails'] ?? null) && count($newData['emails'])));
        $hasIdentity = ($newData['profil_type'] ?? '') === 'Société'
            ? !!$societe_nom
            : ($prenom !== '' && $nom !== '');
        $isDraft = ($hasContact && $hasIdentity) ? 0 : 1;

        $ins = db()->prepare(
            "INSERT INTO clients
             (user_id, data, projet, is_investisseur, archived, is_draft, prenom, nom, societe_nom, tel, email, created_at, updated_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $ins->execute([
            $user['id'],
            json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $newProfil,
            (int) (bool) $newData['is_investisseur'],
            $isDraft,
            $prenom, $nom, $societe_nom, $tel, $email,
        ]);
        $newId = (int) db()->lastInsertId();

        // 4) Mettre à jour linked_dossiers dans TOUTES les casquettes du groupe (srcId + linkedIds).
        $groupIds = array_values(array_unique(array_merge([$srcId], $linkedIds, [$newId])));
        // Re-fetch pour persister proprement les linked_dossiers dans chaque data JSON.
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $fetch = db()->prepare("SELECT id, data FROM clients WHERE id IN ($placeholders) AND user_id = ?");
        $fetch->execute(array_merge($groupIds, [$user['id']]));
        $update = db()->prepare("UPDATE clients SET data = ? WHERE id = ? AND user_id = ?");
        foreach ($fetch->fetchAll() as $r) {
            $d = json_decode($r['data'] ?? '{}', true) ?: [];
            $otherIds = array_values(array_filter($groupIds, fn($x) => (int) $x !== (int) $r['id']));
            $d['linked_dossiers'] = array_values(array_unique(array_map('intval', $otherIds)));
            $update->execute([
                json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) $r['id'],
                $user['id'],
            ]);
        }

        logAction((int) $user['id'], 'dossier_casquette_add', 'src=' . $srcId . ' new=' . $newId . ' profil=' . $newProfil);

        jsonOk([
            'new_id' => $newId,
            'new_profil' => $newProfil,
            'source_id' => $srcId,
            'linked_ids' => array_values(array_filter($groupIds, fn($x) => $x !== $newId)),
        ]);
    }

    default:
        jsonError('action inconnue : ' . $action, 400);
}
