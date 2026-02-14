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
- Invite-status tracking (pending/joined) inside Team Members
- Invite owner controls: resend and revoke only for the sender of that invite
- Account management (display name + password update)
- Support-reviewed email change requests with approve/deny workflow
- Team branding with owner/admin logo upload + removal
- Team profile metadata (age group, season year, level, rink, city, notes)
- Team update and owner-only delete with double-confirm safeguard
- Premium gallery experience: sort, group, rich detail modal, batch actions
- Infinite-scroll gallery with lazy-loaded media cards and render optimization
- Thumbnail-first gallery rendering for faster loads
- Team-scoped gallery with search and filtering

## Tech stack

- Vue 3 + Vite frontend (`src/`)
- PHP API (`api/index.php`)
- MySQL 8 schema (`database/schema.sql`)
- cPanel Git deployment via `.cpanel.yml`

## First-time setup

1. Create a MySQL database/user in cPanel.
2. Import `database/schema.sql` into that database.
3. If upgrading an existing install, run:
   - `database/migrations/2026-02-14-team-metadata.sql`
   - `database/migrations/2026-02-14-team-logo.sql`
   - `database/migrations/2026-02-14-team-invites.sql`
   - `database/migrations/2026-02-14-email-change-requests.sql`
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

The account email-change workflow also uses PHP `mail()` and sends support approval links to `support@pucc.us` by default.

To improve inbox placement, set these in `api/config.local.php`:

- `LOCAL_APP_MAIL_FROM_EMAIL` (recommended: a real mailbox on your domain, e.g. `noreply@snap.pucc.us`)
- `LOCAL_APP_MAIL_FROM_NAME`
- `LOCAL_APP_MAIL_RETURN_PATH`
- `LOCAL_APP_MAIL_REPLY_TO`

In cPanel, also verify:

1. `Email Deliverability` shows SPF and DKIM as valid for your sending domain.
2. The `From` mailbox exists (or is a valid route) on that domain.
3. DMARC exists for the parent domain (start with `p=none`, tighten later).

## Image thumbnail optimization

Photo uploads generate server-side thumbnails (used in gallery cards) when PHP GD functions are available.
If GD is unavailable, uploads still work and the original image is used as fallback.

## Optional branding config

In `api/config.local.php`, you can set:

- `LOCAL_APP_PUBLIC_URL`
- `LOCAL_APP_BRAND_NAME`
- `LOCAL_APP_INVITE_LOGO_URL`
- `LOCAL_SUPPORT_EMAIL`
- `LOCAL_APP_MAIL_FROM_EMAIL`
- `LOCAL_APP_MAIL_FROM_NAME`
- `LOCAL_APP_MAIL_RETURN_PATH`
- `LOCAL_APP_MAIL_REPLY_TO`

These values are used in branded HTML invite emails.

## Main paths

- Frontend app: `src/App.vue`
- API router: `api/index.php`
- DB schema: `database/schema.sql`
- Deploy config: `.cpanel.yml`
