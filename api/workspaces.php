<?php
// M/2026/05/06/82.1 — endpoint CRUD workspaces + client_workspaces.
// Actions :
//   GET  ?action=list                 → liste tous les WS du tenant + count clients par WS
//   POST ?action=create  {nom}         → cree un nouveau WS
//   POST ?action=delete  {id}          → supprime un WS (cascade client_workspaces)
//   POST ?action=attach  {client_id, workspace_id}  → ajoute association
//   POST ?action=detach  {client_id, workspace_id}  → retire association
//   GET  ?action=for_client&client_id=N  → liste WS associes a un client

require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'list': {
        $sql = "SELECT w.id, w.nom, w.couleur, w.created_at,
                       (SELECT COUNT(*) FROM client_workspaces cw WHERE cw.workspace_id = w.id) AS clients_count
                FROM workspaces w
                ORDER BY w.nom ASC";
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        jsonOk(['workspaces' => $rows]);
    }

    case 'create': {
        $input = getInput();
        $nom = trim((string) ($input['nom'] ?? ''));
        if ($nom === '' || strlen($nom) > 120) jsonError('Nom requis (1-120 chars)', 400);
        $couleur = (string) ($input['couleur'] ?? '#6B4F8F');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur)) $couleur = '#6B4F8F';
        try {
            $stmt = db()->prepare("INSERT INTO workspaces (nom, couleur) VALUES (?, ?)");
            $stmt->execute([$nom, $couleur]);
            $id = (int) db()->lastInsertId();
            jsonOk(['workspace' => ['id' => $id, 'nom' => $nom, 'couleur' => $couleur, 'clients_count' => 0]]);
        } catch (Throwable $e) {
            jsonError('Echec creation : ' . $e->getMessage(), 409);
        }
    }

    case 'delete': {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        if (!$id) jsonError('id requis', 400);
        $stmt = db()->prepare("DELETE FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        jsonOk(['deleted' => $id]);
    }

    case 'attach': {
        $input = getInput();
        $client_id = (int) ($input['client_id'] ?? 0);
        $workspace_id = (int) ($input['workspace_id'] ?? 0);
        if (!$client_id || !$workspace_id) jsonError('client_id + workspace_id requis', 400);
        // Verif ownership client.
        $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$client_id, $user['id']]);
        if (!$chk->fetch()) jsonError('Client introuvable ou acces refuse', 403);
        try {
            $stmt = db()->prepare("INSERT IGNORE INTO client_workspaces (client_id, workspace_id) VALUES (?, ?)");
            $stmt->execute([$client_id, $workspace_id]);
            jsonOk(['attached' => true]);
        } catch (Throwable $e) {
            jsonError('Echec attach : ' . $e->getMessage(), 500);
        }
    }

    case 'detach': {
        $input = getInput();
        $client_id = (int) ($input['client_id'] ?? 0);
        $workspace_id = (int) ($input['workspace_id'] ?? 0);
        if (!$client_id || !$workspace_id) jsonError('client_id + workspace_id requis', 400);
        $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$client_id, $user['id']]);
        if (!$chk->fetch()) jsonError('Client introuvable ou acces refuse', 403);
        $stmt = db()->prepare("DELETE FROM client_workspaces WHERE client_id = ? AND workspace_id = ?");
        $stmt->execute([$client_id, $workspace_id]);
        jsonOk(['detached' => true]);
    }

    case 'for_client': {
        $client_id = (int) ($_GET['client_id'] ?? 0);
        if (!$client_id) jsonError('client_id requis', 400);
        $chk = db()->prepare("SELECT id FROM clients WHERE id = ? AND user_id = ? LIMIT 1");
        $chk->execute([$client_id, $user['id']]);
        if (!$chk->fetch()) jsonError('Client introuvable ou acces refuse', 403);
        $stmt = db()->prepare("SELECT w.id, w.nom, w.couleur FROM client_workspaces cw JOIN workspaces w ON w.id = cw.workspace_id WHERE cw.client_id = ? ORDER BY w.nom ASC");
        $stmt->execute([$client_id]);
        jsonOk(['workspaces' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    default:
        jsonError('Action inconnue', 400);
}
