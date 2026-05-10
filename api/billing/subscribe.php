<?php
// M107 — POST /api/billing/subscribe.php {plan: 'channel_premium'}
// Cree Customer + Subscription Stripe (ou stub) + retourne checkout URL.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/billing_channel.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner'], $user);
$tenant = $user['slug'];
$userId = (int) $user['user_id'];
$email = $user['email'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

$d = getInput();
$plan = $d['plan'] ?? 'channel_premium';
if ($plan !== 'channel_premium') jsonError('plan inconnu', 400);

bch_ensure_schema();

// Cree ou recupere Customer Stripe
$sub = bch_get_subscription($tenant);
$stripeCustId = $sub['stripe_customer_id'] ?? null;
if (!$stripeCustId) {
    $cust = bch_stripe_request('POST', '/customers', ['email' => $email, 'metadata[tenant_slug]' => $tenant, 'metadata[user_id]' => $userId]);
    if (empty($cust['id'])) jsonError('Stripe customer creation failed', 502, ['stripe_response' => $cust]);
    $stripeCustId = $cust['id'];
}

// Cree Checkout Session
$ck = bch_stripe_request('POST', '/checkout/sessions', [
    'customer' => $stripeCustId,
    'mode' => 'subscription',
    'success_url' => 'https://' . $tenant . '.ocre.immo/reglages-abonnement.html?success=1',
    'cancel_url' => 'https://' . $tenant . '.ocre.immo/reglages-abonnement.html?canceled=1',
    'line_items[0][price]' => CHANNEL_STRIPE_PRICE_ID,
    'line_items[0][quantity]' => 1,
    'metadata[tenant_slug]' => $tenant,
    'metadata[user_id]' => $userId,
    'metadata[plan]' => 'channel_premium',
]);
if (empty($ck['url'])) jsonError('Stripe checkout creation failed', 502, ['stripe_response' => $ck]);

// Upsert subscription draft
$db = bch_meta_pdo();
$db->prepare(
    "INSERT INTO billing_subscriptions (tenant_slug, user_id, stripe_customer_id, plan_pro_active, plan_channel_active, status)
     VALUES (?, ?, ?, 1, 0, 'incomplete')
     ON DUPLICATE KEY UPDATE stripe_customer_id=VALUES(stripe_customer_id)"
)->execute([$tenant, $userId, $stripeCustId]);

jsonResponse([
    'ok' => true,
    'checkout_url' => $ck['url'],
    'customer_id' => $stripeCustId,
    'session_id' => $ck['id'] ?? null,
    'mock' => !empty($ck['_mock']),
]);
