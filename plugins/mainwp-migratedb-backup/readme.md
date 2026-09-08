# MainWP Migrate DB Backup

One plugin, no dependencies. Install it on any WordPress site and it adds its own
**Tools → DB Backup** page: one click runs a database export (via WP Migrate DB Pro/Lite +
WP-CLI), optionally bundled with Themes/Plugins/Media uploads/other wp-content files into a
single zip, downloaded straight to your browser.

If you *also* install this exact same plugin on your MainWP dashboard site, it additionally
adds a **DB Backup** tab to each connected site's page there (Sites → a site → tab strip),
letting you trigger that site's own copy of this plugin remotely, over MainWP's normal signed
request mechanism. That's the only thing "MainWP integration" means here — there is no separate
dashboard extension plugin, and MainWP Child is never modified. Each of the three things this
plugin can do (standalone page / dashboard tab / MainWP Child responder) is simply inert if the
MainWP piece it optionally talks to isn't present — none of the three requires the others.

## What it backs up

- **Database** — always included. A plain `wp migratedb export` via WP-CLI: no find & replace,
  no migration, just the full database, gzipped.
- **Themes / Plugins / Media uploads / Other wp-content files** — optional checkboxes, all
  checked by default except "Other". When any are ticked, the database dump and the selected
  folders are bundled into a single `.zip` via plain PHP `ZipArchive`.

## Requirements

- **WP Migrate DB Pro** (or the free WP Migrate Lite) active on the site being backed up.
- **WP-CLI** installed and reachable — either on `PATH`, or at `/usr/local/bin/wp`,
  `~/bin/wp`, or `~/.wp-cli/bin/wp` — with PHP's `exec()` enabled.
- **PHP's `ZipArchive` extension** — only if you tick any of the file checkboxes.

If anything required is missing, clicking the button returns a specific error message
explaining which, rather than a generic failure.

## Using it

**On a single site (no MainWP needed):** wp-admin → **Tools → DB Backup** → tick what you want
→ Run Backup. Downloads automatically when done.

**Across MainWP-connected sites:** install this plugin on both your MainWP dashboard site and
each child site you want to back up. On the dashboard, go to **Sites → (a site) → DB Backup**
tab → tick what you want → Run Backup. That calls the same code running on the child site, and
the resulting file downloads straight from the child to your browser.

## How the MainWP pieces work

- The dashboard tab is added via `mainwp_getsubpages_sites` — a plain MainWP core filter, no
  formal "Extension" registration needed.
- Its button posts to this plugin's own dashboard-side AJAX action, which calls
  `apply_filters('mainwp_fetchurlauthed', ..., 'extra_execution', [...])` — MainWP's standard
  signed-request mechanism. The signing key it needs (`md5(__FILE__ . '-SNNonceAdder')`) is
  computed directly, verified against MainWP's own source (`MainWP_Extensions_Handler::hook_verify()`)
  — it's a deterministic hash, not something that needs a licensing/activation flow first.
- On the child site, MainWP Child's `extra_execution` callable fires `mainwp_child_extra_execution`,
  which this plugin answers if it recognises the request, running the same `run_backup()` used
  by the standalone page.

## Known limitation

Everything runs synchronously inside one HTTP request — click button → WP-CLI export → zip →
response (→ relayed through the dashboard, for the remote path). Fine for a typical site; a very
large database or media library can hit a host's execution-time limit before finishing. If that
happens, the fix is a background/poll pattern, which isn't built yet.

## Installing

Build the zip from the repo root:

```
node bin/plugin/dist.js mainwp-migratedb-backup
```

That produces `dist/mainwp-migratedb-backup.zip`. Install it like any other plugin — wp-admin
**Plugins → Add New → Upload Plugin** on each site you want it on (dashboard, child sites, or
both), or in bulk across MainWP-connected sites via MainWP's own **Plugins → Install Plugin**.

`deploy-plugin.yml` deploys this to the dashboard site only (see its matrix). Any child site
still needs it installed there separately — that workflow has no reach beyond the dashboard.

## Changelog

### 3.0
- Combined the old two-plugin setup (`mainwp-development-extension` dashboard extension +
  `mainwp-migratedb-backup-child`) into this one plugin. Same codebase now runs on the dashboard
  site (adds the per-site tab) and on child sites (does the actual backup) — install it wherever
  you need either role, nothing else to deploy or keep in sync.

### 2.1 (as `mainwp-migratedb-backup-child`)
- Added optional Themes/Plugins/Media uploads/Other file bundling into a single zip alongside
  the database export, using plain `ZipArchive`.
- Downloads trigger automatically on success instead of requiring a second "Download" click.

### 2.0 (as `mainwp-migratedb-backup-child`)
- Made standalone: own Tools → DB Backup admin page, button, and AJAX handler.

### 1.0 (as `mainwp-migratedb-backup-child`)
- Initial release: WP Migrate DB Pro backup trigger (MainWP-remote-only, required the separate
  `mainwp-development-extension` dashboard plugin) + token-protected download.
