<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Google init redirect
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('google');
$state = oauth_state_set('google');
if ($env['_mock']) {
    // Mode mock : redirect direct callback avec fake code
    header('Location: ' . oauth_redirect_uri('google') . '?code=MOCK_CODE&state=' . $state);
    exit;
}
$params = http_build_query([
    'client_id' => $env['GOOGLE_CLIENT_ID'] ?? '',
    'redirect_uri' => oauth_redirect_uri('google'),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'prompt' => 'select_account',
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
