<?php
// M107 — POST /api/billing/resume.php
// Annule la cancellation programmee (cancel_at_period_end=false).

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/billing_channel.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner'], $user);
$tenant = $user['slug'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('method', 405);

$sub = bch_get_subscription($tenant);
if (!$sub || empty($sub['stripe_subscription_id'])) jsonError('Aucune subscription active', 404);

$r = bch_stripe_request('POST', '/subscriptions/' . urlencode($sub['stripe_subscription_id']), [
    'cancel_at_period_end' => 'false',
]);
if (($r['_http_code'] ?? 0) >= 400) jsonError('Stripe resume failed', 502, ['stripe_response' => $r]);

bch_meta_pdo()->prepare("UPDATE billing_subscriptions SET cancel_at_period_end=0 WHERE tenant_slug=?")->execute([$tenant]);

jsonResponse(['ok' => true, 'resumed' => true]);
