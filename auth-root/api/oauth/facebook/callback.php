<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Facebook callback : exchange code → user → JWT cookie
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('facebook');
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
if (!$code) { http_response_code(400); echo "code requis"; exit; }
if (!oauth_state_check('facebook', $state)) { http_response_code(400); echo "state invalide"; exit; }

if ($env['_mock']) {
    // M_OAUTH_MOCK_ACCOUNT_PICKER — récupère depuis consent picker
    $email = strtolower(trim((string)($_GET['email'] ?? 'philippe.ciftci@gmail.com')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'philippe.ciftci@gmail.com';
    $firstName = trim((string)($_GET['first_name'] ?? ''));
    $lastName = trim((string)($_GET['last_name'] ?? ''));
    if (!$firstName && $email === 'philippe.ciftci@gmail.com') $firstName = 'Philippe';
    if (!$lastName && $email === 'philippe.ciftci@gmail.com') $lastName = 'Ciftci';
    $providerUserId = 'mock_facebook_' . hash('sha256', $email);
} else {
    // Exchange code → access_token GET
    $params = http_build_query([
        'client_id' => $env['FB_APP_ID'],
        'client_secret' => $env['FB_APP_SECRET'],
        'code' => $code,
        'redirect_uri' => oauth_redirect_uri('facebook'),
    ]);
    $ch = curl_init('https://graph.facebook.com/v18.0/oauth/access_token?' . $params);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $tokenResp = json_decode(curl_exec($ch), true) ?: [];
    curl_close($ch);
    $accessToken = $tokenResp['access_token'] ?? '';
    if (!$accessToken) { http_response_code(500); echo "Token exchange failed"; exit; }
    // Fetch user info
    $ch = curl_init('https://graph.facebook.com/me?fields=id,email,first_name,last_name&access_token=' . urlencode($accessToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $info = json_decode(curl_exec($ch), true) ?: [];
    curl_close($ch);
    $email = $info['email'] ?? '';
    $providerUserId = (string)($info['id'] ?? '');
    $firstName = $info['first_name'] ?? '';
    $lastName = $info['last_name'] ?? '';
    if (!$email || !$providerUserId) { http_response_code(500); echo "User info incomplete"; exit; }
}

$uid = oauth_upsert_user('facebook', $providerUserId, $email, $firstName, $lastName);
oauth_complete_login($uid, $email, 'facebook');
