<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Apple callback (Sign In With Apple form_post)
// NOTE prod : Apple OAuth requiert JWT client_secret signe avec p8 private key + APPLE_TEAM_ID + APPLE_KEY_ID.
// Implementation full prod = M_OCRE_AGENT_SIGNUP_V1-2 (necessite JWT ES256 + lib firebase/php-jwt ou impl manuelle).
// Mode actuel : mock fonctionnel + skeleton prod a completer une fois credentials Apple disponibles.
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('apple');
$code = $_POST['code'] ?? ($_GET['code'] ?? '');
$state = $_POST['state'] ?? ($_GET['state'] ?? '');
if (!$code) { http_response_code(400); echo "code requis"; exit; }
if (!oauth_state_check('apple', $state)) { http_response_code(400); echo "state invalide"; exit; }

if ($env['_mock']) {
    $email = 'philippe.ciftci@gmail.com';
    $providerUserId = 'mock_apple_user_' . hash('sha256', $email);
    $firstName = 'Philippe'; $lastName = 'Ciftci';
} else {
    // PROD STUB : Apple necessite generation client_secret JWT ES256 sign avec p8 file
    // Voir M_OCRE_AGENT_SIGNUP_V1-2 pour implementation full
    http_response_code(501);
    echo "Apple OAuth prod non implemente (necessite JWT ES256 + p8 sign). Placeholder mock seulement.";
    exit;
}

$uid = oauth_upsert_user('apple', $providerUserId, $email, $firstName, $lastName);
oauth_complete_login($uid, $email);
