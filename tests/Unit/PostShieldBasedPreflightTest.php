<?php
/**
 * Unit tests for BasedPreflight's pure parts: which entries a save changes
 * (and therefore gets its real URLs replayed), and the base a URL lives
 * under (the "its posts live under …" hint).
 *
 * The replay itself needs real permalinks and is proven on a site: a save
 * enabling a wrong base is refused, the right base saves.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\BasedPreflight;

require_once __DIR__ . '/../../post-404-shield/src/php/Library/BasedPreflight.php';

/**
 * Test class for BasedPreflight.
 */
class PostShieldBasedPreflightTest extends TestCase {

	/**
	 * A based entry.
	 *
	 * @param array<string, mixed> $over Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( array $over = [] ): array {
		return array_merge(
			[
				'enabled'   => true,
				'mode'      => 'allowlist',
				'post_type' => 'manual',
				'url_base'  => [ 'support/manual/detail' ],
				'match'     => 'slug',
			],
			$over
		);
	}

	/**
	 * Enabling an entry, or changing what it blocks, puts it in scope.
	 * Anything that cannot change which real URLs break does not.
	 *
	 * @return void
	 */
	public function test_changed_keys_scope(): void {
		$current = [
			'manual' => $this->entry( [ 'enabled' => false ] ),
			'story'  => $this->entry(
				[
					'post_type' => 'story',
					'url_base'  => [ 'stories' ],
				]
			),
			'news'   => $this->entry(
				[
					'post_type' => 'news',
					'url_base'  => [ 'news' ],
				]
			),
		];

		$candidate = $current;
		$this->assertSame( [], BasedPreflight::changed_keys( $candidate, $current ), 'Unchanged: nothing to replay (self-heal, CLI write).' );

		$candidate['manual']['enabled'] = true;
		$this->assertSame( [ 'manual' ], BasedPreflight::changed_keys( $candidate, $current ), 'Newly enabled.' );

		$candidate['story']['url_base'] = [ 'story' ];
		$candidate['news']['cache_ttl'] = 60;
		$this->assertSame( [ 'manual', 'story' ], BasedPreflight::changed_keys( $candidate, $current ), 'Base change in scope; a TTL change is not.' );

		$this->assertSame( [ 'manual', 'story', 'news' ], BasedPreflight::changed_keys( $candidate, null ), 'No stored config: every enabled entry.' );
	}

	/**
	 * Disabled entries, blocked bases and root entries are never replayed here.
	 *
	 * @return void
	 */
	public function test_changed_keys_ignores_what_cannot_break_real_urls_here(): void {
		$candidate = [
			'off'   => $this->entry( [ 'enabled' => false ] ),
			'block' => [
				'enabled'  => true,
				'mode'     => 'block',
				'url_base' => [ 'old' ],
			],
			'root'  => $this->entry(
				[
					'post_type' => 'page',
					'root'      => true,
					'url_base'  => [],
				]
			),
		];
		$this->assertSame( [], BasedPreflight::changed_keys( $candidate, null ) );
	}

	/**
	 * The base a URL lives under: locale and slug stripped.
	 *
	 * @return void
	 */
	public function test_base_of(): void {
		$pattern = '[a-z]{2}-[a-z]{2}|intl';

		$this->assertSame( 'support/manual/detail', BasedPreflight::base_of( '/intl/support/manual/detail/x-pro3/', $pattern ) );
		$this->assertSame( 'stories', BasedPreflight::base_of( '/en-gb/stories/a-story/', $pattern ) );
		$this->assertSame( 'stories', BasedPreflight::base_of( '/stories/a-story/', '' ), 'No locale pattern.' );
		$this->assertSame( '', BasedPreflight::base_of( '/intl/a-page/', $pattern ), 'Top-level: no base.' );
	}
}
