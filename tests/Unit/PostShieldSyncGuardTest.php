<?php
/**
 * Unit tests for how the sync controller's hooks are registered: through a
 * guard that coerces a hook's scalar arguments the way WordPress's own
 * dispatch does (the controller is strict_types, and PublishPress passes a
 * database row's numeric-string ID to save_post), and that contains a failed
 * read or a mistyped argument instead of failing an editor's save.
 *
 * Each test runs in its own process: the WordPress stubs are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Controller\PostShieldSyncController;
use Post404Shield\Library\AllowlistBuilder;

/**
 * Test class for the sync controller's hook guard.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldSyncGuardTest extends TestCase {

	/**
	 * Load the controller with the WordPress calls its save handler makes, and
	 * return the callbacks it registered, by hook.
	 *
	 * @param string $get_post_type Body of the get_post_type() stub.
	 *
	 * @return array<string, callable[]>
	 */
	private function hooks( string $get_post_type ): array {
		$GLOBALS['post_shield_test_hooks'] = [];
		ini_set( 'error_log', sys_get_temp_dir() . '/postshield-guard-test.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the contained error is logged; keep it off the test's output.
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["post_shield_test_hooks"][ $hook ][] = $callback; return true; }
			function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return add_action( $hook, $callback, $priority, $args ); }
			function wp_is_post_revision( $id ) { return false; }
			function wp_is_post_autosave( $id ) { return false; }
			function get_post( $id ) { return null; }
			function get_post_type( $id ) { ' . $get_post_type . ' }'
		);
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Controller/PostShieldSyncController.php';
		( new PostShieldSyncController( new AllowlistBuilder( [] ) ) )->set_up();
		return $GLOBALS['post_shield_test_hooks'];
	}

	/**
	 * A numeric-string ID, as PublishPress passes to save_post, is coerced.
	 *
	 * @return void
	 */
	public function test_a_numeric_string_id_is_coerced(): void {
		$hooks = $this->hooks( '$GLOBALS["post_shield_test_seen"] = $id; return false;' );
		( $hooks['save_post'][0] )( '123', null, true );
		$this->assertSame( 123, $GLOBALS['post_shield_test_seen'], 'The handler got an int, and ran.' );
	}

	/**
	 * A failed read inside a handler is contained, and a filter's value is
	 * passed through.
	 *
	 * @return void
	 */
	public function test_a_failed_read_is_contained(): void {
		$hooks = $this->hooks( 'throw new \RuntimeException( "database query failed" );' );
		$this->assertSame( 123, ( $hooks['save_post'][0] )( 123 ), 'No exception reaches the save.' );
		$update = [ 'post_name' => 'renamed' ];
		$this->assertSame( $update, ( $hooks['revisionary_apply_revision_data'][0] )( $update, null, (object) [ 'ID' => 'x' ] ) );
	}
}
