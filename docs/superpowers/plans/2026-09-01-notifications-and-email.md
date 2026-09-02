# Notifications and Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real outbound email for five events (invite, assignment/reassignment, editor-facing stage-change, admin-facing internal-review, and cron-driven due-date reminders) via a small Resend-backed mailer.

**Architecture:** A single non-web-servable helper library (`api/lib/mailer.php`) wraps Resend's HTTP API behind one low-level `ff_send_email()` and five notification-specific functions. Every existing mutation endpoint that needs to notify someone calls one of those five functions as a side effect; a new CLI-only cron script handles the two time-based due-date reminders.

**Tech Stack:** PHP 8 (no Composer — raw cURL, matching the rest of `api/`), MySQL/PDO, Resend HTTP API (`https://api.resend.com/emails`), Hostinger account cron.

**Spec:** `docs/superpowers/specs/2026-09-01-notifications-and-email-design.md`

## Global Constraints

- No Composer dependencies — use raw `curl_init()`/`curl_exec()`, matching `api/auth_helpers.php`'s existing `ff_http_post_form()`/`ff_http_get_bearer()` pattern.
- Every `ff_send_email()` failure (network error, non-2xx, missing config) is logged via `error_log()` and **never thrown** — a mail failure must never turn an otherwise-successful invite/create/update request into an error response.
- No automated test suite exists for this project (manual/curl verification only, matching every prior task in this codebase). Each task below is verified by tracing the code by hand against the listed scenarios and, where noted, by a manual `php -l` style read-through — there is no local PHP runtime available, so treat "test" steps as rigorous manual trace-throughs, not runnable commands.
- Never commit `api/config.php` (already gitignored) — only ever add new keys to `api/config.example.php`.
- The due-date cron script must refuse to run under any SAPI other than `cli`, and must also be blocked at the `.htaccess` level — it is never meant to be reachable over HTTP.
- All new/changed files must remain consistent with the existing style in `api/`: `ff_`-prefixed function names, `ff_json()`/`ff_require_role()` for endpoint guards, PDO prepared statements for every query touching request input.

---

### Task 1: Mailer library, config, schema migration, and cron lockdown

**Files:**
- Create: `api/lib/mailer.php`
- Modify: `api/config.example.php`
- Modify: `api/schema.sql`
- Modify: `api/setup.php`
- Create: `api/cron/.htaccess`

**Interfaces:**
- Consumes: `ff_config()` from `api/db.php` (already exists — returns the array from `api/config.php`).
- Produces (used by Tasks 2-5):
  - `ff_send_email(string $to, string $subject, string $html): void`
  - `ff_notify_invite(string $email, string $inviterName, string $role): void`
  - `ff_notify_assigned(string $editorEmail, string $editorName, array $project): void` — `$project` needs keys `title`, `client`, `due_at` (a MySQL `DATETIME` string, e.g. `"2026-09-10 21:00:00"`).
  - `ff_notify_stage_change(string $editorEmail, string $editorName, array $project, string $newStage): void` — `$project` needs `title`, `client`. `$newStage` is `'revisions_requested'` or `'approved'`.
  - `ff_notify_internal_review(array $adminEmails, array $project): void` — `$project` needs `title`, `client`.
  - `ff_notify_due_reminder(string $editorEmail, string $editorName, array $project, string $kind): void` — `$project` needs `title`, `client`, `due_at`. `$kind` is `'3day'` or `'due'`.
  - New DB columns: `projects.reminder_3day_sent_at DATETIME NULL`, `projects.reminder_due_sent_at DATETIME NULL`.
  - New config keys: `$cfg['resend']['api_key']`, `$cfg['app']['mail_from']`.

- [ ] **Step 1: Create the mailer library**

Create `api/lib/mailer.php`:

```php
<?php
require_once __DIR__ . '/../db.php';

function ff_send_email(string $to, string $subject, string $html): void {
    $cfg = ff_config();
    $apiKey = $cfg['resend']['api_key'] ?? '';
    $from = $cfg['app']['mail_from'] ?? '';
    if ($apiKey === '' || $from === '') {
        error_log('[ff-mail] missing resend config, skipping send to ' . $to);
        return;
    }

    $payload = json_encode([
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false || $status < 200 || $status >= 300) {
        error_log('[ff-mail] send failed to ' . $to . ' status=' . $status . ' curl_error=' . $curlError . ' body=' . $result);
    }
}

function ff_notify_invite(string $email, string $inviterName, string $role): void {
    $baseUrl = ff_config()['app']['base_url'];
    $html = '<p>' . htmlspecialchars($inviterName) . ' invited you to <strong>Frame &amp; Fold</strong> (' . htmlspecialchars($role) . ' access).</p>'
        . '<p>Sign in with Google using this email address to get started:</p>'
        . '<p><a href="' . htmlspecialchars($baseUrl) . '">' . htmlspecialchars($baseUrl) . '</a></p>';
    ff_send_email($email, "You're invited to Frame & Fold", $html);
}

function ff_notify_assigned(string $editorEmail, string $editorName, array $project): void {
    $baseUrl = ff_config()['app']['base_url'];
    $due = date('M j, Y g:i A', strtotime($project['due_at']));
    $html = '<p>Hi ' . htmlspecialchars($editorName) . ',</p>'
        . '<p><strong>' . htmlspecialchars($project['title']) . '</strong> (' . htmlspecialchars($project['client']) . ') has been assigned to you, due ' . htmlspecialchars($due) . '.</p>'
        . '<p><a href="' . htmlspecialchars($baseUrl) . '">Open Frame &amp; Fold</a></p>';
    ff_send_email($editorEmail, 'New assignment: ' . $project['title'], $html);
}

function ff_notify_stage_change(string $editorEmail, string $editorName, array $project, string $newStage): void {
    $baseUrl = ff_config()['app']['base_url'];
    $stageLabels = [
        'revisions_requested' => 'Revisions requested',
        'approved' => 'Approved',
    ];
    $label = $stageLabels[$newStage] ?? $newStage;
    $html = '<p>Hi ' . htmlspecialchars($editorName) . ',</p>'
        . '<p><strong>' . htmlspecialchars($project['title']) . '</strong> (' . htmlspecialchars($project['client']) . ') is now: <strong>' . htmlspecialchars($label) . '</strong>.</p>'
        . '<p><a href="' . htmlspecialchars($baseUrl) . '">Open Frame &amp; Fold</a></p>';
    ff_send_email($editorEmail, $label . ': ' . $project['title'], $html);
}

function ff_notify_internal_review(array $adminEmails, array $project): void {
    $baseUrl = ff_config()['app']['base_url'];
    $html = '<p><strong>' . htmlspecialchars($project['title']) . '</strong> (' . htmlspecialchars($project['client']) . ') has moved to internal review.</p>'
        . '<p><a href="' . htmlspecialchars($baseUrl) . '">Open Frame &amp; Fold</a></p>';
    foreach ($adminEmails as $email) {
        ff_send_email($email, 'Internal review: ' . $project['title'], $html);
    }
}

function ff_notify_due_reminder(string $editorEmail, string $editorName, array $project, string $kind): void {
    $baseUrl = ff_config()['app']['base_url'];
    $due = date('M j, Y g:i A', strtotime($project['due_at']));
    $subject = $kind === '3day'
        ? 'Due in 3 days: ' . $project['title']
        : 'Now due: ' . $project['title'];
    $message = $kind === '3day'
        ? 'is due in 3 days (' . htmlspecialchars($due) . ').'
        : 'was due ' . htmlspecialchars($due) . '.';
    $html = '<p>Hi ' . htmlspecialchars($editorName) . ',</p>'
        . '<p><strong>' . htmlspecialchars($project['title']) . '</strong> (' . htmlspecialchars($project['client']) . ') ' . $message . '</p>'
        . '<p><a href="' . htmlspecialchars($baseUrl) . '">Open Frame &amp; Fold</a></p>';
    ff_send_email($editorEmail, $subject, $html);
}
```

- [ ] **Step 2: Add the new config keys to the example template**

In `api/config.example.php`, the file currently reads:

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
        'redirect_uri' => 'https://framefold.io/api/auth/google/callback.php',
    ],
    'app' => [
        'base_url' => 'https://framefold.io',
        'owner_email' => 'ethan@edhnmedia.com',
    ],
    'setup_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
```

Replace it with:

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
        'redirect_uri' => 'https://framefold.io/api/auth/google/callback.php',
    ],
    'resend' => [
        'api_key' => 'CHANGE_ME',
    ],
    'app' => [
        'base_url' => 'https://framefold.io',
        'owner_email' => 'ethan@edhnmedia.com',
        'mail_from' => 'Frame & Fold <notifications@framefold.io>',
    ],
    'setup_token' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
];
```

- [ ] **Step 3: Add the new columns to `schema.sql` for fresh installs**

In `api/schema.sql`, the `projects` table currently ends with:

```sql
  delivered_on_time TINYINT(1) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (editor_id) REFERENCES users(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Change it to:

```sql
  delivered_on_time TINYINT(1) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  reminder_3day_sent_at DATETIME NULL,
  reminder_due_sent_at DATETIME NULL,
  FOREIGN KEY (editor_id) REFERENCES users(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Add an idempotent migration to `setup.php` for the already-live database**

`schema.sql`'s `CREATE TABLE IF NOT EXISTS` won't add columns to a table that already exists in production. `api/setup.php` currently reads:

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
```

Insert an idempotent column-migration block between the schema loop and the owner-seed block:

```php
$pdo = ff_db();
$sql = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}

// Idempotent migrations for columns added after schema.sql was first
// applied to production — safe to re-run this whole script at any time.
$hasCol = $pdo->query("SHOW COLUMNS FROM projects LIKE 'reminder_3day_sent_at'")->fetch();
if (!$hasCol) {
    $pdo->exec('ALTER TABLE projects ADD COLUMN reminder_3day_sent_at DATETIME NULL');
}
$hasCol = $pdo->query("SHOW COLUMNS FROM projects LIKE 'reminder_due_sent_at'")->fetch();
if (!$hasCol) {
    $pdo->exec('ALTER TABLE projects ADD COLUMN reminder_due_sent_at DATETIME NULL');
}

$ownerEmail = $cfg['app']['owner_email'];
```

(The rest of the file — the owner-seed block — is unchanged.)

- [ ] **Step 5: Lock down the future cron directory**

Create `api/cron/.htaccess`:

```
Require all denied
```

This unconditionally blocks every file under `api/cron/` from direct HTTP access, regardless of filename — appropriate since this directory will only ever hold scripts meant to run via the Hostinger account cron (a shell command), never a URL fetch.

- [ ] **Step 6: Verify by tracing, not running**

There is no local PHP runtime and no automated test suite in this project (matches every prior task). Verify by reading the code against these scenarios:

1. `ff_send_email()` with a valid `$cfg['resend']['api_key']` and `$cfg['app']['mail_from']` set: builds a JSON payload with `from`/`to`/`subject`/`html`, POSTs to `https://api.resend.com/emails` with a Bearer header, and does not throw regardless of the response.
2. `ff_send_email()` with `resend.api_key` missing or empty: logs and returns immediately, makes no HTTP call.
3. `ff_send_email()` where the cURL call itself fails (e.g. DNS failure returns `curl_exec() === false`): logged, not thrown.
4. Every notify function escapes `$project['title']`/`$project['client']`/`$editorName`/`$inviterName`/`$role` via `htmlspecialchars()` before interpolating into HTML — confirms no injection risk from a project title or user name containing `<`/`&`/etc.
5. `api/setup.php`, run a second time against a database that already has both new columns: the two `SHOW COLUMNS ... LIKE` queries both return a row, so neither `ALTER TABLE` executes — no error from attempting to add a duplicate column.

- [ ] **Step 7: Commit**

```bash
git add api/lib/mailer.php api/config.example.php api/schema.sql api/setup.php api/cron/.htaccess
git commit -m "Add Resend-backed mailer library, config keys, and reminder-tracking columns"
```

---

### Task 2: Invite email

**Files:**
- Modify: `api/users/invite.php`

**Interfaces:**
- Consumes: `ff_notify_invite(string $email, string $inviterName, string $role): void` (Task 1).

- [ ] **Step 1: Wire the notification into the invite endpoint**

`api/users/invite.php` currently reads:

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

Replace it with:

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
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
ff_notify_invite($email, $me['name'] ?? $me['email'], $requestedRole);
ff_json(201, ['id' => (int)$pdo->lastInsertId()]);
```

The only changes: the new `require_once` line, and the `ff_notify_invite(...)` call inserted between the `INSERT` and the final `ff_json(...)`.

- [ ] **Step 2: Verify by tracing**

1. A successful invite (valid email, allowed role) now calls `ff_notify_invite($email, ...)` with the inviting user's own name (or email if they have no name set) and the role that was actually granted.
2. Every early `ff_json(...)` rejection (`invalid_email`, `invalid_role`, `admins_can_only_invite_editors`, `already_exists`) returns before the `INSERT`/notify lines are ever reached — no email is sent for a rejected invite.
3. `ff_json()` calls `exit` (confirmed in `api/auth_helpers.php`), so the notify call can never be skipped by a later `ff_json()` short-circuit — the ordering here (`INSERT` → notify → `ff_json(201, ...)`) is the only reachable path once the insert succeeds.

- [ ] **Step 3: Commit**

```bash
git add api/users/invite.php
git commit -m "Send an invite email when a user is invited"
```

---

### Task 3: Assignment email on project creation

**Files:**
- Modify: `api/projects/create.php`

**Interfaces:**
- Consumes: `ff_notify_assigned(string $editorEmail, string $editorName, array $project): void` (Task 1).

- [ ] **Step 1: Broaden the editor lookup and notify on success**

`api/projects/create.php` currently reads:

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

if (!in_array($input['priority'], ['Urgent', 'High', 'Medium', 'Low'], true)) {
    ff_json(422, ['error' => 'invalid_priority']);
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
```

Replace it with:

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
$me = ff_require_role(['owner', 'admin']);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

foreach (['title', 'client', 'editorId', 'dueAt', 'priority', 'platform', 'aspect'] as $key) {
    if (empty($input[$key])) {
        ff_json(422, ['error' => 'missing_field', 'field' => $key]);
    }
}

if (!in_array($input['priority'], ['Urgent', 'High', 'Medium', 'Low'], true)) {
    ff_json(422, ['error' => 'invalid_priority']);
}

$pdo = ff_db();
$editorCheck = $pdo->prepare("SELECT id, email, name FROM users WHERE id = ? AND role = 'editor'");
$editorCheck->execute([$input['editorId']]);
$editor = $editorCheck->fetch();
if (!$editor) {
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

ff_notify_assigned($editor['email'], $editor['name'] ?? $editor['email'], [
    'title' => $input['title'],
    'client' => $input['client'],
    'due_at' => $dueAt,
]);

ff_json(201, ['id' => $id]);
```

Changes: the new `require_once`; `$editorCheck`'s `SELECT` now also fetches `email, name` and the fetched row is kept as `$editor` (rather than being discarded); and the `ff_notify_assigned(...)` call is added right before the final `ff_json(201, ...)`, using the already-normalized `$dueAt` (not the raw, possibly-differently-formatted `$input['dueAt']`).

- [ ] **Step 2: Verify by tracing**

1. Every failure path (`missing_field`, `invalid_priority`, `invalid_editor`, `id_generation_failed`) returns before the notify call — no email sends for a rejected creation.
2. On success, the assigned editor's row is the same one validated against `role = 'editor'` earlier in the request — no second, separate lookup that could target a different user.
3. `$project['due_at']` passed to `ff_notify_assigned` is `$dueAt` (the DB-formatted `Y-m-d H:i:s` string), matching the format `ff_notify_assigned`'s `strtotime()` call expects — same value that gets written to the `projects` row, so the email's due date always matches what's actually stored.

- [ ] **Step 3: Commit**

```bash
git add api/projects/create.php
git commit -m "Email the assigned editor when a project is created"
```

---

### Task 4: Reassignment, stage-change, and internal-review notifications

**Files:**
- Modify: `api/projects/update.php`

**Interfaces:**
- Consumes: `ff_notify_assigned()`, `ff_notify_stage_change()`, `ff_notify_internal_review()` (Task 1).

- [ ] **Step 1: Track the pre-mutation stage and editor, and require the mailer**

`api/projects/update.php` currently starts:

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
```

Replace it with:

```php
<?php
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../lib/mailer.php';
$me = ff_require_auth();
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$projectId = $input['id'] ?? '';

$pdo = ff_db();
$stmt = $pdo->prepare('SELECT editor_id, stage FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    ff_json(404, ['error' => 'not_found']);
}
if ($me['role'] === 'editor' && (int)$project['editor_id'] !== (int)$me['id']) {
    ff_json(403, ['error' => 'not_your_project']);
}
$stageBefore = $project['stage'];
$editorBefore = (int)$project['editor_id'];
```

- [ ] **Step 2: Notify the new editor on reassignment**

Inside the `if (isset($input['title'])) { ... }` block, immediately after the `try { ... } catch (Throwable $e) { ... }` that wraps the brief-edit transaction (i.e., right after its closing `}`, still inside the outer `if`), add:

```php
    if ((int)$input['editorId'] !== $editorBefore) {
        $newEditorStmt = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
        $newEditorStmt->execute([$input['editorId']]);
        $newEditor = $newEditorStmt->fetch();
        if ($newEditor) {
            ff_notify_assigned($newEditor['email'], $newEditor['name'] ?? $newEditor['email'], [
                'title' => $input['title'],
                'client' => $input['client'],
                'due_at' => $dueAt,
            ]);
        }
    }
```

So that section of the file reads, in full:

```php
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

    if ((int)$input['editorId'] !== $editorBefore) {
        $newEditorStmt = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
        $newEditorStmt->execute([$input['editorId']]);
        $newEditor = $newEditorStmt->fetch();
        if ($newEditor) {
            ff_notify_assigned($newEditor['email'], $newEditor['name'] ?? $newEditor['email'], [
                'title' => $input['title'],
                'client' => $input['client'],
                'due_at' => $dueAt,
            ]);
        }
    }
}
```

(Everything above the `if ((int)$input['editorId'] !== $editorBefore)` block is unchanged from the file's current content — only that block and its surrounding closing `}` for the outer `if (isset($input['title']))` are new/moved.)

- [ ] **Step 3: Notify on stage transitions, after every mutation branch runs**

The file has several independent `if (isset($input[...]))` blocks after the brief-edit block (`stage`, `deliverableId`+`done`, `newRevisionNote`, `resolveRevisionId`, `deliveryLink`), ending with:

```php
if (array_key_exists('deliveryLink', $input)) {
    $pdo->prepare('UPDATE projects SET delivery_link = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$input['deliveryLink'], $projectId]);
}

ff_json(200, ['ok' => true]);
```

Leave every one of those blocks (`stage`, `deliverableId`, `newRevisionNote`, `resolveRevisionId`, `deliveryLink`) exactly as they are. Insert a single before/after comparison right before the final `ff_json(200, ...)`:

```php
if (array_key_exists('deliveryLink', $input)) {
    $pdo->prepare('UPDATE projects SET delivery_link = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$input['deliveryLink'], $projectId]);
}

$afterStmt = $pdo->prepare(
    'SELECT p.stage, p.title, p.client, u.email AS editor_email, u.name AS editor_name
     FROM projects p JOIN users u ON u.id = p.editor_id WHERE p.id = ?'
);
$afterStmt->execute([$projectId]);
$after = $afterStmt->fetch();
if ($after && $after['stage'] !== $stageBefore) {
    if (in_array($after['stage'], ['revisions_requested', 'approved'], true)) {
        ff_notify_stage_change($after['editor_email'], $after['editor_name'] ?? $after['editor_email'], $after, $after['stage']);
    }
    if ($after['stage'] === 'internal_review') {
        $adminEmails = $pdo->query("SELECT email FROM users WHERE role IN ('owner','admin') AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        ff_notify_internal_review($adminEmails, $after);
    }
}

ff_json(200, ['ok' => true]);
```

This is deliberately a single before/after comparison rather than a notify call inside the `stage` block specifically: `newRevisionNote` also changes `stage` to `revisions_requested` as a side effect (see the existing block below), and a per-branch hook would miss that path. Comparing the project's actual stage before the request started to its actual stage once every mutation has run catches both the explicit `stage` field and any side-effect stage change uniformly, including ones added by future code.

- [ ] **Step 4: Verify by tracing**

Trace each of these request shapes against the final file:

1. `{id, title, client, editorId: <same as before>, ...}` (an edit that keeps the same editor): `$editorBefore === (int)$input['editorId']`, so no assignment email fires. The stage comparison at the end also sees no stage change (brief-edit never touches `stage`), so no stage-change email fires either.
2. `{id, title, client, editorId: <different>, ...}`: the reassignment block fires exactly one `ff_notify_assigned()` to the new editor. The old editor receives nothing.
3. `{id, stage: "revisions_requested"}`: the existing `stage` block runs (version bump included), and the after-comparison detects `stage` changed from whatever it was to `revisions_requested`, firing `ff_notify_stage_change()` to the (unchanged) assigned editor.
4. `{id, newRevisionNote: "..."}` on a project not already `approved`/`delivered`: the existing block inserts the revision and sets `stage = 'revisions_requested'` as a side effect. The after-comparison still fires `ff_notify_stage_change()` — same trigger, different code path, exactly as intended.
5. `{id, stage: "internal_review"}`: fires `ff_notify_internal_review()` to every `active` owner/admin, and does **not** fire `ff_notify_stage_change()` (that function is only called for `revisions_requested`/`approved`).
6. `{id, stage: "editing"}` (a stage not in the notify list): the after-comparison sees a stage change but neither `if` inside it matches, so no email of either kind sends.
7. `{id, deliverableId: 3, done: true}` (no `stage` key at all): `$after['stage']` will equal `$stageBefore` (nothing changed it), so the whole notification block is skipped — confirms toggling a deliverable never spuriously emails anyone.
8. Every early `ff_json(...)` rejection exits (via `ff_json()`'s own `exit`) before the end-of-file comparison block runs — the 404/`not_your_project` 403 exit even before `$stageBefore` is captured; the brief-edit `forbidden` 403 and any 422 exit after `$stageBefore` is captured but before any mutation happens. Either way, a rejected request never reaches the notification code.

- [ ] **Step 5: Commit**

```bash
git add api/projects/update.php
git commit -m "Notify on reassignment, revisions/approval, and internal review"
```

---

### Task 5: Due-date reminder cron script

**Files:**
- Create: `api/cron/due_date_check.php`

**Interfaces:**
- Consumes: `ff_notify_due_reminder(string $editorEmail, string $editorName, array $project, string $kind): void` (Task 1), `projects.reminder_3day_sent_at`/`projects.reminder_due_sent_at` (Task 1).

- [ ] **Step 1: Write the cron script**

Create `api/cron/due_date_check.php`:

```php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/mailer.php';

$pdo = ff_db();

$threeDayStmt = $pdo->query(
    "SELECT p.id, p.title, p.client, p.due_at, u.email AS editor_email, u.name AS editor_name
     FROM projects p JOIN users u ON u.id = p.editor_id
     WHERE p.stage NOT IN ('approved','delivered')
       AND p.due_at > NOW()
       AND p.due_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)
       AND p.reminder_3day_sent_at IS NULL"
);
$threeDayCount = 0;
foreach ($threeDayStmt->fetchAll() as $row) {
    ff_notify_due_reminder($row['editor_email'], $row['editor_name'] ?? $row['editor_email'], $row, '3day');
    $pdo->prepare('UPDATE projects SET reminder_3day_sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
    $threeDayCount++;
}

$dueStmt = $pdo->query(
    "SELECT p.id, p.title, p.client, p.due_at, u.email AS editor_email, u.name AS editor_name
     FROM projects p JOIN users u ON u.id = p.editor_id
     WHERE p.stage NOT IN ('approved','delivered')
       AND p.due_at <= NOW()
       AND p.reminder_due_sent_at IS NULL"
);
$dueCount = 0;
foreach ($dueStmt->fetchAll() as $row) {
    ff_notify_due_reminder($row['editor_email'], $row['editor_name'] ?? $row['editor_email'], $row, 'due');
    $pdo->prepare('UPDATE projects SET reminder_due_sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
    $dueCount++;
}

error_log("[ff-cron] due_date_check: sent $threeDayCount 3-day reminders, $dueCount due/overdue reminders");
```

Note this requires `api/db.php` directly, not `api/auth_helpers.php` — there is no HTTP session in a CLI cron invocation, matching the existing precedent in `api/setup.php` (also `db.php`-only, also CLI/token-triggered rather than session-authenticated).

- [ ] **Step 2: Verify by tracing**

1. Run under any SAPI other than `cli` (i.e., requested directly over HTTP): exits with 403 before requiring `db.php` or touching the database at all. Combined with `api/cron/.htaccess` from Task 1 (which blocks it at the web-server level before PHP even runs), this is blocked twice over.
2. A project due in exactly 2 days, active stage, `reminder_3day_sent_at IS NULL`: matches the first query (`due_at > NOW()` and `<= NOW() + 3 days`), gets one email, then `reminder_3day_sent_at` is set — a second run of the script the same day will no longer match (`IS NULL` now fails), so no duplicate.
3. A project 1 hour past its `due_at`, active stage, `reminder_due_sent_at IS NULL`: matches the second query, gets one email, then `reminder_due_sent_at` is set — same non-duplication guarantee on a second run.
4. A project that is both within 3 days of due **and** already past due at the moment the script runs (edge case: a project due in the past few hours might satisfy `due_at <= NOW()` for the second query while also having been eligible for the first query on an earlier run): the two queries are independent and use two independent columns, so a project can receive at most one 3-day reminder and at most one due reminder — never zero, never more than one of each, regardless of how many times the cron fires in between.
5. A project already `approved` or `delivered`: excluded from both queries via `stage NOT IN (...)`, so it never receives a due-date reminder even if its `due_at` technically falls in either window and it was never reminded — matches the intent that reminders are about *needing attention*, not about closed work.
6. `$row['due_at']` passed into `ff_notify_due_reminder()` is the raw MySQL `DATETIME` string from the query, matching what `ff_notify_due_reminder`'s internal `strtotime()` call expects (same shape `ff_notify_assigned` already handles).

- [ ] **Step 3: Commit**

```bash
git add api/cron/due_date_check.php
git commit -m "Add cron script for 3-day-out and past-due reminder emails"
```

---

## After implementation (controller-only — not a dispatched task)

These steps require live Hostinger/production access that a worktree-isolated implementer subagent does not have, and are consequential/external actions — they are performed directly by whoever is coordinating this plan's execution, after all five tasks pass review:

1. Add `resend.api_key` and `app.mail_from` to the **production** `api/config.php` (never committed).
2. Deploy all changed/new files to `framefold.io`: `api/lib/mailer.php`, `api/lib/.htaccess`, `api/cron/.htaccess`, `api/cron/due_date_check.php`, `api/projects/create.php`, `api/projects/update.php`, `api/users/invite.php`, `api/setup.php`, `api/schema.sql`. The `api/lib/` and `api/cron/` directories don't exist on the server yet and must be created. Both `.htaccess` files are dotfiles — some FTP clients and file managers hide these by default; confirm they actually uploaded, not just the visible files, since `create.php`/`update.php`/`invite.php` all `require_once` the new mailer file and will 500 on every request if it's missing.
3. Run the migration via CLI (not HTTP — `api/setup.php` is blocked from web access by `api/.htaccess`, and the CLI-argv branch added in this fix wave is what makes this possible): `php /home/u487353131/domains/framefold.io/public_html/api/setup.php "token=<setup_token>"` — via SSH if available, or as a one-off Hostinger account cron job scheduled a minute out and deleted immediately after it fires.
4. Create the recurring Hostinger account cron job: schedule `0 * * * *`, command `php /home/u487353131/domains/framefold.io/public_html/api/cron/due_date_check.php`. Confirm the interpreter path — Hostinger shared hosting sometimes needs an absolute PHP path (e.g. `/usr/bin/php` or a version-specific alt-php path) rather than bare `php`.
5. Verify the lockdown actually took effect: `curl -i https://framefold.io/api/cron/due_date_check.php` and `curl -i https://framefold.io/api/lib/mailer.php` should both return 403.
6. Run the manual QA checklist from the spec's Testing section against production, plus two additional scenarios this fix wave's findings surfaced: (a) extend a past-due project's deadline via edit-brief and confirm a new past-due reminder fires again after the new deadline also passes (not silently skipped because the old reminder flag was never cleared); (b) as an admin, drag your own project's card to Internal Review and confirm you do NOT receive your own internal-review email, while other admins do.
