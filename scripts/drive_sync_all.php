<?php
// M/2026/04/29/33 — Cron daemon : itere drive_tokens connectes sur tous tenants,
// lance sync, log + notif Telegram warning si fails. Lance via systemd timer Mon 04:00 UTC.
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/drive_token_crypto.php';
require_once __DIR__ . '/../api/lib/mini_xlsx.php';
require_once __DIR__ . '/../api/drive_sync.php';

$logFile = '/var/log/ocre-drive-sync.log';
function lg(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

lg('=== drive_sync_all start ===');
$totalOk = 0; $totalFail = 0; $errors = [];

$pdo = pdo_meta();
$rows = $pdo->query("SELECT slug FROM workspaces WHERE deleted_at IS NULL")->fetchAll();
foreach ($rows as $w) {
    $slug = $w['slug'];
    if (!preg_match('/^[a-z0-9_-]+$/', $slug)) continue;
    try {
        $wsp = pdo_workspace('ocre_wsp_' . $slug);
        $tokens = $wsp->query("SELECT * FROM drive_tokens")->fetchAll();
        foreach ($tokens as $tok) {
            $uid = (int) $tok['user_id'];
            try {
                $GLOBALS['_v20_slug'] = $slug;
                $GLOBALS['_v20_mode'] = 'agent';
                drive_sync_for_user($uid, $slug);
                lg("OK uid=$uid slug=$slug");
                $totalOk++;
            } catch (Throwable $e) {
                $err = "uid=$uid slug=$slug: " . $e->getMessage();
                lg("FAIL $err");
                $errors[] = $err;
                $totalFail++;
            }
        }
    } catch (Throwable $e) {
        lg("WSP_FAIL $slug: " . $e->getMessage());
    }
}

lg("=== done OK=$totalOk FAIL=$totalFail ===");

if ($totalFail > 0) {
    $body = "Sync hebdo Drive : $totalFail fail / " . ($totalOk + $totalFail) . " total\n" . implode("\n", array_slice($errors, 0, 5));
    @exec('/root/bin/notify --project ocre --priority warning --mission-id "M/2026/04/29/33" --title "Drive sync hebdo: ' . $totalFail . ' fail" --body ' . escapeshellarg($body) . ' >/dev/null 2>&1');
}
