<?php
// M_OCRE_V19_COLLAB — Routeur unique commentaires/versions/presence/followers d un dossier
// Endpoints :
//   POST   ?action=comment_add        body {dossier_id, content, field_path?, parent_comment_id?}
//   POST   ?action=comment_resolve    body {comment_id}
//   GET    ?action=comments&dossier_id=42
//   GET    ?action=versions&dossier_id=42&limit=50
//   POST   ?action=follow             body {dossier_id} (toggle)
//   POST   ?action=presence_ping      body {dossier_id}
//   GET    ?action=presence&dossier_id=42
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/collab.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int)$user['id'];
$tenant = collab_tenant_slug($user);
if (!$tenant) jsonError('Tenant inconnu', 400);
collab_ensure_schema();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

switch ($action) {

case 'comment_add': {
    $did = (int)($input['dossier_id'] ?? 0);
    $content = trim((string)($input['content'] ?? ''));
    if ($did <= 0 || $content === '') jsonError('dossier_id et content requis', 400);
    if (mb_strlen($content) > 4000) jsonError('content trop long (max 4000)', 400);
    if (!collab_dossier_belongs($did, $uid)) jsonError('Dossier non autorise', 403);
    $field = isset($input['field_path']) ? substr((string)$input['field_path'], 0, 128) : null;
    $parent = isset($input['parent_comment_id']) ? (int)$input['parent_comment_id'] : null;
    $st = db()->prepare("INSERT INTO dossier_comments (tenant_slug, dossier_id, user_id, parent_comment_id, field_path, content) VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([$tenant, $did, $uid, $parent ?: null, $field, $content]);
    $cid = (int) db()->lastInsertId();
    collab_emit($tenant, "comments:dossier:$did", ['type'=>'comment_added','comment_id'=>$cid,'user_id'=>$uid,'field'=>$field,'preview'=>mb_substr($content,0,80)]);
    // Notif owner + followers (sauf auteur)
    collab_notify_followers($tenant, $did, $uid, mb_substr($content, 0, 100));
    jsonResponse(['ok'=>true, 'comment_id'=>$cid]);
}

case 'comment_resolve': {
    $cid = (int)($input['comment_id'] ?? 0);
    if ($cid <= 0) jsonError('comment_id requis', 400);
    // Verif dossier ownership via join
    $st = db()->prepare("SELECT c.dossier_id FROM dossier_comments c JOIN clients d ON d.id=c.dossier_id WHERE c.id=? AND c.tenant_slug=? AND d.user_id=? LIMIT 1");
    $st->execute([$cid, $tenant, $uid]);
    $did = (int) $st->fetchColumn();
    if (!$did) jsonError('Comment introuvable ou non autorise', 403);
    db()->prepare("UPDATE dossier_comments SET resolved_at = NOW() WHERE id=? AND tenant_slug=?")->execute([$cid, $tenant]);
    collab_emit($tenant, "comments:dossier:$did", ['type'=>'comment_resolved','comment_id'=>$cid,'user_id'=>$uid]);
    jsonResponse(['ok'=>true]);
}

case 'comments': {
    $did = (int)($_GET['dossier_id'] ?? 0);
    if ($did <= 0 || !collab_dossier_belongs($did, $uid)) jsonError('dossier_id requis ou non autorise', 403);
    $st = db()->prepare("SELECT id, user_id, parent_comment_id, field_path, content, resolved_at, created_at FROM dossier_comments WHERE tenant_slug=? AND dossier_id=? ORDER BY created_at DESC LIMIT 200");
    $st->execute([$tenant, $did]);
    jsonResponse(['ok'=>true, 'comments'=>$st->fetchAll()]);
}

case 'versions': {
    $did = (int)($_GET['dossier_id'] ?? 0);
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    if ($did <= 0 || !collab_dossier_belongs($did, $uid)) jsonError('dossier_id requis ou non autorise', 403);
    $st = db()->prepare("SELECT id, user_id, field_path, old_value, new_value, created_at FROM dossier_versions WHERE tenant_slug=? AND dossier_id=? ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $tenant); $st->bindValue(2, $did, PDO::PARAM_INT); $st->bindValue(3, $limit, PDO::PARAM_INT);
    $st->execute();
    jsonResponse(['ok'=>true, 'versions'=>$st->fetchAll()]);
}

case 'follow': {
    $did = (int)($input['dossier_id'] ?? 0);
    if ($did <= 0 || !collab_dossier_belongs($did, $uid)) jsonError('dossier_id requis ou non autorise', 403);
    // Toggle : delete si existe, insert sinon
    $st = db()->prepare("SELECT id FROM dossier_followers WHERE user_id=? AND dossier_id=? LIMIT 1");
    $st->execute([$uid, $did]);
    if ($st->fetchColumn()) {
        db()->prepare("DELETE FROM dossier_followers WHERE user_id=? AND dossier_id=?")->execute([$uid, $did]);
        jsonResponse(['ok'=>true, 'following'=>false]);
    } else {
        db()->prepare("INSERT INTO dossier_followers (tenant_slug, dossier_id, user_id) VALUES (?, ?, ?)")->execute([$tenant, $did, $uid]);
        jsonResponse(['ok'=>true, 'following'=>true]);
    }
}

case 'presence_ping': {
    $did = (int)($input['dossier_id'] ?? 0);
    if ($did <= 0 || !collab_dossier_belongs($did, $uid)) jsonError('dossier_id requis ou non autorise', 403);
    db()->prepare("INSERT INTO dossier_presence (tenant_slug, dossier_id, user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_ping_at = NOW(), tenant_slug = VALUES(tenant_slug), dossier_id = VALUES(dossier_id)")->execute([$tenant, $did, $uid]);
    collab_emit($tenant, "presence:dossier:$did", ['type'=>'presence_ping','user_id'=>$uid]);
    jsonResponse(['ok'=>true]);
}

case 'presence': {
    $did = (int)($_GET['dossier_id'] ?? 0);
    if ($did <= 0 || !collab_dossier_belongs($did, $uid)) jsonError('dossier_id requis ou non autorise', 403);
    $st = db()->prepare("SELECT user_id, UNIX_TIMESTAMP(last_ping_at) AS last_ping_ts FROM dossier_presence WHERE tenant_slug=? AND dossier_id=? AND last_ping_at >= NOW() - INTERVAL 60 SECOND ORDER BY last_ping_at DESC");
    $st->execute([$tenant, $did]);
    jsonResponse(['ok'=>true, 'present'=>$st->fetchAll()]);
}

default:
    jsonError('Action inconnue (comment_add|comment_resolve|comments|versions|follow|presence_ping|presence)', 400);
}
