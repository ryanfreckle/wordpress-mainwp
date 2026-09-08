# MainWP Migrate DB Backup - Child

Child-site companion for the [MainWP Development Extension](../mainwp-development-extension/)
dashboard plugin. Installed on each **child** site (not the MainWP dashboard site), it lets the
"Run Database Backup" button on that site's Manage Sites page trigger a plain WP Migrate DB Pro
database export — no find & replace, no migration — and hands back a one-click download link.

## Requirements on the child site

- **MainWP Child** plugin, connected to your dashboard.
- **WP Migrate DB Pro** (or the free WP Migrate Lite) active.
- **WP-CLI** installed and reachable — either on `PATH`, or at `/usr/local/bin/wp`,
  `~/bin/wp`, or `~/.wp-cli/bin/wp` — with PHP's `exec()` enabled (some hosts disable it;
  the button will report a clear error if so, rather than hanging or failing silently).

If any of these are missing, clicking the button returns a specific error message explaining
which one, rather than a generic failure.

## How it works

1. The dashboard extension calls `apply_filters('mainwp_fetchurlauthed', ..., 'extra_execution', ['mwp_dev_action' => 'migratedb_backup'])`.
2. MainWP Child's built-in `extra_execution` callable fires `mainwp_child_extra_execution`,
   which this plugin hooks.
3. This plugin runs `wp migratedb export <file>.sql.gz --gzip-file --exclude-post-revisions --exclude-spam`
   and saves the result to a protected folder under `wp-content/uploads/mainwp-dev-backups/`
   (blocked from direct access via `.htaccess`).
4. A random, single-use, 24-hour token is generated for the file; the dashboard shows it as a
   "Download" link that streams the file straight from this site when clicked.

## Installing on your child sites

Build the zip from the repo root:

```
node bin/plugin/dist.js mainwp-migratedb-backup-child
```

That produces `dist/mainwp-migratedb-backup-child.zip`. Install it on your child sites
either:
- via MainWP's own **Plugins → Install Plugin** bulk-install to selected/all sites, or
- manually, per site, via wp-admin **Plugins → Add New → Upload Plugin**.

This plugin does **not** go through `deploy-plugin.yml` — that workflow deploys to the MainWP
dashboard site only. This one needs to land on your child sites instead.

## Changelog

### 1.0
- Initial release: WP Migrate DB Pro backup trigger + token-protected download.
