# Slapshot Snapshot

Vue-powered photo/video showcase for sharing youth hockey memories with family and friends.

## Stack

- Vue 3
- Vite
- Static deploy via cPanel Git Version Control

## Local development

```bash
npm install
npm run dev
```

## Build for production

```bash
npm run build
```

This generates `dist/`, which is what cPanel deploys to:

- `/home/puccus/snap.pucc.us`

## cPanel deployment

`.cpanel.yml` is configured to:

1. Set deployment path to `/home/puccus/snap.pucc.us`
2. Remove stale deployed files (while preserving `.well-known`)
3. Copy fresh build files from `dist/`
4. Copy hardened Apache config from `.htaccess`

## Security defaults included

- HTTPS redirect
- Security headers (`HSTS`, `CSP`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`)
- SPA routing fallback to `index.html`
- Directory listing disabled (`Options -Indexes`)

## Customize media

Edit `src/media.js`:

- `thumb`: card thumbnail image
- `src`: full image URL or YouTube embed URL for videos
- `type`: `photo` or `video`
