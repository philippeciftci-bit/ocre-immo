<?php
// M113 — GET /api/i18n/get_strings.php?lang=fr|en|es|ar
// Retourne le JSON traduction. Fallback FR si key manquante (cote front).

header('Content-Type: application/json; charset=utf-8');
$lang = strtolower($_GET['lang'] ?? 'fr');
if (!in_array($lang, ['fr', 'en', 'es', 'ar'])) $lang = 'fr';

$path = '/opt/ocre-app/i18n/' . $lang . '.json';
if (!is_readable($path)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Lang file not found']);
    exit;
}
echo file_get_contents($path);
