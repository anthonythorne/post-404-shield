<?php
/**
 * Unit tests for the sync controller's instant appends when a URL moves with
 * no save of the post it belongs to: WPML's save-time parent sync fixes an
 * out-of-step translation while a DIFFERENT post of the type is saved, and a
 * parent that is not live itself (a draft, an embargoed parent) is renamed
 * over its published children. Either way the moved URLs must be listed in
 * the same request, not after the nightly rebuild.
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for moves no post hook of the moved post reports.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldSyncMovesTest extends TestCase {

	/**
	 * The controller over a hierarchical, slug-mode `photographer` type whose
	 * posts are $GLOBALS['post_shield_test_nodes'] (ID => [slug, parent,
	 * status]), with a builder that records what is appended.
	 *
	 * @return array{0: \Post404Shield\Controller\PostShieldSyncController, 1: object}
	 */
	private function controller(): array {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_post_type( $id ) { return "photographer"; }
			function is_post_type_hierarchical( $type ) { return true; }
			function post_type_exists( $type ) { return true; }
			function get_post_status_object( $status ) { return (object) [ "private" => false, "public" => true ]; }
			function is_post_status_viewable( $status ) { return true; }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish" ]; }
			function get_post_status( $id ) { return $GLOBALS["post_shield_test_nodes"][ $id ][2] ?? false; }
			function wp_is_post_revision( $id ) { return false; }
			function wp_is_post_autosave( $id ) { return false; }
			function get_permalink( $id ) { return ""; }
			function has_action( $hook, $callback = false ) { return isset( $GLOBALS["post_shield_test_hooks"][ $hook ] ); }
			function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["post_shield_test_hooks"][ $hook ][ $priority ][] = $callback; return true; }
			function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return add_action( $hook, $callback, $priority, $args ); }'
		);
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '4.7.0' );
		}
		// WPML with its "sync page parent" setting on (its default).
		$GLOBALS['sitepress'] = new class() {
			/**
			 * A WPML setting.
			 *
			 * @param string $key Setting.
			 * @return int
			 */
			public function get_setting( $key ) {
				return 'sync_page_parent' === $key ? 1 : 0;
			}
		};
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = '';
			/**
			 * Stub prepare: the query with its first integer argument kept.
			 *
			 * @param string $query SQL.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$ints = array_values( array_filter( $args, 'is_int' ) );
				return (string) $query . ( [] === $ints ? '' : ' #' . $ints[0] );
			}
			/**
			 * The type's rows as the table has them now.
			 *
			 * @param string $query SQL.
			 * @return array<object>
			 */
			public function get_results( $query ) {
				$rows = [];
				foreach ( $GLOBALS['post_shield_test_nodes'] as $id => $node ) {
					if ( false !== strpos( (string) $query, 'post_parent <> 0' ) && 0 === $node[1] ) {
						continue;
					}
					$rows[] = (object) [
						'ID'          => $id,
						'post_name'   => $node[0],
						'post_parent' => $node[1],
						'post_status' => $node[2],
					];
				}
				return $rows;
			}
			/**
			 * A child of the post in the query, or null.
			 *
			 * @param string $query SQL.
			 * @return int|null
			 */
			public function get_var( $query ) {
				$parent = preg_match( '/ #(\d+)$/', (string) $query, $m ) ? (int) $m[1] : -1;
				foreach ( $GLOBALS['post_shield_test_nodes'] as $id => $node ) {
					if ( $node[1] === $parent ) {
						return $id;
					}
				}
				return null;
			}
		};
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Controller/PostShieldSyncController.php';
		$builder = new class(
			[
				'photographer' => [
					'url_base'    => [ 'photographers' ],
					'post_status' => [ 'publish' ],
				],
			]
		) extends \Post404Shield\Library\AllowlistBuilder {
			/** @var array<string, string[]> What was appended, by type. */
			public array $appended = [];
			/**
			 * Addresses from the stub table.
			 *
			 * @param string $post_type Type.
			 * @param int[]  $ids       IDs.
			 * @return array<int, string>
			 */
			public function uris_for( string $post_type, array $ids ): array {
				$out = [];
				foreach ( $ids as $id ) {
					$parts = [];
					for ( $at = (int) $id; $at > 0; $at = $GLOBALS['post_shield_test_nodes'][ $at ][1] ) {
						array_unshift( $parts, $GLOBALS['post_shield_test_nodes'][ $at ][0] );
					}
					$out[ (int) $id ] = implode( '/', $parts );
				}
				return $out;
			}
			/**
			 * Record an append.
			 *
			 * @param string   $post_type Type.
			 * @param string[] $slugs     Lines.
			 * @return int
			 */
			public function append_slugs( string $post_type, array $slugs ): int {
				$this->appended[ $post_type ] = array_merge( $this->appended[ $post_type ] ?? [], $slugs );
				return count( $slugs );
			}
			/**
			 * Nothing kept on disk.
			 *
			 * @param array<int, string> $old Old URIs.
			 * @return void
			 */
			public function record_old_uris( array $old ): void {
			}
		};
		$sync = new \Post404Shield\Controller\PostShieldSyncController( $builder );
		$sync->set_up();
		return [ $sync, $builder ];
	}

	/**
	 * Run the callbacks the controller hooked on a hook at a priority.
	 *
	 * @param string $hook     Hook.
	 * @param int    $priority Priority.
	 * @param mixed  ...$args  Arguments.
	 *
	 * @return void
	 */
	private static function fire( string $hook, int $priority, ...$args ): void {
		foreach ( $GLOBALS['post_shield_test_hooks'][ $hook ][ $priority ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}

	/**
	 * Saving one post while WPML re-parents ANOTHER post's translation (its
	 * parent was out of step with its original's): the moved translation's
	 * new first segment is listed in that save.
	 *
	 * @return void
	 */
	public function test_wpml_moving_a_bystander_translation_on_save_lists_it(): void {
		$GLOBALS['post_shield_test_nodes'] = [
			1 => [ 'draft-series', 0, 'draft' ],
			2 => [ 'studio', 0, 'publish' ],
			3 => [ 'jane', 1, 'publish' ],
		];
		[ , $builder ] = $this->controller();

		self::fire( 'save_post', 1, 2 );
		$GLOBALS['post_shield_test_nodes'][3][1] = 2; // WPML's sync at save_post 100.
		self::fire( 'save_post', 200, 2 );

		$this->assertContains( 'studio', $builder->appended['photographer'] ?? [], 'The moved translation now lives under /studio/.' );
	}

	/**
	 * Slug mode: renaming a draft top-level parent moves its published
	 * child's URL, whose first segment is listed at once.
	 *
	 * @return void
	 */
	public function test_renaming_a_draft_parent_lists_its_live_childs_new_first_segment(): void {
		$GLOBALS['post_shield_test_nodes'] = [
			1 => [ 'd-new', 0, 'draft' ],
			3 => [ 'specifications', 1, 'publish' ],
		];
		[ $sync, $builder ] = $this->controller();
		$moved              = new \ReflectionProperty( $sync, 'moved' );
		$moved->setAccessible( true );
		$moved->setValue( $sync, [ 1 => true ] ); // As post_updated records a rename.

		self::fire( 'save_post', 10, 1 );

		$this->assertSame( [ 'd-new' ], $builder->appended['photographer'] ?? [] );
	}

	/**
	 * A long-running process (a CLI migration) saves many posts while other
	 * requests change the table: each save starts from the table as it is,
	 * not from the parent map an earlier save read, and a post moved in one
	 * save is not "moved" in the next.
	 *
	 * @return void
	 */
	public function test_each_save_reads_the_table_afresh(): void {
		$GLOBALS['post_shield_test_nodes'] = [
			1 => [ 'd-one', 0, 'draft' ],
			2 => [ 'd-two', 0, 'draft' ],
			3 => [ 'spec', 1, 'publish' ],
		];
		[ $sync, $builder ] = $this->controller();
		$moved              = new \ReflectionProperty( $sync, 'moved' );
		$moved->setAccessible( true );

		$moved->setValue( $sync, [ 1 => true ] );
		self::fire( 'save_post', 10, 1 );
		self::fire( 'wp_after_insert_post', PHP_INT_MAX, 1 );
		$this->assertSame( [], $moved->getValue( $sync ), 'The move is over with its save.' );

		// Another request publishes a child under the other draft parent.
		$GLOBALS['post_shield_test_nodes'][4] = [ 'spec-2', 2, 'publish' ];
		$moved->setValue( $sync, [ 2 => true ] );
		self::fire( 'save_post', 10, 2 );

		$this->assertSame( [ 'd-one', 'd-two' ], $builder->appended['photographer'] ?? [] );
	}

	/**
	 * Slug mode: deleting a parent for good moves its child up a level; the
	 * child's old address is listed at once by its old first segment, so the
	 * old URL still reaches WordPress's 301.
	 *
	 * @return void
	 */
	public function test_a_deleted_parents_old_segment_is_listed_at_once(): void {
		$GLOBALS['post_shield_test_nodes'] = [
			1 => [ 'x-series', 0, 'publish' ],
			3 => [ 'specs', 1, 'publish' ],
		];
		[ $sync, $builder ] = $this->controller();

		$sync->handle_before_delete( 1 );
		unset( $GLOBALS['post_shield_test_nodes'][1] );
		$GLOBALS['post_shield_test_nodes'][3][1] = 0; // Core moves the child up.
		$sync->handle_after_delete( 1 );

		$this->assertContains( 'specs', $builder->appended['photographer'] ?? [], 'Its new address.' );
		$this->assertContains( 'x-series', $builder->appended['photographer'] ?? [], 'Its old address, by the old first segment.' );
	}

	/**
	 * A draft parent with no live child lists nothing.
	 *
	 * @return void
	 */
	public function test_renaming_a_draft_parent_with_no_live_child_lists_nothing(): void {
		$GLOBALS['post_shield_test_nodes'] = [
			1 => [ 'd-new', 0, 'draft' ],
			3 => [ 'specifications', 1, 'draft' ],
		];
		[ $sync, $builder ] = $this->controller();
		$moved              = new \ReflectionProperty( $sync, 'moved' );
		$moved->setAccessible( true );
		$moved->setValue( $sync, [ 1 => true ] );

		self::fire( 'save_post', 10, 1 );

		$this->assertSame( [], $builder->appended );
	}
}
