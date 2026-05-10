<?php
// M107 — Helper Channel Premium billing (cohabite avec lib/billing.php existant pour les plans app).
// Tables dediees billing_subscriptions + billing_invoices pour Channel Premium 99EUR/mois HT pack tout-inclus.
// Architecture flexible : si Philippe veut switch vers pay-per-portail (10EUR/mois/portail) plus tard,
// changer la const PRICING_MODE et adapter ensure_active_for_publish() sans refacto.

require_once __DIR__ . '/billing.php';

const CHANNEL_PRICING_MODE = 'pack'; // 'pack' (99EUR tout-inclus) ou 'per_portal' (10EUR/portail)
const CHANNEL_PACK_PRICE_EUR = 99;
const CHANNEL_PER_PORTAL_PRICE_EUR = 10;
const CHANNEL_STRIPE_PRICE_ID = 'price_channel_monthly'; // a remplacer par vrai price_xxx Stripe quand cree
const CHANNEL_STRIPE_PRODUCT_ID = 'prod_ocre_channel';
const CHANNEL_MOCK_BASE = 'http://127.0.0.1:8889';

function bch_meta_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../db.php';
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function bch_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $db = bch_meta_pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS billing_subscriptions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        stripe_customer_id VARCHAR(64) NULL,
        stripe_subscription_id VARCHAR(64) NULL,
        plan_pro_active TINYINT(1) DEFAULT 1,
        plan_channel_active TINYINT(1) DEFAULT 0,
        channel_features_until DATETIME NULL,
        trial_ends_at DATETIME NULL,
        current_period_start DATETIME NULL,
        current_period_end DATETIME NULL,
        cancel_at_period_end TINYINT(1) DEFAULT 0,
        status ENUM('active','past_due','canceled','trialing','incomplete') DEFAULT 'active',
        pricing_mode VARCHAR(16) DEFAULT 'pack',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant (tenant_slug),
        INDEX idx_user (user_id),
        INDEX idx_stripe_sub (stripe_subscription_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS billing_invoices (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_slug VARCHAR(64) NOT NULL,
        stripe_invoice_id VARCHAR(64) NULL,
        amount_eur DECIMAL(10,2),
        status VARCHAR(32),
        paid_at DATETIME NULL,
        pdf_url VARCHAR(512) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tenant (tenant_slug),
        INDEX idx_stripe (stripe_invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function bch_get_subscription(string $tenantSlug): ?array {
    bch_ensure_schema();
    $st = bch_meta_pdo()->prepare("SELECT * FROM billing_subscriptions WHERE tenant_slug = ? LIMIT 1");
    $st->execute([$tenantSlug]);
    return $st->fetch() ?: null;
}

// Verifie si le tenant peut publier sur les portails channel (plan_channel_active + period encore ouverte).
function bch_channel_can_publish(string $tenantSlug): array {
    $sub = bch_get_subscription($tenantSlug);
    if (!$sub) return ['can_publish' => false, 'reason' => 'no_subscription', 'plan_active' => false];
    if (!$sub['plan_channel_active']) return ['can_publish' => false, 'reason' => 'channel_not_active', 'plan_active' => false];
    if ($sub['status'] === 'canceled') return ['can_publish' => false, 'reason' => 'subscription_canceled', 'plan_active' => false];
    if ($sub['channel_features_until'] && strtotime($sub['channel_features_until']) < time()) {
        return ['can_publish' => false, 'reason' => 'features_expired', 'plan_active' => false];
    }
    return ['can_publish' => true, 'plan_active' => true, 'until' => $sub['channel_features_until']];
}

// Stripe stub : ce wrapper utilise le mock local en mode dev.
function bch_stripe_request(string $method, string $path, array $params = []): array {
    $env = billing_load_stripe_env();
    $useMock = empty($env['_configured']) || !empty($env['STRIPE_USE_MOCK']);
    $base = $useMock ? CHANNEL_MOCK_BASE . '/v1' : 'https://api.stripe.com/v1';
    $url = $base . $path;
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ];
    if (!$useMock && !empty($env['STRIPE_SECRET_KEY'])) {
        $opts[CURLOPT_USERPWD] = $env['STRIPE_SECRET_KEY'] . ':';
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } elseif ($params && $method === 'GET') {
        $url .= '?' . http_build_query($params);
    }
    $opts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = $resp ? (json_decode($resp, true) ?: []) : [];
    $data['_http_code'] = $code;
    $data['_mock'] = $useMock;
    return $data;
}

function bch_get_invoices(string $tenantSlug, int $limit = 20): array {
    bch_ensure_schema();
    $st = bch_meta_pdo()->prepare("SELECT * FROM billing_invoices WHERE tenant_slug = ? ORDER BY created_at DESC LIMIT ?");
    $st->bindValue(1, $tenantSlug);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
