<?php
// M107 — GET /api/billing/status.php
// Retourne subscription + factures recentes + flags features.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../_session.php';
require_once __DIR__ . '/../lib/permissions.php';
require_once __DIR__ . '/../lib/billing_channel.php';

setCorsHeaders();
$user = getCurrentUserDualMode();
if (!$user || !empty($user['_no_tenant_user']) || !empty($user['_tenant_mismatch'])) jsonError('Non authentifie', 401);
requireRole(['owner', 'manager'], $user);
$tenant = $user['slug'];

bch_ensure_schema();
$sub = bch_get_subscription($tenant);
$can = bch_channel_can_publish($tenant);
$invoices = bch_get_invoices($tenant, 12);

jsonResponse([
    'ok' => true,
    'plans' => [
        'pro' => [
            'name' => 'Ocre Pro',
            'price_monthly_eur' => 49,
            'active' => true,    // toujours actif (base)
        ],
        'channel_premium' => [
            'name' => 'Channel Premium',
            'price_monthly_eur' => CHANNEL_PACK_PRICE_EUR,
            'active' => $can['can_publish'],
            'pricing_mode' => CHANNEL_PRICING_MODE,
            'until' => $can['until'] ?? null,
            'cancel_at_period_end' => $sub ? (bool) $sub['cancel_at_period_end'] : false,
            'reason_inactive' => $can['can_publish'] ? null : ($can['reason'] ?? null),
        ],
    ],
    'subscription' => $sub ?: null,
    'invoices' => $invoices,
]);
