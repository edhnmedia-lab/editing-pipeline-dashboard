<?php
require_once __DIR__ . '/../auth_helpers.php';
$me = ff_require_role(['owner', 'admin']);
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$targetId = (int)($input['id'] ?? 0);

$pdo = ff_db();
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
$stmt->execute([$targetId]);
$target = $stmt->fetch();
if (!$target) {
    ff_json(404, ['error' => 'not_found']);
}
if ($target['role'] === 'owner') {
    ff_json(403, ['error' => 'owner_is_protected']);
}
if ($me['role'] === 'admin' && $target['role'] !== 'editor') {
    ff_json(403, ['error' => 'admins_can_only_manage_editors']);
}

$fields = [];
$params = [];
if (array_key_exists('title', $input)) {
    $fields[] = 'title = ?';
    $params[] = trim($input['title']) ?: null;
}
if (array_key_exists('role', $input)) {
    $newRole = $input['role'];
    if (!in_array($newRole, ['admin', 'editor'], true)) {
        ff_json(422, ['error' => 'invalid_role']);
    }
    if ($me['role'] === 'admin' && $newRole !== 'editor') {
        ff_json(403, ['error' => 'admins_can_only_manage_editors']);
    }
    $fields[] = 'role = ?';
    $params[] = $newRole;
}
if (!$fields) {
    ff_json(422, ['error' => 'nothing_to_update']);
}
$params[] = $targetId;
$pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
ff_json(200, ['ok' => true]);
