<?php
/**
 * Unit tests for the pre-swap rebuild's contract inside ConfigStore::write():
 * a save that needs a list rebuilt before its artifact goes live (a type
 * becoming full-path, root mode switching on) must refuse when that list was
 * not written, and turn a failed database read into a retry. A swap over a
 * list that was never rebuilt serves every nested real URL a cacheable 404.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ReadFailure.php';

/**
 * Test class for rebuild_before_swap().
 */
class PostShieldRebuildBeforeSwapTest extends TestCase {

	/**
	 * Run the pre-swap rebuild with a stub handler.
	 *
	 * @param callable             $handler        The rebuild handler.
	 * @param string[]             $rebuild_before Types becoming full-path.
	 * @param array<string, mixed> $entries        Candidate entries.
	 *
	 * @return array{0: bool, 1: string[]} Whether it passed, and the lists it names as failed.
	 */
	private function run_rebuild( callable $handler, array $rebuild_before, array $entries = [] ): array {
		$store = new ConfigStore();
		$store->set_rebuild_handler( $handler );
		$method = new \ReflectionMethod( ConfigStore::class, 'rebuild_before_swap' );
		$method->setAccessible( true );
		$passed   = $method->invoke( $store, $rebuild_before, [ 'entries' => $entries ] );
		$failures = new \ReflectionProperty( ConfigStore::class, 'rebuild_failures' );
		$failures->setAccessible( true );
		return [ $passed, $failures->getValue( $store ) ];
	}

	/**
	 * Candidate entries with root mode on (no live artifact: switching on).
	 *
	 * @return array<string, mixed>
	 */
	private function root_entries(): array {
		return [
			'page' => [
				'enabled'   => true,
				'mode'      => 'allowlist',
				'post_type' => 'page',
				'root'      => true,
			],
		];
	}

	/**
	 * Every list written: the save goes on.
	 *
	 * @return void
	 */
	public function test_all_written_passes(): void {
		$this->assertSame( [ true, [] ], $this->run_rebuild( static fn() => true, [ 'photographer' ] ) );
	}

	/**
	 * The handler names a list it could not write: refused, naming it.
	 *
	 * @return void
	 */
	public function test_a_named_failed_list_refuses(): void {
		$this->assertSame( [ false, [ 'photographer' ] ], $this->run_rebuild( static fn() => [ 'photographer' ], [ 'photographer', 'camera' ] ) );
	}

	/**
	 * A plain false, or a bug in the rebuild, fails every list it was for —
	 * root-extras too when root mode is switching on and no type is.
	 *
	 * @return void
	 */
	public function test_false_or_a_bug_fails_every_list(): void {
		$this->assertSame( [ false, [ 'photographer' ] ], $this->run_rebuild( static fn() => false, [ 'photographer' ] ) );

		ini_set( 'error_log', sys_get_temp_dir() . '/postshield-rebuild-test.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the contained error is logged; keep it off the test's output.
		$bug = static function (): bool {
			throw new \TypeError( 'a bug' );
		};
		$this->assertSame( [ false, [ 'root-extras' ] ], $this->run_rebuild( $bug, [], $this->root_entries() ) );
		$this->assertSame( [ false, [ 'root-extras' ] ], $this->run_rebuild( static fn() => null, [], $this->root_entries() ) );
	}

	/**
	 * A failed database read propagates, for write() to refuse with a retry.
	 *
	 * @return void
	 */
	public function test_a_failed_read_propagates(): void {
		$this->expectException( \Post404Shield\Library\ReadFailure::class );
		$this->run_rebuild(
			static function (): bool {
				throw new \Post404Shield\Library\ReadFailure( 'Deadlock found' );
			},
			[ 'photographer' ]
		);
	}

	/**
	 * Any other RuntimeException (an SPL one from a bug) refuses; it is not
	 * read as a database blip to retry.
	 *
	 * @return void
	 */
	public function test_another_runtime_exception_refuses(): void {
		ini_set( 'error_log', sys_get_temp_dir() . '/postshield-rebuild-test.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- the contained error is logged; keep it off the test's output.
		$bug = static function (): bool {
			throw new \UnexpectedValueException( 'a bug' );
		};
		$this->assertSame( [ false, [ 'photographer' ] ], $this->run_rebuild( $bug, [ 'photographer' ] ) );
	}

	/**
	 * Nothing to rebuild before the swap: the handler is not called.
	 *
	 * @return void
	 */
	public function test_nothing_to_rebuild_skips_the_handler(): void {
		$called = false;
		$result = $this->run_rebuild(
			static function () use ( &$called ): bool {
				$called = true;
				return false;
			},
			[]
		);
		$this->assertSame( [ true, [] ], $result );
		$this->assertFalse( $called );
	}
}
