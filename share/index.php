<?php
// V20 Mission B — page publique lecture seule du dossier partagé via token.
// URL : https://<wsp-slug>.ocre.immo/share/<token>
// M/2026/05/01/4 — Refonte radicale : redirect 302 vers /s/<token> qui sert shared.html
// (rendu visuel immersif avec photo cover + grid photos + design ocre). L'ancien rendu
// HTML minimaliste etait une regression bug critique pour les clients reels (capture
// IMG_2704). Les flags hide_* sont stockes en DB row shared_links V20 et lus par
// api/share.php?action=get_shared (anti-contournement URL).

require_once __DIR__ . '/../api/lib/router.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
if (!$token || strlen($token) < 32) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;text-align:center'><h1>Lien invalide</h1></body></html>";
    exit;
}

// Validation row + expiration (incrementation viewed_count est faite par api/share.php?action=get_shared
// quand shared.html fait son fetch initial).
try {
    // M/2026/05/01/5 — accepte expires_at NULL (liens permanents).
    $st = pdo_meta()->prepare(
        "SELECT id FROM shared_links WHERE token = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1"
    );
    $st->execute([$token]);
    $row = $st->fetch();
} catch (Throwable $e) { $row = null; }

if (!$row) {
    http_response_code(410);
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>Lien expiré</title></head>";
    echo "<body style='font-family:DM Sans,sans-serif;padding:40px;text-align:center;background:#F0E8D8;color:#5C3B1E'>";
    echo "<h1 style=\"font-family:Cormorant Garamond,serif;font-size:32px;margin-bottom:10px\">Lien expiré</h1>";
    echo "<p style='color:#8B7F6E'>Ce lien n'est plus valide.</p>";
    echo "</body></html>";
    exit;
}

// Redirect 302 vers /s/<token> qui sert shared.html (rendu visuel via fetch /api/share.php).
$host = $_SERVER['HTTP_HOST'] ?? 'app.ocre.immo';
header('Location: https://' . $host . '/s/' . $token, true, 302);
exit;
