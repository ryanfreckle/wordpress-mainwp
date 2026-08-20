# Freckle Theme

Barebones classic WordPress theme — the minimum set of files WordPress
needs to run (`style.css`, `index.php`, `header.php`, `footer.php`,
`functions.php`), no design opinions baked in. Meant as a blank base to
build a client theme from, not a finished product.

## What's included

- `title-tag`, `post-thumbnails`, `automatic-feed-links` and HTML5 markup
  theme supports.
- One registered nav menu location (`primary`).
- One enqueued stylesheet (`style.css` itself, via `get_stylesheet_uri()`).
- No block/FSE support (`theme.json`, `templates/`) — this is a classic
  PHP-template theme. Add `theme.json` + block templates later if a project
  needs full site editing.

## Deploy

Built and FTP-synced to `wp-content/themes` by
`.github/workflows/deploy-theme.yml` on push to `main` (same pattern as
`deploy-plugin.yml` for plugins) — see that workflow for the deploy
mechanics.
