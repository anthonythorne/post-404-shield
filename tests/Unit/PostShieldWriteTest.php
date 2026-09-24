<?php
/**
 * Unit tests that drive ConfigStore::write() end to end — options, the save
 * lock, the artifact files and the rebuild handler stubbed — and check what
 * is rebuilt around the swap, and what an automatic write archives:
 *
 * - a status the save drops is rebuilt out of its list after the swap, on
 *   every save path (a hidden status must not stay confirmable for a day);
 * - posts first leaving the site root rebuild root-extras before the swap,
 *   with the candidate's own record, and the live artifact's statuses kept;
 * - an automatic write that changes only what root matching reads, while
 *   root settings are kept switched off, archives no revision.
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for write().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldWriteTest extends TestCase {

	/**
	 * The temp artifact directory.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Remove the temp directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->dir ) {
			foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}
			rmdir( $this->dir );
		}
	}

	/**
	 * A store over a temp directory holding $live as its artifact, with the
	 * WordPress calls write() makes stubbed, and a handler that records calls.
	 *
	 * @param array<string, mixed> $live      Live artifact (and stored option).
	 * @param string               $structure `permalink_structure`.
	 *
	 * @return array{0: \Post404Shield\Library\ConfigStore, 1: \ArrayObject<int, array<int, mixed>>}
	 */
	private function store( array $live, string $structure = '/%postname%/' ): array {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function update_option( $name, $value, $autoload = null ) { $GLOBALS["post_shield_test_options"][ $name ] = $value; return true; }
			function delete_option( $name ) { unset( $GLOBALS["post_shield_test_options"][ $name ] ); return true; }
			function wp_cache_delete( $key, $group = "" ) { return true; }
			function wp_cache_get( $key, $group = "", $force = false, &$found = null ) { $found = false; return false; }
			function wp_cache_set( $key, $data, $group = "", $expire = 0 ) { return true; }
			function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
			function wp_generate_password( $length = 12, $special = true ) { return substr( md5( (string) mt_rand() ), 0, $length ); }
			function get_post_stati( $args = [] ) { return [ "publish" => "publish", "private" => "private", "zz-arch" => "zz-arch" ]; }
			function get_post_status_object( $status ) { return (object) [ "private" => "private" === $status, "public" => "private" !== $status ]; }
			function is_post_status_viewable( $status ) { return true; }
			function post_type_exists( $type ) { return in_array( $type, [ "page", "post", "story" ], true ); }'
		);
		$GLOBALS['wpdb'] = new class() {
			/** @var string */
			public $options = 'wp_options';
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
			 * Every lock query succeeds.
			 *
			 * @return int
			 */
			public function query() {
				return 1;
			}
			/**
			 * No lock row, no rows.
			 *
			 * @return null
			 */
			public function get_var() {
				return null;
			}
			/**
			 * No rows.
			 *
			 * @return array
			 */
			public function get_results() {
				return [];
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
			 * Stub suppress_errors.
			 *
			 * @return bool
			 */
			public function suppress_errors() {
				return false;
			}
		};
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';

		$this->dir = sys_get_temp_dir() . '/postshield-write-' . getmypid() . '-' . uniqid();
		mkdir( $this->dir );
		// What the store logs (a failed pass, a switch-off) is expected here.
		ini_set( 'error_log', $this->dir . '/php-error.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test process only.
		$GLOBALS['post_shield_test_log'] = $this->dir . '/php-error.log';
		$GLOBALS['post_shield_test_dir']     = $this->dir;
		$GLOBALS['post_shield_test_options'] = [
			'permalink_structure'                           => $structure,
			\Post404Shield\Library\ConfigStore::OPTION => $live,
		];
		$store = new class() extends \Post404Shield\Library\ConfigStore {
			/**
			 * The temp directory.
			 *
			 * @return string
			 */
			public function artifact_dir(): string {
				return (string) $GLOBALS['post_shield_test_dir'];
			}
		};
		$this->assertTrue( $store->write_artifact( $live ), 'The live artifact is in place.' );
		$calls = new \ArrayObject();
		$store->set_rebuild_handler(
			static function ( ...$args ) use ( $calls ) {
				$calls[] = $args;
				return true;
			}
		);
		return [ $store, $calls ];
	}

	/**
	 * A valid document.
	 *
	 * @param array<string, mixed> $entries Entries.
	 *
	 * @return array<string, mixed>
	 */
	private static function doc( array $entries ): array {
		return [
			'version' => 1,
			'locale'  => [ 'mode' => 'none' ],
			'entries' => $entries,
		];
	}

	/**
	 * A story entry listing these statuses.
	 *
	 * @param string[] $statuses Statuses.
	 *
	 * @return array<string, mixed>
	 */
	private static function story( array $statuses ): array {
		return [
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => 'story',
			'url_base'    => [ 'stories' ],
			'post_status' => $statuses,
		];
	}

	/**
	 * A save that drops a status rebuilds that list after the swap, whoever
	 * saves (here, as the CLI and a restore do: no follow-up job is queued).
	 *
	 * @return void
	 */
	public function test_a_dropped_status_is_rebuilt_out_after_the_swap(): void {
		[ $store, $calls ] = $this->store( self::doc( [ 'story' => self::story( [ 'publish', 'private' ] ) ] ) );

		$result = $store->write( self::doc( [ 'story' => self::story( [ 'publish' ] ) ] ), 'test', [ 'allow_status_drop' => true ] );

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertSame( [ 'publish' ], $store->artifact()['entries']['story']['post_status'] );
		$this->assertSame( [ 'publish' ], self::last_statuses_rebuilt( $calls, 'story' ), 'The narrowed list is rebuilt with the narrowed statuses.' );
	}

	/**
	 * A save that drops one status and adds another: the list ends up with
	 * exactly the candidate's statuses (the union was only for before the
	 * swap).
	 *
	 * @return void
	 */
	public function test_a_drop_and_an_add_end_with_the_candidates_statuses(): void {
		[ $store, $calls ] = $this->store( self::doc( [ 'story' => self::story( [ 'publish', 'private' ] ) ] ) );

		$result = $store->write( self::doc( [ 'story' => self::story( [ 'publish', 'zz-arch' ] ) ] ), 'test', [ 'allow_status_drop' => true ] );

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertSame( [ 'publish', 'zz-arch' ], self::last_statuses_rebuilt( $calls, 'story' ) );
	}

	/**
	 * A post-swap pass that fails does not skip the other one.
	 *
	 * @return void
	 */
	public function test_a_failed_post_swap_pass_does_not_skip_the_other(): void {
		$live              = self::doc(
			[
				'story'  => self::story( [ 'publish', 'private' ] ),
				'camera' => [
					'enabled'     => true,
					'mode'        => 'allowlist',
					'post_type'   => 'page',
					'url_base'    => [ 'cameras' ],
					'post_status' => [ 'publish' ],
				],
			]
		);
		[ $store ]         = $this->store( $live );
		$calls             = new \ArrayObject();
		$store->set_rebuild_handler(
			static function ( ...$args ) use ( $calls ) {
				$calls[] = $args;
				// The first post-swap pass (the second call) fails.
				if ( 2 === count( $calls ) ) {
					throw new \RuntimeException( 'a list could not be written' );
				}
				return true;
			}
		);
		$candidate                              = $live;
		$candidate['entries']['camera']['match'] = 'full-path';
		$candidate['entries']['story']          = self::story( [ 'publish' ] );

		$result = $store->write( $candidate, 'test', [ 'allow_status_drop' => true ] );

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertCount( 3, $calls, 'Before the swap, then both post-swap passes, although the first failed.' );
		$this->assertSame( [ 'publish' ], self::last_statuses_rebuilt( $calls, 'story' ), 'The narrowing pass ran.' );
	}

	/**
	 * Posts off the site root: dropping a status from the Posts entry also
	 * rebuilds root-extras, which lists those posts' old root slugs.
	 *
	 * @return void
	 */
	public function test_a_posts_status_drop_rebuilds_root_extras_once_posts_left_the_root(): void {
		$live                   = self::doc(
			[
				'page' => [
					'enabled'     => true,
					'mode'        => 'allowlist',
					'post_type'   => 'page',
					'root'        => true,
					'url_base'    => [],
					'post_status' => [ 'publish' ],
				],
				'post' => [
					'enabled'     => true,
					'mode'        => 'allowlist',
					'post_type'   => 'post',
					'url_base'    => [ 'blog' ],
					'post_status' => [ 'publish', 'private' ],
				],
			]
		);
		$live['root_acknowledged'] = true;
		$live['excluded_bases']    = [
			'floor'           => [],
			'derived'         => [],
			'operator'        => [],
			'endpoints'       => [],
			'post_base'       => 'blog',
			'posts_left_root' => true,
		];
		[ $store, $calls ]         = $this->store( $live, '/blog/%postname%/' );
		$candidate                 = $live;
		$candidate['entries']['post']['post_status'] = [ 'publish' ];

		$result = $store->write(
			$candidate,
			'test',
			[
				'skip_root_preflight' => true,
				'allow_status_drop'   => true,
			]
		);

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$after_swap = array_filter( $calls->getArrayCopy(), static fn( array $call ): bool => in_array( 'post', (array) $call[0], true ) && true === ( $call[2] ?? false ) );
		$this->assertNotEmpty( $after_swap, 'Root-extras is rebuilt with the Posts list.' );
	}

	/**
	 * Root mode still fits and its snapshot is current, but a real URL is
	 * now blocked (a type came to live at the root): the daily check
	 * switches root matching off.
	 *
	 * @return void
	 */
	public function test_the_daily_check_switches_root_off_over_a_newly_blocked_url(): void {
		$root = static fn( string $type ): array => [
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => $type,
			'root'        => true,
			'url_base'    => [],
			'post_status' => [ 'publish' ],
		];
		$doc  = self::doc(
			[
				'page' => $root( 'page' ),
				'post' => $root( 'post' ),
			]
		);
		$doc['root_acknowledged'] = true;
		[ $store ]                = $this->store( $doc );
		// Save once, so the stored snapshot is current.
		$this->assertTrue( $store->write( $doc, 'test' )['ok'] );
		$GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::OPTION ] = $store->artifact();

		$store->set_preflight_handler( static fn(): array => [ 'would_block' => [] ] );
		$this->assertFalse( $store->revalidate_root( 'test', true ), 'Nothing blocked: root stays on.' );

		// Blocked in one pass only (a page published while the walk ran):
		// the fresh second pass passes it, and root stays on.
		$passes = 0;
		$store->set_preflight_handler(
			static function () use ( &$passes ): array {
				return [ 'would_block' => 1 === ++$passes ? [ '/spring-sale/' ] : [] ];
			}
		);
		$this->assertFalse( $store->revalidate_root( 'the daily check', true ), 'One pass is not enough.' );
		$this->assertSame( 2, $passes, 'A second, fresh pass ran.' );

		$store->set_preflight_handler( static fn(): array => [ 'would_block' => [ '/clothing/t-shirt/' ] ] );
		$this->assertFalse( $store->revalidate_root( 'a follow-up' ), 'A route follow-up does not replay the walk.' );
		$GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::PREFLIGHT_ACCEPTED_OPTION ] = [ '/clothing/t-shirt/' ];
		$this->assertFalse( $store->revalidate_root( 'the daily check', true ), 'Accepted on an earlier forced save: root stays on.' );
		unset( $GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::PREFLIGHT_ACCEPTED_OPTION ] );
		$this->assertTrue( $store->revalidate_root( 'the daily check', true ), 'The daily check does: blocked twice, root goes off.' );
		$this->assertFalse( $store->artifact()['entries']['page']['enabled'] );
		$said = implode( ' | ', (array) ( $GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::ROOT_OFF_OPTION ]['errors'] ?? [] ) );
		$this->assertStringContainsString( '1 real URL now gets a pre-boot 404', $said );
		$this->assertStringContainsString( 'Blocked: /clothing/t-shirt/', $said );
		$this->assertStringNotContainsString( 'nothing was saved', $said, 'The switch-off was saved.' );
	}

	/**
	 * A stale snapshot (a new route, a redirect) is refreshed by a save, whose
	 * root preflight must block a URL in two passes before root matching goes
	 * off; the notice names the URLs, and says nothing about "nothing saved".
	 *
	 * @return void
	 */
	public function test_a_stale_snapshot_refresh_needs_two_blocking_passes(): void {
		$root = static fn( string $type ): array => [
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => $type,
			'root'        => true,
			'url_base'    => [],
			'post_status' => [ 'publish' ],
		];
		$doc  = self::doc(
			[
				'page' => $root( 'page' ),
				'post' => $root( 'post' ),
			]
		);
		$doc['root_acknowledged'] = true;
		[ $store ]                = $this->store( $doc );
		$store->set_preflight_handler( static fn(): array => [ 'would_block' => [] ] );
		$this->assertTrue( $store->write( $doc, 'test' )['ok'] );
		$stale = static function () use ( $store ): void {
			$option = $store->artifact();
			$option['excluded_bases']['floor'][] = 'a-route-that-is-gone';
			$GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::OPTION ] = $option;
			$store->write_artifact( $option ); // Live with the old snapshot too.
		};

		// Blocked in the refresh's first pass only (a page published while
		// the walk ran): the refresh lands, and root stays on.
		$stale();
		$GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::PREFLIGHT_OPTION ] = [
			'would_block' => 1,
			'warn_sample' => [],
		];
		$passes = 0;
		$store->set_preflight_handler(
			static function () use ( &$passes ): array {
				return [ 'would_block' => 1 === ++$passes ? [ '/spring-sale/' ] : [] ];
			}
		);
		$this->assertFalse( $store->revalidate_root( 'a follow-up check' ), 'One pass is not enough.' );
		$this->assertSame( 2, $passes, 'A second pass ran.' );
		$this->assertTrue( $store->artifact()['entries']['page']['enabled'] );
		$this->assertNotContains( 'a-route-that-is-gone', (array) $store->artifact()['excluded_bases']['floor'], 'The refresh landed.' );
		$tile = $GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::PREFLIGHT_OPTION ];
		$this->assertSame( 0, $tile['would_block'], 'The tile says what both passes blocked.' );

		// Blocked in both: root goes off, and the notice says what is blocked.
		$stale();
		$store->set_preflight_handler( static fn(): array => [ 'would_block' => [ '/spring-sale/' ] ] );
		$this->assertTrue( $store->revalidate_root( 'a follow-up check' ) );
		$this->assertFalse( $store->artifact()['entries']['page']['enabled'] );
		$said = implode( ' | ', (array) ( $GLOBALS['post_shield_test_options'][ \Post404Shield\Library\ConfigStore::ROOT_OFF_OPTION ]['errors'] ?? [] ) );
		$this->assertStringContainsString( '1 real URL now gets a pre-boot 404', $said );
		$this->assertStringContainsString( 'Blocked: /spring-sale/', $said );
		$this->assertStringContainsString( 'The snapshot refresh root matching needs was refused', $said );
		$this->assertStringNotContainsString( 'nothing was saved', $said, 'The switch-off was saved.' );
	}

	/**
	 * A save that switches root mode on and changes the Posts entry's
	 * statuses (posts off the root) streams root-extras once before the swap
	 * and once after, not a third time.
	 *
	 * @return void
	 */
	public function test_root_extras_is_not_streamed_a_third_time(): void {
		$live                      = self::doc(
			[
				'page' => [
					'enabled'     => false,
					'mode'        => 'allowlist',
					'post_type'   => 'page',
					'root'        => true,
					'url_base'    => [],
					'post_status' => [ 'publish' ],
				],
				'post' => [
					'enabled'     => true,
					'mode'        => 'allowlist',
					'post_type'   => 'post',
					'url_base'    => [ 'blog' ],
					'post_status' => [ 'publish', 'private' ],
				],
			]
		);
		$live['root_acknowledged'] = true;
		$live['excluded_bases']    = [
			'floor'           => [],
			'derived'         => [],
			'operator'        => [],
			'endpoints'       => [],
			'post_base'       => 'blog',
			'posts_left_root' => true,
		];
		[ $store, $calls ]         = $this->store( $live, '/blog/%postname%/' );
		$candidate                 = $live;
		$candidate['entries']['page']['enabled']     = true;
		$candidate['entries']['post']['post_status'] = [ 'publish' ];

		$result = $store->write(
			$candidate,
			'test',
			[
				'skip_root_preflight' => true,
				'allow_status_drop'   => true,
			]
		);

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$streams = array_filter( $calls->getArrayCopy(), static fn( array $call ): bool => true === ( $call[2] ?? false ) );
		$this->assertCount( 2, $streams, 'Before the swap, and once after.' );
	}

	/**
	 * One save that widens one root list and narrows another streams
	 * root-extras before the swap and once after, not a third time for the
	 * narrowed list: the first post-swap pass built it from this document.
	 *
	 * @return void
	 */
	public function test_a_widen_and_a_narrow_stream_root_extras_twice(): void {
		$root                      = static fn( string $type, array $statuses ): array => [
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => $type,
			'root'        => true,
			'url_base'    => [],
			'post_status' => $statuses,
		];
		$live                      = self::doc(
			[
				'page' => $root( 'page', [ 'publish' ] ),
				'post' => $root( 'post', [ 'publish', 'private' ] ),
			]
		);
		$live['root_acknowledged'] = true;
		[ $store, $calls ]         = $this->store( $live );
		$candidate                 = $live;
		$candidate['entries']['page']['post_status'] = [ 'publish', 'private' ];
		$candidate['entries']['post']['post_status'] = [ 'publish' ];

		$result = $store->write(
			$candidate,
			'test',
			[
				'skip_root_preflight' => true,
				'allow_status_drop'   => true,
			]
		);

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertSame( [ [ 'page' ], [ 'page' ], [ 'post' ] ], array_map( static fn( array $call ): array => (array) $call[0], $calls->getArrayCopy() ), 'Page before and after the swap, then post.' );
		$streams = array_filter( $calls->getArrayCopy(), static fn( array $call ): bool => true === ( $call[2] ?? false ) );
		$this->assertCount( 2, $streams, 'Root-extras before the swap, and once after.' );
	}

	/**
	 * The statuses the last rebuild of a type received.
	 *
	 * @param \ArrayObject<int, array<int, mixed>> $calls Handler calls.
	 * @param string                               $type  Type.
	 *
	 * @return string[]|null
	 */
	private static function last_statuses_rebuilt( \ArrayObject $calls, string $type ): ?array {
		$last = null;
		foreach ( $calls as $call ) {
			if ( in_array( $type, (array) $call[0], true ) ) {
				$last = $call[1][ $type ]['post_status'] ?? null;
			}
		}
		return $last;
	}

	/**
	 * Posts moved off the site root while root matching stays on: the save
	 * rebuilds root-extras before the swap with the candidate's record that
	 * they left, and with the live artifact's statuses kept.
	 *
	 * @return void
	 */
	public function test_posts_leaving_the_root_rebuild_root_extras_before_the_swap(): void {
		$root = static fn( string $type, array $statuses ): array => [
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => $type,
			'root'        => true,
			'url_base'    => [],
			'post_status' => $statuses,
		];
		$live = self::doc(
			[
				'page' => $root( 'page', [ 'publish', 'zz-arch' ] ),
				'post' => $root( 'post', [ 'publish' ] ),
			]
		);
		// Posts now live under /blog/; the page row stays at the root and the
		// Posts row moves to the base.
		[ $store, $calls ] = $this->store( $live, '/blog/%postname%/' );
		// The save also drops a Pages status: root-extras, rebuilt before the
		// swap, must still hold what the live artifact lists.
		$candidate         = self::doc(
			[
				'page' => $root( 'page', [ 'publish' ] ),
				'post' => [
					'enabled'     => true,
					'mode'        => 'allowlist',
					'post_type'   => 'post',
					'url_base'    => [ 'blog' ],
					'post_status' => [ 'publish' ],
				],
			]
		);
		$candidate['root_acknowledged'] = true;

		$result = $store->write(
			$candidate,
			'test',
			[
				'skip_root_preflight' => true,
				'allow_status_drop'   => true,
			]
		);

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertTrue( $store->artifact()['excluded_bases']['posts_left_root'] ?? false, 'Recorded.' );
		$first = $calls[0] ?? [];
		$this->assertTrue( $first[2] ?? false, 'Root-extras is rebuilt before the swap.' );
		$this->assertTrue( $first[3] ?? false, 'With the candidate\'s record that posts left the root.' );
		$this->assertContains( 'zz-arch', (array) ( $first[1]['page']['post_status'] ?? [] ), 'With the live statuses kept.' );
	}

	/**
	 * Root settings kept switched off (Disable shield): an automatic
	 * re-snapshot that changes only what root matching reads archives no
	 * revision, so the pre-Disable one stays in retention.
	 *
	 * @return void
	 */
	public function test_kept_off_root_rows_archive_nothing_for_an_unread_change(): void {
		$live                              = self::doc(
			[
				'page'  => [
					'enabled'     => false,
					'mode'        => 'allowlist',
					'post_type'   => 'page',
					'root'        => true,
					'url_base'    => [],
					'post_status' => [ 'publish' ],
				],
				'story' => array_merge( self::story( [ 'publish' ] ), [ 'enabled' => false ] ),
			]
		);
		$live['excluded_bases']            = [
			'floor'     => [ 'wp-admin' ],
			'derived'   => [ 'a-redirect-that-went' ],
			'operator'  => [],
			'endpoints' => [],
			'post_base' => '',
		];
		[ $store ]                         = $this->store( $live );
		$before                            = glob( $this->dir . '/config-*.php' );

		$result = $store->write( $live, 'auto: snapshot refreshed — test', [ 'fail_open' => true ] );

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertTrue( $result['unchanged'] ?? false, 'Nothing the loader reads changed.' );
		$this->assertSame( $before, glob( $this->dir . '/config-*.php' ), 'No revision archived.' );
	}

	/**
	 * A stored config whose operator base looks like it starts with a
	 * language folder still saves: Disable shield, the self-heal and a
	 * restore must land whatever the operator typed (it is a warning).
	 *
	 * @return void
	 */
	public function test_a_language_looking_operator_base_never_blocks_a_save(): void {
		$live                   = self::doc( [ 'story' => self::story( [ 'publish' ] ) ] );
		$live['locale']         = [
			'mode'    => 'custom',
			'pattern' => '[a-z]{2}',
		];
		$live['excluded_bases'] = [
			'floor'    => [],
			'derived'  => [],
			'operator' => [ 'ir/reports' ],
		];
		[ $store ]              = $this->store( $live );
		$disabled               = $live;
		$disabled['entries']['story']['enabled'] = false;

		$result = $store->write( $disabled, 'test' );

		$this->assertTrue( $result['ok'], implode( ' | ', $result['errors'] ) );
		$this->assertStringContainsString( 'looks like a language folder', implode( ' | ', $result['warnings'] ) );
	}

	/**
	 * A self-heal leaves a held lock alone (a save or heal of a large site
	 * outlives the lock's TTL), and heals once the lock is old enough to be
	 * a crashed save's.
	 *
	 * @return void
	 */
	public function test_a_heal_waits_for_a_held_lock(): void {
		[ $store ] = $this->store( self::doc( [ 'story' => self::story( [ 'publish' ] ) ] ) );
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_transient( $name ) { return false; }
			function set_transient( $name, $value, $ttl = 0 ) { return true; }
			function delete_transient( $name ) { return true; }'
		);
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		unlink( $store->artifact_path() );
		$GLOBALS['wpdb'] = new class( $GLOBALS['wpdb'] ) {
			/** @var object */
			private $inner;
			/** @var string */
			public $options = 'wp_options';
			/** @var string */
			public $posts = 'wp_posts';
			/** @var string */
			public $postmeta = 'wp_postmeta';
			/** @var string */
			public $last_error = '';
			/**
			 * Wrap the harness's $wpdb.
			 *
			 * @param object $inner The harness's $wpdb.
			 */
			public function __construct( $inner ) {
				$this->inner = $inner;
			}
			/**
			 * The lock row: stamped at post_shield_test_lock_at.
			 *
			 * @return string
			 */
			public function get_var() {
				return $GLOBALS['post_shield_test_lock_at'] . ':another-save';
			}
			/**
			 * Everything else as the harness does it.
			 *
			 * @param string $name Method.
			 * @param array  $args Arguments.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				return $this->inner->$name( ...$args );
			}
		};
		$GLOBALS['post_shield_test_lock_at'] = time() - 120;
		$store->self_heal();
		$this->assertNull( $store->artifact(), 'A two-minute-old lock is a live save: the heal waits.' );

		$GLOBALS['post_shield_test_lock_at'] = time() - 700;
		$store->self_heal();
		$this->assertNotNull( $store->artifact(), 'An old lock is a crashed save\'s: the heal runs.' );
	}
}
