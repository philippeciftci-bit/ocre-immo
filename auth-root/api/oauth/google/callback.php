<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Google callback : exchange code → user → JWT cookie → redirect
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('google');
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
if (!$code) { http_response_code(400); echo "code requis"; exit; }
if (!oauth_state_check('google', $state)) { http_response_code(400); echo "state invalide"; exit; }

if ($env['_mock']) {
    // M_OAUTH_MOCK_ACCOUNT_PICKER — récupère email/first_name/last_name depuis consent page query string
    $email = strtolower(trim((string)($_GET['email'] ?? 'philippe.ciftci@gmail.com')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = 'philippe.ciftci@gmail.com';
    $firstName = trim((string)($_GET['first_name'] ?? ''));
    $lastName = trim((string)($_GET['last_name'] ?? ''));
    if (!$firstName && $email === 'philippe.ciftci@gmail.com') $firstName = 'Philippe';
    if (!$lastName && $email === 'philippe.ciftci@gmail.com') $lastName = 'Ciftci';
    $providerUserId = 'mock_google_' . hash('sha256', $email);
} else {
    // Exchange code → access_token via POST
    $postData = http_build_query([
        'client_id' => $env['GOOGLE_CLIENT_ID'],
        'client_secret' => $env['GOOGLE_CLIENT_SECRET'],
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => oauth_redirect_uri('google'),
    ]);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $tokenResp = json_decode(curl_exec($ch), true) ?: [];
    curl_close($ch);
    $accessToken = $tokenResp['access_token'] ?? '';
    if (!$accessToken) { http_response_code(500); echo "Token exchange failed"; exit; }
    // Fetch userinfo
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $info = json_decode(curl_exec($ch), true) ?: [];
    curl_close($ch);
    $email = $info['email'] ?? '';
    $providerUserId = (string)($info['sub'] ?? '');
    $firstName = $info['given_name'] ?? '';
    $lastName = $info['family_name'] ?? '';
    if (!$email || !$providerUserId) { http_response_code(500); echo "User info incomplete"; exit; }
}

$uid = oauth_upsert_user('google', $providerUserId, $email, $firstName, $lastName);
oauth_complete_login($uid, $email, 'google');
