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
if ($targetId === (int)$me['id']) {
    ff_json(403, ['error' => 'cannot_remove_self']);
}
$hasProjects = $pdo->prepare('SELECT 1 FROM projects WHERE editor_id = ? LIMIT 1');
$hasProjects->execute([$targetId]);
if ($hasProjects->fetch()) {
    ff_json(409, ['error' => 'user_has_projects']);
}
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
ff_json(200, ['ok' => true]);
