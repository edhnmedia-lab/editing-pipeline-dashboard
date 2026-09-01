# Frame & Fold — Editor Production Dashboard

A one-page, self-contained editor-facing production pipeline dashboard for a video-editing team. Built as a front-end prototype with realistic sample data — no build step, no framework, no server.

**Live demo:** enable GitHub Pages on this repo (Settings → Pages → source: `main` / `/ (root)`) and it will serve `index.html` directly.

## What's here

- `index.html` — the entire app: markup, styles, and JavaScript in one file.

## Sign-in (prototype)

The dashboard sits behind a simulated login screen:

- **"Continue with Google" / "Continue with GitHub"** — simulated OAuth round-trip (no real provider is called; a static file has nowhere safe to hold an OAuth client secret). Signs you in as the workspace's demo account, Alex Morgan.
- **Sign in** with the demo credentials shown on screen (`alex.morgan@framefold.co` / `demo1234`).
- **Create account** — spins up a brand-new local editor account with an empty pipeline, to demonstrate the "no projects assigned yet" empty state.

Sessions persist in `localStorage` so a refresh keeps you signed in; "Sign out" clears it.

## What it demonstrates

- Editor-scoped visibility: the signed-in editor only ever sees their own projects, KPIs, notifications, and performance chart. Other editors' projects exist in the underlying sample dataset (to prove the filter works) but never render anywhere in the UI.
- Full pipeline workflow: brief intake → editing → review → revisions → delivery, as a Kanban board and a sortable/filterable table.
- A project detail drawer with the creative brief, deliverables checklist, asset/reference links, revision history, stage controls, and a final delivery link.
- "Create from brief" with field-level validation, plus reset-to-sample-data.
- A native SVG bar chart (no charting library) for delivered videos over time.

## Production notes (read the code comments)

This is a front-end prototype. Every place a real backend would need to take over is marked in `index.html` with a bracketed tag:

- `[AUTH]` — real sign-in needs an actual OAuth/SSO provider or hosted auth service (NextAuth.js, Auth0, Clerk, Supabase Auth) with a server-verified session. Nothing in this file should be trusted as real authentication.
- `[DB]` / `[PERSIST]` — `localStorage` stands in for API reads/writes.
- `[AUTHZ/RLS]` — the editor-scoped filtering in this file is presentation logic only. It provides **no security**. Production access control must be enforced server-side (e.g. Postgres row-level security) on every read and write.
- `[INGEST]` — creative briefs would arrive from a real intake form or webhook, validated and assigned server-side.
- `[FILES]` — asset/reference/delivery links would resolve through signed, time-limited object storage URLs.
- `[NOTIFY]` — notifications would come from a server-side feed already scoped to the signed-in editor.

## Local use

Open `index.html` directly in a browser, or serve it with any static file server:

```bash
python3 -m http.server 8080
# then visit http://localhost:8080
```
