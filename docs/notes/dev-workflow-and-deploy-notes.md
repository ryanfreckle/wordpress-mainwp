# Dev workflow & deploy setup notes

Personal reference — where the plugin deploy setup stands, plus a write-up
of the standard PR/code review process for future reference.

## Deploy workflow status

`.github/workflows/deploy-plugin.yml` — builds `mainwp-development-extension`
via `bin/plugin/dist.js` and SFTP-syncs it to the WP server on push to `main`
(or manual dispatch from the Actions tab).

- [x] Workflow file written and committed (on `develop`)
- [x] `REMOTE_PLUGINS_PATH` set to
      `public_html/wordpress.developandtest.uk/wp-content/plugins`
- [x] Repo secrets set: `WP_SFTP_HOST`, `WP_SFTP_USERNAME`, `WP_SFTP_PASSWORD`
- [x] `develop` pushed to origin
- [ ] Merge `develop` → `main` to trigger the first real deploy (not done yet —
      pick up here)
- [ ] Watch the Actions run after merging, confirm the plugin actually lands
      on the server correctly

Was originally going to reuse [unknownsock/gha-wp-deploy](https://github.com/unknownsock/gha-wp-deploy),
but its reusable workflow hardcodes a `themes/` path — it's built for theme
deploys, not plugins — so this project got its own small standalone workflow
instead rather than editing that repo.

## Theme deploy workflow status

`.github/workflows/deploy-theme.yml` — same pattern as `deploy-plugin.yml`
(build via `bin/theme/dist.js`, FTP-sync on push to `main`), pointed at
`wp-content/themes` instead of `wp-content/plugins`. Reuses the same
`WP_SFTP_*` repo secrets, so no new secrets needed.

- [x] Workflow file written (on `develop`)
- [x] `themes/freckle-theme` barebones theme added (classic PHP templates,
      no block/FSE support)
- [ ] Merge `develop` → `main` to trigger the first real deploy
- [ ] Confirm the theme actually lands in `wp-content/themes` and activates
      cleanly on the real server

### Follow-up ideas (not started)
- Optional: front-end lockdown feature in the extension (hide/redirect the
  public front end via `template_redirect`) — discussed, deliberately left
  for later.
- Optional: PHPCS/PHPStan in CI — see notes below on how to avoid the
  "blocked by one extra space" annoyance.

## Git / PR process — full write-up

### Commit vs push vs PR
- **Commit** — a saved snapshot, local until pushed.
- **Push** — sends local commits to the remote branch. You choose when.
- **PR** — not a separate store of commits; it's a live comparison between
  two branches (e.g. `develop` vs `main`). It shows whatever commits exist on
  the source branch but not the target, and updates automatically as you push
  more.

### Merge strategies (chosen at merge time)
| Strategy | Result on target branch |
|---|---|
| Merge commit | All individual commits kept, plus a merge commit |
| Squash and merge | All commits on the branch collapsed into one commit |
| Rebase and merge | Each commit replayed individually, no merge commit |

Squash is usually nicest solo/small-team default — messy in-progress commits
("wip", "fix typo") stay off the target branch's history; only one clean
commit per PR lands on it.

### Standard end-to-end process
1. **Branch off `main`/`develop`** with a descriptive name:
   `feature/x`, `fix/x`, `chore/x`.
2. **Commit often locally** — these are checkpoints for you, not the
   reviewer. Write messages that explain *why*, not just *what* (the diff
   already shows what).
3. **Open the PR**:
   - Title: one line, imperative mood (like a commit message).
   - Description: what changed & why, **how to test it** (exact steps —
     this is the single most useful habit; it forces you to define "done"
     before claiming it), screenshots/output if relevant, linked issue if
     any.
   - Mark **Draft** if not ready for real review but want CI/early eyes.
   - Keep it small — reviewable in well under an hour. Split unrelated
     changes into separate PRs; split one big feature by logical layer, not
     by how much you happened to write in one sitting.
4. **CI runs automatically** — lint, tests, build, type-check. Branch
   protection rules can require CI green + N approvals before merge is even
   allowed.
5. **Review**:
   - Priority order: correctness > edge cases/failure modes > consistency
     with existing patterns > readability > style (style should mostly be
     auto-fixed before a human ever sees it — see PHPCS notes below).
   - `nit:` prefix for optional comments.
   - Author pushes fixes as **new commits** during review (don't
     amend/force-push mid-review) so the reviewer only re-reviews the delta.
     Squash happens once, at merge.
6. **Merge** once approved + CI green. Delete the branch after.
7. **After merge** — if merge triggers deploy (like this repo), watch the
   deploy run and verify it actually worked; not "done" until it's live and
   confirmed. If something breaks, revert the PR (one click, makes a revert
   commit) rather than scrambling to hotfix under pressure.

### Who reviews?
- **CODEOWNERS-based** (common at mid-size+ companies) — a `CODEOWNERS` file
  maps paths to people/teams; GitHub auto-requests review from whoever owns
  the touched files.
- **Round-robin / whole team** — smaller teams, anyone reviews anyone.
- **Rotating designated reviewer** — "reviewer of the week" picks up most
  incoming PRs.
- **Lead/senior-gated** — everything through one person, common early on,
  doesn't scale.
- Constant across all of them: **the author never approves their own PR**,
  even on trivial changes — a second set of eyes is the point.

### PHPCS/PHPStan — avoiding the "blocked by one space" annoyance
The annoyance was blocking on things that should've been auto-fixed. Split
enforcement:

| Tool | Where it runs | How it should behave |
|---|---|---|
| PHPCS | pre-commit / local script | auto-fix via `phpcbf`, not just report |
| PHPCS (unfixable) | CI | warn/annotate only, don't hard-block |
| PHPStan | CI | hard-block merge to `main` |

Nobody should ever see "blocked: one extra space" — `phpcbf` should have
already rewritten it before it reached a push.
