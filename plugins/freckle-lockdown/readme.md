# Freckle Lockdown

Small standalone WordPress plugin — no MainWP dependency. Two independent
features, each toggled from **Settings > Freckle Lockdown**:

1. **Front-end lockdown** — every public request returns a plain 404.
   Applies to everyone, including logged-in admins. wp-admin, the login
   page, admin-ajax.php, REST and cron are unaffected (the lockdown hooks
   `template_redirect`, which never fires for those).
2. **Login URL rename** — moves the login form off `wp-login.php` onto a
   custom slug (default `freckleadmin`). Direct hits on `wp-login.php` and
   logged-out hits on `wp-admin` are redirected/blocked. wp-admin itself is
   not moved — once authenticated, `wp-admin/*` works as normal.

## Safety valve

If the login rename ever locks you out, add this to `wp-config.php` and
reload (no FTP/SFTP deactivation needed):

```php
define( 'FRECKLE_LOCKDOWN_DISABLE', true );
```

## Known limitations

- Login-slug matching assumes a root install (`REQUEST_URI` compared
  directly against the slug) — not tested against subdirectory installs.
- This reimplements the well-known "hide wp-login" technique (same idea as
  WPS Hide Login) rather than pulling in a dependency, so it stays small and
  auditable. It has not been hardened against every edge case (multisite,
  custom login flows from other plugins, etc.) — review before using on a
  site that isn't your own dev/test box.
