<?php
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

function computeIsDraft($d) {
    $tel = trim((string)($d['tel'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    $hasContact = ($tel !== '' || $email !== '');
    if (!$hasContact) return 1;
    if (($d['profil_type'] ?? '') === 'Société') {
        return (trim((string)($d['societe_nom'] ?? '')) === '') ? 1 : 0;
    }
    $prenom = trim((string)($d['prenom'] ?? ''));
    $nom = trim((string)($d['nom'] ?? ''));
    return ($prenom === '' || $nom === '') ? 1 : 0;
}

switch ($action) {

    case 'list': {
        $stmt = db()->prepare(
            "SELECT id, data, is_draft, archived, projet, is_investisseur, updated_at
             FROM clients WHERE user_id = ? ORDER BY updated_at DESC"
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode($r['data'] ?? '{}', true) ?: [];
            $d['id'] = (int)$r['id'];
            $d['archived'] = (bool)(int)$r['archived'];
            $d['is_draft'] = (bool)(int)$r['is_draft'];
            $d['projet'] = $r['projet'] ?? ($d['projet'] ?? 'Acheteur');
            $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
            $d['updated_at'] = $r['updated_at'];
            $out[] = $d;
        }
        jsonOk(['clients' => $out]);
    }

    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$id, $user['id']]);
        $r = $stmt->fetch();
        if (!$r) jsonError('Introuvable', 404);
        $d = json_decode($r['data'] ?? '{}', true) ?: [];
        $d['id'] = (int)$r['id'];
        $d['archived'] = (bool)(int)$r['archived'];
        $d['is_draft'] = (bool)(int)$r['is_draft'];
        $d['projet'] = $r['projet'] ?? ($d['projet'] ?? 'Acheteur');
        $d['is_investisseur'] = (bool)(int)($r['is_investisseur'] ?? 0);
        jsonOk(['client' => $d]);
    }

    case 'save': {
        $c = $input['client'] ?? [];
        if (!is_array($c)) jsonError('client invalide');
        $id = isset($c['id']) ? (int)$c['id'] : 0;
        $projet = (string)($c['projet'] ?? 'Acheteur');
        $is_investisseur = !empty($c['is_investisseur']) ? 1 : 0;
        $archived = !empty($c['archived']) ? 1 : 0;
        $is_draft = computeIsDraft($c);
        $prenom = substr(trim((string)($c['prenom'] ?? '')), 0, 100);
        $nom = substr(trim((string)($c['nom'] ?? '')), 0, 100);
        $societe_nom = substr(trim((string)($c['societe_nom'] ?? '')), 0, 150);
        $tel = substr(trim((string)($c['tel'] ?? '')), 0, 30);
        $email = substr(trim((string)($c['email'] ?? '')), 0, 150);
        $data = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($id > 0) {
            $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ?");
            $chk->execute([$id, $user['id']]);
            if (!$chk->fetch()) jsonError('Accès refusé', 403);
            $stmt = db()->prepare(
                "UPDATE clients SET data = ?, projet = ?, is_investisseur = ?, archived = ?,
                   is_draft = ?, prenom = ?, nom = ?, societe_nom = ?, tel = ?, email = ?,
                   updated_at = NOW()
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$data, $projet, $is_investisseur, $archived, $is_draft,
                            $prenom, $nom, $societe_nom, $tel, $email, $id, $user['id']]);
        } else {
            $stmt = db()->prepare(
                "INSERT INTO clients (user_id, data, projet, is_investisseur, archived,
                                      is_draft, prenom, nom, societe_nom, tel, email,
                                      created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([$user['id'], $data, $projet, $is_investisseur, $archived, $is_draft,
                            $prenom, $nom, $societe_nom, $tel, $email]);
            $id = (int)db()->lastInsertId();
        }
        $c['id'] = $id;
        $c['is_draft'] = (bool)$is_draft;
        $c['archived'] = (bool)$archived;
        jsonOk(['client' => $c]);
    }

    case 'delete': {
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("DELETE FROM clients WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        logAction((int)$user['id'], 'client_delete', "id=$id");
        jsonOk(['deleted' => $id]);
    }

    case 'archive': {
        $id = (int)($input['id'] ?? 0);
        $archived = !empty($input['archived']) ? 1 : 0;
        if (!$id) jsonError('id requis');
        $stmt = db()->prepare("UPDATE clients SET archived = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$archived, $id, $user['id']]);
        jsonOk(['id' => $id, 'archived' => (bool)$archived]);
    }

    // V17.1 fix-ux-3 — suggestions basées sur les saisies antérieures de l'utilisateur.
    case 'suggest_city': {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));
        if ($q === '') {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) AS ville
                 FROM clients WHERE user_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) IS NOT NULL
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) AS ville
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) LIKE ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.ville')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $q . '%');
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $items = array_values(array_filter(array_map(fn($r) => $r['ville'], $stmt->fetchAll())));
        jsonOk(['items' => $items]);
    }

    case 'suggest_address': {
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 8)));
        if ($q === '') {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) AS adresse
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) IS NOT NULL
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = db()->prepare(
                "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) AS adresse
                 FROM clients WHERE user_id = ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) LIKE ?
                 AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.adresse')) != ''
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $stmt->bindValue(1, (int)$user['id'], PDO::PARAM_INT);
            $stmt->bindValue(2, '%' . $q . '%');
            $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $items = array_values(array_filter(array_map(fn($r) => $r['adresse'], $stmt->fetchAll())));
        jsonOk(['items' => $items]);
    }

    default:
        jsonError('Action inconnue', 404);
}
