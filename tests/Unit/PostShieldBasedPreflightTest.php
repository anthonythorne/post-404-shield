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
	 * An absent key and its default are the same value: a config written by
	 * the legacy importer omits allow_pagination, which the screen saves as
	 * true — that must not make every entry look changed. Reordered lists are
	 * not a change either.
	 *
	 * @return void
	 */
	public function test_changed_keys_ignores_defaults_and_order(): void {
		$stored    = [
			'manual' => [
				'enabled'     => true,
				'post_type'   => 'manual',
				'url_base'    => [ 'support/manual/detail' ],
				'post_status' => [ 'publish', 'discontinued' ],
			],
		];
		$candidate = [
			'manual' => array_merge(
				$stored['manual'],
				[
					'mode'             => 'allowlist',
					'match'            => 'slug',
					'allow_pagination' => true,
					'post_status'      => [ 'discontinued', 'publish' ],
				]
			),
		];
		$this->assertSame( [], BasedPreflight::changed_keys( $candidate, $stored ) );
	}

	/**
	 * Editing reserved slugs is in scope: removing one can 404 the page it
	 * protected.
	 *
	 * @return void
	 */
	public function test_changed_keys_includes_reserved_slug_edits(): void {
		$stored    = [ 'story' => $this->entry( [ 'post_type' => 'story', 'url_base' => [ 'stories' ], 'reserved_allowlist' => [ 'b2b-solutions' ] ] ) ];
		$candidate = [ 'story' => $this->entry( [ 'post_type' => 'story', 'url_base' => [ 'stories' ], 'reserved_allowlist' => [] ] ) ];
		$this->assertSame( [ 'story' ], BasedPreflight::changed_keys( $candidate, $stored ) );
	}

	/**
	 * Disabled and root entries are never replayed here; a blocked section is
	 * when it is new or its bases moved.
	 *
	 * @return void
	 */
	public function test_changed_keys_gates_blocked_sections(): void {
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
		$this->assertSame( [ 'block' ], BasedPreflight::changed_keys( $candidate, null ), 'A new blocked section is gated: it must cover no real content.' );

		// An unchanged blocked section is not re-checked; a moved one is.
		$current = [ 'block' => $candidate['block'] ];
		$this->assertSame( [], BasedPreflight::changed_keys( $candidate, $current ) );
		$current['block']['url_base'] = [ 'older' ];
		$this->assertSame( [ 'block' ], BasedPreflight::changed_keys( $candidate, $current ) );
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

	/**
	 * An empty candidate list is measured armed: the loader's own decision
	 * judges a real post against it (a 404, which the gate reports as a
	 * dropped or unlisted status), never passes it as an empty list would.
	 *
	 * @return void
	 */
	public function test_an_empty_list_is_measured_armed(): void {
		require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';
		$armed = new \ReflectionMethod( BasedPreflight::class, 'armed_body' );
		$armed->setAccessible( true );
		$entries = [
			'software' => [
				'enabled'     => true,
				'mode'        => 'allowlist',
				'post_type'   => 'software',
				'url_base'    => [ 'products/software' ],
				'post_status' => [ 'publish' ],
				'match'       => 'slug',
			],
		];
		$decide  = static fn( string $body ): ?array => \Post404Shield\decide_based( '/global/products/software/pc-autosave/', '/global/products/software/pc-autosave/', $entries, static fn() => $body, '[a-z]{2}-[a-z]{2}|global' );

		$this->assertSame( 'blocked-unknown-slug', $decide( $armed->invoke( null, [] ) )['marker'] ?? 'pass', 'Armed: judged.' );

		// Through the reader run() uses, with a builder whose list is empty.
		require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
		$empty  = new class( [] ) extends \Post404Shield\Library\AllowlistBuilder {
			/**
			 * No lines.
			 *
			 * @param string        $post_type Type.
			 * @param string[]|null $statuses  Statuses.
			 * @return string[]
			 */
			public function lines_for( string $post_type, ?array $statuses = null ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the parent's signature.
				return [];
			}
		};
		$reader = new \ReflectionMethod( BasedPreflight::class, 'body_reader' );
		$reader->setAccessible( true );
		$read   = $reader->invoke( null, $empty );
		$this->assertSame( 'blocked-unknown-slug', $decide( $read( 'software' ) )['marker'] ?? 'pass', 'run()\'s reader arms it too.' );
		$this->assertSame( 'pass', $decide( "<?php exit;\n\n" )['marker'] ?? 'pass', 'What the loader does with a truly empty list.' );
		$this->assertSame( 'allowed-known-slug', $decide( $armed->invoke( null, [ 'pc-autosave' ] ) )['marker'] );
	}
}
