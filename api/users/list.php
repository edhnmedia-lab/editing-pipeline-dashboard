<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_require_role(['owner', 'admin']);
$stmt = ff_db()->query(
    "SELECT id, email, name, title, role, status, last_login_at FROM users
     ORDER BY (role = 'owner') DESC, (role = 'admin') DESC, email ASC"
);
ff_json(200, ['users' => $stmt->fetchAll()]);
