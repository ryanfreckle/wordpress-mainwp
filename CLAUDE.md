# wordpress-mainwp

This repo holds custom plugins/themes for a personal MainWP setup, plus the CI that deploys
them.

**Branch note (as of writing this):** working tree is on `develop`, not `main`, and is an
*earlier* snapshot than the design this file describes below — `mainwp-migratedb-backup-child`
here is still v1.0 (MainWP-remote-trigger only, pre-standalone), and the later consolidated
`mainwp-migratedb-backup` plugin doesn't exist on this branch at all. Before continuing, check
`git log --oneline -10` and `git status` to see where things actually stand — don't assume this
file's description of "current" plugins matches what's on disk; treat it as the design/decision
record, and reconcile the actual branch/commit state separately.

Two independent things live in this repo conceptually:

1. **A MainWP dashboard site** (`wordpress.developandtest.uk`) — the management server plugins
   in `plugins/` deploy to, via `.github/workflows/deploy-plugin.yml`.
2. **MainWP-connected child sites** — separate WordPress installs, managed *from* the dashboard
   but not reachable by that deploy workflow. Anything meant to run on a child site has to be
   installed there separately (manually, or via MainWP's own bulk "Install Plugin").

A plugin folder existing in `plugins/` does not mean it's deployed anywhere in particular —
check `deploy-plugin.yml`'s `matrix:` list for what ships to the dashboard.

## Repo layout

- `plugins/` — custom WordPress plugins, one folder each.
- `themes/` — custom WordPress themes (currently `freckle-theme`).
- `bin/plugin/dist.js` / `bin/theme/dist.js` — build any plugin/theme folder into
  `dist/<name>/` + `dist/<name>.zip`. Run with `node bin/plugin/dist.js <folder-name>`.
- `.github/workflows/deploy-plugin.yml`, `deploy-theme.yml` — build + FTP-sync to the dashboard
  site on push to `main`.
- `docs/notes/` — personal reference notes on the deploy setup and git/PR workflow.

## The goal

One click, from the MainWP dashboard, to back up a specific child site's database (optionally
+ themes/plugins/media) — the file ending up downloaded, not just sitting on a server somewhere.

## What was tried, in order, and what was actually learned

### 1. Two-plugin split (dashboard extension + child companion)
Started from MainWP's official developer boilerplate (`mainwp-development-extension`, still in
this repo as reference — MainWP's real starter kit for building dashboard extensions) plus a
required child-site companion plugin that only worked when called remotely via MainWP Child's
`mainwp_child_extra_execution` filter.

Verified against MainWP's and MainWP Child's actual source (not guessed):
- Dashboard → child: `apply_filters('mainwp_fetchurlauthed', $pluginFile, $key, $websiteId, 'extra_execution', $post_data)`.
- Child side: MainWP Child's built-in `extra_execution` callable fires
  `apply_filters('mainwp_child_extra_execution', $information, $_POST)` — a plugin hooks this,
  checks its own marker in `$_POST`, does the work, returns the response array.
- **No formal "MainWP Extension" registration/licensing dance is actually required.** Checked
  `MainWP_Extensions_Handler::hook_verify()` in MainWP's source: the key it checks is just
  `md5($pluginFile . '-SNNonceAdder')` — a deterministic hash, computable directly, no
  activation flow needed. `mainwp_extension_enabled_check` always returns that same hash
  regardless of any "enabled" state, despite its name. This is what made a single
  self-contained plugin (see #3 below) practical instead of needing MainWP's extension
  scaffolding on the dashboard side.
- Per-site tab site ID: on a `mainwp_getsubpages_sites` subpage, the site ID arrives as
  `$_GET['id']` (or `\MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()`), *not*
  `$_GET['dashboard']` (that's only for MainWP's own built-in "Overview" tab).
- AJAX nonces on the dashboard: any action registered via
  `do_action('mainwp_ajax_add_action', $action, $callback)` automatically gets its nonce
  collected into the global JS var `security_nonces[$action]` — no manual nonce plumbing needed
  dashboard-side.

### 2. Made the child plugin standalone
Realized the two-plugin split meant nothing worked without both halves deployed and in sync.
Rebuilt the child plugin (`mainwp-migratedb-backup-child`) to have its own wp-admin page
(**Tools → DB Backup**), own button, own AJAX handler, own nonce — works with zero MainWP
involvement. Kept the MainWP remote-trigger hook as a bonus (inert unless MainWP Child is also
present), not a requirement.

### 3. Added file bundling (DB + themes + plugins + media)
User wanted "the same options as the WP Migrate plugin" — i.e. a full-site backup, not just DB.

Checked WP Migrate DB Pro's actual source (from the WordPress.org SVN trunk of the free "WP
Migrate Lite," which shares the same core as Pro): it genuinely does have a "Full-Site Export"
feature (DB + media + themes + plugins as one zip) — an earlier claim in this project that WP
Migrate only touches the database was wrong and got corrected. But that feature
(`class/Common/FullSite/FullSiteExport.php`) is a private, batched, browser-only AJAX process
with **no WP-CLI equivalent** — confirmed by checking `class/Common/Cli/Command.php`, which only
exposes `export`/`find-replace`/etc., nothing for media/theme/plugin files. Reverse-engineering
that internal state machine would be exactly the kind of fragile, update-breaking hack worth
avoiding.

Built the *same practical result* independently instead: the existing `wp migratedb export`
WP-CLI call for the database, plus plain PHP `ZipArchive` walking `wp-content/themes`,
`/plugins`, `/uploads` (skipping the plugin's own backups output folder so it can't zip itself),
all bundled into one zip. Downloads switched from a manual "click this link" step to automatic
(an invisible `<a>` click — `Content-Disposition: attachment` triggers a real browser download,
no popup blocker involved, works in any browser).

### 4. Consolidated into one plugin (`mainwp-migratedb-backup`)
Merged the dashboard extension and the child companion into a single plugin file with three
independently-inert integration points:

1. Standalone Tools → DB Backup page (always works, no MainWP needed).
2. MainWP dashboard "DB Backup" per-site tab (`mainwp_getsubpages_sites` + its own
   `mainwp_ajax_add_action` handler) — only does anything if MainWP's dashboard core happens to
   be active on that install.
3. MainWP Child responder (`mainwp_child_extra_execution`) — only does anything if MainWP Child
   happens to be active on that install.

All three register unconditionally in the constructor; #2 and #3 are simply never fired by
anything if the MainWP piece they depend on isn't present — so the plugin has no real
dependency on MainWP at all, but gains dashboard-trigger behavior for free if you install the
same file on both the dashboard and a child site.

**Still true regardless of design**: to get a *dashboard button* for WP Migrate DB Pro
specifically, code is needed on both the dashboard site and each child site — verified that
MainWP Child has zero built-in awareness of WP Migrate (not in its callable function list, not
a core module). That's the actual floor for this specific combination, not something to shrink
further while keeping WP Migrate as the tool doing the export.

### 5. Explored MainWP's own native backup features, to see if custom code could be avoided entirely
- **API Backups** (MainWP → Backups → API Backups, a real built-in core module,
  `modules/api-backups/`): ruled out. It only triggers backups via a *hosting provider's own
  API* (Cloudways, GridPane, Vultr, Linode, DigitalOcean, cPanel+WP Toolkit, Plesk+WP Toolkit,
  Kinsta) — not a WordPress-level DB/file dump at all. Doesn't apply to shared/reseller hosting
  outside those specific providers.
- **Legacy Backup Feature**: MainWP's older built-in backup mechanism. Confirmed in source
  (`class-mainwp-install.php`) that it's **disabled by default on any fresh MainWP install**
  (only stays on if upgraded from an old version that had it) — status on this specific
  dashboard install wasn't confirmed either way, and it's the deprecated path MainWP is moving
  away from.
- **UpdraftPlus** (MainWP → Backups → UpdraftPlus): MainWP Child has this natively wired in
  (`updraftplus` is in its built-in callable list) — **zero custom code needed on the dashboard
  side at all**, it's fully native. Still needs UpdraftPlus itself active on each child site to
  do the actual work, but that's a standard wordpress.org plugin, bulk-installable via MainWP's
  own Install Plugin page in one action — not something to build/maintain, unlike the custom
  plugin. Produces a normal local DB+files backup in `wp-content/uploads/updraft`, same
  practical shape as what's wanted.

## Open decision, not yet resolved

**Which tool does the export: WP Migrate DB Pro (custom code, both ends) or UpdraftPlus (native
dashboard support, zero custom code, but a different tool doing the work)?** Leaning toward
"doesn't have to be WP Migrate specifically" was floated but not confirmed either way.

**If sticking with the custom plugin: should the child-side plugin stay narrow, or become a
generic remote executor?**

- **Narrow (current design)** — the child plugin has one specific method per capability (right
  now: run a backup). Any genuinely new capability means adding a method and re-deploying the
  plugin to every site it's on. Safe, auditable — it can only ever do what's explicitly coded.
- **Generic ("freckle-link" idea)** — the child plugin has exactly one capability: receive PHP
  from the dashboard (signed via MainWP's real per-site authentication), run it, return the
  result. Never needs touching again after the initial install; all new features become
  dashboard-side code shipped per-request. This is a real, established pattern — MainWP's own
  official "Code Snippets" extension works exactly this way. The honest tradeoff: this is, by
  definition, a standing remote-code-execution channel into every site it's installed on. Not
  insecure in the sense of being forgeable externally (it rides on MainWP's real site
  authentication), but if the MainWP dashboard account itself is ever compromised, whoever
  controls it can run arbitrary PHP on every connected site with this installed — not just
  trigger a backup. Legitimate to build, but deserves deliberate safeguards (e.g. logging what
  gets sent/run, restricting who on the dashboard can trigger it) rather than being treated as
  equivalent-risk to the narrow, backup-only plugin.

Decision on both of these is where to pick this back up.

## Plugins currently in the repo (check actual branch/commit before trusting this list)

- `mainwp-development-extension` — MainWP's official boilerplate, kept as reference/base, not
  meant to be actively deployed (its functionality was superseded by the work above).
- `mainwp-development-extension-child` / `mainwp-migratedb-backup-child` /
  `mainwp-migratedb-backup` — various stages of the child-plugin work described above; which of
  these actually exist depends on which commit/branch is checked out. Reconcile against
  `git log` before assuming any one of them is "the current version."
- `freckle-lockdown` — login-hardening plugin (renamed login URL, etc.), unrelated to the
  backup work, deployed to the dashboard site.

## Reference: verified MainWP mechanics (useful regardless of which design is picked)

- `apply_filters('mainwp_fetchurlauthed', $pluginFile, $key, $websiteId, 'extra_execution', $post_data)`
  → dashboard-to-child signed request. `$key = md5($pluginFile . '-SNNonceAdder')`, no
  activation flow needed.
- Child side: hook `mainwp_child_extra_execution` (fires via MainWP Child's built-in
  `extra_execution` callable), inspect `$_POST` for your own marker, return an array.
- Dashboard per-site tabs: `add_filter('mainwp_getsubpages_sites', ...)` with
  `['title'=>..., 'slug'=>..., 'sitetab'=>true, 'menu_hidden'=>true, 'callback'=>...]`. Site ID
  on that page: `$_GET['id']` or `MainWP_System_Utility::get_current_wpid()`.
- Dashboard AJAX: `do_action('mainwp_ajax_add_action', $action, $callback)` registers
  `wp_ajax_$action` and auto-generates a nonce collected into JS global `security_nonces[$action]`.
  Verify server-side with `do_action('mainwp_secure_request', $action)`.
