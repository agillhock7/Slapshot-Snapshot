# Slapshot Snapshot

Slapshot Snapshot is a multi-team media sharing app for hockey families, built for cPanel hosting:

- Team spaces with join codes
- Family/friend member onboarding
- Photo and video uploads
- Multi-file drag/drop uploads with progress
- YouTube link sharing
- Direct invite links (`?join=CODE`) with auto-join flow
- One-click invite actions (copy/share/text/email) plus server-sent email invites
- Team member management (owner/admin/member roles)
- Team profile metadata (age group, season year, level, rink, city, notes)
- Team update and owner-only delete with double-confirm safeguard
- Infinite-scroll gallery with lazy-loaded media cards
- Team-scoped gallery with search and filtering

## Tech stack

- Vue 3 + Vite frontend (`src/`)
- PHP API (`api/index.php`)
- MySQL 8 schema (`database/schema.sql`)
- cPanel Git deployment via `.cpanel.yml`

## First-time setup

1. Create a MySQL database/user in cPanel.
2. Import `database/schema.sql` into that database.
3. If upgrading an existing install, run `database/migrations/2026-02-14-team-metadata.sql`.
4. Copy `api/config.local.example.php` to `api/config.local.php`.
5. Fill DB credentials in `api/config.local.php`.
6. Build frontend:
   ```bash
   npm install
   npm run build
   ```
7. Commit and push to `main`.
8. In cPanel Git Version Control, update and deploy HEAD commit.

## Local run

Frontend only:
```bash
npm run dev
```

Production build:
```bash
npm run build
```

## Deployment behavior

`.cpanel.yml` deploys to `/home/puccus/snap.pucc.us` and:

1. Copies built frontend from `dist/`
2. Copies backend API from `api/`
3. Ensures `uploads/` exists and applies upload security rules
4. Applies root `.htaccess`

## Security defaults

- HTTPS redirect
- Session-based auth with secure cookie flags
- Team-level access control on API routes
- Upload MIME validation and size limit
- Upload directory blocks PHP execution
- Invite email endpoint includes rate limiting
- Security headers (`HSTS`, `CSP`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`)

## Mail requirement for auto-invite

The `invite_email` API action uses PHP `mail()`. On cPanel this typically works via local sendmail.
If invite sending fails, verify outbound mail settings and domain email policies (SPF/DKIM).

## Optional branding config

In `api/config.local.php`, you can set:

- `LOCAL_APP_PUBLIC_URL`
- `LOCAL_APP_BRAND_NAME`
- `LOCAL_APP_INVITE_LOGO_URL`

These values are used in branded HTML invite emails.

## Main paths

- Frontend app: `src/App.vue`
- API router: `api/index.php`
- DB schema: `database/schema.sql`
- Deploy config: `.cpanel.yml`
