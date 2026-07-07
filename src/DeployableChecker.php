<?php

namespace Mai\DeployableGuard;

/**
 * Verify a committed composer files-autoloader is deployable as a raw git tree.
 *
 * These repos deploy by copying/checking out a branch with no composer build, so
 * every file `vendor/composer/autoload_files.php` eagerly require()s at load time
 * must be COMMITTED (git-tracked). If that file was regenerated with dev deps, it
 * references dev-only packages that .gitignore excludes from the commit, and a
 * raw-branch deploy then fatals on load.
 *
 * The check is against git-tracked status, NOT file_exists: in a dev checkout the
 * dev-only files are physically present (built but gitignored), so file_exists
 * would give a false pass. Tracked-status is exactly what ships in a branch.
 */
final class DeployableChecker {

	public function __construct( private string $root ) {}

	/**
	 * Relative paths referenced by the files-autoloader but not git-tracked.
	 *
	 * @return list<string> Empty when deployable (or when there is no files-autoloader).
	 */
	public function missing(): array {
		$autoload_files = $this->root . '/vendor/composer/autoload_files.php';

		// Skip-if-absent: no files-autoloader means nothing to verify.
		if ( ! is_file( $autoload_files ) ) {
			return [];
		}

		/** @var array<string,string> $files */
		$files     = require $autoload_files;
		$root_real = realpath( $this->root ) ?: $this->root;
		$tracked   = $this->tracked_files();
		$missing   = [];

		foreach ( $files as $path ) {
			// Normalize both sides through realpath so a repo under a symlinked path
			// (e.g. /tmp -> /private/tmp, or a symlinked checkout) still matches the
			// repo-relative paths `git ls-files` reports.
			$real = realpath( $path );
			if ( false === $real ) {
				// Referenced file is not on disk at all -> certainly not deployable.
				$missing[] = ltrim( str_replace( $this->root, '', $path ), '/' );
				continue;
			}
			$rel = ltrim( str_replace( $root_real, '', $real ), '/' );
			if ( ! isset( $tracked[ $rel ] ) ) {
				$missing[] = $rel;
			}
		}

		return $missing;
	}

	/**
	 * @return array<string,true> git-tracked paths, keyed for O(1) lookup.
	 */
	private function tracked_files(): array {
		$out  = [];
		$code = 0;
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' ls-files 2>/dev/null', $out, $code );

		if ( 0 !== $code ) {
			throw new \RuntimeException( "git ls-files failed in {$this->root} (not a git checkout?)" );
		}

		return array_fill_keys( $out, true );
	}
}
