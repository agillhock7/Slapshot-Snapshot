# Slapshot Snapshot

Slapshot Snapshot is a multi-team media sharing app for hockey families, built for cPanel hosting:

- Team spaces with join codes
- Family/friend member onboarding
- Photo and video uploads
- Multi-file drag/drop uploads with progress
- YouTube link sharing
- Direct invite links (`?join=CODE`) with auto-join flow
- One-click invite actions (copy/share/text/email) plus server-sent email invites
- Team-scoped gallery with search and filtering

## Tech stack

- Vue 3 + Vite frontend (`src/`)
- PHP API (`api/index.php`)
- MySQL 8 schema (`database/schema.sql`)
- cPanel Git deployment via `.cpanel.yml`

## First-time setup

1. Create a MySQL database/user in cPanel.
2. Import `database/schema.sql` into that database.
3. Copy `api/config.local.example.php` to `api/config.local.php`.
4. Fill DB credentials in `api/config.local.php`.
5. Build frontend:
   ```bash
   npm install
   npm run build
   ```
6. Commit and push to `main`.
7. In cPanel Git Version Control, update and deploy HEAD commit.

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

## Main paths

- Frontend app: `src/App.vue`
- API router: `api/index.php`
- DB schema: `database/schema.sql`
- Deploy config: `.cpanel.yml`
