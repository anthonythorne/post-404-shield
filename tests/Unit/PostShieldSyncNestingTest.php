<?php
/**
 * Unit tests for how often a trash or delete reads its type's parent map:
 * once before, and once when the OUTERMOST trash or delete ends — not once
 * per nested one. WPML trashes or deletes every translation inside the outer
 * call when it deletes them together, and core deletes each revision inside
 * a delete; a read per nested call re-reads the whole type dozens of times.
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the nested trash/delete diff.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldSyncNestingTest extends TestCase {

	/**
	 * The controller over a hierarchical `photographer` type, with a $wpdb
	 * that counts the parent-map reads.
	 *
	 * @return \Post404Shield\Controller\PostShieldSyncController
	 */
	private function controller(): \Post404Shield\Controller\PostShieldSyncController {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_post_type( $id ) { return "photographer"; }
			function is_post_type_hierarchical( $type ) { return true; }
			function post_type_exists( $type ) { return true; }
			function get_post_status_object( $status ) { return (object) [ "private" => false, "public" => true ]; }
			function is_post_status_viewable( $status ) { return true; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish" ]; }
			function has_action( $hook, $callback = false ) { return $GLOBALS["post_shield_test_shutdown"] ?? false; }
			function add_action( $hook, $callback, $priority = 10, $args = 1 ) { if ( "shutdown" === $hook ) { $GLOBALS["post_shield_test_shutdown"] = true; } return true; }'
		);
		$GLOBALS['post_shield_test_reads'] = 0;
		$GLOBALS['wpdb']                   = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = '';
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
			 * The parent map: counted, and unchanged.
			 *
			 * @param string $query SQL.
			 * @return array
			 */
			public function get_results( $query ) {
				if ( false !== strpos( (string) $query, 'SELECT ID, post_name, post_parent, post_status' ) ) {
					++$GLOBALS['post_shield_test_reads'];
				}
				return [];
			}
		};
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Controller/PostShieldSyncController.php';
		return new \Post404Shield\Controller\PostShieldSyncController(
			new \Post404Shield\Library\AllowlistBuilder(
				[
					'photographer' => [
						'url_base'    => [ 'photographers' ],
						'post_status' => [ 'publish' ],
					],
				]
			)
		);
	}

	/**
	 * A trash with 47 nested translation trashes reads the map twice.
	 *
	 * @return void
	 */
	public function test_nested_trashes_read_the_type_once_before_and_once_after(): void {
		$sync = $this->controller();
		$sync->handle_before_trash( 1 );
		foreach ( range( 2, 48 ) as $id ) {
			$sync->handle_before_trash( $id );
			$sync->handle_trashed( $id );
		}
		$sync->handle_trashed( 1 );
		$this->assertSame( 2, $GLOBALS['post_shield_test_reads'] );
	}

	/**
	 * A delete with nested deletes (revisions, translations) does the same.
	 *
	 * @return void
	 */
	public function test_nested_deletes_read_the_type_once_before_and_once_after(): void {
		$sync = $this->controller();
		$sync->handle_before_delete( 1 );
		foreach ( range( 2, 48 ) as $id ) {
			$sync->handle_before_delete( $id );
			$sync->handle_after_delete( $id );
		}
		$sync->handle_after_delete( 1 );
		$this->assertSame( 2, $GLOBALS['post_shield_test_reads'] );
	}
}
