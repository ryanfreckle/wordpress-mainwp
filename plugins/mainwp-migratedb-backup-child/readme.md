# MainWP Migrate DB Backup - Child

A self-contained WP Migrate DB Pro database backup button. Install it on any WordPress site
and it adds its own **Tools → DB Backup** page with a one-click backup + download — nothing
else required.

MainWP is entirely optional here. If the MainWP Child plugin also happens to be installed on
the same site, this plugin additionally answers a remote trigger from the
[MainWP Development Extension](../mainwp-development-extension/) dashboard plugin — but that's
a bonus, not a dependency. Install this on a plain WordPress site with no MainWP anywhere in
sight and the Tools → DB Backup button works exactly the same.

## Requirements

- **WP Migrate DB Pro** (or the free WP Migrate Lite) active on the site.
- **WP-CLI** installed and reachable — either on `PATH`, or at `/usr/local/bin/wp`,
  `~/bin/wp`, or `~/.wp-cli/bin/wp` — with PHP's `exec()` enabled (some hosts disable it;
  the button reports a clear error if so, rather than hanging or failing silently).

If either is missing, clicking the button returns a specific error message explaining which,
rather than a generic failure.

## How it works

1. **Tools → DB Backup** in wp-admin shows a "Run Database Backup" button and a list of past
   backups. Clicking it calls this plugin's own `wp_ajax_mwp_migratedb_backup_run` handler
   (own nonce, own capability check — nothing MainWP-specific).
2. That handler runs `wp migratedb export <file>.sql.gz --gzip-file --exclude-post-revisions --exclude-spam`
   — deliberately no `--find`/`--replace`, since that's what makes it a *backup* rather than a
   migration export — and saves the result to a protected folder under
   `wp-content/uploads/mainwp-dev-backups/` (blocked from direct access via `.htaccess`).
3. A random, single-use, 24-hour download token is generated for the file, shown as a
   "Download" link on the same page.
4. *(Optional, only if MainWP Child is also present)* the same backup logic is reachable via
   MainWP Child's `mainwp_child_extra_execution` filter, which the
   [MainWP Development Extension](../mainwp-development-extension/) dashboard plugin's
   per-site "Development Individual" tab calls into. This is purely additive: the filter is
   simply never fired if MainWP Child isn't installed, so it adds no dependency.

## Installing

Build the zip from the repo root:

```
node bin/plugin/dist.js mainwp-migratedb-backup-child
```

That produces `dist/mainwp-migratedb-backup-child.zip`. Install it like any other plugin —
manually via wp-admin **Plugins → Add New → Upload Plugin**, or in bulk across MainWP-connected
sites via MainWP's own **Plugins → Install Plugin** if you're using that.

This plugin does **not** go through `deploy-plugin.yml` — that workflow deploys to the MainWP
dashboard site only, and this plugin belongs on whichever site(s) you actually want backups of.

## Changelog

### 2.0
- Made fully standalone: own **Tools → DB Backup** admin page, button, and AJAX handler —
  no longer requires the MainWP Development Extension or MainWP Child to function. The
  MainWP remote-trigger path is now optional/additive.

### 1.0
- Initial release: WP Migrate DB Pro backup trigger (MainWP-remote-only) + token-protected
  download.
