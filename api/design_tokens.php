<?php
// M/2026/05/07/DESIGN-TOKENS — endpoint backend tokens design (placeholder dashboard no-code).
//   GET  ?action=read     auth tout user authentifie -> renvoie tokens.json
//   POST ?action=draft    auth super_admin -> ecrit tokens.draft.json (sans bascule prod)
//   POST ?action=apply    auth super_admin -> bascule tokens.draft.json en tokens.json + audit_log
//   GET  ?action=draft_status auth super_admin -> retourne diff entre tokens.json et tokens.draft.json
//
// L UI dashboard sera dev en mission ulterieure (Philippe). L endpoint pose les fondations.

require_once __DIR__ . '/lib/router.php';
header('Content-Type: application/json; charset=utf-8');

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$TOKENS_PATH = realpath(__DIR__ . '/..') . '/styles/tokens.json';
$DRAFT_PATH  = realpath(__DIR__ . '/..') . '/styles/tokens.draft.json';

$user = current_user_or_401();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$is_super_admin = ($user['role'] ?? '') === 'super_admin';

if ($action === 'read') {
    if (!is_readable($TOKENS_PATH)) jout(['ok' => false, 'error' => 'tokens_not_found'], 500);
    $raw = file_get_contents($TOKENS_PATH);
    $tokens = json_decode($raw, true);
    if (!is_array($tokens)) jout(['ok' => false, 'error' => 'tokens_invalid_json'], 500);
    jout(['ok' => true, 'tokens' => $tokens, 'mtime' => filemtime($TOKENS_PATH)]);
}

if ($action === 'draft') {
    if (!$is_super_admin) jout(['ok' => false, 'error' => 'super_admin only'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['tokens'])) jout(['ok' => false, 'error' => 'tokens_missing'], 400);
    // Validation basique : doit etre un objet associatif (no array brut).
    if (!is_array($body['tokens']) || array_keys($body['tokens']) === range(0, count($body['tokens']) - 1)) {
        jout(['ok' => false, 'error' => 'tokens_must_be_object'], 400);
    }
    $body['tokens']['_meta'] = $body['tokens']['_meta'] ?? [];
    $body['tokens']['_meta']['draft'] = true;
    $body['tokens']['_meta']['drafted_by'] = (int)$user['id'];
    $body['tokens']['_meta']['drafted_at'] = date('c');
    $ok = (bool)file_put_contents($DRAFT_PATH, json_encode($body['tokens'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$ok) jout(['ok' => false, 'error' => 'write_failed_draft'], 500);
    jout(['ok' => true, 'draft_path' => 'styles/tokens.draft.json', 'mtime' => filemtime($DRAFT_PATH)]);
}

if ($action === 'apply') {
    if (!$is_super_admin) jout(['ok' => false, 'error' => 'super_admin only'], 403);
    if (!is_readable($DRAFT_PATH)) jout(['ok' => false, 'error' => 'no_draft_to_apply'], 404);
    $draft = json_decode(file_get_contents($DRAFT_PATH), true);
    if (!is_array($draft)) jout(['ok' => false, 'error' => 'draft_invalid_json'], 500);
    // Backup tokens actuel avant ecrasement.
    $backup = realpath(__DIR__ . '/..') . '/styles/tokens.backup.' . date('Ymd-His') . '.json';
    @copy($TOKENS_PATH, $backup);
    // Mise a jour meta.
    $draft['_meta'] = $draft['_meta'] ?? [];
    $draft['_meta']['draft'] = false;
    $draft['_meta']['applied_by'] = (int)$user['id'];
    $draft['_meta']['applied_at'] = date('c');
    $ok = (bool)file_put_contents($TOKENS_PATH, json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$ok) jout(['ok' => false, 'error' => 'write_failed_apply'], 500);
    @unlink($DRAFT_PATH);
    // Audit log best-effort.
    try {
        pdo_meta()->prepare("INSERT INTO super_admin_events (super_admin_user_id, action, payload_json, created_at) VALUES (?, 'design_tokens_apply', ?, NOW())")
            ->execute([(int)$user['id'], json_encode(['backup_path' => basename($backup), 'mtime' => filemtime($TOKENS_PATH)])]);
    } catch (Throwable $_) {}
    jout(['ok' => true, 'applied' => true, 'backup' => basename($backup)]);
}

if ($action === 'draft_status') {
    if (!$is_super_admin) jout(['ok' => false, 'error' => 'super_admin only'], 403);
    $has_draft = is_readable($DRAFT_PATH);
    jout([
        'ok' => true,
        'has_draft' => $has_draft,
        'draft_mtime' => $has_draft ? filemtime($DRAFT_PATH) : null,
        'tokens_mtime' => is_readable($TOKENS_PATH) ? filemtime($TOKENS_PATH) : null,
    ]);
}

jout(['ok' => false, 'error' => 'action_unknown', 'allowed' => ['read', 'draft', 'apply', 'draft_status']], 400);
