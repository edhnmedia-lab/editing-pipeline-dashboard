<?php
require_once __DIR__ . '/../auth_helpers.php';
$me = ff_require_auth();
$pdo = ff_db();

if ($me['role'] === 'editor') {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE editor_id = ? ORDER BY due_at ASC');
    $stmt->execute([$me['id']]);
} else {
    $stmt = $pdo->query('SELECT * FROM projects ORDER BY due_at ASC');
}
$projects = $stmt->fetchAll();

$ids = array_column($projects, 'id');
$deliverablesByProject = [];
$linksByProject = [];
$revisionsByProject = [];

if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $d = $pdo->prepare("SELECT * FROM deliverables WHERE project_id IN ($placeholders) ORDER BY sort_order ASC");
    $d->execute($ids);
    foreach ($d->fetchAll() as $row) {
        $deliverablesByProject[$row['project_id']][] = $row;
    }

    $l = $pdo->prepare("SELECT * FROM project_links WHERE project_id IN ($placeholders)");
    $l->execute($ids);
    foreach ($l->fetchAll() as $row) {
        $linksByProject[$row['project_id']][$row['kind']][] = $row;
    }

    $r = $pdo->prepare("SELECT * FROM revisions WHERE project_id IN ($placeholders) ORDER BY created_at DESC");
    $r->execute($ids);
    foreach ($r->fetchAll() as $row) {
        $revisionsByProject[$row['project_id']][] = $row;
    }
}

foreach ($projects as &$p) {
    $p['deliverablesList'] = $deliverablesByProject[$p['id']] ?? [];
    $p['assets'] = $linksByProject[$p['id']]['asset'] ?? [];
    $p['references'] = $linksByProject[$p['id']]['reference'] ?? [];
    $p['revisions'] = $revisionsByProject[$p['id']] ?? [];
}
unset($p);

ff_json(200, ['projects' => $projects]);
