<?php
/**
 * Unit tests for AllowlistBuilder::is_servable_status() — which statuses a
 * list may hold. A registered status WordPress never serves at a post's own
 * address (draft, pending, scheduled) is left out; public and private stay;
 * an unregistered one stays, so a status plugin briefly off cannot 404 its
 * posts once it is back. Separate processes: the WordPress stubs are global.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\AllowlistBuilder;

/**
 * Test class for the servable-status rule.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PostShieldServableStatusTest extends TestCase {

	/**
	 * Core's status objects, reduced to the flags the rule reads.
	 *
	 * @return void
	 */
	private function stub_statuses(): void {
		eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only global stubs, in a separate process.
			'function get_post_status_object( $name ) {
				$all = [
					"publish"      => [ "public" => true, "private" => false, "protected" => false, "publicly_queryable" => true ],
					"discontinued" => [ "public" => true, "private" => false, "protected" => false, "publicly_queryable" => true ],
					"private"      => [ "public" => false, "private" => true, "protected" => false, "publicly_queryable" => false ],
					"draft"        => [ "public" => false, "private" => false, "protected" => true, "publicly_queryable" => false ],
					"future"       => [ "public" => false, "private" => false, "protected" => true, "publicly_queryable" => false ],
					"pending"      => [ "public" => false, "private" => false, "protected" => true, "publicly_queryable" => false ],
				];
				return isset( $all[ $name ] ) ? (object) ( $all[ $name ] + [ "name" => $name ] ) : null;
			}
			function is_post_status_viewable( $status ) {
				$status = is_object( $status ) ? $status : get_post_status_object( $status );
				return null !== $status && $status->publicly_queryable && $status->public;
			}'
		);
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
	}

	/**
	 * Public and private are listed; protected statuses are not; unknown ones are kept.
	 *
	 * @return void
	 */
	public function test_servable_statuses(): void {
		$this->stub_statuses();
		foreach ( [ 'publish', 'discontinued', 'private', 'embargoed-unregistered' ] as $status ) {
			$this->assertTrue( AllowlistBuilder::is_servable_status( $status ), $status );
		}
		foreach ( [ 'draft', 'future', 'pending' ] as $status ) {
			$this->assertFalse( AllowlistBuilder::is_servable_status( $status ), $status );
		}
	}
}
