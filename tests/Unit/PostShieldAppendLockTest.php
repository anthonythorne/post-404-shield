<?php
/**
 * Unit tests for the instant append's lock protocol against a rebuild in
 * another process: the append must wait for a rebuild that holds the list's
 * lock, then check every line against the file that rebuild renamed in — an
 * append checked only against the old file is lost when the rename replaces
 * it, and its post gets a pre-boot 404 until the next rebuild.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\AllowlistBuilder;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';

/**
 * Test class for the append lock protocol.
 */
class PostShieldAppendLockTest extends TestCase {

	/**
	 * The temp list directory.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Build the list directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/postshield-lock-' . getmypid() . '-' . uniqid();
		mkdir( $this->dir );
	}

	/**
	 * Remove it.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( (array) scandir( $this->dir ) as $file ) {
			if ( '.' !== $file && '..' !== $file ) {
				unlink( $this->dir . '/' . $file );
			}
		}
		rmdir( $this->dir );
	}

	/**
	 * An append during a rebuild waits for it, and every line lands in the
	 * rebuilt file — the one only the old file listed too.
	 *
	 * @return void
	 */
	public function test_an_append_during_a_rebuild_lands_in_the_rebuilt_list(): void {
		$file = $this->dir . '/allowlist.php';
		// The old list still carries `old-a` (a stale line removals wait on).
		file_put_contents( $file, "<?php exit;\nold-a\nkept\n" );
		// The rebuild's list, read before the append's post was written.
		file_put_contents( $this->dir . '/rebuilt.tmp', "<?php exit;\nkept\n" );

		// The rebuild: hold the lock, rename its list in, let go.
		$rebuild = proc_open(
			[
				PHP_BINARY,
				'-r',
				'$h = fopen( $argv[1] . "/.lock", "c" ); flock( $h, LOCK_EX ); echo "LOCKED\n"; fflush( STDOUT ); usleep( 1500000 ); rename( $argv[1] . "/rebuilt.tmp", $argv[1] . "/allowlist.php" ); flock( $h, LOCK_UN );',
				$this->dir,
			],
			[ 1 => [ 'pipe', 'w' ] ],
			$pipes
		);
		$this->assertSame( "LOCKED\n", fgets( $pipes[1] ), 'The rebuild holds the lock.' );

		$started  = microtime( true );
		$appended = ( new AllowlistBuilder( [] ) )->append_slugs_to_file( $file, [ 'old-a', 'new-b' ] );
		$waited   = microtime( true ) - $started;
		proc_close( $rebuild );

		$lines = explode( "\n", trim( (string) file_get_contents( $file ) ) );
		$this->assertGreaterThan( 1.0, $waited, 'The append waited for the rebuild.' );
		$this->assertSame( 2, $appended );
		$this->assertContains( 'new-b', $lines );
		$this->assertContains( 'old-a', $lines, 'A line only the old file listed is re-checked against the new one.' );
		$this->assertContains( 'kept', $lines );
	}

	/**
	 * With no rebuild in flight, a line already listed is not written again.
	 *
	 * @return void
	 */
	public function test_a_listed_line_is_not_appended_again(): void {
		$file = $this->dir . '/allowlist.php';
		file_put_contents( $file, "<?php exit;\nlisted\n" );
		$this->assertSame( 0, ( new AllowlistBuilder( [] ) )->append_slugs_to_file( $file, [ 'listed' ] ) );
		$this->assertSame( "<?php exit;\nlisted\n", file_get_contents( $file ) );
	}
}
