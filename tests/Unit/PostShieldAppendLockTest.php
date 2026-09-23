<?php
/**
 * Unit tests for the instant append's lock protocol against a rebuild in
 * another process: the append must wait for a rebuild that holds the list's
 * lock, then check every line against the file that rebuild renamed in — an
 * append checked only against the old file is lost when the rename replaces
 * it, and its post gets a pre-boot 404 until the next rebuild. The same holds
 * for a root-extras stream, which reads without the lock.
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
	 * An append during a root-extras stream (which reads without the list
	 * lock) writes every line to the live list — the one the old file already
	 * listed too — and the stream's commit carries that tail over. Deduped
	 * against the old file, a line the stream's read left out would be lost.
	 *
	 * @return void
	 */
	public function test_an_append_during_a_stream_reaches_the_committed_list(): void {
		$file = $this->dir . '/allowlist.php';
		file_put_contents( $file, "<?php exit;\nabout/photo\n" );
		clearstatcache( true, $file );
		$start = [ (int) filesize( $file ), (int) fileinode( $file ) ];

		// The stream: hold `.stream` shared, as rebuild_root_extras() does.
		$stream = proc_open(
			[
				PHP_BINARY,
				'-r',
				'$h = fopen( $argv[1] . "/.stream", "c" ); flock( $h, LOCK_SH ); echo "STREAMING\n"; fflush( STDOUT ); fgets( STDIN ); flock( $h, LOCK_UN );',
				$this->dir,
			],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		$this->assertSame( "STREAMING\n", fgets( $pipes[1] ), 'The stream is in flight.' );

		$builder  = new AllowlistBuilder( [] );
		$appended = $builder->append_slugs_to_file( $file, [ 'about/photo' ], 'full-path' );
		fwrite( $pipes[0], "done\n" );
		proc_close( $stream );
		$this->assertSame( 1, $appended, 'Written although the old file lists it.' );

		// The commit: the stream's own read left the line out; the tail brings it.
		$handle = fopen( $this->dir . '/commit.tmp', 'w+' );
		$copy   = new \ReflectionMethod( AllowlistBuilder::class, 'copy_appended' );
		$copy->setAccessible( true );
		$this->assertSame( 1, $copy->invoke( $builder, $file, $start, $handle ) );
		rewind( $handle );
		$this->assertSame( "about/photo\n", stream_get_contents( $handle ) );
		fclose( $handle );

		// With the stream gone, the same append is a no-op again.
		$this->assertSame( 0, $builder->append_slugs_to_file( $file, [ 'about/photo' ], 'full-path' ) );
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
