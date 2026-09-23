<?php
/**
 * Unit tests for the example site loader (examples/mu-plugins/05-…).
 *
 * The loader decides whether the write side (bootstrap.php) loads at all. A
 * page view skips it — unless something writes a post during the page view
 * (PublishPress Revisions publishing a due scheduled revision inline), when
 * the first post-write hook must load it, or the new slug is never appended.
 * Each test runs in its own process: the loader defines globals and
 * require_once's files that must not leak between cases.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the site loader.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldSiteLoaderTest extends TestCase {

	/**
	 * The temp mu-plugins tree, removed after each test.
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
		if ( '' !== $this->root ) {
			foreach ( [ '/post-404-shield/src/php/Function/LoadContext.php', '/post-404-shield/bootstrap.php', '/05-post-404-shield-bootstrap.php' ] as $file ) {
				unlink( $this->root . $file );
			}
			foreach ( [ '/post-404-shield/src/php/Function', '/post-404-shield/src/php', '/post-404-shield/src', '/post-404-shield', '' ] as $dir ) {
				rmdir( $this->root . $dir );
			}
		}
	}

	/**
	 * A temp mu-plugins tree: the real loader and LoadContext.php, and a
	 * generator stub that counts how often it loads.
	 *
	 * @return string Loader path.
	 */
	private function site(): string {
		$root       = sys_get_temp_dir() . '/postshield-loader-' . getmypid() . '-' . uniqid();
		$this->root = $root;
		mkdir( $root . '/post-404-shield/src/php/Function', 0755, true );
		copy( dirname( __DIR__, 2 ) . '/examples/mu-plugins/05-post-404-shield-bootstrap.php', $root . '/05-post-404-shield-bootstrap.php' );
		copy( dirname( __DIR__, 2 ) . '/post-404-shield/src/php/Function/LoadContext.php', $root . '/post-404-shield/src/php/Function/LoadContext.php' );
		file_put_contents( $root . '/post-404-shield/bootstrap.php', "<?php\n\$GLOBALS['post_shield_test_loads'] = ( \$GLOBALS['post_shield_test_loads'] ?? 0 ) + 1;\n" );
		return $root . '/05-post-404-shield-bootstrap.php';
	}

	/**
	 * The WordPress calls the loader makes, recording add_action().
	 *
	 * @return void
	 */
	private function stub_wordpress(): void {
		if ( ! defined( 'POST_SHIELD_LOADED' ) ) {
			define( 'POST_SHIELD_LOADED', true ); // Tier 1 already ran: skip the read path.
		}
		$GLOBALS['post_shield_test_hooks'] = [];
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["post_shield_test_hooks"][] = [ $hook, $callback, $priority ]; return true; }
			function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["post_shield_test_hooks"][] = [ $hook, $callback, $priority ]; return true; }
			function is_admin() { return false; }
			function wp_doing_cron() { return false; }
			function rest_get_url_prefix() { return "wp-json"; }'
		);
	}

	/**
	 * A page view does not load the write side — until it writes a post.
	 *
	 * @return void
	 */
	public function test_page_view_loads_the_write_side_on_the_first_post_write(): void {
		$this->stub_wordpress();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/global/about/';
		require $this->site();

		$this->assertArrayNotHasKey( 'post_shield_test_loads', $GLOBALS, 'A page view skips the generator.' );
		$hooks = array_column( $GLOBALS['post_shield_test_hooks'], 2, 0 );
		foreach ( [ 'transition_post_status', 'post_updated', 'save_post', 'add_attachment', 'edit_attachment', 'before_delete_post', 'revision_applied', 'revision_published' ] as $hook ) {
			$this->assertSame( PHP_INT_MIN, $hooks[ $hook ] ?? null, $hook . ' loads it first, ahead of any listener.' );
		}

		$callback = $GLOBALS['post_shield_test_hooks'][0][1];
		$callback();
		$callback();
		$this->assertSame( 1, $GLOBALS['post_shield_test_loads'], 'Loaded once, on the first write.' );
	}

	/**
	 * PublishPress's pre-apply filter loads the write side before the revision
	 * is written, and passes the revision data through unchanged.
	 *
	 * @return void
	 */
	public function test_the_revision_filter_loads_the_write_side_and_passes_the_data_through(): void {
		$this->stub_wordpress();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/global/about/';
		require $this->site();

		$filters = array_filter( $GLOBALS['post_shield_test_hooks'], static fn( $hook ) => 'revisionary_apply_revision_data' === $hook[0] );
		$this->assertCount( 1, $filters );
		$filter = reset( $filters );
		$this->assertSame( PHP_INT_MIN, $filter[2] );
		$data = [ 'post_name' => 'renamed' ];
		$this->assertSame( $data, ( $filter[1] )( $data ) );
		$this->assertSame( 1, $GLOBALS['post_shield_test_loads'] );
	}

	/**
	 * A request that needs the write side loads it at once, with no lazy hooks.
	 *
	 * @return void
	 */
	public function test_a_post_request_loads_the_write_side_at_once(): void {
		$this->stub_wordpress();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/global/contact/';
		require $this->site();

		$this->assertSame( 1, $GLOBALS['post_shield_test_loads'] );
		$this->assertSame( [], $GLOBALS['post_shield_test_hooks'] );
	}
}
