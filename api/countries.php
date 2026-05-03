<?php
// V17.10 — pays administrables. Table countries_config + endpoints public (list) + admin (CRUD).
require_once __DIR__ . '/db.php';
setCorsHeaders();

function ensureCountriesSchema() {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS countries_config (
        code CHAR(2) PRIMARY KEY,
        name VARCHAR(60),
        flag_emoji VARCHAR(10),
        currency VARCHAR(4),
        devise_symbol VARCHAR(10),
        phone_prefix VARCHAR(6),
        enabled TINYINT NOT NULL DEFAULT 1,
        sort_order INT DEFAULT 100
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // Seed initial si table vide (ne réécrit jamais après).
    $r = db()->query("SELECT COUNT(*) c FROM countries_config")->fetch();
    if ((int)($r['c'] ?? 0) === 0) {
        $seed = [
            ['MA','Maroc','🇲🇦','MAD','MAD','212',1,10],
            ['FR','France','🇫🇷','EUR','€','33',1,20],
            ['ES','Espagne','🇪🇸','EUR','€','34',0,30],
            ['IT','Italie','🇮🇹','EUR','€','39',0,40],
            ['PT','Portugal','🇵🇹','EUR','€','351',0,50],
            ['DE','Allemagne','🇩🇪','EUR','€','49',0,60],
            ['BE','Belgique','🇧🇪','EUR','€','32',0,70],
            ['CH','Suisse','🇨🇭','CHF','CHF','41',0,80],
            ['LU','Luxembourg','🇱🇺','EUR','€','352',0,90],
            ['GB','Royaume-Uni','🇬🇧','GBP','£','44',0,100],
            ['US','États-Unis','🇺🇸','USD','$','1',0,110],
            ['CA','Canada','🇨🇦','CAD','$CA','1',0,120],
            ['AE','Émirats','🇦🇪','AED','AED','971',0,130],
            ['SA','Arabie Saoudite','🇸🇦','SAR','SAR','966',0,140],
            ['QA','Qatar','🇶🇦','QAR','QAR','974',0,150],
            ['SG','Singapour','🇸🇬','SGD','S$','65',0,160],
            ['HK','Hong Kong','🇭🇰','HKD','HK$','852',0,170],
            ['JP','Japon','🇯🇵','JPY','¥','81',0,180],
            ['TH','Thaïlande','🇹🇭','THB','฿','66',0,190],
        ];
        $stmt = db()->prepare("INSERT IGNORE INTO countries_config (code,name,flag_emoji,currency,devise_symbol,phone_prefix,enabled,sort_order) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($seed as $row) $stmt->execute($row);
    }
    $done = true;
}

function verifyAdminHeader() {
    // Cohérent avec settings.php : X-Admin-Code header + session admin.
    $u = requireAdmin();
    $code = $_SERVER['HTTP_X_ADMIN_CODE'] ?? '';
    if (!$code) jsonError('Admin code requis', 403);
    $expected = getSetting('admin_code', '');
    if (!$expected || $code !== $expected) jsonError('Admin code invalide', 403);
    return $u;
}

ensureCountriesSchema();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input = getInput();

switch ($action) {
    case 'list': {
        // Public — enabled only (alimente l'app frontend).
        $rows = db()->query("SELECT code,name,flag_emoji,currency,devise_symbol,phone_prefix,sort_order FROM countries_config WHERE enabled=1 ORDER BY sort_order, code")->fetchAll();
        jsonOk(['countries' => $rows]);
    }
    case 'list_all': {
        // Admin — tous (enabled + disabled).
        verifyAdminHeader();
        $rows = db()->query("SELECT * FROM countries_config ORDER BY sort_order, code")->fetchAll();
        jsonOk(['countries' => $rows]);
    }
    case 'toggle': {
        verifyAdminHeader();
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        if (!preg_match('/^[A-Z]{2}$/', $code)) jsonError('code invalide');
        $stmt = db()->prepare("UPDATE countries_config SET enabled=? WHERE code=?");
        $stmt->execute([$enabled, $code]);
        jsonOk(['code' => $code, 'enabled' => (bool)$enabled, 'updated' => (int)$stmt->rowCount()]);
    }
    case 'update': {
        verifyAdminHeader();
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $code)) jsonError('code invalide');
        $stmt = db()->prepare("INSERT INTO countries_config (code,name,flag_emoji,currency,devise_symbol,phone_prefix,sort_order,enabled)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            name=VALUES(name), flag_emoji=VALUES(flag_emoji), currency=VALUES(currency),
            devise_symbol=VALUES(devise_symbol), phone_prefix=VALUES(phone_prefix), sort_order=VALUES(sort_order), enabled=VALUES(enabled)");
        $stmt->execute([
            $code,
            substr((string)($input['name'] ?? ''), 0, 60),
            substr((string)($input['flag_emoji'] ?? ''), 0, 10),
            substr((string)($input['currency'] ?? ''), 0, 4),
            substr((string)($input['devise_symbol'] ?? ''), 0, 10),
            substr((string)($input['phone_prefix'] ?? ''), 0, 6),
            (int)($input['sort_order'] ?? 100),
            !empty($input['enabled']) ? 1 : 0,
        ]);
        jsonOk(['updated' => true, 'code' => $code]);
    }
    default:
        jsonError('Action inconnue', 404);
}
