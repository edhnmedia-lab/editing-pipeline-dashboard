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

if (isset($input['title'])) {
    if (!in_array($me['role'], ['owner', 'admin'], true)) {
        ff_json(403, ['error' => 'forbidden']);
    }

    foreach (['title', 'client', 'editorId', 'dueAt', 'priority', 'platform', 'aspect'] as $key) {
        if (empty($input[$key])) {
            ff_json(422, ['error' => 'missing_field', 'field' => $key]);
        }
    }
    if (!in_array($input['priority'], ['Urgent', 'High', 'Medium', 'Low'], true)) {
        ff_json(422, ['error' => 'invalid_priority']);
    }
    $editorCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'editor'");
    $editorCheck->execute([$input['editorId']]);
    if (!$editorCheck->fetch()) {
        ff_json(422, ['error' => 'invalid_editor']);
    }
    $dueAt = date('Y-m-d H:i:s', strtotime($input['dueAt']));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE projects SET title = ?, client = ?, editor_id = ?, due_at = ?, priority = ?, platform = ?, aspect = ?, instructions = ?, updated_at = NOW() WHERE id = ?')
            ->execute([
                $input['title'], $input['client'], $input['editorId'], $dueAt,
                $input['priority'], $input['platform'], $input['aspect'],
                $input['instructions'] ?? null, $projectId,
            ]);

        // Deliverables: preserve the done-flag and id for any line whose label
        // is unchanged, so fixing an unrelated field never resets an editor's
        // completed checkboxes. Matches by exact label text.
        $existingStmt = $pdo->prepare('SELECT id, label, done FROM deliverables WHERE project_id = ? ORDER BY sort_order');
        $existingStmt->execute([$projectId]);
        $existing = $existingStmt->fetchAll();
        $usedIds = [];
        foreach (($input['deliverables'] ?? []) as $i => $label) {
            $matchId = null;
            foreach ($existing as $row) {
                if ($row['label'] === $label && !in_array($row['id'], $usedIds, true)) {
                    $matchId = $row['id'];
                    break;
                }
            }
            if ($matchId !== null) {
                $usedIds[] = $matchId;
                $pdo->prepare('UPDATE deliverables SET sort_order = ? WHERE id = ?')->execute([$i, $matchId]);
            } else {
                $pdo->prepare('INSERT INTO deliverables (project_id, label, done, sort_order) VALUES (?, ?, 0, ?)')
                    ->execute([$projectId, $label, $i]);
            }
        }
        $staleIds = array_diff(array_column($existing, 'id'), $usedIds);
        if ($staleIds) {
            $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
            $pdo->prepare("DELETE FROM deliverables WHERE id IN ($placeholders)")->execute(array_values($staleIds));
        }

        // Assets and references carry no state of their own, so a plain
        // replace (matching create.php's insert shape) is enough.
        $pdo->prepare("DELETE FROM project_links WHERE project_id = ? AND kind = 'asset'")->execute([$projectId]);
        foreach (($input['assets'] ?? []) as $item) {
            $pdo->prepare('INSERT INTO project_links (project_id, kind, label, url) VALUES (?, "asset", ?, ?)')
                ->execute([$projectId, $item['label'], $item['url']]);
        }
        $pdo->prepare("DELETE FROM project_links WHERE project_id = ? AND kind = 'reference'")->execute([$projectId]);
        foreach (($input['references'] ?? []) as $item) {
            $pdo->prepare('INSERT INTO project_links (project_id, kind, label, url) VALUES (?, "reference", ?, ?)')
                ->execute([$projectId, $item['label'], $item['url']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

if (isset($input['stage'])) {
    $validStages = ['brief_received', 'assets_ready', 'editing', 'internal_review', 'client_review', 'revisions_requested', 'approved', 'delivered'];
    if (!in_array($input['stage'], $validStages, true)) {
        ff_json(422, ['error' => 'invalid_stage']);
    }
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
