<?php
/**
 * Unit tests for two save-time checks:
 *
 * - A switched-off entry whose `post_status` holds a value that is no status
 *   name at all (`Publish`, a number) is refused by name. The runtime reader
 *   rejects it whether the entry is on or not, so a warning let the save
 *   reach the invariant check and fail as an unnamed "Internal error".
 * - A redirect reader whose plugin is off runs no query, so it must not be
 *   blamed for an earlier, unrelated failed query (`$wpdb->last_error`).
 *
 * Each test runs in its own process: the WordPress stubs and $wpdb are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

/**
 * Test class for the save-time checks.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldValidateTest extends TestCase {

	/**
	 * Load the store with the WordPress calls validate() makes.
	 *
	 * @return void
	 */
	private function stub(): void {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_post_stati( $args = [] ) { return [ "publish" => "publish", "private" => "private" ]; }
			function post_type_exists( $type ) { return false; }'
		);
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
	}

	/**
	 * A switched-off entry: an unregistered status name warns, a value that
	 * is no status name is refused by name.
	 *
	 * @return void
	 */
	public function test_a_switched_off_entry_names_a_value_that_is_no_status(): void {
		$this->stub();
		$entry = static fn( array $statuses ): array => [
			'locale'  => [ 'mode' => 'none' ],
			'entries' => [
				'news' => [
					'enabled'     => false,
					'mode'        => 'allowlist',
					'post_type'   => 'news',
					'url_base'    => [ 'news' ],
					'post_status' => $statuses,
				],
			],
		];
		$store = new ConfigStore();

		$gone = $store->validate( $entry( [ 'publish', 'workflow-gone' ] ) );
		$this->assertSame( [], $gone['errors'], 'An unregistered status on a switched-off entry only warns.' );

		foreach ( [ 'Publish', 1 ] as $bad ) {
			$errors = implode( ' | ', $store->validate( $entry( [ 'publish', $bad ] ) )['errors'] );
			$this->assertStringContainsString( '"' . $bad . '" is not a post status name', $errors, (string) $bad );
		}
	}

	/**
	 * With no redirect plugin active, an earlier failed query does not mark
	 * the redirect read as failed.
	 *
	 * @return void
	 */
	public function test_an_earlier_failed_query_is_not_a_failed_redirect_read(): void {
		$this->stub();
		$GLOBALS['wpdb'] = new class() {
			/** @var string A query before the save failed. */
			public $last_error = 'Table wp_other_plugin does not exist';
		};
		$store   = new ConfigStore();
		$sources = new \ReflectionMethod( $store, 'redirect_sources' );
		$sources->setAccessible( true );
		$this->assertSame( [], $sources->invoke( $store ) );
		$failed = new \ReflectionProperty( $store, 'redirect_read_failed' );
		$failed->setAccessible( true );
		$this->assertFalse( $failed->getValue( $store ) );
	}
}
