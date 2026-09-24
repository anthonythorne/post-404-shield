<?php
/**
 * Unit test for the root-extras stream's commit when other rebuilds replace
 * the live list while it reads: the stream holds the original file open, so a
 * replacement can never be given its inode back and pass for the same file
 * (ext4 hands a freed inode straight back), and the commit copies the whole
 * new body — lines appended meanwhile included — instead of a tail past the
 * old size, which a smaller replacement would leave empty.
 *
 * Runs in its own process, against a copy of the plugin in a temp site tree
 * (the builder finds its data directory from its own place in the tree).
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the stream commit.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldStreamCommitTest extends TestCase {

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
	 * A temp site with a day-old root-extras list, whose stream runs
	 * $meanwhile (given the list path) at its first read, then commits.
	 *
	 * @param callable $meanwhile What happens while the stream reads.
	 * @param bool     $locked    Whether the commit holds the list's lock.
	 *
	 * @return string The committed list.
	 */
	private function commit_with( callable $meanwhile, bool $locked = true ): string {
		$this->root = sys_get_temp_dir() . '/postshield-stream-' . getmypid() . '-' . uniqid();
		$plugin     = dirname( __DIR__, 2 ) . '/post-404-shield/src/php';
		$dest       = $this->root . '/wp-content/mu-plugins/post-404-shield/src/php';
		mkdir( $dest . '/Function', 0755, true );
		mkdir( $dest . '/Library', 0755, true );
		foreach ( [ 'Function/ConfigReader.php', 'Library/AllowlistBuilder.php', 'Library/ReadFailure.php' ] as $file ) {
			copy( $plugin . '/' . $file, $dest . '/' . $file );
		}
		$dir  = $this->root . '/wp-content/uploads/post-404-shield/root-extras';
		$list = $dir . '/allowlist.php';
		mkdir( $dir, 0755, true );
		// The day-old list, larger than what replaces it.
		file_put_contents( $list, "<?php exit; // guard\nold-line-one\nold-line-two\nold-line-three\n" );

		ini_set( 'error_log', $this->root . '/error.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keep log lines off the test's output.
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function post_type_exists( $type ) { return true; }
			function is_post_type_hierarchical( $type ) { return true; }
			function get_post_status_object( $status ) { return (object) [ "private" => false, "public" => true ]; }
			function is_post_status_viewable( $status ) { return true; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish" ]; }'
		);
		// The stream's first read is when the other commits land.
		$GLOBALS['post_shield_test_meanwhile'] = $meanwhile;
		$GLOBALS['post_shield_test_list'] = $list;
		$GLOBALS['wpdb']                  = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = '';
			/** @var bool */
			private $done = false;
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
			 * No rows — the first time, after what happens meanwhile.
			 *
			 * @return array
			 */
			public function get_results() {
				if ( ! $this->done ) {
					$this->done = true;
					( $GLOBALS['post_shield_test_meanwhile'] )( $GLOBALS['post_shield_test_list'] );
				}
				return [];
			}
		};
		require $dest . '/Function/ConfigReader.php';
		require $dest . '/Library/AllowlistBuilder.php';

		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'page' => [
					'root'        => true,
					'match'       => 'full-path',
					'post_status' => [ 'publish' ],
				],
			]
		);
		// A storage that cannot lock: warnings from the failed lock are expected.
		set_error_handler( static fn(): bool => ! $locked ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test only.
		try {
			$builder->rebuild_root_extras();
		} finally {
			restore_error_handler();
		}
		clearstatcache( true, $list );
		if ( ! $locked ) {
			$this->assertFileDoesNotExist( dirname( $list ) . '/.committed', 'No lock, no marker: the next stream copies the whole body.' );
			return (string) file_get_contents( $list );
		}
		$marker = explode( ':', (string) file_get_contents( dirname( $list ) . '/.committed' ) );
		$this->assertSame( [ (string) fileinode( $list ), (string) filesize( $list ) ], array_slice( $marker, 0, 2 ), 'The commit records itself for the next stream.' );
		return (string) file_get_contents( $list );
	}

	/**
	 * Two replacements of the live list during the stream (the second smaller
	 * than the list the stream started from, with a line appended after it):
	 * the committed list keeps that line.
	 *
	 * @return void
	 */
	public function test_a_line_appended_after_two_replacements_survives_the_commit(): void {
		$committed = $this->commit_with(
			static function ( string $list ): void {
				// Two replacements (temp file + rename), then an append.
				foreach ( [ "<?php exit; // guard\nfresh-a\n", "<?php exit; // guard\nfresh-b\n" ] as $i => $body ) {
					file_put_contents( $list . '.r' . $i, $body );
					rename( $list . '.r' . $i, $list );
				}
				file_put_contents( $list, "appended-meanwhile\n", FILE_APPEND );
			}
		);
		$this->assertStringContainsString( "\nappended-meanwhile\n", $committed );
		$this->assertStringContainsString( "\nfresh-b\n", $committed, 'The whole replacement body is carried over.' );
		$this->assertStringNotContainsString( 'old-line-one', $committed );
	}

	/**
	 * One other stream committed meanwhile (its marker says it replaced the
	 * list this one started from): the lines appended before and after that
	 * commit are kept, the other stream's own lines are not — a status this
	 * one drops stays dropped.
	 *
	 * @return void
	 */
	public function test_one_other_commit_carries_only_the_appends(): void {
		$committed = $this->commit_with(
			static function ( string $list ): void {
				file_put_contents( $list, "appended-before-its-commit\n", FILE_APPEND );
				$original = (int) fileinode( $list );
				file_put_contents( $list . '.r', "<?php exit; // guard\nits-own-line\n" );
				rename( $list . '.r', $list );
				clearstatcache( true, $list );
				file_put_contents( dirname( $list ) . '/.committed', fileinode( $list ) . ':' . filesize( $list ) . ':' . $original );
				file_put_contents( $list, "appended-after-its-commit\n", FILE_APPEND );
			}
		);
		$this->assertStringContainsString( "\nappended-before-its-commit\n", $committed );
		$this->assertStringContainsString( "\nappended-after-its-commit\n", $committed );
		$this->assertStringNotContainsString( 'its-own-line', $committed, 'Not the union of two builds.' );
		$this->assertStringNotContainsString( 'old-line-one', $committed );
	}

	/**
	 * A commit that cannot take the list's lock leaves no marker (an append
	 * could land between its rename and the size it records), and removes
	 * an earlier one.
	 *
	 * @return void
	 */
	public function test_an_unlocked_commit_leaves_no_marker(): void {
		$committed = $this->commit_with(
			static function ( string $list ): void {
				file_put_contents( dirname( $list ) . '/.committed', '1:2:3' );
				mkdir( dirname( $list ) . '/.lock' ); // The lock file cannot be opened.
			},
			false
		);
		$this->assertStringNotContainsString( 'old-line-one', $committed );
	}
}
