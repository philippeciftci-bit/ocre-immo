<?php
// M/2026/04/29/2 — Stripe webhook : signature + dispatch events vers subscriptions.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/billing.php';
require_once __DIR__ . '/admin/_audit.php';

header('Content-Type: application/json');
$env = billing_load_stripe_env();
$secret = $env['STRIPE_WEBHOOK_SECRET'] ?? '';
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Vérification signature (algo Stripe : t=<timestamp>,v1=<hmac>).
function verifyStripeSig(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    if (!$secret) return false;
    $items = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $items[$k] = $v;
    }
    if (empty($items['t']) || empty($items['v1'])) return false;
    $signed = $items['t'] . '.' . $payload;
    $expected = hash_hmac('sha256', $signed, $secret);
    if (!hash_equals($expected, $items['v1'])) return false;
    if (abs(time() - (int) $items['t']) > $tolerance) return false;
    return true;
}

if (empty($env['_configured'])) {
    // Mode stub : on log et on ack (utile pour tests Philippe).
    @file_put_contents('/var/log/ocre-stripe-webhook.log', date('c') . " STUB no_keys payload=" . substr($payload, 0, 200) . "\n", FILE_APPEND);
    echo json_encode(['ok' => true, '_stub' => true]);
    exit;
}

if (!verifyStripeSig($payload, $sigHeader, $secret)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$event = json_decode($payload, true) ?: [];
$type = $event['type'] ?? '';
$obj = $event['data']['object'] ?? [];

billing_ensure_schema();
$dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

audit_log(0, 'stripe.webhook.' . $type, 'subscription', $obj['id'] ?? null, $obj);

switch ($type) {

case 'checkout.session.completed': {
    $uid = (int) ($obj['client_reference_id'] ?? ($obj['metadata']['user_id'] ?? 0));
    $plan = $obj['metadata']['plan_key'] ?? 'pro';
    $customerId = $obj['customer'] ?? null;
    $subId = $obj['subscription'] ?? null;
    if ($uid) {
        $pdo->prepare(
            "INSERT INTO subscriptions (user_id, plan_key, stripe_customer_id, stripe_subscription_id, status, current_period_end)
             VALUES (?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))
             ON DUPLICATE KEY UPDATE plan_key = VALUES(plan_key), stripe_customer_id = VALUES(stripe_customer_id), stripe_subscription_id = VALUES(stripe_subscription_id), status = 'active'"
        )->execute([$uid, $plan, $customerId, $subId]);
    }
    break;
}

case 'customer.subscription.updated':
case 'customer.subscription.created': {
    $subId = $obj['id'] ?? '';
    $status = $obj['status'] ?? 'active';
    $periodEnd = !empty($obj['current_period_end']) ? date('Y-m-d H:i:s', (int) $obj['current_period_end']) : null;
    $cancelAtEnd = !empty($obj['cancel_at_period_end']) ? 1 : 0;
    $pdo->prepare(
        "UPDATE subscriptions SET status = ?, current_period_end = ?, cancel_at_period_end = ? WHERE stripe_subscription_id = ?"
    )->execute([$status, $periodEnd, $cancelAtEnd, $subId]);
    break;
}

case 'customer.subscription.deleted': {
    $subId = $obj['id'] ?? '';
    $pdo->prepare("UPDATE subscriptions SET status = 'canceled', plan_key = 'decouverte' WHERE stripe_subscription_id = ?")
        ->execute([$subId]);
    break;
}

case 'invoice.payment_failed': {
    $customerId = $obj['customer'] ?? '';
    $pdo->prepare(
        "UPDATE subscriptions SET status = 'grace_period', grace_period_end = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE stripe_customer_id = ?"
    )->execute([$customerId]);
    // Notif Telegram super_admin (best-effort)
    @shell_exec("/root/bin/notify --project ocre --priority warning --title 'Stripe payment_failed' --body 'customer=" . escapeshellarg($customerId) . "' >/dev/null 2>&1");
    break;
}

case 'invoice.payment_succeeded': {
    $customerId = $obj['customer'] ?? '';
    $pdo->prepare(
        "UPDATE subscriptions SET status = 'active', grace_period_end = NULL WHERE stripe_customer_id = ?"
    )->execute([$customerId]);
    break;
}

}

echo json_encode(['ok' => true, 'event_type' => $type]);
