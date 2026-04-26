<?php
// V20 phase 11 — endpoint public lecture seule pour widgets vitrine (api.ocre.immo).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=120');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/router.php';

function jout(array $d, int $code = 200) {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['t'] ?? ''));
$widget = $_GET['w'] ?? 'gallery';
if (!$slug) jout(['ok' => false, 'error' => 'tenant slug requis (?t=)'], 400);

$meta = pdo_meta();
$ws = $meta->prepare("SELECT * FROM workspaces WHERE slug = ? AND type = 'wsp' AND archived_at IS NULL LIMIT 1");
$ws->execute([$slug]);
$wsp = $ws->fetch();
if (!$wsp) jout(['ok' => false, 'error' => 'WSp introuvable'], 404);

$pdo = pdo_workspace('ocre_wsp_' . $slug);

function safe_query(PDO $pdo, string $sql, array $args = []): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

switch ($widget) {
case 'gallery': {
    $rows = safe_query($pdo,
        "SELECT b.id, b.titre, b.prix, b.type_bien, b.ville, b.surface, b.photo_principale
         FROM biens b
         JOIN published_to_vitrine p ON p.bien_id = b.id
         ORDER BY p.published_at DESC LIMIT 50");
    jout(['ok' => true, 'widget' => 'gallery', 'tenant' => $slug, 'items' => $rows]);
}

case 'search': {
    $q = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $q . '%';
    $rows = safe_query($pdo,
        "SELECT b.id, b.titre, b.prix, b.type_bien, b.ville, b.surface, b.photo_principale
         FROM biens b
         JOIN published_to_vitrine p ON p.bien_id = b.id
         WHERE b.titre LIKE ? OR b.ville LIKE ? OR b.type_bien LIKE ?
         ORDER BY p.published_at DESC LIMIT 50",
        [$like, $like, $like]);
    jout(['ok' => true, 'widget' => 'search', 'tenant' => $slug, 'query' => $q, 'items' => $rows]);
}

case 'detail': {
    $id = (int)($_GET['id'] ?? 0);
    $rows = safe_query($pdo,
        "SELECT b.* FROM biens b JOIN published_to_vitrine p ON p.bien_id = b.id WHERE b.id = ? LIMIT 1",
        [$id]);
    if (!$rows) jout(['ok' => false, 'error' => 'bien non publié ou introuvable'], 404);
    jout(['ok' => true, 'widget' => 'detail', 'tenant' => $slug, 'bien' => $rows[0]]);
}

default:
    jout(['ok' => false, 'error' => 'widget inconnu (gallery|search|detail)'], 400);
}
