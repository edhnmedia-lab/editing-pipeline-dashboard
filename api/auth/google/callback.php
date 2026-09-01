<?php
require_once __DIR__ . '/../../auth_helpers.php';
ff_start_session();
$cfg = ff_config();
$appBase = $cfg['app']['base_url'];

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';

if (!$state || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in failed (state mismatch). Please try again.'));
    exit;
}
unset($_SESSION['oauth_state']);

if (!$code) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in was cancelled.'));
    exit;
}

$g = $cfg['google'];
$tokenData = json_decode(ff_http_post_form('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $g['client_id'],
    'client_secret' => $g['client_secret'],
    'redirect_uri' => $g['redirect_uri'],
    'grant_type' => 'authorization_code',
]), true);

if (!isset($tokenData['access_token'])) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in failed (token exchange). Please try again.'));
    exit;
}

$userInfo = json_decode(
    ff_http_get_bearer('https://www.googleapis.com/oauth2/v3/userinfo', $tokenData['access_token']),
    true
);
$email = $userInfo['email'] ?? null;
$emailVerified = $userInfo['email_verified'] ?? false;
$sub = $userInfo['sub'] ?? null;
$name = $userInfo['name'] ?? null;

if (!$email || !$emailVerified || !$sub) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Google did not return a verified email.'));
    exit;
}

$pdo = ff_db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Your Google account is not invited. Ask an admin to invite you.'));
    exit;
}

$pdo->prepare('UPDATE users SET google_sub = ?, name = COALESCE(name, ?), status = "active", last_login_at = NOW() WHERE id = ?')
    ->execute([$sub, $name, $user['id']]);

$_SESSION['user_id'] = $user['id'];
header('Location: ' . $appBase . '/');
exit;
