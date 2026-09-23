<?php
/**
 * Unit tests for a redirect read that fails during a save's snapshot: the
 * reserved slugs and excluded bases the stored config derived must stay (the
 * union, fail-open), no fingerprint is recorded, and the staleness check
 * judges by the same rule. A save built from "no redirects" would drop every
 * derived slug, and each redirect under a shielded base would get a pre-boot
 * 404 until a later derivation succeeded.
 *
 * Each test runs in its own process: the Rank Math constant and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

/**
 * Test class for a failed redirect read.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldFailedRedirectReadTest extends TestCase {

	/**
	 * A store whose Rank Math read fails (or reads, correctly, as empty), with
	 * this config stored.
	 *
	 * @param bool $fails Whether the redirect query errors.
	 *
	 * @return ConfigStore
	 */
	private function store( bool $fails ): ConfigStore {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
		define( 'RANK_MATH_VERSION', '1.0' );
		ini_set( 'error_log', sys_get_temp_dir() . '/postshield-redirect-read-test.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the failed read is logged; keep it off the test's output.
		$GLOBALS['post_shield_test_options'] = [ 'rank_math_modules' => [ 'redirections' ] ];
		$GLOBALS['wpdb']                     = new class( $fails ) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var string */
			public $last_error = '';
			/** @var bool */
			private $fails;
			/**
			 * Build the stub.
			 *
			 * @param bool $fails Whether the redirect query errors.
			 */
			public function __construct( bool $fails ) {
				$this->fails = $fails;
			}
			/**
			 * Stub prepare: the query with its arguments in.
			 *
			 * @param string $query SQL.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( '%s', "'%s'", (string) $query ), $args );
			}
			/**
			 * The redirect table exists.
			 *
			 * @param string $query SQL.
			 * @return string|null
			 */
			public function get_var( $query ) {
				return false !== strpos( (string) $query, 'rank_math_redirections' ) ? 'wp_rank_math_redirections' : null;
			}
			/**
			 * No rows — and, when failing, the error the store checks for.
			 *
			 * @return array
			 */
			public function get_col() {
				$this->last_error = $this->fails ? 'Deadlock found when trying to get lock' : '';
				return [];
			}
		};
		return new ConfigStore();
	}

	/**
	 * The config as stored: a derived reserved slug and a derived excluded base.
	 *
	 * @param ConfigStore $store Store (for the live snapshot to build on).
	 *
	 * @return array<string, mixed>
	 */
	private function stored( ConfigStore $store ): array {
		$snapshot              = $store->excluded_bases_snapshot( [] );
		$snapshot['derived'][] = 'legacy-route';
		return [
			'version'        => 1,
			'locale'         => [ 'mode' => 'none' ],
			'excluded_bases' => $snapshot,
			'entries'        => [
				'story' => [
					'enabled'          => true,
					'mode'             => 'allowlist',
					'post_type'        => 'story',
					'url_base'         => [ 'stories' ],
					'reserved_derived' => [ 'old-story' ],
				],
			],
		];
	}

	/**
	 * Run take_snapshots() on a candidate.
	 *
	 * @param ConfigStore          $store  Store.
	 * @param array<string, mixed> $config Candidate, updated.
	 *
	 * @return string|null The fingerprint to record.
	 */
	private function snapshot( ConfigStore $store, array &$config ): ?string {
		$method = new \ReflectionMethod( ConfigStore::class, 'take_snapshots' );
		$method->setAccessible( true );
		return $method->invokeArgs( $store, [ &$config ] );
	}

	/**
	 * A failed read keeps the stored derivations and records no fingerprint;
	 * the staleness check does not flag the slugs it kept.
	 *
	 * @return void
	 */
	public function test_a_failed_read_keeps_the_stored_derivations(): void {
		$store  = $this->store( true );
		$stored = $this->stored( $store );
		$GLOBALS['post_shield_test_options'][ ConfigStore::OPTION ] = $stored;

		$candidate = $stored;
		unset( $candidate['entries']['story']['reserved_derived'] );
		$this->assertNull( $this->snapshot( $store, $candidate ), 'No fingerprint: the nightly sync tries again.' );
		$this->assertTrue( $store->redirect_read_failed() );
		$this->assertContains( 'old-story', $candidate['entries']['story']['reserved_derived'] ?? [] );
		$this->assertContains( 'legacy-route', $candidate['excluded_bases']['derived'] );
		$this->assertFalse( $store->snapshot_is_stale( $stored ), 'The kept slugs are not a reason to re-save.' );
	}

	/**
	 * A read that finds, correctly, no redirects drops what they reserved.
	 *
	 * @return void
	 */
	public function test_a_clean_empty_read_drops_them(): void {
		$store  = $this->store( false );
		$stored = $this->stored( $store );
		$GLOBALS['post_shield_test_options'][ ConfigStore::OPTION ] = $stored;

		$candidate = $stored;
		$this->assertSame( '', $this->snapshot( $store, $candidate ) );
		$this->assertFalse( $store->redirect_read_failed() );
		$this->assertArrayNotHasKey( 'reserved_derived', $candidate['entries']['story'] );
		$this->assertNotContains( 'legacy-route', $candidate['excluded_bases']['derived'] );
		$this->assertTrue( $store->snapshot_is_stale( $stored ) );
	}
}
