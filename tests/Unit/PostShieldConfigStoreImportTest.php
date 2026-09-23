<?php
/**
 * Unit tests for ConfigStore::import_legacy().
 *
 * The importer is pure: it maps a legacy committed config array onto a v1
 * document without touching WordPress. These tests pin the one property the
 * importer promises and once broke — a wire-level no-op against the legacy
 * loader — for entries that share a post type across several URL bases.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';

/**
 * Test class for the legacy importer.
 */
class PostShieldConfigStoreImportTest extends TestCase {

	/**
	 * Import a legacy array and return the resulting entries.
	 *
	 * @param array<string, array<string, mixed>> $legacy Legacy config.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function import( array $legacy ): array {
		return ( new ConfigStore() )->import_legacy( $legacy, 'none', '' )['entries'];
	}

	/**
	 * The legacy loader decided enabled per base. A disabled sibling's base
	 * must not become shielded because another sibling sharing its post type
	 * is on.
	 *
	 * @return void
	 */
	public function test_disabled_sibling_base_is_not_shielded(): void {
		$entries = $this->import(
			[
				'support-compatibility-cameras' => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/cameras',
				],
				'support-compatibility-lenses'  => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/lenses',
					'enabled'   => false,
				],
			]
		);

		$this->assertCount( 1, $entries );
		$entry = reset( $entries );
		$this->assertTrue( $entry['enabled'] );
		$this->assertSame( [ 'support/compatibility/cameras' ], $entry['url_base'] );
	}

	/**
	 * Order must not matter: a disabled FIRST sibling, whose position the
	 * merged entry takes, must not leak its base either.
	 *
	 * @return void
	 */
	public function test_disabled_first_sibling_base_is_not_shielded(): void {
		$entries = $this->import(
			[
				'support-compatibility-cameras' => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/cameras',
					'enabled'   => false,
				],
				'support-compatibility-lenses'  => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/lenses',
				],
			]
		);

		$entry = reset( $entries );
		$this->assertTrue( $entry['enabled'] );
		$this->assertSame( [ 'support/compatibility/lenses' ], $entry['url_base'] );
	}

	/**
	 * Every sibling off: the entry is off and keeps all of its bases, so
	 * importing a fully disabled type loses nothing.
	 *
	 * @return void
	 */
	public function test_all_siblings_disabled_keeps_every_base(): void {
		$entries = $this->import(
			[
				'support-compatibility-cameras' => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/cameras',
					'enabled'   => false,
				],
				'support-compatibility-lenses'  => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/lenses',
					'enabled'   => false,
				],
			]
		);

		$entry = reset( $entries );
		$this->assertFalse( $entry['enabled'] );
		$this->assertSame( [ 'support/compatibility/cameras', 'support/compatibility/lenses' ], $entry['url_base'] );
	}

	/**
	 * All siblings on: every base is kept, in legacy order, so the loader's
	 * early-return iteration order is unchanged.
	 *
	 * @return void
	 */
	public function test_all_siblings_enabled_keeps_legacy_order(): void {
		$entries = $this->import(
			[
				'support-compatibility-cameras' => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/cameras',
				],
				'support-compatibility-lenses'  => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/lenses',
				],
				'support-compatibility-software' => [
					'post_type' => 'compat',
					'url_base'  => 'support/compatibility/software',
				],
			]
		);

		$this->assertArrayHasKey( 'support-compatibility', $entries );
		$entry = $entries['support-compatibility'];
		$this->assertTrue( $entry['enabled'] );
		$this->assertSame(
			[ 'support/compatibility/cameras', 'support/compatibility/lenses', 'support/compatibility/software' ],
			$entry['url_base']
		);
		$this->assertArrayNotHasKey( 'base_switches', $entry );
		$this->assertArrayNotHasKey( 'merged_keys', $entry );
	}

	/**
	 * A single, unmerged entry keeps its own switch either way.
	 *
	 * @return void
	 */
	public function test_single_entry_keeps_its_switch(): void {
		$on  = $this->import( [ 'story' => [ 'url_base' => 'stories' ] ] );
		$off = $this->import(
			[
				'story' => [
					'url_base' => 'stories',
					'enabled'  => false,
				],
			]
		);

		$this->assertTrue( $on['story']['enabled'] );
		$this->assertSame( [ 'stories' ], $on['story']['url_base'] );
		$this->assertFalse( $off['story']['enabled'] );
		$this->assertSame( [ 'stories' ], $off['story']['url_base'] );
	}

	/**
	 * The redirect-derivation version salts the redirect fingerprint, so a
	 * reducer change reaches existing sites only when the version moves. This
	 * pins both together: change what the reducers produce and this fails
	 * until REDIRECT_DERIVATION_VERSION is bumped and the hash updated.
	 *
	 * @return void
	 */
	public function test_derivation_version_moves_with_the_reducers(): void {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		$pattern = '[a-z]{2}-[a-z]{2}|global';
		$sources = [
			[ '^/old-product-\\d+/?$', true ],
			[ '^/promo', true ],
			[ '^/promo/', true ],
			[ '^/sale\\-', true ],
			[ '^/news/(.+)$', true ],
			[ '^/product-.*$', true ],
			[ '^/colou?r$', true ],
			[ '^/([a-z]{2}-[a-z]{2})/stories/old/?$', true ],
			[ '^([a-z]{2}-[a-z]{2}|global)/products/(finder|grip)/?$', true ],
			[ '/old-page/', false ],
			[ 'faq?x=1', false ],
			[ 'ja-jp/special/x/', false ],
			[ '^/?products/cameras/old-model/?$', true ],
			[ '^([a-z]{2}-[a-z]{2}|global)?/?products/cameras/old-model/?$', true ],
			[ '^(?:[a-z]{2}-[a-z]{2}/)?products/cameras/old-model/?$', true ],
			[ '^/(en-us|Nope1)/x', true ],
		];
		$out = [];
		foreach ( $sources as [ $source, $regex ] ) {
			foreach ( \Post404Shield\redirect_source_variants( $source, $regex, $pattern ) as $variant ) {
				$out[] = \Post404Shield\redirect_pattern_base( $variant, $regex );
			}
		}
		$version = ( new \ReflectionClassConstant( ConfigStore::class, 'REDIRECT_DERIVATION_VERSION' ) )->getValue();
		$this->assertSame(
			[ 3, 'cca3e5ba921bf138e780eab3d1583dc5' ],
			[ $version, md5( implode( '|', $out ) ) ],
			'The reducers changed: bump REDIRECT_DERIVATION_VERSION and update this hash. Outputs: ' . implode( ' | ', $out )
		);
	}
}
