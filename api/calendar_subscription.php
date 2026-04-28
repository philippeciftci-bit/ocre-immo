<?php
// M/2026/04/28/57 — Génère/régénère un token signé pour subscription calendrier .ics.
require_once __DIR__ . '/db.php';
setCorsHeaders();

$user = requireAuth();
$uid = (int) $user['id'];
$action = $_GET['action'] ?? 'get';

$keyFile = '/root/.secrets/ocre_dev_key';
if (!is_readable($keyFile)) jsonError('Configuration manquante', 500);
$key = trim((string) file_get_contents($keyFile));

$sig = substr(hash_hmac('sha256', "calsub:{$uid}", $key), 0, 32);
$token = $uid . '.' . $sig;

$host = $_SERVER['HTTP_HOST'] ?? 'zefk.ocre.immo';
$url = "https://{$host}/api/event_ics.php?sub_token=" . urlencode($token);

jsonOk(['subscription_url' => $url, 'token' => $token]);
