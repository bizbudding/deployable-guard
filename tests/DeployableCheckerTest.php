<?php

use Mai\DeployableGuard\DeployableChecker;
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
}
