<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Facebook init
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('facebook');
$state = oauth_state_set('facebook');
if ($env['_mock']) {
    header('Location: ' . oauth_redirect_uri('facebook') . '?code=MOCK_CODE&state=' . $state);
    exit;
}
$params = http_build_query([
    'client_id' => $env['FB_APP_ID'] ?? '',
    'redirect_uri' => oauth_redirect_uri('facebook'),
    'response_type' => 'code',
    'scope' => 'email public_profile',
    'state' => $state,
]);
header('Location: https://www.facebook.com/v18.0/dialog/oauth?' . $params);
exit;
