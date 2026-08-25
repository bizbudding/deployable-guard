# deployable-guard: one shape across the fleet

**Date:** 2026-08-25
**Status:** Shape A decided. Pilot done (springwire-publish-wp). 4 plugins remain.
**Scope:** `~/Plugins/*` (64 plugins), plus this package's README

## Why

`vendor/composer/autoload_*.php` is committed so a raw-tree deploy works without Composer. `composer install` rewrites those files to reference dev-only packages that `.gitignore` excludes. Push that state and `autoload_real.php:41` does a bare `require` on a file that was never deployed, fataling every request.

This is not hypothetical. It happened twice on 2026-08-25 in `springwire-publish-wp`: `mai-sync` refused the push, and separately two test runs failed silently because the autoloader had been reset by hand. `deployable-guard` exists to catch exactly this at commit time. Two plugins do not have it, and three have it wired in a way that breaks.

## Current state, audited 2026-08-25

64 plugins with git + composer in `~/Plugins`.

| Group | Count | State |
|---|---|---|
| Correct: indirection wiring | 43 | `install-git-hooks` script, called from post-install/post-update |
| **Broken: unguarded direct wiring** | 3 | `mai-analytics`, `mai-publisher`, `tvn-newsletter` |
| **Missing: no guard at all** | 2 | `springwire-publish-wp`, `springwire-ingest-wp` |
| Not at risk: no dev deps | 11 | Nothing to regenerate |
| Not at risk: vendor untracked | 5 | Deploy does not use a raw tree |

All 46 that require the guard use `bizbudding/deployable-guard` and have `.github/workflows/deployable.yml`. Package name, repo URL and CI gate are already consistent. Only the script wiring differs.

### Why the 3 are broken, not just different

```jsonc
// mai-analytics, mai-publisher, tvn-newsletter
"post-install-cmd": [ "@php vendor/bin/deployable-guard install-hook" ]
```

Under `composer install --no-dev` the bin is absent, so this fails with "Could not open input file" and aborts the install. This package's own README calls the `test -f` guard load-bearing for that reason. These three predate that note.

### Repo rename, already handled

`maithemewp/deployable-guard` redirects to `bizbudding/deployable-guard`. The live README and `composer.json` both say `bizbudding`. Only *vendored copies* inside plugins still say `maithemewp`, because they were installed before the rename. They update on the next `composer update`. Nothing to fix in any plugin's `composer.json`.

## Decision: shape A (settled 2026-08-25)

Both work. They differ only in whether the command is named.

**A. Indirection** (43 plugins use this, README does not document it)

```jsonc
"scripts": {
  "install-git-hooks": [
    "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'"
  ],
  "post-install-cmd": [ "@install-git-hooks" ],
  "post-update-cmd":  [ "@install-git-hooks" ]
}
```

**B. Flat** (README documents this, 0 plugins use it)

```jsonc
"scripts": {
  "post-install-cmd": [ "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'" ],
  "post-update-cmd":  [ "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'" ]
}
```

**Chosen: A.** The command exists in one place instead of two, it can be run on its own with `composer install-git-hooks` when the hook needs reinstalling, and 43 plugins already match it so only 5 change instead of 46. B duplicates a fiddly `sh -c` string that has to stay identical in both entries.

Whichever wins, the README changes to document it, so the package and the fleet stop disagreeing.

## Work

### 1. This package
- [ ] Update README install block to the chosen shape.
- [ ] Keep the `test -f` explanation and the `scripts-no-dev` warning. Both are load-bearing.
- [ ] Keep the step-by-step adoption section, updated to match.

### 2. Fix the 3 broken plugins
- [ ] `mai-analytics`, `mai-publisher`, `tvn-newsletter`
- [ ] Replace unguarded direct wiring with the chosen shape.
- [ ] Confirm `composer install --no-dev` completes without aborting. This is the bug being fixed, so it is the test that matters.

### 3. Adopt in the 2 springwire plugins
- [x] `springwire-publish-wp` — done 2026-08-25, commit 25e8be9 on develop. Pilot.
- [ ] `springwire-ingest-wp`
- [ ] Add VCS repo, `require-dev` entry, scripts.
- [ ] `composer update bizbudding/deployable-guard`
- [ ] `composer dump-autoload --no-dev`
- [ ] Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`
- [ ] Verify `php vendor/bin/deployable-guard check` prints OK.

### 4. Normalize the remaining 43
- [x] Not needed. A won, and all 43 already match it.

## Testing

All six steps were run against the pilot and all six passed. Results below are
measured, not expected.

| Step | Pilot result |
|---|---|
| 1. `composer install --no-dev` completes | Pass. Ran the script, bin absent, no abort. |
| 2. `composer install` writes `.githooks/pre-commit` | Pass. |
| 3. `core.hooksPath` is `.githooks` | Pass. Set by install-hook, `.githooks/` appended to .gitignore. |
| 4. Dirty `vendor/composer/` blocks the commit | Pass. Named both offending files, HEAD unchanged. |
| 5. `dump-autoload --no-dev` then commit succeeds | Pass. |
| 6. `deployable-guard check` prints OK | Pass, exit 0. Exit 1 while dirty. |

Extra test worth repeating on the 3 broken plugins: swap in their unguarded
wiring and run `composer install --no-dev`. It aborts with "Could not open input
file" and post-install-cmd returns 1. That is the bug, reproduced on demand.

Each fixed plugin needs:

1. `composer install --no-dev` completes. Catches the missing `test -f`.
2. `composer install` completes and writes `.githooks/pre-commit`.
3. `git config --local core.hooksPath` returns `.githooks`.
4. Stage a dev-dirty `vendor/composer/` and confirm the commit is blocked.
5. `composer dump-autoload --no-dev`, then confirm the commit is allowed.
6. `php vendor/bin/deployable-guard check` prints OK.

Step 4 is the one that proves the guard works rather than merely installs. Do not skip it.

Use `mai-bulk-update` for the rollout. It exists for this.

## Note on `.githooks/`

`.githooks/` is gitignored and regenerated on every install, and `core.hooksPath` is local config. So a fresh clone is unprotected until someone runs `composer install`. CI is the real gate. Do not read an absent `.githooks/pre-commit` as a broken plugin. That misread happened during this audit.

## Unfinished, unrelated to the rollout

`springwire-publish-wp` has one item still open from 2026-08-25:

- [ ] Import-result transient is keyed only by user ID, so a stored result can surface on a page load the viewer did not submit. Needs a per-request token threaded through the redirect. Low value, real work, deliberately deferred.

Everything else from that session shipped in 0.3.0 or landed on develop after it.
