# State
Updated: 2026-10-03 by Claude (Opus 5.5)

## Now

`check` also covers libraries loaded by mai-package-loader: each one's `mai-package.php`, the class files it declares, and `vendor/composer/installed.php` must be committed. The README now says to commit `installed.php` instead of ignoring it, because the loader reads it at runtime. The pre-commit hook runs when anything under `vendor/`, or `.gitignore`, is staged. Committed locally on `main` (fcf19cc), not pushed or tagged.

## Next

1. Ask Mike: push `main`, tag `v1.1.0`, and move the `v1` tag, which every plugin's CI workflow checks out.
2. Plugins pick it up on their next `composer update bizbudding/deployable-guard`.

## Blocked / waiting on

Mike's yes for the push and tags.

## Verify

```sh
composer install && vendor/bin/phpunit
```

Expect 16 tests passing.

## Gotchas

- `~/LocalPackages/deployable-guard` is an older clone, two commits behind. Work here, in `~/Plugins/deployable-guard`.
- A library is recognised from the committed `installed.json` by requiring the loader, so `check` works in CI, where untracked files do not exist.
- `installed.php` changes two lines on every `composer install` or `update` (the plugin's own git commit). `composer dump-autoload` does not touch it.
