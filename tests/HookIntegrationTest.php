<?php

use Bizbudding\DeployableGuard\HookInstaller;
use PHPUnit\Framework\TestCase;

final class HookIntegrationTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/dg-hook-' . uniqid();
		mkdir( $this->root . '/vendor/composer', 0777, true );
		mkdir( $this->root . '/vendor/bin', 0777, true );
		// Make the real bin resolvable inside the scratch repo (as composer would).
		symlink( dirname( __DIR__ ) . '/bin/deployable-guard', $this->root . '/vendor/bin/deployable-guard' );

		$g = 'git -C ' . escapeshellarg( $this->root ) . ' ';
		exec( $g . 'init -q' );
		exec( $g . 'config user.email t@t.t' );
		exec( $g . 'config user.name t' );
		// An empty local value overrides any machine-wide setting, so only the tests that set it run extra hooks.
		exec( $g . 'config deployable-guard.extraHooks ""' );
	}

	/**
	 * A folder of extra hooks that refuse when the staged text or the message holds "secret".
	 */
	private function extra_hooks(): string {
		$dir = sys_get_temp_dir() . '/dg-extra-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/pre-commit', "#!/bin/sh\nif git diff --cached | grep -q secret; then echo 'refused' >&2; exit 1; fi\n" );
		file_put_contents( $dir . '/commit-msg', "#!/bin/sh\nif grep -q secret \"$1\"; then echo 'refused' >&2; exit 1; fi\n" );
		chmod( $dir . '/pre-commit', 0755 );
		chmod( $dir . '/commit-msg', 0755 );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' config deployable-guard.extraHooks ' . escapeshellarg( $dir ) );

		return $dir;
	}

	private function stage( string $file, string $text ): void {
		file_put_contents( $this->root . '/' . $file, $text );
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add ' . escapeshellarg( $file ) );
	}

	private function commit( string $msg ): int {
		$code = 0;
		$out  = [];
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' commit -q -m ' . escapeshellarg( $msg ) . ' 2>&1', $out, $code );
		return $code;
	}

	public function test_install_writes_executable_hook_and_sets_path(): void {
		( new HookInstaller( $this->root ) )->install();

		self::assertFileExists( $this->root . '/.githooks/pre-commit' );
		self::assertTrue( (bool) ( fileperms( $this->root . '/.githooks/pre-commit' ) & 0111 ) );

		exec( 'git -C ' . escapeshellarg( $this->root ) . ' config core.hooksPath', $out );
		self::assertSame( '.githooks', trim( $out[0] ?? '' ) );
	}

	public function test_polluted_autoloader_blocks_commit_but_clean_passes(): void {
		( new HookInstaller( $this->root ) )->install();

		// Clean: the referenced entry IS tracked -> commit is allowed.
		file_put_contents( $this->root . '/tracked.php', '<?php' );
		file_put_contents(
			$this->root . '/vendor/composer/autoload_files.php',
			'<?php return ' . var_export( [ 'x' => $this->root . '/tracked.php' ], true ) . ';'
		);
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add tracked.php vendor/composer/autoload_files.php' );
		self::assertSame( 0, $this->commit( 'clean' ), 'clean autoloader should commit' );

		// Polluted: the referenced entry is NOT tracked -> the hook blocks the commit.
		file_put_contents( $this->root . '/vendor/dev.php', '<?php' );
		file_put_contents(
			$this->root . '/vendor/composer/autoload_files.php',
			'<?php return ' . var_export( [ 'x' => $this->root . '/vendor/dev.php' ], true ) . ';'
		);
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add vendor/composer/autoload_files.php' );
		self::assertNotSame( 0, $this->commit( 'polluted' ), 'dev-polluted autoloader must be blocked' );
	}

	public function test_missing_binary_skips_instead_of_blocking(): void {
		( new HookInstaller( $this->root ) )->install();

		// Simulate a consumer `composer install --no-dev`: the dev-only binary is gone.
		unlink( $this->root . '/vendor/bin/deployable-guard' );

		// A dev-polluted autoloader would normally be blocked, but with the binary
		// absent the hook must fail open (skip) rather than error out and false-block.
		file_put_contents( $this->root . '/vendor/dev.php', '<?php' );
		file_put_contents(
			$this->root . '/vendor/composer/autoload_files.php',
			'<?php return ' . var_export( [ 'x' => $this->root . '/vendor/dev.php' ], true ) . ';'
		);
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' add vendor/composer/autoload_files.php' );
		self::assertSame( 0, $this->commit( 'no-binary' ), 'missing binary must skip the check, not block the commit' );
	}

	public function test_install_writes_an_executable_commit_msg_hook(): void {
		( new HookInstaller( $this->root ) )->install();

		self::assertFileExists( $this->root . '/.githooks/commit-msg' );
		self::assertTrue( (bool) ( fileperms( $this->root . '/.githooks/commit-msg' ) & 0111 ) );
	}

	public function test_extra_hooks_refuse_staged_text_and_messages(): void {
		( new HookInstaller( $this->root ) )->install();
		$this->extra_hooks();

		$this->stage( 'notes.md', 'a secret name' );
		self::assertNotSame( 0, $this->commit( 'notes' ), 'the extra pre-commit refuses staged text' );

		$this->stage( 'notes.md', 'nothing private' );
		self::assertNotSame( 0, $this->commit( 'about the secret site' ), 'the extra commit-msg refuses the message' );
		self::assertSame( 0, $this->commit( 'notes' ), 'clean text and message commit' );
	}

	public function test_without_extra_hooks_nothing_extra_runs(): void {
		( new HookInstaller( $this->root ) )->install();

		$this->stage( 'notes.md', 'a secret name' );
		self::assertSame( 0, $this->commit( 'about the secret site' ) );
	}

	public function test_a_missing_extra_folder_does_not_block(): void {
		( new HookInstaller( $this->root ) )->install();
		exec( 'git -C ' . escapeshellarg( $this->root ) . ' config deployable-guard.extraHooks /nowhere/at/all' );

		$this->stage( 'notes.md', 'a secret name' );
		self::assertSame( 0, $this->commit( 'notes' ) );
	}
}
