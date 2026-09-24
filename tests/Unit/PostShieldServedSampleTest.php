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
			function get_post_stati( $args = [] ) { return [ "publish" => "publish", "discontinued" => "discontinued", "private" => "private", "draft" => "draft" ]; }
			function get_post_status_object( $status ) { return in_array( $status, [ "publish", "discontinued", "private", "draft" ], true ) ? (object) [ "name" => $status, "private" => "private" === $status, "public" => ! in_array( $status, [ "draft", "private" ], true ) ] : null; }
			function is_post_status_viewable( $status ) { return is_object( $status ) ? $status->public : "draft" !== $status; }
			function is_post_type_hierarchical( $type ) { return "page" === $type; }
			function get_post_field( $field, $id ) { return 9 === (int) $id ? "old-camera" : "svc-post"; }
			function get_page_uri( $id ) { return 20 === (int) $id ? "news" : get_post_field( "post_name", $id ); }
			function get_children( $args ) { return 20 === (int) $args["post_parent"] && in_array( "discontinued", (array) $args["post_status"], true ) ? [ 21 ] : []; }
			function get_page_by_path( $path ) { return null; }
			function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["post_shield_test_filters"][ $hook ][] = $callback; return true; }
			function remove_filter( $hook, $callback, $priority = 10 ) { foreach ( $GLOBALS["post_shield_test_filters"][ $hook ] ?? [] as $i => $filter ) { if ( $filter === $callback ) { unset( $GLOBALS["post_shield_test_filters"][ $hook ][ $i ] ); } } return true; }
			function get_permalink( $id ) {
				// Private post 6: its pretty link only for a reader (core asks
				// current_user_can( "read_post", 6 ), which runs user_has_cap).
				if ( 6 === (int) $id ) {
					$caps = [];
					foreach ( $GLOBALS["post_shield_test_filters"]["user_has_cap"] ?? [] as $filter ) {
						$caps = $filter( $caps, [ "read_private_posts" ], [ "read_post", 0, 6 ] );
					}
					return empty( $caps["read_private_posts"] ) ? "https://example.test/?p=6" : "https://example.test/svc-private/";
				}
				$map = [ 9 => "https://example.test/support/firmware/old-camera/", 5 => "https://example.test/svc-post/", 21 => "https://example.test/news/old-child/" ];
				return $map[ (int) $id ] ?? "https://example.test/?p=" . (int) $id;
			}
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
			 * Served posts by slug: firmware's old-camera, only discontinued,
			 * and a published reusable block (wp_block, not public) named
			 * newsletter. A query that lists types matches only those types.
			 *
			 * @param string $query SQL.
			 * @return string|null
			 */
			public function get_var( $query ) {
				foreach ( [ [ '9', 'old-camera', 'discontinued', 'firmware' ], [ '30', 'newsletter', 'publish', 'wp_block' ] ] as [ $id, $slug, $status, $type ] ) {
					if ( false !== strpos( $query, "'$slug'" ) && false !== strpos( $query, "'$status'" ) && ( false === strpos( $query, 'post_type' ) || false !== strpos( $query, "'$type'" ) ) ) {
						return $id;
					}
				}
				return null;
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
				if ( false !== strpos( $query, "post_type = 'page'" ) && false !== strpos( $query, "post_name = 'news'" ) ) {
					return [ 20 ];
				}
				if ( false !== strpos( $query, "'service'" ) ) {
					// The newest service rows are private (no pretty link in
					// cron); the published one is older.
					return false !== strpos( $query, "'publish'" ) && false === strpos( $query, "'private'" ) ? [ 5 ] : ( false !== strpos( $query, "'private'" ) ? [ 6, 7, 8 ] : [] );
				}
				return [];
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
	 * A page beneath a base is replayed in every public status WordPress
	 * serves (a discontinued child here), whatever the entry lists.
	 *
	 * @return void
	 */
	public function test_a_discontinued_page_beneath_a_base_is_replayed(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\BasedPreflight::class, 'real_paths' );
		$method->setAccessible( true );
		$paths = $method->invoke( new \Post404Shield\Library\BasedPreflight(), 'story', [ 'news' ], [ 'publish' ] );
		$this->assertContains( '/news/old-child/', $paths );
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
		$this->assertContains( '/svc-post/', $paths, 'The published post is sampled, although newer private rows exist.' );
	}

	/**
	 * A type shielded under a base is sampled in the statuses its entries
	 * list, not in one it leaves out by design (its posts 404 under the base,
	 * and a warning to reserve their slugs would confirm them); a listed
	 * private post is read as a reader, and a type with no entry in every
	 * served status.
	 *
	 * @return void
	 */
	public function test_the_root_sample_takes_a_based_types_listed_statuses(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\RootPreflight::class, 'other_public_sample' );
		$method->setAccessible( true );
		$sample = static fn( array $entries ): array => $method->invoke( new \Post404Shield\Library\RootPreflight(), [ 'page' ], $entries );
		$entry  = static fn( array $statuses ): array => [
			'mode'        => 'allowlist',
			'post_type'   => 'service',
			'url_base'    => [ 'services' ],
			'post_status' => $statuses,
		];
		$this->assertNotContains( '/svc-private/', $sample( [ 'service' => $entry( [ 'publish' ] ) ] ), 'Private is not listed.' );
		$this->assertContains( '/svc-private/', $sample( [ 'service' => $entry( [ 'publish', 'private' ] ) ] ), 'Listed, and read at its pretty address with no user.' );
		$this->assertContains( '/svc-private/', $sample( [] ), 'No entry: every served status.' );
		$this->assertSame( [], $GLOBALS['post_shield_test_filters']['user_has_cap'] ?? [], 'The reader filter is removed again.' );
	}

	/**
	 * The based gate reads a private post at its pretty address with no user
	 * (WP-CLI, cron), as a settings save does.
	 *
	 * @return void
	 */
	public function test_the_based_gate_reads_a_private_post_as_a_reader(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\BasedPreflight::class, 'real_paths' );
		$method->setAccessible( true );
		$this->assertContains( '/svc-private/', $method->invoke( new \Post404Shield\Library\BasedPreflight(), 'service', [ 'services' ], [ 'private' ] ) );
	}

	/**
	 * A reserved slug carried only by a discontinued post still has content:
	 * removing it is replayed.
	 *
	 * @return void
	 */
	public function test_a_slug_on_a_discontinued_post_has_content(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\BasedPreflight::class, 'slug_has_content' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( new \Post404Shield\Library\BasedPreflight(), 'old-camera' ) );
		$this->assertFalse( $method->invoke( new \Post404Shield\Library\BasedPreflight(), 'never-used' ) );
	}

	/**
	 * A slug carried only by a post of a non-public type (a reusable block,
	 * a template part, a form) has no content: WordPress never serves it at
	 * a URL, so removing it is not replayed.
	 *
	 * @return void
	 */
	public function test_a_slug_on_a_non_public_post_has_no_content(): void {
		$this->stub();
		$method = new \ReflectionMethod( \Post404Shield\Library\BasedPreflight::class, 'slug_has_content' );
		$method->setAccessible( true );
		$this->assertFalse( $method->invoke( new \Post404Shield\Library\BasedPreflight(), 'newsletter' ) );
	}
}
