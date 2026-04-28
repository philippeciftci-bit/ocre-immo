<?php
// M/2026/04/29/2 — Endpoint billing : statut, portal, checkout, cancel.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/billing.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? 'status';
$input = getInput();

billing_ensure_schema();

switch ($action) {

case 'status': {
    $plan = billing_get_user_plan($uid);
    $limits = billing_get_user_plan_limits($uid);
    jsonOk(['plan' => $plan, 'limits' => $limits]);
}

case 'create_checkout_session': {
    $newPlan = $input['plan'] ?? 'pro';
    if (!in_array($newPlan, ['pro', 'equipe'], true)) jsonError('plan invalide (pro|equipe)', 400);
    $env = billing_load_stripe_env();
    if (empty($env['_configured'])) {
        jsonOk([
            '_stub' => true,
            'message' => 'Stripe non configuré. Mode stub : abonnement créé directement en base.',
            'redirect' => null,
        ]);
        // En mode stub, on simule directement l'abonnement.
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare(
            "INSERT INTO subscriptions (user_id, plan_key, status, current_period_end) VALUES (?, ?, 'active', DATE_ADD(NOW(), INTERVAL 30 DAY))
             ON DUPLICATE KEY UPDATE plan_key = VALUES(plan_key), status = 'active', current_period_end = VALUES(current_period_end)"
        )->execute([$uid, $newPlan]);
        exit;
    }
    $priceId = $newPlan === 'pro' ? ($env['STRIPE_PRICE_PRO'] ?? '') : ($env['STRIPE_PRICE_EQUIPE'] ?? '');
    if (!$priceId) jsonError('price_id manquant pour ' . $newPlan, 500);
    $session = billing_stripe_call('POST', '/checkout/sessions', [
        'mode' => 'subscription',
        'payment_method_types[]' => 'card',
        'line_items[0][price]' => $priceId,
        'line_items[0][quantity]' => 1,
        'customer_email' => $user['email'] ?? '',
        'success_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'app.ocre.immo') . '/preferences?billing=success',
        'cancel_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'app.ocre.immo') . '/preferences?billing=cancel',
        'client_reference_id' => (string) $uid,
        'metadata[user_id]' => (string) $uid,
        'metadata[plan_key]' => $newPlan,
    ]);
    if (empty($session['url'])) jsonError('Stripe checkout failed: ' . substr(json_encode($session), 0, 200), 500);
    jsonOk(['url' => $session['url']]);
}

case 'create_portal_session': {
    $env = billing_load_stripe_env();
    if (empty($env['_configured'])) jsonOk(['_stub' => true, 'url' => null, 'message' => 'Stripe non configuré (mode stub)']);
    $sub = billing_get_user_plan($uid);
    if (empty($sub['stripe_customer_id'])) jsonError('Aucun abonnement Stripe actif', 404);
    $portal = billing_stripe_call('POST', '/billing_portal/sessions', [
        'customer' => $sub['stripe_customer_id'],
        'return_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'app.ocre.immo') . '/preferences',
    ]);
    if (empty($portal['url'])) jsonError('Stripe portal failed', 500);
    jsonOk(['url' => $portal['url']]);
}

case 'cancel': {
    $sub = billing_get_user_plan($uid);
    if (empty($sub['stripe_subscription_id'])) {
        // Mode stub : direct cancel
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare("UPDATE subscriptions SET cancel_at_period_end = 1 WHERE user_id = ?")->execute([$uid]);
        jsonOk(['ok' => true, '_stub' => true]);
    }
    $resp = billing_stripe_call('POST', '/subscriptions/' . $sub['stripe_subscription_id'], [
        'cancel_at_period_end' => 'true',
    ]);
    jsonOk(['ok' => true, 'subscription' => $resp]);
}

default:
    jsonError('Action inconnue (status | create_checkout_session | create_portal_session | cancel)', 400);
}
