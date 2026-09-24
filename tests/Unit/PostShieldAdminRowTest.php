<?php
/**
 * Unit tests for a settings row's state (PostShieldAdminController::type_state()):
 * the Posts row coming back from root mode (the permalinks gained a base
 * while a root Posts entry was stored) offers what a new flat row offers —
 * no matching selector — in a refused save's draft as on a normal render.
 * The selector posted a `match` the row never offers outside a draft.
 *
 * Each test runs in its own process: the WordPress stubs are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the row state.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldAdminRowTest extends TestCase {

	/**
	 * The state of the Posts row for a stored entry and a draft entry.
	 *
	 * @param array<string, mixed>|null $entry  What the row shows (the draft, or the stored entry).
	 * @param array<string, mixed>      $stored What is stored.
	 *
	 * @return array<string, mixed>
	 */
	private function posts_row( ?array $entry, array $stored ): array {
		$method = new \ReflectionMethod( \Post404Shield\Controller\PostShieldAdminController::class, 'type_state' );
		$method->setAccessible( true );
		$store = new \Post404Shield\Library\ConfigStore();
		$admin = new \Post404Shield\Controller\PostShieldAdminController( new \Post404Shield\Library\AllowlistBuilder( [] ), $store, [] );
		return $method->invoke( $admin, 'post', new \WP_Post_Type(), $entry, [], $stored );
	}

	/**
	 * Stub WordPress: posts under /blog/, a flat Posts type.
	 *
	 * @return void
	 */
	private function stub(): void {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'final class WP_Post_Type { public $hierarchical = false; public $rewrite = false; public $labels; public function __construct() { $this->labels = (object) [ "name" => "Posts" ]; } }
			function get_post_status_object( $status ) { return null; }'
		);
		$GLOBALS['post_shield_test_options'] = [ 'permalink_structure' => '/blog/%postname%/' ];
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Controller/PostShieldAdminController.php';
	}

	/**
	 * Normal render and a refused save's draft offer the same: no selector.
	 *
	 * @return void
	 */
	public function test_a_row_leaving_root_mode_offers_no_matching_in_the_draft_either(): void {
		$this->stub();
		$stored = [
			'enabled'   => false,
			'mode'      => 'allowlist',
			'post_type' => 'post',
			'root'      => true,
			'url_base'  => [],
			'match'     => 'full-path',
		];
		$draft  = [
			'enabled'   => true,
			'mode'      => 'allowlist',
			'post_type' => 'post',
			'url_base'  => [ 'blog' ],
			'match'     => 'slug',
		];
		$this->assertFalse( $this->posts_row( $stored, $stored )['offerMatch'], 'Normal render.' );
		$this->assertFalse( $this->posts_row( $draft, $stored )['offerMatch'], 'The draft of a refused save.' );
		$this->assertSame( 'slug', $this->posts_row( $stored, $stored )['match'] );
	}

	/**
	 * The rebuild jobs a save queues (one per changed type, run together in
	 * one cron request) stream root-extras once between them, not once per
	 * root type.
	 *
	 * @return void
	 */
	public function test_queued_rebuilds_stream_root_extras_once(): void {
		$this->stub();
		$builder = new class( [] ) extends \Post404Shield\Library\AllowlistBuilder {
			/** @var string[] */
			public $done = [];
			/**
			 * Record a type's rebuild.
			 *
			 * @param string $post_type Post type.
			 * @return int
			 */
			public function rebuild_type( string $post_type ): int {
				$this->done[] = $post_type;
				return 0;
			}
			/**
			 * Pages and posts live at the root.
			 *
			 * @param string $post_type Post type.
			 * @return bool
			 */
			public function is_root_type( string $post_type ): bool {
				return in_array( $post_type, [ 'page', 'post' ], true );
			}
			/**
			 * Record the root-extras stream.
			 *
			 * @return int
			 */
			public function rebuild_root_extras(): int {
				$this->done[] = 'root-extras';
				return 0;
			}
		};
		$admin   = new \Post404Shield\Controller\PostShieldAdminController( $builder, new \Post404Shield\Library\ConfigStore(), [ 'page', 'post', 'story' ] );
		foreach ( [ 'page', 'story', 'post' ] as $type ) {
			$admin->run_type_rebuild( $type );
		}
		$this->assertSame( [ 'page', 'root-extras', 'story', 'post' ], $builder->done );
	}
}
