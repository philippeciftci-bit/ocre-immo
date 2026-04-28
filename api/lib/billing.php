<?php
// M/2026/04/29/2 — Helper billing : limites par plan + Stripe wrapper léger.
if (!function_exists('billing_ensure_schema')) {

function billing_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            plan_key VARCHAR(50) NOT NULL DEFAULT 'decouverte',
            stripe_customer_id VARCHAR(255) NULL,
            stripe_subscription_id VARCHAR(255) NULL,
            status ENUM('active','past_due','canceled','trialing','incomplete','grace_period') NOT NULL DEFAULT 'active',
            current_period_start DATETIME NULL,
            current_period_end DATETIME NULL,
            cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
            grace_period_end DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user (user_id),
            INDEX idx_stripe_sub (stripe_subscription_id),
            INDEX idx_status (status)
        ) CHARACTER SET utf8mb4");
    } catch (Throwable $e) {}
    $done = true;
}

function billing_get_user_plan(int $userId): array {
    billing_ensure_schema();
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $st = $pdo->prepare("SELECT * FROM subscriptions WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $sub = $st->fetch();
        if ($sub) return $sub;
    } catch (Throwable $e) {}
    return ['plan_key' => 'decouverte', 'status' => 'active', 'current_period_end' => null, 'stripe_customer_id' => null];
}

function billing_get_user_plan_limits(int $userId): array {
    $sub = billing_get_user_plan($userId);
    $plan = ($sub['status'] === 'active' || $sub['status'] === 'trialing' || $sub['status'] === 'grace_period')
        ? ($sub['plan_key'] ?? 'decouverte')
        : 'decouverte';
    $limits = [
        'decouverte' => ['max_dossiers' => 10, 'max_photos_per_dossier' => 5, 'wsc_allowed' => false, 'scan_web' => false, 'export_pdf' => false],
        'pro' => ['max_dossiers' => 100, 'max_photos_per_dossier' => 20, 'wsc_allowed' => true, 'scan_web' => true, 'export_pdf' => true],
        'equipe' => ['max_dossiers' => 9999, 'max_photos_per_dossier' => 30, 'wsc_allowed' => true, 'scan_web' => true, 'export_pdf' => true],
    ];
    $base = $limits[$plan] ?? $limits['decouverte'];
    $base['plan'] = $plan;
    $base['status'] = $sub['status'] ?? 'active';
    $base['current_period_end'] = $sub['current_period_end'] ?? null;
    return $base;
}

function billing_load_stripe_env(): array {
    $envFile = '/root/.secrets/stripe.env';
    if (!is_readable($envFile)) return ['_configured' => false];
    $vars = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if (!$l || $l[0] === '#') continue;
        if (preg_match('/^([A-Z_]+)=(.+)$/', $l, $m)) $vars[$m[1]] = trim($m[2]);
    }
    $vars['_configured'] = !empty($vars['STRIPE_SECRET_KEY']) && !empty($vars['STRIPE_PUBLIC_KEY']);
    return $vars;
}

function billing_stripe_call(string $method, string $path, array $params = []): array {
    $env = billing_load_stripe_env();
    if (empty($env['_configured'])) return ['_stub' => true, 'error' => 'stripe_not_configured'];
    $url = 'https://api.stripe.com/v1' . $path;
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $env['STRIPE_SECRET_KEY'] . ':',
        CURLOPT_TIMEOUT => 10,
    ];
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
    if ($resp === false) return ['_error' => 'curl_failed'];
    $data = json_decode($resp, true) ?: [];
    $data['_http_code'] = $code;
    return $data;
}

}
