<?php
require_once __DIR__ . '/../auth_helpers.php';
$me = ff_require_role(['owner', 'admin']);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

foreach (['title', 'client', 'editorId', 'dueAt', 'priority', 'platform', 'aspect'] as $key) {
    if (empty($input[$key])) {
        ff_json(422, ['error' => 'missing_field', 'field' => $key]);
    }
}

$pdo = ff_db();
$editorCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'editor'");
$editorCheck->execute([$input['editorId']]);
if (!$editorCheck->fetch()) {
    ff_json(422, ['error' => 'invalid_editor']);
}

$insertSql = 'INSERT INTO projects
    (id, title, client, editor_id, assigned_by, date_assigned, due_at, priority, stage, version, platform, aspect, delivery_link, instructions, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, "brief_received", 1, ?, ?, ?, ?, NOW(), NOW())';

$dueAt = date('Y-m-d H:i:s', strtotime($input['dueAt']));

$id = null;
for ($attempt = 0; $attempt < 5; $attempt++) {
    $candidateId = 'PRJ-' . random_int(1000, 9999);
    try {
        $pdo->prepare($insertSql)->execute([
            $candidateId, $input['title'], $input['client'], $input['editorId'], $me['id'],
            $dueAt, $input['priority'], $input['platform'], $input['aspect'],
            $input['deliveryLink'] ?? null, $input['instructions'] ?? null,
        ]);
        $id = $candidateId;
        break;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            continue; // duplicate id, try again with a freshly generated one
        }
        throw $e;
    }
}

if ($id === null) {
    ff_json(500, ['error' => 'id_generation_failed']);
}

foreach (($input['deliverables'] ?? []) as $i => $label) {
    $pdo->prepare('INSERT INTO deliverables (project_id, label, done, sort_order) VALUES (?, ?, 0, ?)')
        ->execute([$id, $label, $i]);
}
foreach (($input['assets'] ?? []) as $item) {
    $pdo->prepare('INSERT INTO project_links (project_id, kind, label, url) VALUES (?, "asset", ?, ?)')
        ->execute([$id, $item['label'], $item['url']]);
}
foreach (($input['references'] ?? []) as $item) {
    $pdo->prepare('INSERT INTO project_links (project_id, kind, label, url) VALUES (?, "reference", ?, ?)')
        ->execute([$id, $item['label'], $item['url']]);
}

ff_json(201, ['id' => $id]);
