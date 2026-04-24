<?php
// V20 demo proof — one-shot IP-whitelist. V23 : inclut signature HMAC des photos (même
// logique que clients.php → les URLs de retour sont prêtes à être servies par image.php).
require_once __DIR__ . '/db.php';
@require_once __DIR__ . '/_image_hmac.php';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '46.225.215.148') { http_response_code(403); exit('Forbidden'); }

function signPhotoUrl_proof(string $raw): string {
    if (!defined('IMAGE_HMAC_SECRET')) return $raw;
    $path = $raw;
    if (strpos($raw, '/api/image.php?path=') === 0) {
        $q = parse_url($raw, PHP_URL_QUERY) ?: '';
        parse_str($q, $qs);
        $path = $qs['path'] ?? '';
    } elseif (preg_match('#^https?://#i', $raw)) return $raw;
    $path = ltrim((string) $path, '/');
    if (!$path) return $raw;
    $expires = time() + (defined('IMAGE_HMAC_TTL') ? IMAGE_HMAC_TTL : 7200);
    $t = hash_hmac('sha256', $path . '|' . $expires, IMAGE_HMAC_SECRET);
    return '/api/image.php?path=' . rawurlencode($path) . '&t=' . $t . '&e=' . $expires;
}

$ids = $_GET['ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $ids)));
if (!$ids) { http_response_code(400); exit('ids requis'); }
$in = implode(',', array_map('intval', $ids));
$st = db()->query("SELECT id, projet, archived, is_draft, is_investisseur, data FROM clients WHERE id IN ($in)");
$out = [];
foreach ($st as $r) {
    $d = json_decode($r['data'] ?? '{}', true) ?: [];
    $d['id'] = (int) $r['id'];
    $d['archived'] = (bool) (int) $r['archived'];
    $d['is_draft'] = (bool) (int) $r['is_draft'];
    $d['projet'] = $r['projet'];
    $d['is_investisseur'] = (bool) (int) $r['is_investisseur'];
    if (isset($d['bien']['photos']) && is_array($d['bien']['photos'])) {
        foreach ($d['bien']['photos'] as $i => $raw) {
            if (is_string($raw)) $d['bien']['photos'][$i] = signPhotoUrl_proof($raw);
        }
    }
    $out[] = $d;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'clients' => $out], JSON_UNESCAPED_UNICODE);
