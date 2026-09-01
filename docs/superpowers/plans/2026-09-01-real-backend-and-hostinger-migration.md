# Frame & Fold: Real Backend, Auth, and Hostinger Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Frame & Fold editor dashboard from a GitHub-hosted, mock-data prototype to a real app on Hostinger, backed by MySQL, real Google OAuth, invite-only accounts, and an owner/admin/editor role system.

**Architecture:** One Hostinger shared-hosting website (`framefold.com`) serves a static frontend (the existing single-page dashboard, rewired to call a real API) plus a PHP `/api/` backend, both reading/writing one MySQL database. PHP native sessions are the sole source of session/role truth; the client never asserts its own identity.

**Tech Stack:** PHP 8.1+ (no Composer dependencies — Google OAuth is done with raw cURL), MySQL (PDO), vanilla JS/HTML/CSS (existing prototype's stack, no framework introduced), Hostinger Business Web Hosting (shared hosting, order id `1009954812`).

**Spec:** `docs/superpowers/specs/2026-09-01-real-backend-and-hostinger-migration-design.md`

## Global Constraints

- Roles are exactly `owner | admin | editor`. There is exactly one owner, permanently `ethan@edhnmedia.com`; no endpoint may ever change the owner's role or delete the owner row, or create a second owner.
- Admins may invite/edit/remove **editors only** — never admins or the owner. Every role check happens server-side, on every request; nothing is enforced by hiding UI alone.
- Only Google OAuth is wired up for real. The prototype's GitHub button and password sign-in/create-account UI are removed, not disabled.
- Accounts are invite-only: a Google sign-in only succeeds if the email already exists in `users`.
- `localStorage` is removed from all auth state and app data. It may not be reintroduced for either.
- No Composer/vendor directory — this is shared hosting; keep PHP dependency-free.
- No automated test suite is being introduced (approved in spec) — every task ends in a concrete manual/curl verification step instead.
- Never commit `api/config.php` (real secrets). Only `api/config.example.php` is committed.

---

## Task 1: Provision Hostinger infrastructure

**Files:** None (Hostinger account state only).

**Interfaces:**
- Produces: a live website on Hostinger for `framefold.com`, and a MySQL database + user, whose connection details (host, db name, db user, db password) are consumed by Task 2's `config.php`.

- [ ] **Step 1: Check WHOIS profile exists for claiming the domain**

Call `mcp__plugin_hostinger_hostinger__domains_getWHOISProfileListV1`. If it returns no profile usable for a `.com` TLD, call `mcp__plugin_hostinger_hostinger__domains_createWHOISProfileV1` — this requires real registrant contact details (name, address, email, phone). If you don't have these on hand, stop and ask the user for them; this is a legal requirement for domain registration, not something to fabricate.

- [ ] **Step 2: Claim the free domain**

Call `mcp__plugin_hostinger_hostinger__domains_claimFreeDomainV1` with `{"domain": "framefold.com"}`. If it errors with a WHOIS-related code, go back to Step 1. If it errors because the free-domain entitlement is unavailable, stop and report to the user rather than purchasing a paid domain without confirmation.

- [ ] **Step 3: Pick a datacenter and create the website**

Call `mcp__plugin_hostinger_hostinger__hosting_listAvailableDatacentersV1` to see options. Pick the one closest to where the team is based (ask the user if it's not obvious). Then call `mcp__plugin_hostinger_hostinger__hosting_createWebsiteV1` with `{"domain": "framefold.com", "order_id": 1009954812, "datacenter_code": "<chosen code>"}`.

- [ ] **Step 4: Verify the website exists**

Call `mcp__plugin_hostinger_hostinger__hosting_listWebsitesV1` and confirm a row for `framefold.com` appears (website creation can take a few minutes — poll every 30s if it's not there yet, up to ~5 minutes).

- [ ] **Step 5: Create the MySQL database**

Generate a strong random password (e.g. 24+ random alphanumeric characters). Call `mcp__plugin_hostinger_hostinger__hosting_createAccountDatabaseV1` with a username, database name (e.g. `ff_app`), db user (e.g. `ff_app_user`), the generated password, and `website_domain: "framefold.com"`.

- [ ] **Step 6: Record connection details**

Note the resulting full database name, full database username, host (typically `localhost` on Hostinger shared hosting — confirm via the create-database response), and the password you generated. These four values are needed verbatim in Task 2.

---

## Task 2: Backend foundation — DB connection, schema, session helpers

**Files:**
- Create: `api/config.example.php`
- Create: `api/config.php` (local only — never committed; real secrets)
- Create: `api/db.php`
- Create: `api/auth_helpers.php`
- Create: `api/schema.sql`
- Create: `api/setup.php`
- Create: `api/.htaccess`
- Modify: `.gitignore`

**Interfaces:**
- Produces: `ff_config(): array`, `ff_db(): PDO`, `ff_start_session(): void`, `ff_json(int $status, array $body): void`, `ff_current_user(): ?array`, `ff_require_auth(): array`, `ff_require_role(array $roles): array` — every later API file uses these by `require_once __DIR__ . '/../auth_helpers.php'` (path depth varies by file location).

- [ ] **Step 1: Write `api/config.example.php`**

```php
<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'CHANGE_ME',
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
    ],
    'google' => [
        'client_id' => 'CHANGE_ME.apps.googleusercontent.com',
        'client_secret' => 'CHANGE_ME',
        'redirect_uri' => 'https://framefold.com/api/auth/google/callback.php',
    ],
    'app' => [
        'base_url' => 'https://framefold.com',
        'owner_email' => 'ethan@edhnmedia.com',
    ],
    'setup_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
```

- [ ] **Step 2: Create `api/config.php` locally with real values**

Copy `config.example.php` to `config.php`. Fill in `db.host` / `db.name` / `db.user` / `db.pass` from Task 1, Step 6. Generate a random `setup_token` (e.g. `php -r "echo bin2hex(random_bytes(24));"`). Leave `google.client_id` / `google.client_secret` as placeholders for now — Task 3 fills them in.

- [ ] **Step 3: Add `config.php` to `.gitignore`**

Open `.gitignore` and add a line: `api/config.php`

- [ ] **Step 4: Write `api/db.php`**

```php
<?php
function ff_config(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function ff_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = ff_config()['db'];
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
```

- [ ] **Step 5: Write `api/auth_helpers.php`**

```php
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
```

- [ ] **Step 6: Write `api/schema.sql`**

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  role ENUM('owner','admin','editor') NOT NULL,
  status ENUM('invited','active') NOT NULL DEFAULT 'invited',
  google_sub VARCHAR(255) NULL,
  invited_by INT NULL,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL,
  FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
  id VARCHAR(32) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  client VARCHAR(255) NOT NULL,
  editor_id INT NOT NULL,
  assigned_by INT NOT NULL,
  date_assigned DATETIME NOT NULL,
  due_at DATETIME NOT NULL,
  priority ENUM('Urgent','High','Medium','Low') NOT NULL,
  stage VARCHAR(64) NOT NULL,
  version INT NOT NULL DEFAULT 1,
  platform VARCHAR(255) NOT NULL,
  aspect VARCHAR(32) NOT NULL,
  delivery_link VARCHAR(1024) NULL,
  instructions TEXT NULL,
  delivered_at DATETIME NULL,
  delivered_on_time TINYINT(1) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (editor_id) REFERENCES users(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deliverables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  label VARCHAR(255) NOT NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  kind ENUM('asset','reference') NOT NULL,
  label VARCHAR(255) NOT NULL,
  url VARCHAR(1024) NOT NULL,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS revisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id VARCHAR(32) NOT NULL,
  note TEXT NOT NULL,
  author VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 7: Write `api/setup.php`** (one-time schema + owner seed, token-guarded)

```php
<?php
require_once __DIR__ . '/db.php';

$cfg = ff_config();
if (($_GET['token'] ?? '') !== $cfg['setup_token']) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$pdo = ff_db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

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
```

- [ ] **Step 8: Write `api/.htaccess`** to block direct access to sensitive files

```
<FilesMatch "^(config\.php|config\.example\.php|schema\.sql|setup\.php)$">
  Require all denied
</FilesMatch>
```

Note: this blocks `setup.php` unconditionally, which is intentional for normal operation — see Step 10.

- [ ] **Step 9: Deploy to Hostinger**

Use the `hostinger:hosting-deploy-static-site` skill to upload the current repo contents (the existing `index.html` plus the new `api/` directory, including the real `api/config.php`) to the `framefold.com` website. PHP files need no build step, so this is a plain file deploy, not a Node/build deploy.

- [ ] **Step 10: Verify — temporarily allow `setup.php`, run it, then re-block it**

Comment out `setup.php` from the `.htaccess` `FilesMatch` line, redeploy just that one file, then visit `https://framefold.com/api/setup.php?token=<your setup_token>` in a browser. Confirm the response reads `Schema applied. Owner seeded: ethan@edhnmedia.com`. Then put `setup.php` back into the `.htaccess` block list and redeploy `.htaccess` so the endpoint can't be hit again.

- [ ] **Step 11: Commit**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
git add api/config.example.php api/db.php api/auth_helpers.php api/schema.sql api/setup.php api/.htaccess .gitignore
git commit -m "Add MySQL schema, DB/session helpers, and one-time setup script"
```

---

## Task 3: Google OAuth sign-in

**Files:**
- Create: `api/auth/google/start.php`
- Create: `api/auth/google/callback.php`
- Create: `api/auth/me.php`
- Create: `api/auth/logout.php`
- Modify: `api/config.php` (fill in real Google credentials — not committed)

**Interfaces:**
- Consumes: `ff_start_session()`, `ff_config()`, `ff_db()`, `ff_json()`, `ff_http_post_form()`, `ff_http_get_bearer()` from Task 2.
- Produces: an authenticated PHP session (`$_SESSION['user_id']`) that Tasks 4–6 read via `ff_current_user()` / `ff_require_auth()` / `ff_require_role()`.

- [ ] **Step 1: Blocked on the user — get a real Google OAuth Client ID**

This cannot be done via any available tool — it requires a human to sign into Google Cloud Console. Ask the user to:
1. Go to Google Cloud Console → APIs & Services → Credentials.
2. Create an OAuth Client ID (Web application type).
3. Add authorized redirect URI: `https://framefold.com/api/auth/google/callback.php`.
4. Send you the resulting Client ID and Client Secret.

Do not proceed to Step 2 until you have both values.

- [ ] **Step 2: Update `api/config.php` with the real Google credentials**

Replace the `google.client_id` and `google.client_secret` placeholder values with the real ones from Step 1.

- [ ] **Step 3: Write `api/auth/google/start.php`**

```php
<?php
require_once __DIR__ . '/../../auth_helpers.php';
ff_start_session();
$cfg = ff_config()['google'];
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$params = http_build_query([
    'client_id' => $cfg['client_id'],
    'redirect_uri' => $cfg['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
]);
header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
```

- [ ] **Step 4: Write `api/auth/google/callback.php`**

```php
<?php
require_once __DIR__ . '/../../auth_helpers.php';
ff_start_session();
$cfg = ff_config();
$appBase = $cfg['app']['base_url'];

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';

if (!$state || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in failed (state mismatch). Please try again.'));
    exit;
}
unset($_SESSION['oauth_state']);

if (!$code) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in was cancelled.'));
    exit;
}

$g = $cfg['google'];
$tokenData = json_decode(ff_http_post_form('https://oauth2.googleapis.com/token', [
    'code' => $code,
    'client_id' => $g['client_id'],
    'client_secret' => $g['client_secret'],
    'redirect_uri' => $g['redirect_uri'],
    'grant_type' => 'authorization_code',
]), true);

if (!isset($tokenData['access_token'])) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Sign-in failed (token exchange). Please try again.'));
    exit;
}

$userInfo = json_decode(
    ff_http_get_bearer('https://www.googleapis.com/oauth2/v3/userinfo', $tokenData['access_token']),
    true
);
$email = $userInfo['email'] ?? null;
$emailVerified = $userInfo['email_verified'] ?? false;
$sub = $userInfo['sub'] ?? null;
$name = $userInfo['name'] ?? null;

if (!$email || !$emailVerified || !$sub) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Google did not return a verified email.'));
    exit;
}

$pdo = ff_db();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ' . $appBase . '/?authError=' . urlencode('Your Google account is not invited. Ask an admin to invite you.'));
    exit;
}

$pdo->prepare('UPDATE users SET google_sub = ?, name = COALESCE(name, ?), status = "active", last_login_at = NOW() WHERE id = ?')
    ->execute([$sub, $name, $user['id']]);

$_SESSION['user_id'] = $user['id'];
header('Location: ' . $appBase . '/');
exit;
```

- [ ] **Step 5: Write `api/auth/me.php`**

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_json(200, ['user' => ff_current_user()]);
```

- [ ] **Step 6: Write `api/auth/logout.php`**

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_start_session();
$_SESSION = [];
session_destroy();
ff_json(200, ['ok' => true]);
```

- [ ] **Step 7: Deploy**

Use the `hostinger:hosting-deploy-static-site` skill to upload the new `api/auth/` directory and the updated `api/config.php`.

- [ ] **Step 8: Verify in a real browser, signed in as the owner**

Visit `https://framefold.com/api/auth/google/start.php`, complete Google's consent screen using `ethan@edhnmedia.com`, and confirm you land back on `https://framefold.com/`. Then visit `https://framefold.com/api/auth/me.php` directly and confirm it returns JSON with `"role":"owner"` and `"email":"ethan@edhnmedia.com"`.

Also verify the rejection path: open a private/incognito window, repeat the flow with a Google account that has never been invited, and confirm you're redirected with `authError` set to the "not invited" message (the frontend doesn't render this nicely yet — that's fine, confirm it via the URL query string for now).

- [ ] **Step 9: Commit**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
git add api/auth
git commit -m "Add real Google OAuth sign-in, invite-gated"
```

---

## Task 4: Users API (invite, list, update, remove) with role enforcement

**Files:**
- Create: `api/users/list.php`
- Create: `api/users/invite.php`
- Create: `api/users/update.php`
- Create: `api/users/remove.php`

**Interfaces:**
- Consumes: `ff_require_role(array $roles): array`, `ff_db()`, `ff_json()` from Task 2/3.
- Produces: the four endpoints Task 6's Manage Users UI calls directly by URL (`/api/users/list.php`, `/api/users/invite.php`, `/api/users/update.php`, `/api/users/remove.php`).

- [ ] **Step 1: Write `api/users/list.php`**

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
ff_require_role(['owner', 'admin']);
$stmt = ff_db()->query(
    "SELECT id, email, name, title, role, status, last_login_at FROM users
     ORDER BY (role = 'owner') DESC, (role = 'admin') DESC, email ASC"
);
ff_json(200, ['users' => $stmt->fetchAll()]);
```

- [ ] **Step 2: Write `api/users/invite.php`**

```php
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
```

- [ ] **Step 3: Write `api/users/update.php`**

```php
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
```

- [ ] **Step 4: Write `api/users/remove.php`**

```php
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
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
ff_json(200, ['ok' => true]);
```

- [ ] **Step 5: Deploy**

Use the `hostinger:hosting-deploy-static-site` skill to upload the new `api/users/` directory.

- [ ] **Step 6: Verify with curl, using the owner's session cookie**

Sign in as the owner in a browser (per Task 3, Step 8), open dev tools → Application/Storage → Cookies, and copy the `PHPSESSID` value. Then run:

```bash
COOKIE="PHPSESSID=<paste value>"
curl -s -b "$COOKIE" https://framefold.com/api/users/list.php
# Expect: {"users":[{"id":1,"email":"ethan@edhnmedia.com","role":"owner",...}]}

curl -s -b "$COOKIE" -X POST https://framefold.com/api/users/invite.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test-admin@example.com","role":"admin","title":"Senior Editor"}'
# Expect: {"id":2}

curl -s -b "$COOKIE" -X POST https://framefold.com/api/users/invite.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test-admin@example.com","role":"admin"}'
# Expect: 409 {"error":"already_exists"} — proves duplicate-email rejection

curl -s -b "$COOKIE" -X POST https://framefold.com/api/users/update.php \
  -H "Content-Type: application/json" \
  -d '{"id":1,"role":"admin"}'
# Expect: 403 {"error":"owner_is_protected"} — proves the owner can't be demoted, even by itself
```

Confirm each response matches what's noted above before moving on.

- [ ] **Step 7: Commit**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
git add api/users
git commit -m "Add users API: invite, list, update, remove with role enforcement"
```

---

## Task 5: Projects API (list, create, update) with role-scoped visibility

**Files:**
- Create: `api/projects/list.php`
- Create: `api/projects/create.php`
- Create: `api/projects/update.php`

**Interfaces:**
- Consumes: `ff_require_auth()`, `ff_require_role()`, `ff_db()`, `ff_json()` from Task 2/3.
- Produces: the three endpoints Task 6's frontend rewire calls (`/api/projects/list.php`, `/api/projects/create.php`, `/api/projects/update.php`). Response shape from `list.php` — each project object has all the DB columns (camelCase not applied; frontend Task 6 reads snake_case fields directly) plus `deliverablesList` (array of `{id, project_id, label, done, sort_order}`), `assets` (array of `{id, project_id, kind, label, url}`), `references` (same shape, `kind:"reference"`), `revisions` (array of `{id, project_id, note, author, created_at, resolved}`).

- [ ] **Step 1: Write `api/projects/list.php`**

```php
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
```

- [ ] **Step 2: Write `api/projects/create.php`**

```php
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

$id = 'PRJ-' . random_int(1000, 9999);
$pdo->prepare('INSERT INTO projects
    (id, title, client, editor_id, assigned_by, date_assigned, due_at, priority, stage, version, platform, aspect, delivery_link, instructions, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, "brief_received", 1, ?, ?, ?, ?, NOW(), NOW())')
    ->execute([
        $id, $input['title'], $input['client'], $input['editorId'], $me['id'],
        $input['dueAt'], $input['priority'], $input['platform'], $input['aspect'],
        $input['deliveryLink'] ?? null, $input['instructions'] ?? null,
    ]);

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
```

- [ ] **Step 3: Write `api/projects/update.php`**

```php
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
    $pdo->prepare("UPDATE projects SET stage = ?, delivered_at = $deliveredAtSql, updated_at = NOW() WHERE id = ?")
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
```

- [ ] **Step 4: Deploy**

Use the `hostinger:hosting-deploy-static-site` skill to upload the new `api/projects/` directory.

- [ ] **Step 5: Verify with curl, using the owner's session cookie**

```bash
COOKIE="PHPSESSID=<paste value>"

# find the test editor's numeric id first (from Task 4's invite, then have them sign in once,
# or temporarily read it via: curl -s -b "$COOKIE" https://framefold.com/api/users/list.php)

curl -s -b "$COOKIE" -X POST https://framefold.com/api/projects/create.php \
  -H "Content-Type: application/json" \
  -d '{"title":"Test Project","client":"Test Client","editorId":<editor id>,"dueAt":"2026-09-15T17:00:00Z","priority":"Medium","platform":"YouTube","aspect":"16:9","deliverables":["Master file"],"assets":[],"references":[]}'
# Expect: {"id":"PRJ-xxxx"}

curl -s -b "$COOKIE" https://framefold.com/api/projects/list.php
# Expect: {"projects":[{... "id":"PRJ-xxxx", "deliverablesList":[{"label":"Master file","done":0,...}], ...}]}
```

Confirm the created project round-trips correctly, including its deliverable.

- [ ] **Step 6: Commit**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
git add api/projects
git commit -m "Add projects API: list, create, update with role-scoped visibility"
```

---

## Task 6: Frontend rewire — real auth, real data, role-aware UI

**Files:**
- Modify: `index.html` (canonical file going forward)
- Modify: `Editing Pipeline Dashboard.html` (kept as an identical copy of `index.html`, matching the repo's existing convention of two identical files)

**Interfaces:**
- Consumes: `GET /api/auth/me.php` → `{user: {id, email, name, title, role, status} | null}`; `GET /api/auth/google/start.php` (redirect, no JSON); `POST /api/auth/logout.php` → `{ok:true}`; `GET /api/projects/list.php` → `{projects: [...]}`; `POST /api/projects/create.php` / `/api/projects/update.php`; `GET /api/users/list.php` → `{users:[...]}`; `POST /api/users/invite.php` / `/api/users/update.php` / `/api/users/remove.php` — all from Tasks 3–5.

All steps below edit `index.html`. Do every step there first; Step 12 copies the finished file over `Editing Pipeline Dashboard.html`.

- [ ] **Step 1: Replace the auth screen markup**

Find this block (currently lines ~520-586, but search by content, not line number, since earlier edits may have shifted it):

```html
<div class="auth-screen" id="authScreen">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="brand-mark" aria-hidden="true">FF</div>
      <div>
        <div class="auth-brand-name">Frame &amp; Fold</div>
        <div class="auth-brand-sub">Production pipeline · Editor workspace</div>
      </div>
    </div>

    <h1 class="auth-heading" id="authHeading">Sign in to your workspace</h1>
    <p class="auth-lede" id="authLede">Access the projects assigned to you. Nothing here is shared with other editors.</p>

    <button type="button" class="oauth-btn" id="oauthGoogleBtn" data-provider="Google">
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h6.47a5.5 5.5 0 0 1-2.4 3.63v3h3.86c2.26-2.09 3.56-5.17 3.56-8.87z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.86l-3.86-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.33A7.2 7.2 0 0 1 4.89 12c0-.81.14-1.6.38-2.33V6.58H1.29A12 12 0 0 0 0 12c0 1.94.46 3.77 1.29 5.42z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.94 1.19 15.24 0 12 0A12 12 0 0 0 1.29 6.58l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
      Continue with Google
    </button>
    <button type="button" class="oauth-btn" id="oauthGithubBtn" data-provider="GitHub">
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 .5A11.5 11.5 0 0 0 .5 12c0 5.1 3.3 9.4 7.9 11 .6.1.8-.3.8-.6v-2.1c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.3-1.7-1.3-1.7-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.4-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.4 11.4 0 0 1 6 0c2.3-1.5 3.3-1.2 3.3-1.2.6 1.6.2 2.8.1 3.1.8.8 1.2 1.9 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6 4.6-1.6 7.9-5.9 7.9-11A11.5 11.5 0 0 0 12 .5z"/></svg>
      Continue with GitHub
    </button>

    <div class="oauth-divider">or</div>

    <div class="auth-tabs" role="tablist" aria-label="Sign in or create an account">
      <button type="button" role="tab" id="tabSignIn" aria-selected="true">Sign in</button>
      <button type="button" role="tab" id="tabCreate" aria-selected="false">Create account</button>
    </div>

    <form class="auth-form" id="signInForm" novalidate>
      <div class="field" id="f-si-email">
        <label for="siEmail">Work email</label>
        <input type="text" id="siEmail" placeholder="alex.morgan@framefold.co" autocomplete="username" />
        <p class="err" id="e-si-email"></p>
      </div>
      <div class="field" id="f-si-pass">
        <label for="siPass">Password</label>
        <input type="password" id="siPass" placeholder="••••••••" autocomplete="current-password" />
        <p class="err" id="e-si-pass"></p>
      </div>
      <button type="submit" class="btn btn-primary" id="siSubmit" style="justify-content:center">Sign in</button>
      <p class="auth-hint">Demo account — <code>alex.morgan@framefold.co</code> / <code>demo1234</code>. Or use Google / GitHub above.</p>
    </form>

    <form class="auth-form" id="createForm" novalidate hidden>
      <div class="field" id="f-cr-name">
        <label for="crName">Full name</label>
        <input type="text" id="crName" placeholder="e.g. Jordan Blake" autocomplete="name" />
        <p class="err" id="e-cr-name"></p>
      </div>
      <div class="field" id="f-cr-email">
        <label for="crEmail">Work email</label>
        <input type="text" id="crEmail" placeholder="you@studio.co" autocomplete="username" />
        <p class="err" id="e-cr-email"></p>
      </div>
      <div class="field" id="f-cr-pass">
        <label for="crPass">Password</label>
        <input type="password" id="crPass" placeholder="At least 8 characters" autocomplete="new-password" />
        <p class="err" id="e-cr-pass"></p>
      </div>
      <button type="submit" class="btn btn-primary" id="crSubmit" style="justify-content:center">Create account</button>
      <p class="auth-hint">New accounts start with an empty pipeline — projects appear once a producer assigns you a creative brief.</p>
    </form>

    <p class="auth-switch" id="authSwitch"></p>
  </div>
</div>
```

Replace it with:

```html
<div class="auth-screen" id="authScreen">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="brand-mark" aria-hidden="true">FF</div>
      <div>
        <div class="auth-brand-name">Frame &amp; Fold</div>
        <div class="auth-brand-sub">Production pipeline · Editor workspace</div>
      </div>
    </div>

    <h1 class="auth-heading">Sign in to your workspace</h1>
    <p class="auth-lede">Access the projects assigned to you. Nothing here is shared with other editors.</p>

    <p class="auth-hint" id="authError" style="color:#b91c1c" hidden></p>

    <a class="oauth-btn" id="oauthGoogleBtn" href="/api/auth/google/start.php">
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.2-2.27H12v4.51h6.47a5.5 5.5 0 0 1-2.4 3.63v3h3.86c2.26-2.09 3.56-5.17 3.56-8.87z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.86l-3.86-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.29v3.09A12 12 0 0 0 12 24z"/><path fill="#FBBC05" d="M5.27 14.33A7.2 7.2 0 0 1 4.89 12c0-.81.14-1.6.38-2.33V6.58H1.29A12 12 0 0 0 0 12c0 1.94.46 3.77 1.29 5.42z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.94 1.19 15.24 0 12 0A12 12 0 0 0 1.29 6.58l3.98 3.09C6.22 6.86 8.87 4.75 12 4.75z"/></svg>
      Continue with Google
    </a>

    <p class="auth-hint">Access is invite-only. Ask an admin to invite your Google account if you don't have access yet.</p>
  </div>
</div>
```

- [ ] **Step 2: Remove the mock data / seed / prototype-auth JS block**

Find the block that starts at the comment `1. DATA LAYER` and runs through the end of the AUTH block (this spans the `STORAGE_KEY`/`AUTH_SESSION_KEY`/`AUTH_ACCOUNTS_KEY` constants, `SESSION`, `PEOPLE`, `STAGES`, `PRIORITY_TONE`, `PRIORITY_RANK`, `CLOSED_STAGES`, the `atDays`/`daysAgo`/`uid`/`deliverables`/`links`/`revision` helpers, `ALEX_ACTIVE`, `ALEX_HISTORY_RAW`, `buildHistoryProject`, `OTHER_EDITORS_PROJECTS`, `buildSeed`, `DEMO_ACCOUNT`, `loadAccounts`/`saveAccounts`/`hydrateAccountsIntoPeople`, `getSession`/`persistSession`/`clearSession`, `signInWithProvider`/`signInWithPassword`/`createAccount`). Replace the whole thing with:

```javascript
/* ============================================================
   1. DATA LAYER
   ============================================================ */

var STAGES = [
  { id:"brief_received",      label:"Brief received",      tone:"tone-slate"  },
  { id:"assets_ready",        label:"Assets ready",        tone:"tone-info"   },
  { id:"editing",             label:"Editing",             tone:"tone-violet" },
  { id:"internal_review",     label:"Internal review",     tone:"tone-warn"   },
  { id:"client_review",       label:"Client review",       tone:"tone-teal"   },
  { id:"revisions_requested", label:"Revisions requested", tone:"tone-danger" },
  { id:"approved",            label:"Approved",            tone:"tone-ok"     },
  { id:"delivered",           label:"Delivered",           tone:"tone-done"   }
];
var PRIORITY_TONE = { Urgent:"tone-danger", High:"tone-warn", Medium:"tone-accent", Low:"tone-slate" };
var PRIORITY_RANK = { Urgent:0, High:1, Medium:2, Low:3 };
var CLOSED_STAGES = { approved:true, delivered:true };

var currentUser = null;

async function fetchCurrentUser(){
  var res = await fetch("/api/auth/me.php");
  var data = await res.json();
  currentUser = data.user;
  return currentUser;
}

async function signOut(){
  await fetch("/api/auth/logout.php", { method:"POST" });
  location.reload();
}

/* ============================================================
   1B. TEAM DIRECTORY (owner/admin only — used by Manage Users
   and the assign-editor dropdown on the brief form)
   ============================================================ */
var teamDirectory = [];

async function loadTeamDirectory(){
  if(currentUser.role === "editor"){ teamDirectory = []; return; }
  var res = await fetch("/api/users/list.php");
  var data = await res.json();
  teamDirectory = data.users || [];
}
```

- [ ] **Step 3: Replace `load()`/`save()` with fetch-based project loading**

Find:

```javascript
function load(){
  /* [PERSIST] Replace with: await fetch("/api/projects") */
  try{
    var raw = localStorage.getItem(STORAGE_KEY);
    if(raw){
      var parsed = JSON.parse(raw);
      if(parsed && Array.isArray(parsed.projects) && parsed.projects.length){
        state.projects = parsed.projects;
        return;
      }
    }
  }catch(e){ /* corrupted storage falls through to a clean seed */ }
  state.projects = buildSeed();
  save();
}
function save(){
  /* [PERSIST] Replace with authenticated PATCH/POST calls. */
  try{
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ savedAt:new Date().toISOString(), projects:state.projects }));
  }catch(e){
    toast("err","Could not save","Local storage is unavailable in this browser session.");
  }
}
```

Replace with:

```javascript
async function load(){
  var res = await fetch("/api/projects/list.php");
  if(!res.ok){
    toast("err","Could not load projects","Try refreshing the page.");
    state.projects = [];
    return;
  }
  var data = await res.json();
  state.projects = (data.projects || []).map(function(p){
    return {
      id:p.id, title:p.title, client:p.client, editorId:p.editor_id,
      dateAssigned:p.date_assigned, dueAt:p.due_at, priority:p.priority,
      stage:p.stage, version:p.version, platform:p.platform, aspect:p.aspect,
      deliveryLink:p.delivery_link || "", instructions:p.instructions || "",
      deliveredAt:p.delivered_at, deliveredOnTime:p.delivered_on_time === null ? null : !!(p.delivered_on_time * 1),
      deliverablesList:(p.deliverablesList || []).map(function(d){
        return { id:d.id, label:d.label, done:!!(d.done * 1) };
      }),
      assets:(p.assets || []).map(function(a){ return { label:a.label, url:a.url }; }),
      references:(p.references || []).map(function(r){ return { label:r.label, url:r.url }; }),
      revisions:(p.revisions || []).map(function(r){
        return { id:r.id, note:r.note, author:r.author, createdAt:r.created_at, resolved:!!(r.resolved * 1) };
      })
    };
  });
}
async function apiPost(url, body){
  var res = await fetch(url, {
    method:"POST",
    headers:{ "Content-Type":"application/json" },
    body:JSON.stringify(body)
  });
  if(!res.ok){
    var errBody = await res.json().catch(function(){ return {}; });
    throw new Error(errBody.error || ("Request failed: " + res.status));
  }
  return res.json();
}
```

- [ ] **Step 4: Simplify `myProjects()` / `findProject()` / `me()`**

Find:

```javascript
function myProjects(){
  return state.projects.filter(function(p){ return p.editorId === SESSION.editorId; });
}
function findProject(id){
  var list = myProjects();
  for(var i = 0; i < list.length; i++){ if(list[i].id === id) return list[i]; }
  return null; /* Requesting another editor's id returns nothing. */
}
function me(){
  for(var i = 0; i < PEOPLE.length; i++){ if(PEOPLE[i].id === SESSION.editorId) return PEOPLE[i]; }
  return { id:SESSION.editorId, name:"Unknown editor", role:"editor", title:"Editor" };
}
```

Replace with:

```javascript
function myProjects(){
  /* The server already scopes this list by role (editor: own projects
     only; owner/admin: everything) — see api/projects/list.php. */
  return state.projects;
}
function findProject(id){
  var list = myProjects();
  for(var i = 0; i < list.length; i++){ if(list[i].id === id) return list[i]; }
  return null;
}
function me(){
  return currentUser;
}
```

- [ ] **Step 5: Update `renderIdentity()` to drop the `bEditor` self-assign line**

Find (inside `renderIdentity`):

```javascript
  $("welcomeTitle").textContent = greeting() + ", " + user.name.split(" ")[0];
  $("bEditor").value = user.name + "  (" + user.id + ")";
}
```

Replace with:

```javascript
  $("welcomeTitle").textContent = greeting() + ", " + (user.name || user.email).split(" ")[0];
}
```

- [ ] **Step 6: Rewrite the mutation functions to call the API**

Find:

```javascript
function commit(){ save(); renderAll(); }

function setStage(id, stageId){
  var p = findProject(id);
  if(!p) return;
  var previous = p.stage;
  p.stage = stageId;

  if(stageId === "delivered"){
    p.deliveredAt = new Date().toISOString();
    p.deliveredOnTime = new Date(p.deliveredAt) <= new Date(p.dueAt);
    (p.deliverablesList || []).forEach(function(x){ x.done = true; });
  }else{
    delete p.deliveredAt;
    delete p.deliveredOnTime;
  }
  /* Moving into revisions bumps the working version, mirroring real practice. */
  if(stageId === "revisions_requested" && previous !== "revisions_requested"){
    p.version = (p.version || 1) + 1;
  }
  commit();
  toast("ok","Stage updated", p.title + " is now at “" + stageMeta(stageId).label + "”.");
}

function toggleDeliverable(projectId, deliverableId, checked){
  var p = findProject(projectId);
  if(!p) return;
  (p.deliverablesList || []).forEach(function(x){ if(x.id === deliverableId) x.done = checked; });
  commit();
}

function addRevision(projectId, note){
  var p = findProject(projectId);
  if(!p) return;
  p.revisions = p.revisions || [];
  p.revisions.push({
    id:uid("rev"), note:note, author:me().name + " (self-logged)",
    createdAt:new Date().toISOString(), resolved:false
  });
  if(p.stage !== "revisions_requested" && !isClosed(p)) p.stage = "revisions_requested";
  commit();
  toast("ok","Revision note added", "Logged against " + p.title + ".");
}

function resolveRevision(projectId, revisionId){
  var p = findProject(projectId);
  if(!p) return;
  (p.revisions || []).forEach(function(r){ if(r.id === revisionId) r.resolved = true; });
  if(!hasOpenRevisions(p) && p.stage === "revisions_requested") p.stage = "internal_review";
  commit();
  toast("ok","Revision closed", openRevisions(p) === 0
    ? "All revisions on " + p.title + " are complete."
    : openRevisions(p) + " still open on " + p.title + ".");
}

function setDeliveryLink(projectId, url){
  var p = findProject(projectId);
  if(!p) return;
  p.deliveryLink = url;
  commit();
  toast("ok","Delivery link saved", url ? "Link attached to " + p.title + "." : "Link cleared.");
}

function nextProjectId(){
  var max = 1000;
  state.projects.forEach(function(p){
    var n = parseInt(String(p.id).replace(/[^0-9]/g,""), 10);
    if(!isNaN(n) && n > max) max = n;
  });
  return "PRJ-" + (max + 1);
}

/* [INGEST] A real build receives briefs from the intake form or a Notion
   database webhook; the server assigns the editor and creates the row. */
function createProjectFromBrief(brief){
  var project = {
    id:nextProjectId(),
    title:brief.title,
    client:brief.client,
    editorId:SESSION.editorId,      /* never taken from client input */
    dateAssigned:new Date().toISOString(),
    dueAt:brief.dueAt,
    priority:brief.priority,
    stage:brief.stage,
    version:1,
    platform:brief.platform,
    aspect:brief.aspect,
    deliveryLink:"",
    instructions:brief.instructions,
    deliverablesList:brief.deliverables.map(function(label, i){
      return { id:"dl" + i + "-" + Math.random().toString(36).slice(2,6), label:label, done:false };
    }),
    assets:brief.assets.map(function(u, i){ return { label:"Asset " + (i + 1), url:u }; }),
    references:brief.references.map(function(u, i){ return { label:"Reference " + (i + 1), url:u }; }),
    revisions:brief.note
      ? [{ id:uid("rev"), note:brief.note, author:"Brief intake", createdAt:new Date().toISOString(), resolved:false }]
      : []
  };
  state.projects.push(project);
  commit();
  return project;
}

function resetData(){
  state.projects = buildSeed();
  state.search = ""; state.status = "all"; state.priority = "all";
  state.deadline = "all"; state.revisions = "all";
  $("searchInput").value = "";
  $("statusFilter").value = "all";
  $("priorityFilter").value = "all";
  $("deadlineFilter").value = "all";
  $("revisionFilter").value = "all";
  closeDrawer();
  commit();
  toast("info","Prototype reset","Sample data restored to its original state.");
}
```

Replace with:

```javascript
async function commit(){
  await load();
  renderAll();
}

async function setStage(id, stageId){
  try{
    await apiPost("/api/projects/update.php", { id:id, stage:stageId });
    await commit();
    toast("ok","Stage updated", "Now at “" + stageMeta(stageId).label + "”.");
  }catch(e){ toast("err","Could not update stage", e.message); }
}

async function toggleDeliverable(projectId, deliverableId, checked){
  try{
    await apiPost("/api/projects/update.php", { id:projectId, deliverableId:deliverableId, done:checked });
    await commit();
  }catch(e){ toast("err","Could not update deliverable", e.message); }
}

async function addRevision(projectId, note){
  try{
    await apiPost("/api/projects/update.php", { id:projectId, newRevisionNote:note });
    await commit();
    toast("ok","Revision note added", "Logged against the project.");
  }catch(e){ toast("err","Could not add revision", e.message); }
}

async function resolveRevision(projectId, revisionId){
  try{
    await apiPost("/api/projects/update.php", { id:projectId, resolveRevisionId:revisionId });
    await commit();
    toast("ok","Revision closed", "Marked resolved.");
  }catch(e){ toast("err","Could not resolve revision", e.message); }
}

async function setDeliveryLink(projectId, url){
  try{
    await apiPost("/api/projects/update.php", { id:projectId, deliveryLink:url });
    await commit();
    toast("ok","Delivery link saved", url ? "Link attached." : "Link cleared.");
  }catch(e){ toast("err","Could not save delivery link", e.message); }
}

async function createProjectFromBrief(brief){
  var created = await apiPost("/api/projects/create.php", {
    title:brief.title, client:brief.client, editorId:brief.editorId,
    dueAt:brief.dueAt, priority:brief.priority, platform:brief.platform,
    aspect:brief.aspect, instructions:brief.instructions,
    deliverables:brief.deliverables, assets:brief.assets.map(function(u,i){ return {label:"Asset "+(i+1), url:u}; }),
    references:brief.references.map(function(u,i){ return {label:"Reference "+(i+1), url:u}; })
  });
  await commit();
  return findProject(created.id);
}
```

Note `resetData()` and `nextProjectId()` are deleted entirely — there's no more sample data to reset to, and the server assigns project ids now.

- [ ] **Step 7: Remove the "Reset prototype data" button**

Find (in the WELCOME section HTML):

```html
      <button class="btn btn-ghost" id="resetBtn">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
        Reset prototype data
      </button>
      <button class="btn btn-primary" id="newBriefBtn">
```

Replace with:

```html
      <button class="btn btn-primary" id="newBriefBtn">
```

And find the corresponding wiring:

```javascript
  /* reset */
  $("resetBtn").addEventListener("click", function(){
    if(window.confirm("Reset the prototype? Any changes you made will be discarded and the original sample data restored.")){
      resetData();
    }
  });

```

Delete that block entirely.

- [ ] **Step 8: Make "Create from brief" owner/admin-only, with a real editor-assignment dropdown**

Find (in the brief form HTML):

```html
          <div class="field">
            <label for="bEditor">Assigned editor</label>
            <input type="text" id="bEditor" readonly />
            <p class="hint">Locked to your session. Reassignment is a Creative Director action.</p>
          </div>
```

Replace with:

```html
          <div class="field">
            <label for="bEditor">Assigned editor *</label>
            <select id="bEditor"></select>
            <p class="err" id="e-editor"></p>
          </div>
```

Then find:

```javascript
function openBriefModal(){
  state.lastFocused = document.activeElement;
  $("scrim").hidden = false;
  $("briefModal").hidden = false;
  requestAnimationFrame(function(){
    $("scrim").classList.add("show");
    $("briefModal").classList.add("show");
    $("bTitle").focus();
  });
}
```

Replace with:

```javascript
function openBriefModal(){
  var select = $("bEditor");
  select.innerHTML = "";
  teamDirectory.filter(function(u){ return u.role === "editor"; }).forEach(function(u){
    var o = document.createElement("option");
    o.value = u.id; o.textContent = (u.name || u.email) + (u.title ? " — " + u.title : "");
    select.appendChild(o);
  });
  state.lastFocused = document.activeElement;
  $("scrim").hidden = false;
  $("briefModal").hidden = false;
  requestAnimationFrame(function(){
    $("scrim").classList.add("show");
    $("briefModal").classList.add("show");
    $("bTitle").focus();
  });
}
```

Also update `createProjectFromBrief`'s caller (the `briefForm` submit handler, Step 10 below) to read `editorId` from `$("bEditor").value` (cast with `Number(...)` since the select's `value` is the string form of the numeric user id) into the `brief` object that `validateBrief()` / the submit handler builds — add `brief.editorId = Number($("bEditor").value);` right after `var brief = validateBrief();` succeeds, and validate that a non-empty option exists (`select.options.length > 0`; if zero, block submission with `setFieldError("f-editor","e-editor","Invite at least one editor before assigning a project.")`).

- [ ] **Step 9: Add the Manage Users modal**

Add this HTML immediately after the closing `</div>` of `briefModal` (i.e. right before the `<div class="toasts" id="toasts" ...>` line):

```html
<div class="modal" id="usersModal" role="dialog" aria-modal="true" aria-labelledby="usersModalTitle" hidden>
  <div class="modal-panel">
    <div class="modal-head">
      <div style="flex:1">
        <h2 class="modal-title" id="usersModalTitle" style="font-size:16px">Manage users</h2>
        <p class="drawer-sub" id="usersModalSub"></p>
      </div>
      <button class="icon-btn" id="usersClose" aria-label="Close manage users">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <form id="inviteForm" novalidate>
        <div class="row-3">
          <div class="field" id="f-invite-email">
            <label for="inviteEmail">Email to invite</label>
            <input type="text" id="inviteEmail" placeholder="name@example.com" autocomplete="off" />
            <p class="err" id="e-invite-email"></p>
          </div>
          <div class="field">
            <label for="inviteRole">Role</label>
            <select id="inviteRole">
              <option value="editor" selected>Editor</option>
              <option value="admin" id="inviteRoleAdminOption">Admin</option>
            </select>
          </div>
          <div class="field">
            <label for="inviteTitle">Title (optional)</label>
            <input type="text" id="inviteTitle" placeholder="e.g. Senior Editor" autocomplete="off" />
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Send invite</button>
      </form>
      <table class="table" id="usersTable" style="margin-top:20px">
        <thead>
          <tr><th>Email</th><th>Name</th><th>Title</th><th>Role</th><th>Status</th><th></th></tr>
        </thead>
        <tbody id="usersTableBody"></tbody>
      </table>
    </div>
  </div>
</div>
```

Then add the topbar trigger button — find:

```html
    <div style="position:relative">
      <button class="profile profile-btn" id="profileBtn" aria-haspopup="dialog" aria-expanded="false" aria-controls="profileMenu">
```

Replace with:

```html
    <button class="btn btn-ghost" id="manageUsersBtn" hidden>Manage users</button>

    <div style="position:relative">
      <button class="profile profile-btn" id="profileBtn" aria-haspopup="dialog" aria-expanded="false" aria-controls="profileMenu">
```

Now add the JS. Add this new section right after the mutation functions from Step 6 (before the `/* 11. VALIDATION` comment, or at the end of the mutations section — placement inside the IIFE just needs to be after `apiPost` is defined):

```javascript
/* ============================================================
   10B. MANAGE USERS (owner/admin only; every check is re-enforced
   server-side in api/users/*.php regardless of what this UI allows)
   ============================================================ */

function renderUsersTable(){
  var rows = teamDirectory.map(function(u){
    var isOwner = u.role === "owner";
    var canManage = !isOwner && (currentUser.role === "owner" || u.role === "editor");
    var roleCell = (canManage && currentUser.role === "owner")
      ? '<select data-role-for="' + u.id + '"><option value="editor"' + (u.role === "editor" ? " selected" : "") + '>Editor</option><option value="admin"' + (u.role === "admin" ? " selected" : "") + '>Admin</option></select>'
      : esc(u.role);
    var actions = canManage
      ? '<button type="button" class="btn btn-ghost" data-remove-user="' + u.id + '">Remove</button>'
      : "";
    return "<tr>" +
      "<td>" + esc(u.email) + "</td>" +
      "<td>" + esc(u.name || "—") + "</td>" +
      "<td>" + esc(u.title || "—") + "</td>" +
      "<td>" + roleCell + "</td>" +
      "<td>" + esc(u.status) + "</td>" +
      "<td>" + actions + "</td>" +
      "</tr>";
  });
  $("usersTableBody").innerHTML = rows.join("");

  Array.prototype.forEach.call(document.querySelectorAll("[data-role-for]"), function(sel){
    sel.addEventListener("change", async function(){
      try{
        await apiPost("/api/users/update.php", { id:Number(sel.getAttribute("data-role-for")), role:sel.value });
        await openUsersModal();
        toast("ok","Role updated","");
      }catch(e){ toast("err","Could not update role", e.message); }
    });
  });
  Array.prototype.forEach.call(document.querySelectorAll("[data-remove-user]"), function(btn){
    btn.addEventListener("click", async function(){
      if(!window.confirm("Remove this user?")) return;
      try{
        await apiPost("/api/users/remove.php", { id:Number(btn.getAttribute("data-remove-user")) });
        await openUsersModal();
        toast("ok","User removed","");
      }catch(e){ toast("err","Could not remove user", e.message); }
    });
  });
}

async function openUsersModal(){
  await loadTeamDirectory();
  $("usersModalSub").textContent = currentUser.role === "owner"
    ? "Invite and manage admins and editors."
    : "Invite and manage editors.";
  $("inviteRoleAdminOption").hidden = currentUser.role !== "owner";
  renderUsersTable();
  $("usersModal").hidden = false;
  requestAnimationFrame(function(){ $("usersModal").classList.add("show"); $("scrim").hidden = false; $("scrim").classList.add("show"); });
}
function closeUsersModal(){
  $("usersModal").classList.remove("show");
  $("scrim").classList.remove("show");
  setTimeout(function(){
    $("usersModal").hidden = true;
    if($("briefModal").hidden && $("drawer").hidden) $("scrim").hidden = true;
  }, 200);
}
```

- [ ] **Step 10: Wire up the new UI**

Find (in the wiring section):

```javascript
  $("signOutBtn").addEventListener("click", function(){
    /* [AUTH] A real build would also invalidate the server-side session. */
    clearSession();
    location.reload();
  });
```

Replace with:

```javascript
  $("signOutBtn").addEventListener("click", signOut);

  if(currentUser.role === "owner" || currentUser.role === "admin"){
    $("manageUsersBtn").hidden = false;
    $("manageUsersBtn").addEventListener("click", openUsersModal);
    $("usersClose").addEventListener("click", closeUsersModal);
    $("inviteForm").addEventListener("submit", async function(e){
      e.preventDefault();
      var email = $("inviteEmail").value.trim();
      if(!isValidEmail(email)){
        setFieldError("f-invite-email","e-invite-email","Enter a valid email address.");
        return;
      }
      setFieldError("f-invite-email","e-invite-email","");
      try{
        await apiPost("/api/users/invite.php", {
          email:email, role:$("inviteRole").value, title:$("inviteTitle").value.trim()
        });
        $("inviteForm").reset();
        await openUsersModal();
        toast("ok","Invite sent", email + " can now sign in with Google.");
      }catch(e){ toast("err","Could not send invite", e.message); }
    });
  }else{
    $("newBriefBtn").hidden = true;
  }
```

Find:

```javascript
  $("briefForm").addEventListener("submit", function(e){
    e.preventDefault();
    var brief = validateBrief();
    if(!brief){
      toast("err","Brief incomplete","Fix the highlighted fields and submit again.");
      var firstInvalid = document.querySelector("#briefForm .field.invalid input, #briefForm .field.invalid textarea");
      if(firstInvalid) firstInvalid.focus();
      return;
    }
    var created = createProjectFromBrief(brief);
    $("briefForm").reset();
    $("bDueTime").value = "17:00";
    closeBriefModal();
    toast("ok","Project created", created.id + " — " + created.title + " is now in your pipeline.");
    setTimeout(function(){ openDrawer(created.id); }, 220);
  });
```

Replace with:

```javascript
  $("briefForm").addEventListener("submit", async function(e){
    e.preventDefault();
    var brief = validateBrief();
    if(!brief || !$("bEditor").value){
      toast("err","Brief incomplete","Fix the highlighted fields and submit again.");
      var firstInvalid = document.querySelector("#briefForm .field.invalid input, #briefForm .field.invalid textarea");
      if(firstInvalid) firstInvalid.focus();
      return;
    }
    brief.editorId = Number($("bEditor").value);
    try{
      var created = await createProjectFromBrief(brief);
      $("briefForm").reset();
      $("bDueTime").value = "17:00";
      closeBriefModal();
      toast("ok","Project created", created.id + " — " + created.title + " is now assigned.");
      setTimeout(function(){ openDrawer(created.id); }, 220);
    }catch(err){
      toast("err","Could not create project", err.message);
    }
  });
```

And find the Escape-key handler:

```javascript
    if(!$("briefModal").hidden){ closeBriefModal(); return; }
    if(!$("drawer").hidden){ closeDrawer(); }
  });
```

Replace with:

```javascript
    if(!$("usersModal").hidden){ closeUsersModal(); return; }
    if(!$("briefModal").hidden){ closeBriefModal(); return; }
    if(!$("drawer").hidden){ closeDrawer(); }
  });
```

- [ ] **Step 11: Rewrite `init()` / `enterApp()` for real session bootstrap**

Find:

```javascript
function showAuthScreen(){
  $("authScreen").hidden = false;
  $("appRoot").hidden = true;
}

function enterApp(){
  $("authScreen").hidden = true;
  $("appRoot").hidden = false;
  populateSelects();
  load();
  renderIdentity();
  setView("pipeline");
  renderAll();
  wireEvents();
  toast("ok", "Welcome, " + me().name.split(" ")[0], "You're viewing only the projects assigned to you.");
}

function init(){
  hydrateAccountsIntoPeople();
  wireAuthEvents();
  var existing = getSession();
  var knownEditor = existing && existing.editorId &&
    (existing.editorId === DEMO_ACCOUNT.editorId || PEOPLE.some(function(p){ return p.id === existing.editorId; }));
  if(knownEditor){
    SESSION = existing;
    enterApp();
  }else{
    clearSession();
    showAuthScreen();
  }
}

init();
```

Replace with:

```javascript
function showAuthScreen(){
  $("authScreen").hidden = false;
  $("appRoot").hidden = true;
  var params = new URLSearchParams(location.search);
  var err = params.get("authError");
  if(err){
    $("authError").textContent = err;
    $("authError").hidden = false;
    history.replaceState({}, "", location.pathname);
  }
}

async function enterApp(){
  $("authScreen").hidden = true;
  $("appRoot").hidden = false;
  populateSelects();
  await loadTeamDirectory();
  await load();
  renderIdentity();
  setView("pipeline");
  renderAll();
  wireEvents();
  toast("ok", "Welcome, " + (currentUser.name || currentUser.email).split(" ")[0],
    currentUser.role === "editor" ? "You're viewing only the projects assigned to you." : "You're viewing all projects.");
}

async function init(){
  var user = await fetchCurrentUser();
  if(user){
    enterApp();
  }else{
    showAuthScreen();
  }
}

init();
```

- [ ] **Step 12: Copy the finished file over the duplicate**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
cp index.html "Editing Pipeline Dashboard.html"
```

- [ ] **Step 13: Deploy**

Use the `hostinger:hosting-deploy-static-site` skill to upload the updated `index.html`.

- [ ] **Step 14: Verify end-to-end in a browser**

1. Visit `https://framefold.com/` in a fresh/incognito window — confirm you see only the auth screen with a single "Continue with Google" button (no GitHub button, no password form).
2. Sign in as `ethan@edhnmedia.com` — confirm you land in the dashboard, see the "Manage users" button, and the pipeline is empty (no mock projects).
3. Open "Manage users", invite a real second Google account you control as `role: editor`.
4. Sign that second account in (separate browser/incognito) via `https://framefold.com/` → Continue with Google — confirm it succeeds and shows an empty pipeline with no "Manage users" button and no "Create from brief" button.
5. Back as the owner, click "Create from brief", confirm the "Assigned editor" field is a dropdown listing the invited editor, fill out the form, submit — confirm a toast shows the real `PRJ-xxxx` id and the drawer opens.
6. Refresh the owner's page — confirm the created project is still there (proves it's coming from MySQL, not `localStorage`).
7. As the editor, refresh their tab — confirm the newly assigned project now appears in their pipeline.
8. In browser dev tools → Application → Local Storage for `framefold.com`, confirm it's empty or contains nothing app-related (no session, no projects).

- [ ] **Step 15: Commit**

```bash
cd "/Users/juhan/Documents/Editing Dashboard"
git add index.html "Editing Pipeline Dashboard.html"
git commit -m "Rewire frontend to real Google OAuth, MySQL-backed API, and role-aware UI"
```

---

## Task 7: Full manual QA pass

**Files:** None — verification only, using the already-deployed app.

- [ ] **Step 1: Owner-only admin management**

As the owner, invite a third test account as `role: admin`. Sign in as that admin. Confirm: "Manage users" is visible; the invite-role dropdown does NOT offer "Admin" (only Editor); the users table shows the owner and other admins read-only (no role `<select>`, no Remove button on their rows).

- [ ] **Step 2: Admin cannot escalate**

While signed in as the test admin, use curl with that admin's `PHPSESSID` cookie to attempt `POST /api/users/invite.php` with `{"email":"x@example.com","role":"admin"}`. Confirm `403 {"error":"admins_can_only_invite_editors"}`. Attempt `POST /api/users/update.php` targeting the owner's user id with any field. Confirm `403 {"error":"owner_is_protected"}`.

- [ ] **Step 3: Owner can manage admins**

As the owner, in "Manage users", change the test admin's role to Editor via the role dropdown, confirm it updates live in the table. Then remove the test admin account entirely via the Remove button, confirm it disappears from the list.

- [ ] **Step 4: Editor project scoping holds under load**

With two real invited editors each having at least one assigned project, confirm in the browser that each editor's pipeline shows only their own project(s), and that `GET /api/projects/list.php` called with one editor's session cookie never includes the other editor's project id.

- [ ] **Step 5: Revision and delivery workflow persists**

As an editor, add a revision note to one of their projects, resolve it, move the project through stages up to "Delivered", and attach a delivery link. Refresh the page after each action and confirm the change survived (proves every mutation round-trips through MySQL).

- [ ] **Step 6: Uninvited sign-in is rejected clearly**

In a private window, attempt Google sign-in with a Google account that was never invited. Confirm the auth screen shows the red "Your Google account is not invited. Ask an admin to invite you." message (not a generic error).

- [ ] **Step 7: No `localStorage` usage remains**

In dev tools, run `Object.keys(localStorage)` on `https://framefold.com/` before and after a full session (sign in, create a project, sign out). Confirm it's empty throughout.

- [ ] **Step 8: Record results**

Note any failures found back to the user directly rather than silently patching around them if the fix would change something in the approved spec — small bug fixes within a task's existing scope are fine to just fix and note.
