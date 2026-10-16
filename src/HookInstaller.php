<?php

namespace Bizbudding\DeployableGuard;

/**
 * Install (or refresh) the deployable-guard pre-commit hook in a consumer repo.
 *
 * Git hooks are not version-controlled, so this is invoked from the consumer's
 * composer `post-install-cmd`/`post-update-cmd` — the hook (re)appears on every
 * `composer install`/`update`, with no manual per-clone step. The hook itself is
 * generated (gitignored), so the package stays the single source of truth.
 */
final class HookInstaller {

	public function __construct( private string $root ) {}

	public function install(): void {
		// No-op outside a git work tree (e.g. installed as a nested dependency).
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' rev-parse --git-dir 2>/dev/null', $out, $code );
		if ( 0 !== $code ) {
			return;
		}

		$dir = $this->root . '/.githooks';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		file_put_contents( $dir . '/pre-commit', self::HOOK );
		chmod( $dir . '/pre-commit', 0755 );
		file_put_contents( $dir . '/commit-msg', self::COMMIT_MSG_HOOK );
		chmod( $dir . '/commit-msg', 0755 );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' config core.hooksPath .githooks' );
		$this->ensure_ignored( '.githooks/' );

		echo "deployable-guard: pre-commit and commit-msg hooks installed (core.hooksPath=.githooks).\n";
	}

	private function ensure_ignored( string $line ): void {
		$gitignore = $this->root . '/.gitignore';
		$current   = is_file( $gitignore ) ? (string) file_get_contents( $gitignore ) : '';

		if ( ! preg_match( '/^' . preg_quote( $line, '/' ) . '\s*$/m', $current ) ) {
			file_put_contents( $gitignore, rtrim( $current, "\n" ) . "\n" . $line . "\n" );
		}
	}

	/**
	 * Runs the extra hook of this name first, when `deployable-guard.extraHooks` in git config
	 * names a folder holding one. core.hooksPath points at .githooks, so this is the only way a
	 * machine's own checks (such as one that refuses private names) run in these repos too.
	 */
	private const EXTRA = "extra=\"\$(git config --get deployable-guard.extraHooks || true)\"\n";

	private const HOOK = "#!/bin/sh\n"
		. "# Managed by bizbudding/deployable-guard, regenerated on composer install. Do not edit.\n"
		. "# Your own checks first: git config --global deployable-guard.extraHooks <folder> runs its pre-commit.\n"
		. self::EXTRA
		. "if [ -n \"\$extra\" ] && [ -x \"\$extra/pre-commit\" ]; then\n"
		. "\t\"\$extra/pre-commit\" \"\$@\" || exit 1\n"
		. "fi\n"
		. "if git diff --cached --name-only | grep -q -e '^vendor/' -e '^\\.gitignore$'; then\n"
		. "\tguard=\"\$(git rev-parse --show-toplevel)/vendor/bin/deployable-guard\"\n"
		. "\t# Dev-only binary; if it is absent (e.g. after composer install --no-dev) skip the local check. CI is the hard gate.\n"
		. "\t[ -x \"\$guard\" ] || { echo 'deployable-guard: binary not installed; skipping local check (CI will verify).' >&2; exit 0; }\n"
		. "\texec \"\$guard\" check\n"
		. "fi\n";

	private const COMMIT_MSG_HOOK = "#!/bin/sh\n"
		. "# Managed by bizbudding/deployable-guard, regenerated on composer install. Do not edit.\n"
		. "# Your own checks: git config --global deployable-guard.extraHooks <folder> runs its commit-msg.\n"
		. self::EXTRA
		. "if [ -n \"\$extra\" ] && [ -x \"\$extra/commit-msg\" ]; then\n"
		. "\texec \"\$extra/commit-msg\" \"\$@\"\n"
		. "fi\n";
}
