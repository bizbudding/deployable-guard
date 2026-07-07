<?php

namespace Mai\DeployableGuard;

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
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' config core.hooksPath .githooks' );
		$this->ensure_ignored( '.githooks/' );

		echo "deployable-guard: pre-commit hook installed (core.hooksPath=.githooks).\n";
	}

	private function ensure_ignored( string $line ): void {
		$gitignore = $this->root . '/.gitignore';
		$current   = is_file( $gitignore ) ? (string) file_get_contents( $gitignore ) : '';

		if ( ! preg_match( '/^' . preg_quote( $line, '/' ) . '\s*$/m', $current ) ) {
			file_put_contents( $gitignore, rtrim( $current, "\n" ) . "\n" . $line . "\n" );
		}
	}

	private const HOOK = "#!/bin/sh\n"
		. "# Managed by maithemewp/deployable-guard — regenerated on composer install; do not edit.\n"
		. "if git diff --cached --name-only | grep -q '^vendor/composer/'; then\n"
		. "\texec \"\$(git rev-parse --show-toplevel)/vendor/bin/deployable-guard\" check\n"
		. "fi\n";
}
