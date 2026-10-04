<?php

namespace Bizbudding\DeployableGuard;

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

	private const LOADER = 'maithemewp/mai-package-loader';

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
	 * Relative paths a library loaded by maithemewp/mai-package-loader needs
	 * at runtime but that are not git-tracked.
	 *
	 * Those libraries have no Composer autoload entry, so missing() cannot see
	 * them. The loader finds them through each one's mai-package.php, loads the
	 * classes it declares, and reads vendor/composer/installed.php to find them
	 * quickly. A library is recognised from the committed installed.json by
	 * requiring the loader, so the check works in CI, where untracked files do
	 * not exist on disk.
	 *
	 * @return list<string> Empty when there is nothing to commit, or no such library.
	 */
	public function missing_for_loader(): array {
		$record = $this->root . '/vendor/composer/installed.json';

		if ( ! is_file( $record ) ) {
			return [];
		}

		$data = json_decode( (string) file_get_contents( $record ), true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		// Composer 2 wraps the list; Composer 1 wrote a bare list.
		$packages = $data['packages'] ?? $data;
		$dev      = array_fill_keys( $data['dev-package-names'] ?? [], true );
		$tracked  = $this->tracked_files();
		$missing  = [];
		$found    = false;

		foreach ( is_array( $packages ) ? $packages : [] as $package ) {
			$name = $package['name'] ?? null;

			if ( ! is_string( $name ) || isset( $dev[ $name ] ) || ! isset( $package['require'][ self::LOADER ] ) ) {
				continue;
			}

			$found = true;
			$dir   = self::normalize( 'vendor/composer/' . ( $package['install-path'] ?? '../' . $name ) );

			foreach ( $this->library_paths( $dir, $tracked ) as $path ) {
				$missing[] = $path;
			}
		}

		if ( $found && ! isset( $tracked['vendor/composer/installed.php'] ) ) {
			$missing[] = 'vendor/composer/installed.php';
		}

		return $missing;
	}

	/**
	 * What one library is missing: its declaration, then the files it declares.
	 *
	 * @param array<string,true> $tracked
	 * @return list<string>
	 */
	private function library_paths( string $dir, array $tracked ): array {
		$declaration = $dir . '/mai-package.php';

		if ( ! isset( $tracked[ $declaration ] ) ) {
			return [ $declaration ];
		}

		$data = ( static fn( string $file ): mixed => include $file )( $this->root . '/' . $declaration );

		if ( ! is_array( $data ) ) {
			return [];
		}

		$missing = [];

		foreach ( is_array( $data['classes'] ?? null ) ? $data['classes'] : [] as $file ) {
			if ( is_string( $file ) && ! isset( $tracked[ $dir . '/' . ltrim( $file, '/' ) ] ) ) {
				$missing[] = $dir . '/' . ltrim( $file, '/' );
			}
		}

		if ( isset( $data['namespace'] ) && is_string( $data['path'] ?? null ) ) {
			$path   = rtrim( $dir . '/' . trim( $data['path'], '/' ), '/' );
			$prefix = $path . '/';
			$any    = false;

			foreach ( array_keys( $tracked ) as $file ) {
				if ( str_starts_with( $file, $prefix ) ) {
					$any = true;
					break;
				}
			}

			// In CI only tracked files exist, so a folder nobody committed
			// shows up as nothing tracked inside it.
			if ( ! $any ) {
				$missing[] = $prefix;
			}

			// Locally, a file on disk that git does not track.
			if ( is_dir( $this->root . '/' . $path ) ) {
				$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root . '/' . $path, \FilesystemIterator::SKIP_DOTS ) );

				foreach ( $files as $file ) {
					$rel = substr( $file->getPathname(), strlen( $this->root ) + 1 );

					if ( $file->isFile() && 'php' === $file->getExtension() && ! isset( $tracked[ $rel ] ) ) {
						$missing[] = $rel;
					}
				}
			}
		}

		sort( $missing );

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Resolves "." and ".." in a repo-relative path without touching disk.
	 */
	private static function normalize( string $path ): string {
		$parts = [];

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $parts );
				continue;
			}

			$parts[] = $part;
		}

		return implode( '/', $parts );
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
