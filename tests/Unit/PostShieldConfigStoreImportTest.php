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

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
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
	 * reducer change reaches existing sites only when the version moves.
	 *
	 * The table says what each shape derives today; the history maps every
	 * version to the hash of that table's outputs. Change what a shape
	 * derives and the table must change — and then the hash no longer
	 * matches the version's, so a NEW version has to be appended. History is
	 * append-only: never edit a past line.
	 *
	 * @return void
	 */
	public function test_derivation_version_moves_with_the_reducers(): void {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		$history = [
			3 => 'cca3e5ba921bf138e780eab3d1583dc5', // Round 3; round 4 changed shapes not in this table.
			4 => '0bc27ca5d83125a18dd4731b02b502f6', // Round 5: escaped slashes, top-level alternation, any locale form.
			5 => '9c9ff4bbfaebc567185879c94028faf9', // Round 7: an optional separator not end-anchored is a prefix.
			6 => '3618dea9c8694af6e1f668175dcf2e2c', // Round 8: a zero-or-more brace quantifier on the separator.
			7 => '7cc01b5eff7c64768b3970df0702259d', // Round 9: a locale group whose every branch carries its own slash.
			8 => '38904bbff31f6ad6a1d282563b743f6a', // Round 15: a plain source is lower-cased before its locale is stripped.
		];
		$pattern = '[a-z]{2}-[a-z]{2}|global';
		$table   = [
			[ '^/old-product-\\d+/?$', true, [ 'old-product-*' ] ],
			[ '^/promo', true, [ 'promo*' ] ],
			[ '^/promo/', true, [ 'promo' ] ],
			[ '^/sale\\-', true, [ 'sale-*' ] ],
			[ '^/news/(.+)$', true, [ 'news' ] ],
			[ '^/product-.*$', true, [ 'product-*' ] ],
			[ '^/colou?r$', true, [ 'colo*' ] ],
			[ '^/([a-z]{2}-[a-z]{2})/stories/old/?$', true, [ 'stories/old' ] ],
			[ '^([a-z]{2}-[a-z]{2}|global)/products/(finder|grip)/?$', true, [ 'products/finder', 'products/grip' ] ],
			[ '/old-page/', false, [ 'old-page' ] ],
			[ 'faq?x=1', false, [ 'faq*' ] ],
			[ 'ja-jp/special/x/', false, [ 'special/x' ] ],
			[ '^/?products/cameras/old-model/?$', true, [ 'products/cameras/old-model' ] ],
			[ '^([a-z]{2}-[a-z]{2}|global)?/?products/cameras/old-model/?$', true, [ 'products/cameras/old-model' ] ],
			[ '^(?:[a-z]{2}-[a-z]{2}/)?products/cameras/old-model/?$', true, [ 'products/cameras/old-model' ] ],
			[ '^/(en-us|Nope1)/x', true, [ '' ] ],
			// Version 4: escaped slashes, top-level alternation, any locale form.
			[ '^[a-z]{2}-[a-z]{2}/news/old-article/?$', true, [ 'news/old-article' ] ],
			[ '^\\/ja-jp\\/news\\/old-article\\/?$', true, [ 'news/old-article' ] ],
			[ '^(?:/[a-z]{2}-[a-z]{2})?/photographers/x$', true, [ 'photographers/x' ] ],
			[ '^(?:([a-z]{2}-[a-z]{2}|global)/)?photographers/x$', true, [ 'photographers/x' ] ],
			[ '^global/stories/a/?$|^global/stories/b/?$', true, [ 'stories/a', 'stories/b' ] ],
			[ '^\\/([a-z]{2}-[a-z]{2}|global)\\/news\\/y', true, [ 'news/y*' ] ],
			[ '^\\/news\\/(\\d+)\\/?$', true, [ 'news' ] ],
			[ '^(ja-jp)x/news/q', true, [ 'ja-jpx/news/q*' ] ],
			[ '^(en-us|news)/x', true, [ 'en-us/x*', 'news/x*' ] ],
			[ '^(.*)/old', true, [ '' ] ],
			// Version 5: an optional separator not end-anchored is a prefix.
			[ '^/global/news/old-post/?', true, [ 'news/old-post*' ] ],
			[ '^/global/news/old-post/?$', true, [ 'news/old-post' ] ],
			[ '^/news/?(.*)$', true, [ 'news*' ] ],
			// Version 6: `{0,1}` / `{0,}` on the separator, the same as `?` / `*`.
			[ '^/global/news/old-post/{0,1}', true, [ 'news/old-post*' ] ],
			[ '^/global/news/old-post/{0,}$', true, [ 'news/old-post' ] ],
			// Version 7: a locale group whose every branch carries its own slash.
			[ '^(?:[a-z]{2}-[a-z]{2}/|global/)?products/(cameras|lenses)/x-t3/?$', true, [ 'products/cameras/x-t3', 'products/lenses/x-t3' ] ],
			// Version 8: a plain source is lower-cased before its locale is stripped.
			[ '/Global/stories/old-story/', false, [ 'stories/old-story' ] ],
			[ '/EN-US/products/cameras/old-model', false, [ 'products/cameras/old-model' ] ],
		];
		$as_read = new \ReflectionMethod( ConfigStore::class, 'source_pattern' );
		$as_read->setAccessible( true );
		$out = [];
		foreach ( $table as [ $source, $regex, $expected ] ) {
			$got     = [];
			$pattern_in = $as_read->invoke(
				null,
				[
					'pattern' => $source,
					'regex'   => $regex,
				]
			);
			foreach ( \Post404Shield\redirect_source_variants( $pattern_in, $regex, $pattern ) as $variant ) {
				$got[] = \Post404Shield\redirect_pattern_base( $variant, $regex );
			}
			$this->assertSame( $expected, $got, $source );
			$out[] = implode( ',', $got );
		}
		$version = ( new \ReflectionClassConstant( ConfigStore::class, 'REDIRECT_DERIVATION_VERSION' ) )->getValue();
		$this->assertSame( max( array_keys( $history ) ), $version, 'Append the new version to the history.' );
		$this->assertSame( $history[ $version ], md5( implode( '|', $out ) ), 'The table changed: bump REDIRECT_DERIVATION_VERSION and append its hash.' );
	}

	/**
	 * Where the literal run of a source ends, in bytes of the source: an
	 * escape is two bytes for one literal character.
	 *
	 * @return void
	 */
	public function test_the_reducer_says_where_the_literal_run_ended(): void {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
		$cases = [
			[ 'news/(\\d+)', 'news', 5 ],
			[ 'my\\-news/(\\d+)', 'my-news', 9 ],
			[ '^colou?r$', 'colo*', 5 ],
			[ '(.+)', '', 0 ],
		];
		foreach ( $cases as [ $source, $base, $consumed ] ) {
			$got = null;
			$this->assertSame( $base, \Post404Shield\redirect_pattern_base( $source, true, $got ), $source );
			$this->assertSame( $consumed, $got, $source );
		}
	}

	/**
	 * A merged entry's re-key never replaces another entry: a blocked section
	 * named like the merged prefix keeps its key, and so does the type.
	 *
	 * @return void
	 */
	public function test_a_rekey_never_replaces_another_entry(): void {
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
				'support-compatibility'         => [
					'mode'     => 'block',
					'url_base' => 'support/compatibility-old',
				],
			]
		);
		$this->assertCount( 2, $entries );
		$modes = array_column( $entries, 'mode' );
		sort( $modes );
		$this->assertSame( [ 'allowlist', 'block' ], $modes );
	}
}
