<?php
/**
 * Unit tests for what the gates replay:
 *
 * - A blocked section must cover no real content, in any status WordPress
 *   serves — a discontinued post is as real as a published one.
 * - Root mode's sample of other types reads every type at its real address,
 *   shielded under a base or not: a type whose base a plugin drops lands at
 *   the root, and that is what the daily replay must catch.
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the served-content samples.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldServedSampleTest extends TestCase {

	/**
	 * Stub WordPress: a flat `firmware` type (and `service`) whose one post
	 * is in `discontinued`, a public status, and pages.
	 *
	 * @return void
	 */
	private function stub(): void {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_post_types( $args = [], $output = "names" ) { return "objects" === $output ? [] : [ "page" => "page", "firmware" => "firmware", "service" => "service" ]; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish", "discontinued" => "discontinued", "draft" => "draft" ]; }
			function get_post_status_object( $status ) { return in_array( $status, [ "publish", "discontinued", "draft" ], true ) ? (object) [ "name" => $status, "private" => false, "public" => "draft" !== $status ] : null; }
			function is_post_status_viewable( $status ) { return is_object( $status ) ? $status->public : "draft" !== $status; }
			function is_post_type_hierarchical( $type ) { return "page" === $type; }
			function get_post_field( $field, $id ) { return 9 === (int) $id ? "old-camera" : "svc-post"; }
			function get_page_uri( $id ) { return get_post_field( "post_name", $id ); }
			function get_page_by_path( $path ) { return null; }
			function add_filter( ...$args ) { return true; }
			function remove_filter( ...$args ) { return true; }
			function get_permalink( $id ) { return 9 === (int) $id ? "https://example.test/support/firmware/old-camera/" : "https://example.test/svc-post/"; }
			function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
			function get_taxonomies( $args = [], $output = "names" ) { return []; }
			function get_terms( $args = [] ) { return []; }'
		);
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = '';
			/**
			 * Stub prepare: the arguments put in, quoted.
			 *
			 * @param string $query SQL.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				foreach ( $args as $arg ) {
					$query = (string) preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", (string) $query, 1 );
				}
				return (string) $query;
			}
			/**
			 * Statuses in use: firmware's one post is discontinued.
			 *
			 * @param string $query SQL.
			 * @return array<object>
			 */
			public function get_results( $query ) {
				if ( false !== strpos( $query, 'GROUP BY post_status' ) && false !== strpos( $query, "'firmware'" ) ) {
					return [
						(object) [
							'post_status' => 'discontinued',
							'posts'       => 1,
						],
					];
				}
				return [];
			}
			/**
			 * Post IDs: firmware's post only when discontinued is asked for;
			 * service's post whatever the status list.
			 *
			 * @param string $query SQL.
			 * @return int[]
			 */
			public function get_col( $query ) {
				if ( false !== strpos( $query, "'firmware'" ) ) {
					return false !== strpos( $query, "'discontinued'" ) ? [ 9 ] : [];
				}
				return false !== strpos( $query, "'service'" ) ? [ 5 ] : [];
			}
		};
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/BasedPreflight.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/RootPreflight.php';
	}

	/**
	 * A blocked section's replay includes a post that is only discontinued.
	 *
	 * @return void
	 */
	public function test_a_block_replays_a_discontinued_post(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\BasedPreflight::class, 'every_type_paths' );
		$method->setAccessible( true );
		$paths = $method->invoke( new \Post404Shield\Library\BasedPreflight(), [ 'support/firmware/old-camera' ] );
		$this->assertContains( '/support/firmware/old-camera/', $paths );
	}

	/**
	 * Root mode's sample reads a type shielded under a base at its real
	 * address too.
	 *
	 * @return void
	 */
	public function test_the_root_sample_reads_based_types_at_their_real_address(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\RootPreflight::class, 'other_public_sample' );
		$method->setAccessible( true );
		$paths = $method->invoke(
			new \Post404Shield\Library\RootPreflight(),
			[ 'page' ],
			[
				'service' => [
					'mode'      => 'allowlist',
					'post_type' => 'service',
					'url_base'  => [ 'services' ],
				],
			]
		);
		$this->assertContains( '/svc-post/', $paths );
	}
}
