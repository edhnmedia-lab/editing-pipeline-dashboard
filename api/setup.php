<?php
require_once __DIR__ . '/db.php';

$cfg = ff_config();
if (($_GET['token'] ?? '') !== $cfg['setup_token']) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$pdo = ff_db();
$sql = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

$ownerEmail = $cfg['app']['owner_email'];
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$ownerEmail]);
if (!$stmt->fetch()) {
    $pdo->prepare('INSERT INTO users (email, role, status, created_at) VALUES (?, "owner", "active", NOW())')
        ->execute([$ownerEmail]);
    echo "Schema applied. Owner seeded: $ownerEmail\n";
} else {
    echo "Schema applied. Owner already exists.\n";
}
