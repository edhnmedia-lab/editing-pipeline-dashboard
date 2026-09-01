<?php
require_once __DIR__ . '/../auth_helpers.php';
$me = ff_require_auth();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$projectId = $input['id'] ?? '';

$pdo = ff_db();
$stmt = $pdo->prepare('SELECT editor_id FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    ff_json(404, ['error' => 'not_found']);
}
if ($me['role'] === 'editor' && (int)$project['editor_id'] !== (int)$me['id']) {
    ff_json(403, ['error' => 'not_your_project']);
}

if (isset($input['stage'])) {
    $closed = ['approved' => true, 'delivered' => true];
    $deliveredAtSql = isset($closed[$input['stage']]) ? 'NOW()' : 'NULL';
    $deliveredOnTimeSql = $input['stage'] === 'delivered' ? '(NOW() <= due_at)' : 'NULL';
    $pdo->prepare("UPDATE projects SET stage = ?, delivered_at = $deliveredAtSql, delivered_on_time = $deliveredOnTimeSql, updated_at = NOW() WHERE id = ?")
        ->execute([$input['stage'], $projectId]);
    if ($input['stage'] === 'revisions_requested') {
        $pdo->prepare('UPDATE projects SET version = version + 1 WHERE id = ?')->execute([$projectId]);
    }
    if (isset($closed[$input['stage']])) {
        $pdo->prepare('UPDATE deliverables SET done = 1 WHERE project_id = ?')->execute([$projectId]);
    }
}

if (isset($input['deliverableId'], $input['done'])) {
    $pdo->prepare('UPDATE deliverables SET done = ? WHERE id = ? AND project_id = ?')
        ->execute([$input['done'] ? 1 : 0, $input['deliverableId'], $projectId]);
}

if (isset($input['newRevisionNote'])) {
    $pdo->prepare('INSERT INTO revisions (project_id, note, author, created_at, resolved) VALUES (?, ?, ?, NOW(), 0)')
        ->execute([$projectId, $input['newRevisionNote'], $me['name'] ?? $me['email']]);
    $pdo->prepare("UPDATE projects SET stage = 'revisions_requested', updated_at = NOW() WHERE id = ? AND stage NOT IN ('approved','delivered')")
        ->execute([$projectId]);
}

if (isset($input['resolveRevisionId'])) {
    $pdo->prepare('UPDATE revisions SET resolved = 1 WHERE id = ? AND project_id = ?')
        ->execute([$input['resolveRevisionId'], $projectId]);
}

if (array_key_exists('deliveryLink', $input)) {
    $pdo->prepare('UPDATE projects SET delivery_link = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$input['deliveryLink'], $projectId]);
}

ff_json(200, ['ok' => true]);
