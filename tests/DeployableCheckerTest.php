<?php

use Bizbudding\DeployableGuard\DeployableChecker;
use PHPUnit\Framework\TestCase;

final class DeployableCheckerTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/dg-' . uniqid();
		mkdir( $this->root . '/vendor/composer', 0777, true );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' init -q' );
	}

	private function writeAutoloadFiles( array $absPaths ): void {
		file_put_contents(
			$this->root . '/vendor/composer/autoload_files.php',
			'<?php return ' . var_export( $absPaths, true ) . ';'
		);
	}

	public function test_absent_autoload_files_is_deployable(): void {
		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing() );
	}

	public function test_tracked_entry_passes(): void {
		mkdir( $this->root . '/vendor/pkg', 0777, true );
		file_put_contents( $this->root . '/vendor/pkg/f.php', '<?php' );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add vendor/pkg/f.php' );
		$this->writeAutoloadFiles( [ 'x' => $this->root . '/vendor/pkg/f.php' ] );

		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing() );
	}

	public function test_untracked_entry_is_reported(): void {
		file_put_contents( $this->root . '/vendor/dev.php', '<?php' ); // present but NOT git-added
		$this->writeAutoloadFiles( [ 'x' => $this->root . '/vendor/dev.php' ] );

		self::assertSame( [ 'vendor/dev.php' ], ( new DeployableChecker( $this->root ) )->missing() );
	}

	/**
	 * Builds a plugin bundling one library on the loader, every file tracked.
	 * Returns the library's folder, relative to the root.
	 */
	private function writeLoaderLibrary( string $name = 'maithemewp/mai-demo', array $declaration = [ 'namespace' => 'Mai\\Demo\\', 'path' => 'src' ], array $extra = [] ): string {
		$dir = 'vendor/' . $name;
		mkdir( $this->root . '/' . $dir . '/src/Sub', 0777, true );
		file_put_contents( $this->root . '/' . $dir . '/mai-package.php', '<?php return ' . var_export( [ 'name' => $name, 'version' => '1.0.0' ] + $declaration, true ) . ';' );
		file_put_contents( $this->root . '/' . $dir . '/src/Info.php', '<?php' );
		file_put_contents( $this->root . '/' . $dir . '/src/Sub/Deep.php', '<?php' );
		file_put_contents( $this->root . '/' . $dir . '/Mai_Demo.php', '<?php' );
		file_put_contents( $this->root . '/vendor/composer/installed.php', '<?php return [];' );
		file_put_contents( $this->root . '/vendor/composer/installed.json', json_encode( [
			'packages'          => [
				[ 'name' => $name, 'require' => [ 'maithemewp/mai-package-loader' => '^0.1' ], 'install-path' => '../' . $name ] + $extra,
				[ 'name' => 'maithemewp/mai-package-loader', 'install-path' => '../maithemewp/mai-package-loader' ],
			],
			'dev'               => false,
			'dev-package-names' => [],
		] ) );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add -A' );

		return $dir;
	}

	private function untrack( string $path ): void {
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' rm -q -r --cached ' . escapeshellarg( $path ) );
	}

	public function test_no_installed_json_means_nothing_to_check_for_the_loader(): void {
		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_a_fully_tracked_loader_library_passes(): void {
		$this->writeLoaderLibrary();

		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_untracked_installed_php_is_reported(): void {
		$this->writeLoaderLibrary();
		$this->untrack( 'vendor/composer/installed.php' );

		self::assertSame( [ 'vendor/composer/installed.php' ], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_untracked_declaration_is_reported(): void {
		$dir = $this->writeLoaderLibrary();
		$this->untrack( $dir . '/mai-package.php' );

		self::assertSame( [ $dir . '/mai-package.php' ], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_untracked_class_file_is_reported(): void {
		$dir = $this->writeLoaderLibrary();
		$this->untrack( $dir . '/src/Sub/Deep.php' );

		self::assertSame( [ $dir . '/src/Sub/Deep.php' ], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_a_class_folder_absent_from_the_commit_is_reported(): void {
		// What CI sees: the declaration is committed, the folder is not, so it
		// is not on disk either.
		$dir = $this->writeLoaderLibrary();
		$this->untrack( $dir . '/src' );
		exec( 'rm -rf ' . escapeshellarg( $this->root . '/' . $dir . '/src' ) );

		self::assertSame( [ $dir . '/src/' ], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_untracked_global_class_file_is_reported(): void {
		$dir = $this->writeLoaderLibrary( 'maithemewp/mai-logger', [ 'classes' => [ 'Mai_Demo' => 'Mai_Demo.php' ] ] );
		$this->untrack( $dir . '/Mai_Demo.php' );

		self::assertSame( [ $dir . '/Mai_Demo.php' ], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_dev_only_library_is_not_checked(): void {
		$dir  = $this->writeLoaderLibrary();
		$data = json_decode( file_get_contents( $this->root . '/vendor/composer/installed.json' ), true );
		$data['dev-package-names'] = [ 'maithemewp/mai-demo' ];
		file_put_contents( $this->root . '/vendor/composer/installed.json', json_encode( $data ) );
		$this->untrack( $dir );
		$this->untrack( 'vendor/composer/installed.php' );

		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_package_not_on_the_loader_is_not_checked(): void {
		mkdir( $this->root . '/vendor/other/lib', 0777, true );
		file_put_contents( $this->root . '/vendor/composer/installed.json', json_encode( [ 'packages' => [ [ 'name' => 'other/lib', 'install-path' => '../other/lib' ] ] ] ) );

		self::assertSame( [], ( new DeployableChecker( $this->root ) )->missing_for_loader() );
	}

	public function test_check_command_fails_on_an_untracked_installed_php(): void {
		$this->writeLoaderLibrary();
		$this->untrack( 'vendor/composer/installed.php' );

		exec( 'php ' . escapeshellarg( dirname( __DIR__ ) . '/bin/deployable-guard' ) . ' check --root=' . escapeshellarg( $this->root ) . ' 2>&1', $out, $code );

		self::assertSame( 1, $code );
		self::assertStringContainsString( 'vendor/composer/installed.php', implode( "\n", $out ) );
	}
}
