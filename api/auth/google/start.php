<?php
require_once __DIR__ . '/../../auth_helpers.php';
ff_start_session();
$cfg = ff_config()['google'];
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$params = http_build_query([
    'client_id' => $cfg['client_id'],
    'redirect_uri' => $cfg['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
