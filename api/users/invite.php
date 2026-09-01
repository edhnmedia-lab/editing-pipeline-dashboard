<?php
require_once __DIR__ . '/../auth_helpers.php';
$me = ff_require_role(['owner', 'admin']);
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$email = trim($input['email'] ?? '');
$title = trim($input['title'] ?? '') ?: null;
$requestedRole = $input['role'] ?? 'editor';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ff_json(422, ['error' => 'invalid_email']);
}
if (!in_array($requestedRole, ['admin', 'editor'], true)) {
    ff_json(422, ['error' => 'invalid_role']);
}
if ($me['role'] === 'admin' && $requestedRole !== 'editor') {
    ff_json(403, ['error' => 'admins_can_only_invite_editors']);
}

$pdo = ff_db();
$existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$existing->execute([$email]);
if ($existing->fetch()) {
    ff_json(409, ['error' => 'already_exists']);
}

$pdo->prepare('INSERT INTO users (email, title, role, status, invited_by, created_at) VALUES (?, ?, ?, "invited", ?, NOW())')
    ->execute([$email, $title, $requestedRole, $me['id']]);
ff_json(201, ['id' => (int)$pdo->lastInsertId()]);
