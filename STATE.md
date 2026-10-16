# State
Updated: 2026-10-16 by Claude (Opus 5.5)

## Now

v1.2.0 is released (2026-10-16, `7eaebbc`, tags `v1.2.0` and `v1` pushed). The managed pre-commit runs a machine's own pre-commit first, and a new managed commit-msg hook runs its commit-msg, when `git config deployable-guard.extraHooks` names a folder of them. On Mike's Mac it points at the mai-sites-ops hooks, which refuse client and server names. Unset or missing, nothing extra runs.

## Next

1. Mai Engine is on v1.2.0 and pushed. 46 other `mai-*` plugins have a local, unpushed "Update deployable-guard to v1.2.0" commit on `develop` (mai-sellers-json skipped: being archived). Push them when Mike says.

## Blocked / waiting on

Mike's go to push the plugin commits.

## Verify

```sh
composer install && vendor/bin/phpunit
```

Expect 20 tests passing.

## Gotchas

- `~/LocalPackages/deployable-guard` is an older clone, two commits behind. Work here, in `~/Plugins/deployable-guard`.
- A library is recognised from the committed `installed.json` by requiring the loader, so `check` works in CI, where untracked files do not exist.
- `installed.php` changes two lines on every `composer install` or `update` (the plugin's own git commit). `composer dump-autoload` does not touch it.
