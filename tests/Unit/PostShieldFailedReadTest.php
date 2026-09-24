<?php
/**
 * Unit tests for a failed database read during a rebuild: the list must stay
 * exactly as it was (a list built from "no rows" would drop every real
 * address, and the first append after it would arm it), and the builder must
 * report the read as failed — which the save turns into a retry, not a
 * refusal about the uploads directory.
 *
 * The builder writes under shield_dir(), which it finds from its own place in
 * the tree, so each test loads a copy of the plugin in a temp site tree, in a
 * process of its own.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for failed reads during a rebuild.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldFailedReadTest extends TestCase {

	/**
	 * The temp site root.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Remove the temp tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' === $this->root || ! is_dir( $this->root ) ) {
			return;
		}
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * Copy the builder into a temp tree, stub WordPress and a failing $wpdb,
	 * and load it.
	 *
	 * @return string The uploads/post-404-shield directory.
	 */
	private function site(): string {
		$this->root = sys_get_temp_dir() . '/postshield-read-' . getmypid() . '-' . uniqid();
		$plugin     = dirname( __DIR__, 2 ) . '/post-404-shield/src/php';
		$dest       = $this->root . '/wp-content/mu-plugins/post-404-shield/src/php';
		mkdir( $dest . '/Function', 0755, true );
		mkdir( $dest . '/Library', 0755, true );
		copy( $plugin . '/Function/ConfigReader.php', $dest . '/Function/ConfigReader.php' );
		copy( $plugin . '/Library/AllowlistBuilder.php', $dest . '/Library/AllowlistBuilder.php' );
		copy( $plugin . '/Library/ReadFailure.php', $dest . '/Library/ReadFailure.php' );

		ini_set( 'error_log', $this->root . '/error.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the failure is logged; keep it off the test's output.
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function post_type_exists( $type ) { return true; }
			function is_post_type_hierarchical( $type ) { return false; }
			function get_post_status_object( $status ) { return (object) [ "private" => false, "public" => true ]; }
			function is_post_status_viewable( $status ) { return true; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish" ]; }'
		);
		// Every read fails: no rows, and the error the builder checks for.
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = 'Deadlock found when trying to get lock';
			/**
			 * Stub prepare.
			 *
			 * @param string $query SQL.
			 * @return string
			 */
			public function prepare( $query ) {
				return (string) $query;
			}
			/**
			 * No rows.
			 *
			 * @return array
			 */
			public function get_col() {
				return [];
			}
			/**
			 * No rows.
			 *
			 * @return array
			 */
			public function get_results() {
				return [];
			}
		};
		require $dest . '/Function/ConfigReader.php';
		require $dest . '/Library/AllowlistBuilder.php';
		return $this->root . '/wp-content/uploads/post-404-shield';
	}

	/**
	 * A type's rebuild keeps the previous list and reports the failed read.
	 *
	 * @return void
	 */
	public function test_a_failed_read_keeps_the_type_list(): void {
		$dir = $this->site();
		mkdir( $dir . '/story', 0755, true );
		$before = "<?php exit; // guard\nreal-one\nreal-two\n";
		file_put_contents( $dir . '/story/allowlist.php', $before );

		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'story' => [
					'url_base'    => [ 'stories' ],
					'post_status' => [ 'publish' ],
				],
			]
		);
		$this->assertSame( 0, $builder->rebuild_type( 'story' ) );
		$this->assertSame( $before, file_get_contents( $dir . '/story/allowlist.php' ), 'The previous list stays, byte for byte.' );
		$this->assertSame( [ 'story' ], $builder->failed_reads() );
	}

	/**
	 * Root-extras, streamed, keeps its previous list too.
	 *
	 * @return void
	 */
	public function test_a_failed_read_mid_stream_keeps_root_extras(): void {
		$dir = $this->site();
		mkdir( $dir . '/root-extras', 0755, true );
		$before = "<?php exit; // guard\nsome-media\n";
		file_put_contents( $dir . '/root-extras/allowlist.php', $before );

		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'page' => [
					'root'        => true,
					'match'       => 'full-path',
					'post_status' => [ 'publish' ],
				],
			]
		);
		$this->assertSame( 0, $builder->rebuild_root_extras() );
		$this->assertSame( $before, file_get_contents( $dir . '/root-extras/allowlist.php' ) );
		$this->assertSame( [ \Post404Shield\Library\AllowlistBuilder::ROOT_EXTRAS_DIR ], $builder->failed_reads() );
	}
}
