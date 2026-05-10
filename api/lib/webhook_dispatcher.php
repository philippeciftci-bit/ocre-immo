<?php
// M116 — Webhook dispatcher Ocre vers systemes tiers.
require_once __DIR__ . '/../db.php';

const WEBHOOK_EVENTS = [
    'dossier.created', 'dossier.updated', 'dossier.deleted', 'dossier.archived',
    'dossier.sold', 'dossier.rented', 'pact.created', 'pact.deleted',
    'proposal.received', 'proposal.accepted', 'proposal.rejected',
    'match.detected', 'document.signed',
];
const WEBHOOK_TIMEOUT_SEC = 5;
const WEBHOOK_MAX_FAILURES = 5;

function wh_meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

function wh_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    wh_meta_pdo()->exec("CREATE TABLE IF NOT EXISTS webhooks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        url VARCHAR(512) NOT NULL,
        events JSON NOT NULL,
        secret VARCHAR(64) NOT NULL,
        active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_triggered_at DATETIME NULL,
        consecutive_failures INT UNSIGNED DEFAULT 0,
        INDEX idx_tenant (tenant_slug),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    wh_meta_pdo()->exec("CREATE TABLE IF NOT EXISTS webhook_deliveries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        webhook_id INT UNSIGNED NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        payload JSON NOT NULL,
        response_status_code INT NULL,
        response_body TEXT NULL,
        duration_ms INT NULL,
        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('success','failed','retrying') NOT NULL,
        INDEX idx_webhook (webhook_id),
        INDEX idx_attempted (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

// Dispatch un event vers tous les webhooks actifs du tenant qui ecoutent ce type.
// Synchrone par defaut (pour M116 V1) ; futur : enqueue dans channel_queue async.
function dispatchEvent(string $tenant_slug, string $event_type, array $payload): array {
    if (!in_array($event_type, WEBHOOK_EVENTS, true)) return ['ok' => false, 'error' => 'event_type_inconnu'];
    wh_ensure_schema();
    $st = wh_meta_pdo()->prepare("SELECT id, url, events, secret FROM webhooks WHERE tenant_slug=? AND active=1");
    $st->execute([$tenant_slug]);
    $hooks = $st->fetchAll();
    $results = [];
    foreach ($hooks as $h) {
        $events = json_decode($h['events'], true) ?: [];
        if (!in_array($event_type, $events, true)) continue;
        $r = wh_deliver_one((int) $h['id'], $h['url'], $h['secret'], $event_type, $payload);
        $results[] = $r;
    }
    return ['ok' => true, 'delivered' => count($results), 'results' => $results];
}

function wh_deliver_one(int $webhookId, string $url, string $secret, string $eventType, array $payload): array {
    $body = json_encode([
        'event' => $eventType,
        'timestamp' => time(),
        'data' => $payload,
    ], JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $body, $secret);
    $start = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => WEBHOOK_TIMEOUT_SEC,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: OcreImmo-Webhook/1.0',
            'X-Ocre-Event: ' . $eventType,
            'X-Ocre-Signature: sha256=' . $signature,
            'X-Ocre-Timestamp: ' . time(),
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $durationMs = (int) round((microtime(true) - $start) * 1000);
    $status = ($code >= 200 && $code < 300) ? 'success' : 'failed';

    wh_meta_pdo()->prepare("INSERT INTO webhook_deliveries (webhook_id, event_type, payload, response_status_code, response_body, duration_ms, status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$webhookId, $eventType, $body, $code, substr($resp ?: $err, 0, 1000), $durationMs, $status]);

    if ($status === 'success') {
        wh_meta_pdo()->prepare("UPDATE webhooks SET last_triggered_at=NOW(), consecutive_failures=0 WHERE id=?")->execute([$webhookId]);
    } else {
        wh_meta_pdo()->prepare("UPDATE webhooks SET consecutive_failures=consecutive_failures+1 WHERE id=?")->execute([$webhookId]);
        // Auto-disable si trop de failures consecutifs
        $f = wh_meta_pdo()->prepare("SELECT consecutive_failures FROM webhooks WHERE id=?");
        $f->execute([$webhookId]);
        $cf = (int) $f->fetch()['consecutive_failures'];
        if ($cf >= WEBHOOK_MAX_FAILURES) {
            wh_meta_pdo()->prepare("UPDATE webhooks SET active=0 WHERE id=?")->execute([$webhookId]);
        }
    }
    return ['webhook_id' => $webhookId, 'status' => $status, 'http_code' => $code, 'duration_ms' => $durationMs, 'error' => $err ?: null];
}
