<?php
// M118b — GET /api/calendar/google/oauth/callback.php?code=XXX&state=YYY
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_lib.php';
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
if (!$code || !$state) {
    http_response_code(400);
    echo 'Bad request : code+state requis';
    exit;
}

try {
    $meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Verify state
    $st = $meta->prepare("SELECT * FROM google_oauth_states WHERE state=? LIMIT 1");
    $st->execute([$state]);
    $stateRow = $st->fetch();
    if (!$stateRow) { http_response_code(400); echo 'Invalid state'; exit; }
    $meta->prepare("DELETE FROM google_oauth_states WHERE state=?")->execute([$state]);

    // Exchange code → tokens (MODE STUB : call mock)
    $ch = curl_init('http://127.0.0.1:8892/mock/google-oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['code' => $code, 'grant_type' => 'authorization_code']),
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tokens = $resp ? (json_decode($resp, true) ?: []) : [];
    if ($code_http !== 200 || empty($tokens['access_token'])) {
        http_response_code(502);
        echo 'Token exchange failed';
        exit;
    }

    // Ensure schema + INSERT oauth
    $meta->exec("CREATE TABLE IF NOT EXISTS google_calendar_oauth (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        google_email VARCHAR(255) NOT NULL,
        google_calendar_id VARCHAR(128) NULL,
        access_token TEXT,
        refresh_token TEXT,
        token_expires_at DATETIME,
        scope TEXT,
        status ENUM('active','expired','revoked') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_sync_at DATETIME NULL,
        UNIQUE KEY uniq_user (tenant_slug, user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $expiresIn = $tokens['expires_in'] ?? 3600;
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    $meta->prepare(
        "INSERT INTO google_calendar_oauth (tenant_slug, user_id, google_email, access_token, refresh_token, token_expires_at, scope, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token), token_expires_at=VALUES(token_expires_at), status='active'"
    )->execute([
        $stateRow['tenant_slug'], $stateRow['user_id'],
        $tokens['email'] ?? 'mock@google.com',
        $tokens['access_token'], $tokens['refresh_token'] ?? null,
        $expiresAt, $tokens['scope'] ?? 'calendar',
    ]);

    header('Location: https://' . $stateRow['tenant_slug'] . '.ocre.immo/reglages-calendrier.html?google=connected');
    exit;
} catch (Throwable $e) {
    error_log('[gcal_callback] ' . $e->getMessage());
    http_response_code(500);
    echo 'Erreur : ' . $e->getMessage();
    exit;
}
