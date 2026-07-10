# deployable-guard

Verify a committed composer autoloader is deployable as a **raw git tree**.

These plugins deploy by checking out / copying a branch with no composer build on the server, so the committed `vendor/` must be self-consistent: every file `vendor/composer/autoload_files.php` eagerly `require()`s must be **git-tracked**. If that autoloader was regenerated with dev dependencies installed, it references dev-only packages that `.gitignore` excludes from the commit, and a raw-branch deploy then fatals on load.

`deployable-guard` checks exactly that against **git-tracked status**, not `file_exists` (dev files are physically present locally but gitignored, so `file_exists` would false-pass). It also self-installs a pre-commit hook and provides a CI check.

## Install (in a plugin that commits its vendor tree)

Add the VCS repository and require it as a dev dependency:

```jsonc
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/bizbudding/deployable-guard" }
    ],
    "require-dev": {
        "bizbudding/deployable-guard": "^1"
    },
    "scripts": {
        "post-install-cmd": [ "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'" ],
        "post-update-cmd":  [ "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'" ]
    }
}
```

The `test -f` guard is load-bearing: under `composer install --no-dev` (CI / raw-tree deploy) the dev-only guard bin is absent, so a bare `@php vendor/bin/deployable-guard install-hook` would fail with "Could not open input file" and abort the install. The guard makes it a no-op when the bin isn't there. (Do **not** add a `scripts-no-dev` key to work around this — it is not a real Composer property and fails `composer validate --strict`.)

`composer install` then installs a `.githooks/pre-commit` (gitignored, regenerated on every install/update) that runs the check when `vendor/composer/` is staged, and points `core.hooksPath` at it. Override a block intentionally with `git commit --no-verify`.

## Adopt in a plugin (step by step)

1. Add the VCS repo, the `require-dev` entry, and the `post-install`/`post-update` scripts to `composer.json` (snippet above).
2. Run `composer update bizbudding/deployable-guard`. This installs it, writes the lock, and runs `install-hook`, which sets `core.hooksPath=.githooks` and appends `.githooks/` to `.gitignore`.
3. Run `composer dump-autoload --no-dev` to regenerate the committed production autoloader.
4. Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`.
5. Verify with `php vendor/bin/deployable-guard check`. It should print `OK: committed autoloader is deployable as-is.`
6. Commit `composer.json`, `composer.lock`, the workflow, the `.gitignore` change, and, if you commit `vendor/`, `vendor/composer/installed.json`. **Gitignore `vendor/composer/installed.php`** — it re-stamps the current git ref on every dump (so it dirties the tree after every commit) and is never read at runtime, so tracking it only creates churn; `installed.json` is the stable record. Do not commit `vendor/bizbudding/` or `vendor/bin/`, which stay gitignored as dev-only.

## CI (the hard gate)

Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`. It checks out the committed tree (no composer build, exactly what a raw deploy sees), checks out this tool pinned to `v1`, and runs the check.

## CLI

```
deployable-guard check [--root=PATH]        # exit 1 if the committed autoloader is not deployable
deployable-guard install-hook [--root=PATH] # install the pre-commit hook + set core.hooksPath
```

## Fix when it blocks you

```
composer dump-autoload --no-dev
```
