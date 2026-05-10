<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Apple init (Sign In With Apple)
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('apple');
$state = oauth_state_set('apple');
if ($env['_mock']) {
    header('Location: ' . oauth_redirect_uri('apple') . '?code=MOCK_CODE&state=' . $state);
    exit;
}
$params = http_build_query([
    'client_id' => $env['APPLE_SERVICE_ID'] ?? '',
    'redirect_uri' => oauth_redirect_uri('apple'),
    'response_type' => 'code',
    'scope' => 'name email',
    'state' => $state,
    'response_mode' => 'form_post',
]);
header('Location: https://appleid.apple.com/auth/authorize?' . $params);
exit;
