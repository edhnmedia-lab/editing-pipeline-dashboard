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
$threeDayFailures = 0;
foreach ($threeDayStmt->fetchAll() as $row) {
    try {
        ff_notify_due_reminder($row['editor_email'], $row['editor_name'] ?? $row['editor_email'], $row, '3day');
        $pdo->prepare('UPDATE projects SET reminder_3day_sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $threeDayCount++;
    } catch (Throwable $e) {
        error_log('[ff-cron] 3-day reminder failed for project ' . $row['id'] . ': ' . $e->getMessage());
        $threeDayFailures++;
    }
}

$dueStmt = $pdo->query(
    "SELECT p.id, p.title, p.client, p.due_at, u.email AS editor_email, u.name AS editor_name
     FROM projects p JOIN users u ON u.id = p.editor_id
     WHERE p.stage NOT IN ('approved','delivered')
       AND p.due_at <= NOW()
       AND p.reminder_due_sent_at IS NULL"
);
$dueCount = 0;
$dueFailures = 0;
foreach ($dueStmt->fetchAll() as $row) {
    try {
        ff_notify_due_reminder($row['editor_email'], $row['editor_name'] ?? $row['editor_email'], $row, 'due');
        $pdo->prepare('UPDATE projects SET reminder_due_sent_at = NOW() WHERE id = ?')->execute([$row['id']]);
        $dueCount++;
    } catch (Throwable $e) {
        error_log('[ff-cron] past-due reminder failed for project ' . $row['id'] . ': ' . $e->getMessage());
        $dueFailures++;
    }
}

error_log("[ff-cron] due_date_check: sent $threeDayCount 3-day reminders ($threeDayFailures failed), $dueCount due/overdue reminders ($dueFailures failed)");
