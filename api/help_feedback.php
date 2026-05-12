<?php
// M/2026/05/13/12 — Aide FAQ v2 : collecte feedback 👍/👎 utilisateur en DB + stats articles populaires.
// Table : help_feedback (id, article_id varchar(64), user_id int nullable, type enum, tenant_slug varchar(64) nullable, created_at).
// Endpoints :
//   POST ?action=submit body {article_id, type, user_id?}  -> insert (rate-limit 1/article/user/24h)
//   GET  ?action=stats                                       -> {article_id: {up, down}}
//   GET  ?action=top&limit=3                                 -> top N articles par up - down
require_once __DIR__ . '/db.php';
setCorsHeaders();

$meta = new PDO('mysql:host=' . DB_HOST . ';dbname=ocre_meta;charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Migration idempotente.
try {
    $meta->exec("CREATE TABLE IF NOT EXISTS help_feedback (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id VARCHAR(64) NOT NULL,
        user_id INT UNSIGNED NULL,
        type ENUM('up','down') NOT NULL,
        tenant_slug VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_article (article_id),
        KEY idx_user_article (user_id, article_id),
        KEY idx_created (created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$action = $_GET['action'] ?? 'submit';

if ($action === 'submit') {
    $input = getInput();
    $articleId = trim((string)($input['article_id'] ?? ''));
    $type = (string)($input['type'] ?? '');
    if (!$articleId || !preg_match('/^[a-z0-9_-]{1,64}$/i', $articleId)) jsonError('article_id invalide', 400);
    if (!in_array($type, ['up','down'], true)) jsonError('type invalide (up|down)', 400);

    // user_id : optionnel via currentUser() (no throw).
    $uid = null;
    $u = currentUser();
    if ($u && isset($u['id'])) $uid = (int)$u['id'];

    // Rate-limit : 1 vote par article + user (ou IP si anon) par 24h.
    $key = $uid ? ('uid:' . $uid) : ('ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $st = $meta->prepare("SELECT id FROM help_feedback WHERE article_id = ? AND " .
        ($uid ? "user_id = ?" : "user_id IS NULL AND tenant_slug = ?") .
        " AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
    $st->execute([$articleId, $uid ?: $key]);
    if ($st->fetch()) jsonOk(['ok' => true, 'duplicate' => true]);

    $tenant = $_SERVER['HTTP_HOST'] ?? null;
    if ($tenant) $tenant = strtolower(preg_replace('/^([a-z0-9-]+)\..*$/', '$1', $tenant));

    $ins = $meta->prepare("INSERT INTO help_feedback (article_id, user_id, type, tenant_slug, created_at)
        VALUES (?, ?, ?, ?, NOW())");
    $ins->execute([$articleId, $uid, $type, $tenant]);
    jsonOk(['ok' => true, 'id' => (int)$meta->lastInsertId()]);
}

if ($action === 'stats') {
    $st = $meta->query("SELECT article_id, type, COUNT(*) AS n FROM help_feedback GROUP BY article_id, type");
    $rows = $st->fetchAll();
    $stats = [];
    foreach ($rows as $r) {
        $a = $r['article_id'];
        if (!isset($stats[$a])) $stats[$a] = ['up' => 0, 'down' => 0];
        $stats[$a][$r['type']] = (int)$r['n'];
    }
    jsonOk(['stats' => $stats]);
}

if ($action === 'top') {
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 3)));
    $st = $meta->prepare("SELECT article_id,
        SUM(type='up') AS ups,
        SUM(type='down') AS downs,
        SUM(type='up') - SUM(type='down') AS score
        FROM help_feedback
        GROUP BY article_id
        HAVING score > 0
        ORDER BY score DESC, ups DESC
        LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $top = $st->fetchAll();
    foreach ($top as &$r) { $r['ups'] = (int)$r['ups']; $r['downs'] = (int)$r['downs']; $r['score'] = (int)$r['score']; }
    jsonOk(['top' => $top]);
}

jsonError('Action inconnue', 404);
