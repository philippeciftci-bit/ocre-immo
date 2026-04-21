<?php
require_once __DIR__ . '/db.php';
setCorsHeaders();

$admin = requireAdmin();

function checkAdminCode($in) {
    $code = $in['admin_code'] ?? ($_SERVER['HTTP_X_ADMIN_CODE'] ?? '');
    if (!hash_equals(ADMIN_CODE, (string)$code)) jsonError('Code admin incorrect', 403);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

switch ($action) {

    case 'list': {
        checkAdminCode($_GET);
        $rows = db()->query("SELECT * FROM settings ORDER BY category, key_name")->fetchAll();
        jsonOk(['settings' => $rows]);
    }

    case 'update': {
        checkAdminCode($input);
        $updates = $input['updates'] ?? [];
        if (!is_array($updates) || !$updates) jsonError('updates requis');
        $count = 0;
        foreach ($updates as $k => $v) {
            setSetting((string)$k, (string)$v);
            logAction((int)$admin['id'], 'setting_update', "$k=$v");
            $count++;
        }
        jsonOk(['updated' => $count]);
    }

    case 'verify_admin_code': {
        checkAdminCode($input);
        jsonOk(['valid' => true]);
    }

    default:
        jsonError('Action inconnue', 404);
}
