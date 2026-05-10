<?php
// M_OCRE_AGENT_SIGNUP_V1 — OAuth Google init redirect
require_once __DIR__ . '/../_lib.php';
$env = oauth_load_env('google');
$state = oauth_state_set('google');
// M_OAUTH_DIAGNOSTIC_FIX — capture target app cible via ?app=<slug> dans cookie pour redirect post-login
$appTarget = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['app'] ?? 'agent')));
setcookie('oauth_app_target', $appTarget, ['expires'=>time()+600,'path'=>'/','domain'=>'.ocre.immo','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
if ($env['_mock']) {
    // Mode mock : redirect vers page consent fake locale (style Google)
    header('Location: /api/oauth/mock/consent.php?provider=google&state=' . $state);
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
