# Frame & Fold Dashboard: Notifications and Email

Status: Approved for planning
Date: 2026-09-01

## Context

The dashboard currently has no outbound email at all. Inviting someone
creates a `users` row with `status='invited'` and nothing else happens —
the admin has to separately tell the invitee, out of band, that they've
been invited and should sign in. There is also no notification of any
kind when a project is assigned, changes stage, or approaches its
deadline; the existing notification bell in the header is a purely
client-derived, per-session UI convenience (computed from whatever
projects are currently loaded — overdue, due soon, open revisions) and
is not persisted or delivered anywhere.

Goal: add real outbound email for five specific events, backed by a
small reusable mailer, without turning this into a general notification
platform.

## Email provider

No email service exists for `framefold.io` today — no Titan Mail order,
no MX/SPF/DKIM records. **Resend** is used as the sending provider:
transactional email over a plain HTTPS API (no library/SDK needed — a
single cURL POST per send), a generous free tier for this app's volume,
and out-of-the-box SPF/DKIM once the domain is verified via a few DNS
records added through the Hostinger API.

The user creates the free Resend account and adds `framefold.io` as a
sending domain themselves (this cannot be done via any tool available
here); Resend then issues the exact DNS records to verify it and an API
key. Both get handed over and wired into the config the same way the
Google OAuth credentials were during the original migration.

## Architecture

A new non-web-servable helper, `api/lib/mailer.php`, holds:

- `ff_send_email(string $to, string $subject, string $html): void` —
  cURL POST to `https://api.resend.com/emails` with `Authorization:
  Bearer {resend.api_key}` and `from: {app.mail_from}`. Wrapped so that
  **any failure (network error, non-2xx response, missing config) is
  logged via `error_log` and swallowed, never thrown.** Every trigger
  point in this spec is a side effect of an otherwise-successful
  mutation (an invite, a project update); a Resend outage must never
  turn a successful database write into a failed HTTP response for the
  person using the app.
- One function per notification type (listed below), each building a
  simple HTML string and calling `ff_send_email()`. Templates are plain,
  readable transactional HTML (heading, one or two lines of context, a
  link) — no visual design system needed for internal transactional
  mail.

New config keys in `api/config.php` / `config.example.php`:

```php
'resend' => ['api_key' => 'CHANGE_ME'],
'app' => [
    'base_url' => 'https://framefold.io',   // already exists
    'owner_email' => 'ethan@edhnmedia.com', // already exists
    'mail_from' => 'Frame & Fold <notifications@framefold.io>',
],
```

`mail_from` does not need to correspond to a real mailbox — Resend sends
as any address on a verified domain, whether or not that address can
receive mail.

## Data model change

Two new nullable columns on `projects`, used only to dedupe the due-date
reminder cron (each reminder fires exactly once per project):

```sql
ALTER TABLE projects
  ADD COLUMN reminder_3day_sent_at DATETIME NULL,
  ADD COLUMN reminder_due_sent_at DATETIME NULL;
```

Since the database already has live data, this is applied to production
via the same token-guarded mechanism `setup.php` already uses, made
idempotent: check `SHOW COLUMNS ... LIKE` before altering, so the script
stays safe to re-run and doubles as the project's lightweight migration
tool going forward.

## The five triggers

### 1. Invite email

`api/users/invite.php`, after the existing `INSERT INTO users` succeeds:
call `ff_notify_invite($email, $inviterName, $role)`. Content: "{inviter
name} invited you to Frame & Fold ({role} access). Sign in with Google
using this email address to get started," linking to `{app.base_url}`.
No token/magic-link — sign-in is already Google OAuth gated by the
`users.email` lookup added in the original migration, so a plain link to
the homepage is sufficient and there is no separate "accept invite" flow
to build.

### 2. Assigned email

Fires to the assigned editor in two places:

- `api/projects/create.php`, after a project is successfully created.
- `api/projects/update.php`'s brief-edit branch, when `editorId` in the
  request differs from the project's `editor_id` before the update
  (i.e., a reassignment, not just an edit that leaves the same editor in
  place).

Content: "{title} ({client}) has been assigned to you, due {due date}."
linking to the project (deep link is out of scope — the app has no
per-project URL routing today; link to `{app.base_url}` and let them
find it, same as the invite email).

### 3. Editor-facing stage-change emails (revisions requested / approved)

`api/projects/update.php` currently has multiple code paths that can
change a project's `stage` — the explicit `stage` field, and the
`newRevisionNote` path (which sets `stage = 'revisions_requested'` as a
side effect of adding a revision). Rather than duplicating the
notification call in each path, `update.php` captures the project's
`stage` before any mutation runs (it already fetches the row for the
ownership check) and re-reads it after all mutations complete. If the
stage actually changed and the new stage is `revisions_requested` or
`approved`, the assigned editor gets one email naming the new stage.
This is deliberately a single before/after comparison rather than
per-branch hooks, so no current or future code path that changes stage
can silently bypass it.

Other stage transitions (`assets_ready`, `editing`, `client_review`,
`delivered`) do not email the editor — matches the "only the meaningful
ones" decision.

### 4. Admin-facing internal-review email

Same before/after stage comparison in `update.php`: if the new stage is
`internal_review` and it wasn't before, every `owner`/`admin` user
(`SELECT email FROM users WHERE role IN ('owner','admin') AND status =
'active'`) gets one email naming the project and client.

### 5. Due-date reminders (cron)

A new script, `api/cron/due_date_check.php`, runs hourly via a Hostinger
account cron job (`0 * * * *`). It is **not** a normal web endpoint:

- Guarded at the top with `if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }`,
  and additionally blocked from direct HTTP access via the same
  `.htaccess` `<FilesMatch>` deny-all rule already protecting
  `config.php`/`schema.sql`/`setup.php` — defense in depth, since
  Hostinger's cron runs it as a shell command (`php
  /path/to/due_date_check.php`), never as a URL fetch.
- Queries for two disjoint sets of active (`stage NOT IN
  ('approved','delivered')`) projects:
  - `due_at <= NOW() + INTERVAL 3 DAY AND due_at > NOW() AND
    reminder_3day_sent_at IS NULL` → send the 3-day-out reminder to the
    assigned editor, then set `reminder_3day_sent_at = NOW()`.
  - `due_at <= NOW() AND reminder_due_sent_at IS NULL` → send the
    past-due reminder to the assigned editor, then set
    `reminder_due_sent_at = NOW()`.
- Each project can only ever receive each of these two emails once,
  regardless of how many times the cron runs (matches the "once each"
  decision) — enforced by the two sent-at columns, not by the cron's own
  scheduling.

## Error handling

- Every `ff_send_email()` call failure is logged server-side and never
  surfaces to the end user or blocks the underlying request — an invite,
  a project creation, or a stage change always succeeds or fails on its
  own merits, independent of whether the accompanying email went out.
- The cron script logs (via `error_log`) how many reminders it sent and
  any send failures, for manual inspection via Hostinger's cron job
  output if something looks wrong later.
- No retry queue, no bounce handling, no delivery-status webhook — out
  of scope for this round; Resend's own dashboard is the source of truth
  for whether a given email actually sent.

## Testing

Matches the project's existing manual-QA-only convention:

1. Invite a test address → confirm the email arrives and its link signs
   in correctly.
2. Create a project → assigned editor receives the assignment email.
3. Reassign an existing project to a different editor via edit-brief →
   the new editor receives the assignment email; the old editor does
   not receive anything.
4. Add a revision note → editor receives the "revisions requested"
   email (via the side-effect path, not an explicit stage set).
5. Drag a card to "Approved" → editor receives the approval email.
6. Drag a card to "Internal review" → every owner/admin receives the
   internal-review email; the editor does not.
7. Manually backdate a test project's `due_at` in the database to
   trigger both cron conditions, run the cron script by hand once, and
   confirm both reminder emails send exactly once and the two
   `reminder_*_sent_at` columns are set; run it a second time and
   confirm no duplicate emails.
8. Temporarily break the Resend API key and confirm project
   creation/update/invite still succeed (email failure is swallowed,
   not surfaced).

## Out of scope for this round

- A persisted, cross-session in-app notification center — the existing
  client-derived bell is left exactly as-is.
- Per-project deep links in emails (the app has no per-project URL
  routing yet).
- Retry queues, bounce/complaint handling, unsubscribe preferences.
- Notifying on any stage transition other than the two called out above
  (revisions requested, approved) plus internal review for admins.
- Repeating/escalating overdue reminders — the past-due email fires
  once, not daily.
