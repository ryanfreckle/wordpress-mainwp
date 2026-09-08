# wordpress-mainwp

This repo holds custom plugins/themes for a personal MainWP setup, plus the CI that deploys
them. Two independent things live here:

1. **A MainWP dashboard site** (`wordpress.developandtest.uk`) — the management server plugins
   in `plugins/` deploy to, via `.github/workflows/deploy-plugin.yml`.
2. **MainWP-connected child sites** — separate WordPress installs, managed *from* the dashboard
   but not reachable by that deploy workflow. Anything meant to run on a child site has to be
   installed there separately (manually, or via MainWP's own bulk "Install Plugin").

Getting this distinction right matters: a plugin folder existing in `plugins/` does not mean
it's deployed anywhere in particular — check `deploy-plugin.yml`'s `matrix:` list for what
actually ships to the dashboard, and see each plugin's own readme for where else it needs to go.

## Repo layout

- `plugins/` — custom WordPress plugins, one folder each.
- `themes/` — custom WordPress themes (currently `freckle-theme`).
- `bin/plugin/dist.js` / `bin/theme/dist.js` — build any plugin/theme folder into
  `dist/<name>/` + `dist/<name>.zip`. Run with `node bin/plugin/dist.js <folder-name>`.
- `.github/workflows/deploy-plugin.yml`, `deploy-theme.yml` — build + FTP-sync to the dashboard
  site on push to `main`. Both share one `concurrency` group so they never race the host's FTP
  connection limit; see the comments at the top of `deploy-plugin.yml` for why.
- `docs/notes/` — personal reference notes on the deploy setup and git/PR workflow.

## Plugins

### `mainwp-migratedb-backup`
One plugin, no dependencies — install it anywhere and it adapts to what's already on that site:

1. **Standalone (always active)** — its own **Tools → DB Backup** page in wp-admin, on any
   WordPress site. Button runs a plain WP Migrate DB Pro/Lite database export via WP-CLI (no
   find & replace — that's what makes it a "backup" rather than a migration export), optionally
   bundled with Themes/Plugins/Media uploads/other wp-content files into one zip via plain PHP
   `ZipArchive`, auto-downloaded to the browser.
2. **MainWP dashboard tab (inert unless MainWP core is active on this install)** — adds a
   "DB Backup" tab to each connected site's page (Sites → a site → tab strip) that remotely
   triggers *that site's own copy of this same plugin*, over MainWP's normal signed
   `mainwp_fetchurlauthed` → `extra_execution` mechanism. No formal "MainWP Extension"
   registration/licensing dance needed — verified against MainWP's own source that the signing
   key is just `md5(__FILE__ . '-SNNonceAdder')`, computable directly.
3. **MainWP Child responder (inert unless MainWP Child is active on this install)** — answers
   the `mainwp_child_extra_execution` filter so #2, running on the dashboard, can reach this
   plugin's backup logic running on a child site.

All three register unconditionally in the constructor; #2 and #3 simply never fire if the
MainWP piece they depend on isn't present on that particular site. Full detail, requirements,
and the known execution-time-limit caveat for very large sites are in
[plugins/mainwp-migratedb-backup/readme.md](plugins/mainwp-migratedb-backup/readme.md).

To use the MainWP dashboard tab against a child site, this same plugin needs installing on
*both* the dashboard site and that child site — one codebase, two roles depending on where it's
put. `deploy-plugin.yml` only reaches the dashboard site; any child site needs it installed
separately (manually, or via MainWP's own bulk "Install Plugin").

### `freckle-lockdown`
Login-hardening plugin (renamed login URL, etc.), deployed to the dashboard site alongside
`mainwp-migratedb-backup`.

## How the backup feature evolved (for context on any of the above)

Built across one conversation, in this order — noted here in case future changes need to know
*why* it's shaped the way it is:

1. Started as **two plugins**: a dashboard extension (`mainwp-development-extension`, based on
   MainWP's official developer boilerplate) with a per-site button, plus a required child-site
   companion (`mainwp-migratedb-backup-child`) that only worked when called remotely via
   MainWP's `mainwp_child_extra_execution` hook.
2. Rebuilt the child plugin standalone: added its own wp-admin page/button/AJAX handler so it
   worked with zero MainWP involvement, keeping the MainWP remote-trigger path as an optional
   bonus only.
3. Added optional Themes/Plugins/Media/Other file bundling into a zip alongside the database
   export — after confirming WP Migrate DB Pro's own "Full-Site Export" feature is real but is a
   private, browser-only, AJAX-batched process with no WP-CLI equivalent, so the same practical
   result was built independently with plain `ZipArchive` rather than reverse-engineering that
   internal, update-fragile machinery. Also switched downloads from a manual "click this link"
   step to an automatic one.
4. **Merged both plugins into one** (`mainwp-migratedb-backup`, replacing what
   `mainwp-development-extension` and `mainwp-migratedb-backup-child` did). The old child plugin
   folder was deleted; `mainwp-development-extension/` is kept in the repo as reference — it's
   MainWP's official developer boilerplate and was the base this was built from — but it's
   **not** in `deploy-plugin.yml`'s matrix and isn't meant to be actively deployed anymore; its
   functionality now lives in `mainwp-migratedb-backup`. Discovered along the way that MainWP's
   formal "Extension" registration/licensing
   ceremony the original boilerplate carried isn't actually required for
   `mainwp_fetchurlauthed` to work — the signing key it checks is a deterministic hash, not
   something needing an activation flow — which is what made a single self-contained plugin
   practical instead of needing MainWP's extension scaffolding.
