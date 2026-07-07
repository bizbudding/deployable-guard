# deployable-guard

Verify a committed composer autoloader is deployable as a **raw git tree**.

These plugins deploy by checking out / copying a branch with no composer build on the server, so the committed `vendor/` must be self-consistent: every file `vendor/composer/autoload_files.php` eagerly `require()`s must be **git-tracked**. If that autoloader was regenerated with dev dependencies installed, it references dev-only packages that `.gitignore` excludes from the commit, and a raw-branch deploy then fatals on load.

`deployable-guard` checks exactly that — against **git-tracked status**, not `file_exists` (dev files are physically present locally but gitignored, so `file_exists` would false-pass). It also self-installs a pre-commit hook and provides a CI check.

## Install (in a plugin that commits its vendor tree)

Add the VCS repository and require it as a dev dependency:

```jsonc
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/maithemewp/deployable-guard" }
    ],
    "require-dev": {
        "maithemewp/deployable-guard": "^1"
    },
    "scripts": {
        "post-install-cmd": [ "@php vendor/bin/deployable-guard install-hook" ],
        "post-update-cmd":  [ "@php vendor/bin/deployable-guard install-hook" ]
    }
}
```

`composer install` then installs a `.githooks/pre-commit` (gitignored, regenerated on every install/update) that runs the check when `vendor/composer/` is staged, and points `core.hooksPath` at it. Override a block intentionally with `git commit --no-verify`.

## CI (the hard gate)

Copy `templates/deployable.yml` to `.github/workflows/deployable.yml`. It checks out the committed tree (no composer build — exactly what a raw deploy sees), checks out this tool pinned to `v1`, and runs the check.

## CLI

```
deployable-guard check [--root=PATH]        # exit 1 if the committed autoloader is not deployable
deployable-guard install-hook [--root=PATH] # install the pre-commit hook + set core.hooksPath
```

## Fix when it blocks you

```
composer dump-autoload --no-dev
```
