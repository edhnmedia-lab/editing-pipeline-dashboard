# Frame & Fold Dashboard: Real Backend, Auth, and Hostinger Migration

Status: Approved for planning
Date: 2026-09-01

## Context

`Editing Pipeline Dashboard.html` (a.k.a. `index.html`) is a self-contained,
front-end-only prototype currently hosted on GitHub (Pages). Every place a
real backend would be needed is marked in the file with a bracketed tag:
`[AUTH]`, `[DB]`, `[PERSIST]`, `[AUTHZ/RLS]`, `[INGEST]`, `[FILES]`,
`[NOTIFY]`. Sign-in is simulated (no real OAuth provider is called), and all
data (projects, deliverables, revisions, the signed-in session) lives in
`localStorage`.

Goal: move this to Hostinger, replace the simulated auth and mock data with
a real backend, and add a three-tier role/permission system.

## Hosting environment (confirmed via Hostinger MCP)

- Account is on **Hostinger Business Web Hosting** (shared hosting plan,
  subscription id `16BdjlVTyw1oH5M5f`, order id `1009954812`), not a VPS or
  Agency Plan.
- No website is provisioned on the order yet.
- The plan includes one unclaimed free-domain entitlement (`domain: null,
  type: "free_domain", status: "pending_setup"`). Domain to register:
  **framefold.com**.
- Shared hosting natively supports PHP + MySQL (with phpMyAdmin) as a
  first-class environment; Node.js is supported only via a separate,
  more constrained app-manager layer. Decision: **PHP + MySQL** backend.

## Architecture

One Hostinger website (`framefold.com`), serving:

- Static frontend at the document root — the existing single-page dashboard
  UI, adapted to call a real API instead of `localStorage`.
- A PHP API under `/api/` — auth (OAuth start/callback), users, projects.
- A MySQL database, provisioned on the same hosting account, as the only
  persistence layer. `localStorage` is removed entirely from the app logic
  (it may still be used for trivial UI-only prefs like theme, never for
  auth state or app data).

Sessions are PHP native sessions (HttpOnly, Secure, SameSite=Lax cookie).
The server is the sole source of truth for "who is logged in and what role
they have" — the client never determines or asserts its own identity or
role. This directly closes the prototype's own `[AUTH]` / `[AUTHZ/RLS]`
gaps.

## Data model (MySQL)

Mirrors the prototype's existing project/deliverable/revision shape; the
fictional seed people and sample projects are **not** carried over — the
real app starts with zero projects and one seeded user (the owner).

```sql
users
  id              INT PK AUTO_INCREMENT
  email           VARCHAR UNIQUE NOT NULL
  name            VARCHAR NULL           -- from Google profile, set on first login
  title           VARCHAR NULL           -- free-text display label, e.g. "Head of Long Form Content"
  role            ENUM('owner','admin','editor') NOT NULL
  status          ENUM('invited','active') NOT NULL DEFAULT 'invited'
  google_sub      VARCHAR NULL           -- Google's stable subject id, set on first login
  invited_by      INT NULL FK -> users.id
  created_at      DATETIME NOT NULL
  last_login_at   DATETIME NULL

projects
  id              VARCHAR PK             -- e.g. "PRJ-1038", matching existing id style
  title           VARCHAR NOT NULL
  client          VARCHAR NOT NULL
  editor_id       INT NOT NULL FK -> users.id
  assigned_by     INT NOT NULL FK -> users.id
  date_assigned   DATETIME NOT NULL
  due_at          DATETIME NOT NULL
  priority        ENUM('Urgent','High','Medium','Low') NOT NULL
  stage           VARCHAR NOT NULL       -- one of the existing STAGES ids
  version         INT NOT NULL DEFAULT 1
  platform        VARCHAR NOT NULL
  aspect          VARCHAR NOT NULL
  delivery_link   VARCHAR NULL
  instructions    TEXT NULL
  delivered_at    DATETIME NULL
  delivered_on_time BOOLEAN NULL
  created_at      DATETIME NOT NULL
  updated_at      DATETIME NOT NULL

deliverables
  id              INT PK AUTO_INCREMENT
  project_id      VARCHAR NOT NULL FK -> projects.id
  label           VARCHAR NOT NULL
  done            BOOLEAN NOT NULL DEFAULT FALSE
  sort_order      INT NOT NULL DEFAULT 0

project_links
  id              INT PK AUTO_INCREMENT
  project_id      VARCHAR NOT NULL FK -> projects.id
  kind            ENUM('asset','reference') NOT NULL
  label           VARCHAR NOT NULL
  url             VARCHAR NOT NULL

revisions
  id              INT PK AUTO_INCREMENT
  project_id      VARCHAR NOT NULL FK -> projects.id
  note            TEXT NOT NULL
  author          VARCHAR NOT NULL
  created_at      DATETIME NOT NULL
  resolved        BOOLEAN NOT NULL DEFAULT FALSE
```

Notifications remain derived, not stored — ported from the prototype's
client-side derivation logic (`[NOTIFY]`) into the API response layer
(e.g. computed alongside `GET /api/projects`), so the client still never
invents facts about due dates / revision state on its own.

## Roles and permissions

Three roles, permission-checked server-side on every request (never
UI-only):

- **Owner** — exactly one account, permanently tied to
  `ethan@edhnmedia.com`. Seeded directly into the database at setup with
  `role='owner'`, `status='active'`. Protected invariants enforced in the
  API layer:
  - No endpoint may change the owner's `role` or delete the owner row.
  - No endpoint may create a second `role='owner'` row.
  - Owner can invite, promote/demote, and remove both admins and editors.
  - Owner sees all projects and can set anyone's `title`.
- **Admin** — can invite **editors only** (never admins or another owner).
  Sees all projects (not just assigned-to-them). Can set an editor's
  `title`. Cannot view or use admin-management controls (promoting,
  demoting, or removing admins) — attempting this via the API returns 403
  even if somehow reached client-side.
- **Editor** — scoped to projects where `editor_id = session.user_id`,
  exactly as the current prototype's `myProjects()` filtering already
  models, except the predicate is now enforced in the database query
  (`WHERE editor_id = ?`) rather than only in client-side JS.

## Auth flow: real Google OAuth, invite-only

1. Owner or admin invites someone by email: `POST /api/users/invite
   {email, role, title}` (role restricted to what the caller is allowed to
   grant — admin can only submit `role='editor'`). Creates a `users` row
   with `status='invited'`, `google_sub=NULL`.
2. Invitee clicks "Continue with Google" → browser hits
   `/api/auth/google/start.php`, which redirects to Google's OAuth 2.0
   authorization endpoint with `client_id`, `redirect_uri =
   https://framefold.com/api/auth/google/callback.php`, `scope=openid
   email profile`, and a CSRF `state` token stored in the PHP session.
3. Google redirects back to `callback.php` with `code` + `state`. The
   callback verifies `state`, exchanges `code` for tokens via a
   server-side POST to Google's token endpoint (client secret lives only
   in a non-web-servable config file, never sent to the browser), then
   fetches the verified email/name/sub from Google's userinfo endpoint.
4. Lookup by email in `users`:
   - Not found → reject with an explicit "not authorized — ask an admin to
     invite you" message (not a generic error).
   - Found, `status='invited'` → activate: set `google_sub`, `name`,
     `status='active'`, `last_login_at=NOW()`.
   - Found, `status='active'` → update `google_sub`/`name` if changed,
     `last_login_at=NOW()`.
5. On success, a PHP session is created holding `user_id` and `role`;
   browser is redirected into the dashboard.
6. Bootstrap: the owner row (`ethan@edhnmedia.com`, `role='owner'`,
   `status='active'`) is inserted directly during deployment/setup, so the
   very first login works without anyone having to self-invite.

## Manage Users page

Visible only when `session.role` is `owner` or `admin` (editors never see
this nav item at all, and the underlying endpoints 403 for editors
regardless).

- **Owner** sees the full roster (owner/admins/editors), can invite as
  admin or editor, change any admin/editor's role or title, remove any
  admin or editor.
- **Admin** sees the full roster read-only for the admin/owner rows (so
  they can see the org chart) but can only invite, edit the title of, or
  remove **editor** rows.
- Every mutation (`POST /api/users/invite`, `PATCH /api/users/{id}`,
  `DELETE /api/users/{id}`) re-checks the caller's role and the target
  row's role server-side before acting.

## Projects: create/assign and update

- `GET /api/projects` — editors get only their own rows; owner/admin get
  all rows.
- `POST /api/projects` — owner/admin only. Body includes `editorId`
  (must be an active editor), title, client, dueAt, priority, platform,
  aspect, instructions, deliverables[], assets[], references[].
- `PATCH /api/projects/{id}` — stage changes, deliverable toggles, new
  revisions, delivery link. Editors may update only projects where
  `editor_id = session.user_id`; owner/admin may update any project. The
  editor id on a project is never taken from client input on update —
  only the authenticated session decides what an editor is allowed to
  touch.

## Hosting / deployment plan

1. Register `framefold.com` using the Business plan's free-domain
   entitlement.
2. Create the Hostinger website for that domain
   (`hosting_createWebsiteV1`).
3. Provision a MySQL database + database user
   (`hosting_createAccountDatabaseV1`), capture connection credentials.
4. Package the static frontend + PHP `/api` directory and deploy via the
   Hostinger PHP-site deploy flow (per the `hostinger:hosting-deploy-php-site`
   skill) — upload URL, archive import, as that skill defines.
5. Store secrets (DB credentials, Google OAuth client id/secret) in a PHP
   config file outside any web-servable path (or blocked via `.htaccess`
   deny-all), excluded from git via `.gitignore`. Never commit secrets.
6. **Manual step required from the user**: create an OAuth Client ID in
   Google Cloud Console (APIs & Services → Credentials) with authorized
   redirect URI `https://framefold.com/api/auth/google/callback.php`, and
   hand the client id/secret to be placed in the server config. This
   cannot be done via the Hostinger MCP or any tool available here.
7. Deploy, then run the manual QA checklist below.

## Error handling

- Any API request without a valid session → `401` JSON.
- Role-gated endpoint hit by an insufficient role → `403` JSON (including
  an admin trying to touch an owner/admin row, or an editor hitting any
  admin endpoint).
- OAuth callback failures (state mismatch, token exchange failure, email
  not present/verified) → redirect to the login screen with a specific,
  user-readable error, no session created.
- Uninvited email attempting sign-in → explicit "not authorized" message,
  distinct from a generic failure.
- Database errors are logged server-side (PHP `error_log`) and never
  surfaced to the client beyond a generic 500.
- Owner-row protection (see Roles) is enforced as a guard clause in every
  endpoint that mutates a user, independent of who is asking.

## Testing

No existing test infrastructure exists for this static-prototype repo, and
this is a small internal tool. Plan is a manual QA checklist rather than
standing up PHPUnit:

1. Owner invites an admin → admin signs in via real Google OAuth → lands
   in an empty dashboard with "Manage users" visible, scoped to
   editor-only invite rights.
2. Admin invites an editor → editor signs in → sees "Manage users" is
   absent; sees zero projects (empty state).
3. Owner or admin creates a project assigned to that editor → editor sees
   it after refresh; a second, uninvited editor does not.
4. Editor updates a project's stage / toggles a deliverable / adds a
   revision → persists after logging out and back in (proves real DB
   persistence, not `localStorage`).
5. Admin attempts to invite another admin or to modify/remove an existing
   admin or the owner → rejected (403) at the API level.
6. Owner promotes an editor to admin, and separately removes an admin →
   both succeed; owner row itself cannot be edited or removed by anyone.
7. An uninvited Google account attempts sign-in → rejected with the
   explicit "ask an admin to invite you" message.
8. Confirm no app data or session state is read from or written to
   `localStorage` anywhere in the deployed app.

## Out of scope for this round

- Brief intake automation ("briefs arrive from a form/webhook") — projects
  are created manually by owner/admin through the existing "create from
  brief" UI, extended with an editor-assignment field.
- Signed/expiring file URLs for assets (`[FILES]`) — links remain plain
  URLs as in the prototype.
- Automated test suite (PHPUnit) — manual QA only, revisit if the app
  grows.
- GitHub sign-in (prototype had a button for it) — only Google OAuth is
  wired up for real; the GitHub button is removed rather than left as a
  dead simulation.
