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
	 * Two replacements of the live list during the stream (the second smaller
	 * than the list the stream started from, with a line appended after it):
	 * the committed list keeps that line.
	 *
	 * @return void
	 */
	public function test_a_line_appended_after_two_replacements_survives_the_commit(): void {
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
		// The stream's first read is when the other commits land: two
		// replacements (temp file + rename), then an append to the live list.
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
			 * No rows — the first time, after two other commits and an append.
			 *
			 * @return array
			 */
			public function get_results() {
				if ( ! $this->done ) {
					$this->done = true;
					$list       = $GLOBALS['post_shield_test_list'];
					foreach ( [ "<?php exit; // guard\nfresh-a\n", "<?php exit; // guard\nfresh-b\n" ] as $i => $body ) {
						file_put_contents( $list . '.r' . $i, $body );
						rename( $list . '.r' . $i, $list );
					}
					file_put_contents( $list, "appended-meanwhile\n", FILE_APPEND );
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
		$builder->rebuild_root_extras();
		$committed = (string) file_get_contents( $list );
		$this->assertStringContainsString( "\nappended-meanwhile\n", $committed );
		$this->assertStringContainsString( "\nfresh-b\n", $committed, 'The whole replacement body is carried over.' );
		$this->assertStringNotContainsString( 'old-line-one', $committed );
	}
}
