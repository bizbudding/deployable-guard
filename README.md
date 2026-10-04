# deployable-guard

Verify a committed composer autoloader is deployable as a **raw git tree**.

These plugins deploy by checking out / copying a branch with no composer build on the server, so the committed `vendor/` must be self-consistent: every file `vendor/composer/autoload_files.php` eagerly `require()`s must be **git-tracked**. If that autoloader was regenerated with dev dependencies installed, it references dev-only packages that `.gitignore` excludes from the commit, and a raw-branch deploy then fatals on load.

Libraries loaded by [mai-package-loader](https://github.com/maithemewp/mai-package-loader) have no Composer autoload entry, so the guard also checks that each one's `mai-package.php`, the class files it declares, and `vendor/composer/installed.php` are committed. The loader reads `installed.php` to find those libraries quickly.

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
        "install-git-hooks": [
            "sh -c 'test -f vendor/bin/deployable-guard && php vendor/bin/deployable-guard install-hook || true'"
        ],
        "post-install-cmd": [ "@install-git-hooks" ],
        "post-update-cmd":  [ "@install-git-hooks" ]
    }
}
```

Naming the step as `install-git-hooks` keeps the `sh -c` string in one place and lets you reinstall the hook on its own with `composer install-git-hooks`. All 48 plugins in the fleet use this shape.

The `test -f` guard is load-bearing: under `composer install --no-dev` (CI / raw-tree deploy) the dev-only guard bin is absent, so a bare `@php vendor/bin/deployable-guard install-hook` would fail with "Could not open input file" and abort the install. The guard makes it a no-op when the bin isn't there. (Do **not** add a `scripts-no-dev` key to work around this — it is not a real Composer property and fails `composer validate --strict`.)

`composer install` then installs a `.githooks/pre-commit` (gitignored, regenerated on every install/update) that runs the check when anything under `vendor/`, or `.gitignore`, is staged, and points `core.hooksPath` at it. Override a block intentionally with `git commit --no-verify`.

## Adopt in a plugin (step by step)

1. Add the VCS repo, the `require-dev` entry, and the `install-git-hooks` / `post-install` / `post-update` scripts to `composer.json` (snippet above).
2. Run `composer update bizbudding/deployable-guard`. This installs it, writes the lock, and runs `install-hook`, which sets `core.hooksPath=.githooks` and appends `.githooks/` to `.gitignore`.
3. Run `composer dump-autoload --no-dev` to regenerate the committed production autoloader.
4. Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`.
5. Verify with `php vendor/bin/deployable-guard check`. It should print `OK: committed autoloader is deployable as-is.`
6. Commit `composer.json`, `composer.lock`, the workflow, the `.gitignore` change, and, if you commit `vendor/`, `vendor/composer/installed.json`. **Commit `vendor/composer/installed.php` too.** mai-package-loader reads it at runtime, and without it every page lists each plugin's library folders instead, two to three times slower. It records the plugin's current git commit, so two lines change on every `composer install` or `composer update`: commit them with the rest of the change. `composer dump-autoload` does not touch it. Do not commit `vendor/bizbudding/` or `vendor/bin/`, which stay gitignored as dev-only.

## CI (the hard gate)

Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`. It checks out the committed tree (no composer build, exactly what a raw deploy sees), checks out this tool pinned to `v1`, and runs the check.

## CLI

```
deployable-guard check [--root=PATH]        # exit 1 if the committed autoloader, or a loader library, is not deployable
deployable-guard install-hook [--root=PATH] # install the pre-commit hook + set core.hooksPath
```

## Fix when it blocks you

When the autoloader references uncommitted files:

```
composer dump-autoload --no-dev
```

When a loader library's files are uncommitted, commit the files it lists. If `.gitignore` has a `/vendor/composer/installed.php` line, remove it first.
