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
