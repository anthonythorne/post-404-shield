<?php
/**
 * Unit tests for two kinds of allowlist line the round-14 fixes added:
 *
 * - A full-path list of a hierarchical type carries the first segment of
 *   every live post, as a slug list does. A slug artifact reads a full-path
 *   list while an entry switches mode, around the swap, and after a failed
 *   rebuild; without those lines a published child under a draft parent
 *   gets a pre-boot 404 there.
 * - Once posts have moved from the site root to a base, root-extras lists
 *   every live post's slug and old slugs: old `/{slug}/` links must keep
 *   reaching WordPress, which 301s them to the new address.
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the builder's lines.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldBuilderLinesTest extends TestCase {

	/**
	 * Stub WordPress and a table: a published `model-2` camera under a draft
	 * top-level `draft-series`, and a published post `my-post` whose old slug
	 * is `old-root-post`.
	 *
	 * @return void
	 */
	private function stub(): void {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function is_post_type_hierarchical( $type ) { return "post" !== $type; }
			function get_post_type_object( $type ) { return (object) [ "hierarchical" => "post" !== $type ]; }
			function post_type_exists( $type ) { return true; }
			function get_post_status_object( $status ) { return (object) [ "private" => false, "public" => true ]; }
			function is_post_status_viewable( $status ) { return true; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish" ]; }'
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
			 * Rows by query.
			 *
			 * @param string $query SQL.
			 * @return array<object>
			 */
			public function get_results( $query ) {
				$row = static fn( int $id, string $name, int $parent ): object => (object) [
					'ID'          => $id,
					'post_name'   => $name,
					'post_parent' => $parent,
				];
				if ( false !== strpos( $query, "'_post_shield_old_uri'" ) ) {
					// A child's recorded old address, its parent since deleted.
					return [
						(object) [
							'uri'         => 'x-series/specs',
							'post_type'   => 'camera',
							'post_status' => 'publish',
						],
					];
				}
				if ( false !== strpos( $query, 'WHERE ID IN' ) ) {
					return [ $row( 1, 'draft-series', 0 ) ];
				}
				if ( 0 === strpos( trim( $query ), 'SELECT ID, post_name, post_parent FROM' ) ) {
					if ( false !== strpos( $query, "post_type = 'camera'" ) ) {
						return [ $row( 2, 'model-2', 1 ) ];
					}
					if ( false !== strpos( $query, "post_type = 'post'" ) ) {
						return [ $row( 3, 'my-post', 0 ) ];
					}
				}
				return [];
			}
			/**
			 * Columns by query.
			 *
			 * @param string $query SQL.
			 * @return string[]
			 */
			public function get_col( $query ) {
				if ( false !== strpos( $query, "post_type = 'post'" ) && false === strpos( $query, '_wp_old_slug' ) ) {
					// A flat post whose post_parent is set: WordPress serves it at
					// its flat address, so no top-level filter may drop it.
					return false !== strpos( $query, 'post_parent = 0' ) ? [] : [ 'spec-child' ];
				}
				return false !== strpos( $query, "p.post_type = 'post'" ) && false !== strpos( $query, '_wp_old_slug' ) ? [ 'old-root-post' ] : [];
			}
		};
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
	}

	/**
	 * A full-path list holds the live child's path and its top-level
	 * ancestor, so a slug artifact reading it still passes the child.
	 *
	 * @return void
	 */
	public function test_a_full_path_list_carries_every_live_posts_first_segment(): void {
		$this->stub();
		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'camera' => [
					'url_base'    => [ 'products/cameras' ],
					'post_status' => [ 'publish' ],
					'match'       => 'full-path',
				],
			]
		);
		$lines   = $builder->lines_for( 'camera' );
		$this->assertContains( 'draft-series/model-2', $lines );
		$this->assertContains( 'draft-series', $lines );
		// A former address: whole, and by its first segment for a slug config.
		$this->assertContains( 'x-series/specs', $lines );
		$this->assertContains( 'x-series', $lines );
	}

	/**
	 * After posts left the root, root-extras lists their slugs and old slugs;
	 * before, or while Posts is a root type, it does not.
	 *
	 * @return void
	 */
	public function test_root_extras_list_posts_that_left_the_root(): void {
		$this->stub();
		$config = [
			'page' => [
				'root'        => true,
				'post_type'   => 'page',
				'post_status' => [ 'publish' ],
			],
			'post' => [
				'url_base'    => [ 'blog' ],
				'post_status' => [ 'publish' ],
			],
		];
		$stream = static function ( \Post404Shield\Library\AllowlistBuilder $builder ): array {
			$method = new \ReflectionMethod( $builder, 'root_extras_stream' );
			$method->setAccessible( true );
			return iterator_to_array( $method->invoke( $builder ), false );
		};

		$left = $stream( ( new \Post404Shield\Library\AllowlistBuilder( $config ) )->set_posts_left_root( true ) );
		$this->assertContains( 'my-post', $left );
		$this->assertContains( 'old-root-post', $left );

		$this->assertNotContains( 'my-post', $stream( ( new \Post404Shield\Library\AllowlistBuilder( $config ) )->set_posts_left_root( false ) ) );

		$config['post'] = [
			'root'        => true,
			'post_type'   => 'post',
			'post_status' => [ 'publish' ],
		];
		$this->assertNotContains( 'old-root-post', $stream( ( new \Post404Shield\Library\AllowlistBuilder( $config ) )->set_posts_left_root( true ) ), 'At the root: the Posts list has them.' );
	}

	/**
	 * Slug mode: a child's old address (its parent deleted, the child moved
	 * up) is listed by its old top-level segment, the only part the loader
	 * matches, so the old URL still reaches WordPress's 301.
	 *
	 * @return void
	 */
	public function test_a_slug_list_keeps_an_old_nested_address_by_its_first_segment(): void {
		$this->stub();
		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'camera' => [
					'url_base'    => [ 'products/cameras' ],
					'post_status' => [ 'publish' ],
				],
			]
		);
		$this->assertContains( 'x-series', $builder->lines_for( 'camera' ) );
	}

	/**
	 * A flat type lists a post that has a post_parent (WordPress ignores the
	 * parent and serves it at its flat address).
	 *
	 * @return void
	 */
	public function test_a_flat_post_with_a_parent_is_listed(): void {
		$this->stub();
		$builder = new \Post404Shield\Library\AllowlistBuilder(
			[
				'post' => [
					'url_base'    => [ 'blog' ],
					'post_status' => [ 'publish' ],
				],
			]
		);
		$this->assertContains( 'spec-child', $builder->lines_for( 'post' ) );
	}

	/**
	 * Old addresses are judged once per (type, status), not per row: each
	 * verdict re-reads the live config, inside the list's lock.
	 *
	 * @return void
	 */
	public function test_old_addresses_judge_each_type_and_status_once(): void {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		$GLOBALS['wpdb'] = new class() {
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
			 * Three old addresses: two published, one draft.
			 *
			 * @return array<object>
			 */
			public function get_results() {
				return [
					(object) [ 'uri' => 'news/one', 'post_type' => 'page', 'post_status' => 'publish' ],
					(object) [ 'uri' => 'news/two', 'post_type' => 'page', 'post_status' => 'publish' ],
					(object) [ 'uri' => 'news/three', 'post_type' => 'page', 'post_status' => 'draft' ],
				];
			}
		};
		$builder = new class( [] ) extends \Post404Shield\Library\AllowlistBuilder {
			/** @var int */
			public $asked = 0;
			/**
			 * Count the verdicts; published shields.
			 *
			 * @param string $post_type Post type.
			 * @param string $status    Post status.
			 * @return bool
			 */
			public function is_shielding_status( string $post_type, string $status ): bool {
				++$this->asked;
				return 'publish' === $status;
			}
		};
		$method  = new \ReflectionMethod( \Post404Shield\Library\AllowlistBuilder::class, 'old_uri_lines' );
		$method->setAccessible( true );
		$this->assertSame( [ 'news/one', 'news/two' ], $method->invoke( $builder, [ 'page' ] ) );
		$this->assertSame( 2, $builder->asked );
	}
}
