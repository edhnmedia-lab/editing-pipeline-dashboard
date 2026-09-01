<?php
require_once __DIR__ . '/db.php';

function ff_start_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function ff_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function ff_current_user(): ?array {
    ff_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = ff_db()->prepare('SELECT id, email, name, title, role, status FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function ff_require_auth(): array {
    $user = ff_current_user();
    if (!$user) {
        ff_json(401, ['error' => 'not_authenticated']);
    }
    return $user;
}

function ff_require_role(array $roles): array {
    $user = ff_require_auth();
    if (!in_array($user['role'], $roles, true)) {
        ff_json(403, ['error' => 'forbidden']);
    }
    return $user;
}

function ff_http_post_form(string $url, array $fields): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result === false ? '' : $result;
}

function ff_http_get_bearer(string $url, string $bearerToken): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result === false ? '' : $result;
}
