<?php
// M_OCRE_PARCOURS_V4 — GET /api/me-modules.php
// Retourne modules actifs pour l'utilisateur connecte (cookie ocre_jwt requis)
require_once __DIR__ . '/../lib/auth_db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/user_modules.php';

auth_cors_allow();
um_ensure_schema();

$token = $_COOKIE['ocre_jwt'] ?? '';
if (!$token) auth_send_json(['ok'=>false,'error'=>'no_jwt'], 401);
$r = jwt_decode($token, true);
if (!$r['ok']) auth_send_json(['ok'=>false,'error'=>$r['error']], 401);
$userId = (int) $r['claims']['sub'];

auth_send_json(['ok'=>true, 'user_id'=>$userId, 'modules'=>um_list($userId)]);
