<?php
/**
 * Unit tests for decide_based() — the based-stage decision the pre-boot
 * loader acts on and the root preflight replays.
 *
 * The site-level differential test compares whole HTTP responses before and
 * after; these pin the branches real data may not reach (depth actions other
 * than redirect, full-path matching, missing and empty allowlists, the
 * redirect safety guards).
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use function Post404Shield\decide_based;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';

/**
 * Test class for decide_based().
 */
class PostShieldDecideBasedTest extends TestCase {

	/**
	 * Locale pattern used throughout.
	 */
	private const LOCALES = '[a-z]{2}-[a-z]{2}|intl';

	/**
	 * Decide a request against entries and in-memory allowlists.
	 *
	 * @param string                              $uri     Request URI (query allowed).
	 * @param array<string, array<string, mixed>> $entries Config entries.
	 * @param array<string, string[]|null>        $lists   Type => lines (null = missing file).
	 *
	 * @return array<string, mixed>|null
	 */
	private function decide( string $uri, array $entries, array $lists = [] ): ?array {
		$path = (string) parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test helper.
		$read = static function ( string $type ) use ( $lists ): ?string {
			if ( ! array_key_exists( $type, $lists ) || null === $lists[ $type ] ) {
				return null;
			}
			return "<?php exit;\n" . implode( "\n", $lists[ $type ] ) . "\n";
		};
		return decide_based( $uri, $path, $entries, $read, self::LOCALES );
	}

	/**
	 * A slug-mode entry with the given overrides.
	 *
	 * @param array<string, mixed> $over Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( array $over = [] ): array {
		return array_merge(
			[
				'enabled'       => true,
				'mode'          => 'allowlist',
				'post_type'     => 'story',
				'url_base'      => [ 'stories' ],
				'match'         => 'slug',
				'depth_allowed' => 0,
				'depth_action'  => 'redirect',
			],
			$over
		);
	}

	/**
	 * Real, fake, and a path no entry claims.
	 *
	 * @return void
	 */
	public function test_known_unknown_and_unclaimed(): void {
		$e = [ 'story' => $this->entry() ];
		$l = [ 'story' => [ 'real-one' ] ];

		$this->assertSame( 'allowed-known-slug', $this->decide( '/intl/stories/real-one/', $e, $l )['marker'] );
		$fake = $this->decide( '/en-gb/stories/zz-fake/', $e, $l );
		$this->assertSame( 'blocked-unknown-slug', $fake['marker'] );
		$this->assertSame( 'en-gb', $fake['locale'] );
		$this->assertSame( 'story', $fake['type'] );
		$this->assertNull( $this->decide( '/intl/other/thing/', $e, $l ), 'No entry claims it: root stage next.' );
		$this->assertNull( $this->decide( '/stories/real-one/', $e, $l ), 'Locale prefix required when a pattern is set.' );
	}

	/**
	 * The depth policy counts real levels only: a segment outside
	 * [a-z0-9_-] below a real slug is WordPress's to judge, never a 301 or a
	 * 404 a normalising cache could store under the canonical URL.
	 *
	 * @return void
	 */
	public function test_depth_policy_ignores_non_canonical_segments(): void {
		$e = [ 'story' => $this->entry() ];
		$l = [ 'story' => [ 'real-one' ] ];
		$this->assertSame( 'allowed-deep-path', $this->decide( '/intl/stories/real-one/Upper/', $e, $l )['marker'] );
		$this->assertSame( 'allowed-deep-path', $this->decide( '/intl/stories/real-one/a~b/', $e, $l )['marker'] );
		$this->assertSame( 'redirect-deep-path', $this->decide( '/intl/stories/real-one/a/', $e, $l )['marker'], 'A canonical deeper path still redirects.' );
	}

	/**
	 * Each depth action under a real slug.
	 *
	 * @return void
	 */
	public function test_depth_actions(): void {
		$l = [ 'story' => [ 'real-one' ] ];

		$redirect = $this->decide( '/intl/stories/real-one/a/b/?utm=x', [ 'story' => $this->entry() ], $l );
		$this->assertSame( 'redirect-deep-path', $redirect['marker'] );
		$this->assertSame( '/intl/stories/real-one/?utm=x', $redirect['location'], 'Truncated, query kept.' );

		$one = $this->decide( '/intl/stories/real-one/a/b/', [ 'story' => $this->entry( [ 'depth_allowed' => 1 ] ) ], $l );
		$this->assertSame( '/intl/stories/real-one/a/', $one['location'], 'Keeps depth_allowed levels.' );

		$this->assertSame( 'allowed-deep-path', $this->decide( '/intl/stories/real-one/a/', [ 'story' => $this->entry( [ 'depth_action' => 'passthrough' ] ) ], $l )['marker'] );
		$this->assertSame( 'blocked-deep-path', $this->decide( '/intl/stories/real-one/a/', [ 'story' => $this->entry( [ 'depth_action' => '404' ] ) ], $l )['marker'] );
		$this->assertSame( 'allowed-known-slug', $this->decide( '/intl/stories/real-one/a/b/', [ 'story' => $this->entry( [ 'depth_allowed' => null ] ) ], $l )['marker'], 'Null depth = unlimited.' );
		$this->assertSame( 'allowed-known-slug', $this->decide( '/intl/stories/real-one/page/2/', [ 'story' => $this->entry() ], $l )['marker'], 'Pagination does not count as depth.' );
	}

	/**
	 * Fail-open outcomes: claimed, but handed to WordPress unmarked.
	 *
	 * @return void
	 */
	public function test_fail_open_passes(): void {
		$e = [ 'story' => $this->entry() ];

		$this->assertSame( 'pass', $this->decide( '/intl/stories/zz-fake/', $e, [ 'story' => null ] )['marker'], 'Missing allowlist.' );
		$this->assertSame( 'pass', $this->decide( '/intl/stories/zz-fake/', $e, [ 'story' => [] ] )['marker'], 'Empty allowlist.' );
		$this->assertSame( 'pass', $this->decide( '/intl/stories/feed/', $e, [ 'story' => [ 'real-one' ] ] )['marker'], 'A bare sub-route belongs to WordPress.' );
	}

	/**
	 * Reserved slugs, both buckets, including a derived prefix family.
	 *
	 * @return void
	 */
	public function test_reserved_buckets(): void {
		$e = [
			'story' => $this->entry(
				[
					'reserved_allowlist' => [ 'b2b' ],
					'reserved_derived'   => [ 'promo*' ],
				]
			),
		];
		$l = [ 'story' => [ 'real-one' ] ];

		$this->assertSame( 'allowed-reserved-slug', $this->decide( '/intl/stories/b2b/', $e, $l )['marker'] );
		$this->assertSame( 'allowed-reserved-slug', $this->decide( '/intl/stories/promotional/', $e, $l )['marker'], 'Derived prefix family.' );
		$this->assertSame( 'blocked-unknown-slug', $this->decide( '/intl/stories/prom/', $e, $l )['marker'] );
	}

	/**
	 * Full-path entries exact-match the sub-path and skip depth policy.
	 *
	 * @return void
	 */
	public function test_full_path(): void {
		$e = [
			'guide' => $this->entry(
				[
					'post_type' => 'guide',
					'url_base'  => [ 'guides' ],
					'match'     => 'full-path',
				]
			),
		];
		$l = [ 'guide' => [ 'parent', 'parent/child' ] ];

		$this->assertSame( 'allowed-known-slug', $this->decide( '/intl/guides/parent/child/', $e, $l )['marker'] );
		$this->assertSame( 'allowed-known-slug', $this->decide( '/intl/guides/parent/child/feed/', $e, $l )['marker'], 'Sub-route of a real page.' );
		$this->assertSame( 'blocked-unknown-slug', $this->decide( '/intl/guides/parent/zz-fake/', $e, $l )['marker'] );

		// The builder never lists a segment outside [a-z0-9_-], so WordPress
		// judges it — a translated child's percent-encoded slug, or a
		// mixed-case link WordPress would redirect to the canonical URL.
		$this->assertSame( 'pass', $this->decide( '/ja-jp/guides/parent/%E6%97%A5%E6%9C%AC/', $e, $l )['marker'], 'Percent-encoded, upper case.' );
		$this->assertSame( 'pass', $this->decide( '/ja-jp/guides/parent/%e6%97%a5%e6%9c%ac/', $e, $l )['marker'], 'Percent-encoded, lower case.' );
		$this->assertSame( 'pass', $this->decide( '/intl/guides/parent/Child/', $e, $l )['marker'], 'Mixed case.' );
	}

	/**
	 * Blocked bases, disabled entries, and a substring that must not claim.
	 *
	 * @return void
	 */
	public function test_block_disabled_and_substring(): void {
		$e = [
			'old'   => [
				'enabled'  => true,
				'mode'     => 'block',
				'url_base' => [ 'old-section' ],
			],
			'off'   => $this->entry(
				[
					'enabled'   => false,
					'post_type' => 'off',
					'url_base'  => [ 'off' ],
				]
			),
			'page'  => $this->entry(
				[
					'post_type' => 'page',
					'url_base'  => [ 'page' ],
				]
			),
			'story' => $this->entry(),
		];
		$l = [
			'page'  => [ 'real-page' ],
			'story' => [ 'real-one' ],
		];

		$this->assertSame( 'blocked-denied-base', $this->decide( '/intl/old-section', $e, $l )['marker'], 'Bare base, no slash.' );
		$this->assertSame( 'blocked-denied-base', $this->decide( '/intl/old-section/a/b/', $e, $l )['marker'] );
		$this->assertNull( $this->decide( '/intl/off/anything/', $e, $l ), 'Disabled entry never claims.' );

		// `/page/` appears inside this URI, but the `page` entry cannot match
		// it; the story entry after it must still get its turn.
		$this->assertSame( 'blocked-unknown-slug', $this->decide( '/intl/stories/zz-fake/page/2/', $e, $l )['marker'] );
	}

	/**
	 * A locale a custom pattern captured that is not a plain segment must
	 * not become a redirect target.
	 *
	 * @return void
	 */
	public function test_redirect_refuses_an_untrusted_locale(): void {
		$path = '/a.b/stories/real-one/x/';
		$read = static function (): string {
			return "<?php exit;\nreal-one\n";
		};
		$got = decide_based( $path, $path, [ 'story' => $this->entry() ], $read, '[a-z.]{3}' );
		$this->assertSame( 'pass', $got['marker'], 'Fails open rather than redirect.' );
	}
}
