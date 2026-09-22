<?php
/**
 * Unit tests for the post 404 shield matcher.
 *
 * Pure PHP, no WordPress: requires ONLY the side-effect-free Matcher.php (which
 * merely declares namespaced functions) and exercises the generic path-matching
 * + membership logic used by the front-end read bootstrap.
 *
 * The locale prefix is a config-supplied PATTERN parameter: non-empty means the
 * prefix is REQUIRED (this repo's `[a-z]{2}-[a-z]{2}|global`), '' means the
 * site has no language directories at all.
 *
 * File Path: build-tools/tests/unit-testing/unit/PostShieldMatcherTest.php
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

use function Post404Shield\match_entry;
use function Post404Shield\match_blocked_base;
use function Post404Shield\is_allowed;
use function Post404Shield\prefers_markdown;

// Require ONLY the pure matcher. It has no top-level side effects (namespaced
// function declarations), so it loads without WordPress or any mocks.
// __DIR__ is tests/unit-testing/unit, so four levels up reaches wp-content.
require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';

/**
 * Test class for the post 404 shield matcher.
 */
class PostShieldMatcherTest extends TestCase {

	private const BASE     = 'team';
	private const RESERVED = [ 'member' ];
	private const PATTERN  = '[a-z]{2}-[a-z]{2}|global';

	/**
	 * A valid single-segment entry path yields locale + slug + empty extra.
	 */
	public function test_match_entry_parses_top_level_path() {
		$match = match_entry( '/global/team/jane-doe/', self::BASE, self::RESERVED, self::PATTERN );
		$this->assertSame( 'global', $match['locale'] );
		$this->assertSame( 'jane-doe', $match['slug'] );
		$this->assertSame( [], $match['extra'] );
	}

	/**
	 * Child segments are captured as `extra` for the depth policy.
	 */
	public function test_match_entry_captures_child_segments_as_extra() {
		$match = match_entry( '/fr-fr/team/john-smith/galleries/gallery-01/', self::BASE, self::RESERVED, self::PATTERN );
		$this->assertSame( 'fr-fr', $match['locale'] );
		$this->assertSame( 'john-smith', $match['slug'] );
		$this->assertSame( [ 'galleries', 'gallery-01' ], $match['extra'] );
	}

	/**
	 * A multi-segment base (`products/shoes`) matches too.
	 */
	public function test_match_entry_supports_multi_segment_base() {
		$match = match_entry( '/en-gb/products/shoes/trail-5/', 'products/shoes', [], self::PATTERN );
		$this->assertSame( 'trail-5', $match['slug'] );
		$this->assertSame( [], $match['extra'] );
	}

	/**
	 * When a pattern is set the prefix is REQUIRED — a bare `/{base}/{slug}/`
	 * deliberately falls through (the language plugin owns those URLs).
	 */
	public function test_match_entry_requires_the_prefix_when_a_pattern_is_set() {
		$this->assertNull( match_entry( '/team/jane-doe/', self::BASE, self::RESERVED, self::PATTERN ) );
	}

	/**
	 * No-prefix mode ('' pattern, a site without language directories): the
	 * bare URL matches and the locale is the literal `default`; a prefixed URL
	 * no longer matches the shape and falls through.
	 */
	public function test_match_entry_no_prefix_mode() {
		$match = match_entry( '/team/jane-doe/', self::BASE, self::RESERVED, '' );
		$this->assertSame( 'default', $match['locale'] );
		$this->assertSame( 'jane-doe', $match['slug'] );

		$this->assertNull( match_entry( '/global/team/jane-doe/', self::BASE, self::RESERVED, '' ) );
	}

	/**
	 * Full-path allowlist lines (hierarchical sub-paths) work through the same
	 * matcher: the loader joins slug + extra and exact-matches the whole line.
	 */
	public function test_full_path_membership_matches_whole_hierarchical_lines() {
		$raw = "<?php exit; // guard\nparent\nparent/child\nparent/child/grandchild\n";

		$this->assertTrue( is_allowed( 'parent', $raw ) );
		$this->assertTrue( is_allowed( 'parent/child', $raw ) );
		$this->assertTrue( is_allowed( 'parent/child/grandchild', $raw ) );
		$this->assertFalse( is_allowed( 'parent/other', $raw ), 'A fake child of a real parent must not match.' );
		$this->assertFalse( is_allowed( 'child', $raw ), 'A bare segment of a nested line must not match.' );
	}

	/**
	 * An engine-level pattern error is a NON-match (fail-open, fall through),
	 * never a warning or a block.
	 */
	public function test_a_broken_pattern_falls_through() {
		$this->assertNull( match_entry( '/en-au/team/jane/', self::BASE, [], '[unclosed' ) );
		$this->assertNull( match_blocked_base( '/en-au/old-section/', 'old-section', '[unclosed' ) );
	}

	/**
	 * Paths that are not our surface yield null (fall through to WordPress).
	 *
	 * @dataProvider provide_non_matching_paths
	 *
	 * @param string $path Request path.
	 */
	public function test_match_entry_returns_null_for_non_entry_paths( string $path ) {
		$this->assertNull( match_entry( $path, self::BASE, self::RESERVED, self::PATTERN ), "Expected {$path} to fall through." );
	}

	/**
	 * A base occurring MID-PATH must not anchor-match — the 12 Jul 2026 prod
	 * bypass: /accommodation/<fake>/page/2/ contains the `page` entry's base as a
	 * substring, the loader's strpos pre-check passes, and match_entry correctly
	 * fails here at the anchor. The loader must treat that null as "try the next
	 * base/entry" (continue), NEVER a full return — returning skipped every later
	 * entry and let the fake URL through unshielded. Anchoring is asserted for
	 * both prefix modes and for blocked bases.
	 */
	public function test_mid_path_base_occurrence_does_not_match() {
		// no-prefix mode (single-language): `page` mid-path.
		$this->assertNull( match_entry( '/accommodation/zzz-fake/page/2/', 'page', [], '' ) );
		// prefixed mode: `page` mid-path after a locale + another base.
		$this->assertNull( match_entry( '/en-au/team/zzz-fake/page/2/', 'page', [], self::PATTERN ) );
		// the RIGHT entry still claims the same URI at its anchor.
		$match = match_entry( '/en-au/team/zzz-fake/page/2/', self::BASE, self::RESERVED, self::PATTERN );
		$this->assertSame( 'zzz-fake', $match['slug'] );
		$this->assertSame( [ 'page', '2' ], $match['extra'] );
		// blocked bases anchor the same way.
		$this->assertNull( match_blocked_base( '/en-au/stories/old-section/', 'old-section', self::PATTERN ) );
	}

	/**
	 * A blocked base (mode=block) matches the bare base, with or without the
	 * trailing slash, and at any depth — capturing the locale for the themed 404.
	 */
	public function test_match_blocked_base_matches_base_and_any_depth() {
		$this->assertSame( [ 'locale' => 'cs-cz' ], match_blocked_base( '/cs-cz/old-section', 'old-section', self::PATTERN ) );
		$this->assertSame( [ 'locale' => 'cs-cz' ], match_blocked_base( '/cs-cz/old-section/', 'old-section', self::PATTERN ) );
		$this->assertSame( [ 'locale' => 'global' ], match_blocked_base( '/global/old-section/deep/path', 'old-section', self::PATTERN ) );
	}

	/**
	 * A blocked base in no-prefix mode matches bare URLs with locale `default`.
	 */
	public function test_match_blocked_base_no_prefix_mode() {
		$this->assertSame( [ 'locale' => 'default' ], match_blocked_base( '/old-section/', 'old-section', '' ) );
		$this->assertSame( [ 'locale' => 'default' ], match_blocked_base( '/old-section/deep', 'old-section', '' ) );
		$this->assertNull( match_blocked_base( '/cs-cz/old-section/', 'old-section', '' ) );
	}

	/**
	 * Non-matches fall through: different base, prefix-overlap slugs, no locale.
	 *
	 * @dataProvider provide_non_matching_blocked_paths
	 *
	 * @param string $path Request path.
	 */
	public function test_match_blocked_base_returns_null_for_non_matches( string $path ) {
		$this->assertNull( match_blocked_base( $path, 'old-section', self::PATTERN ), "Expected {$path} to fall through." );
	}

	/**
	 * Paths a blocked base must NOT swallow.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_non_matching_blocked_paths(): array {
		return [
			'prefix overlap'       => [ '/cs-cz/old-section-adapter/' ],
			'different base'       => [ '/cs-cz/team/old-section/' ],
			'no locale segment'    => [ '/old-section/' ],
			'bad locale'           => [ '/wp-admin/old-section/' ],
			'base as deeper part'  => [ '/cs-cz/products/old-section/' ],
			'root'                 => [ '/' ],
		];
	}

	/**
	 * Membership matches whole lines only (first, last, and interior).
	 */
	public function test_is_allowed_matches_whole_lines_only() {
		$body = "jane-doe\neric-bouvet\nbert-stephani\n";

		$this->assertTrue( is_allowed( 'jane-doe', $body ), 'First line should match.' );
		$this->assertTrue( is_allowed( 'bert-stephani', $body ), 'Last line should match.' );
		$this->assertTrue( is_allowed( 'eric-bouvet', $body ), 'Interior line should match.' );
		$this->assertFalse( is_allowed( 'zzz-not-real', $body ), 'Absent slug should not match.' );
		$this->assertFalse( is_allowed( 'eric', $body ), 'Prefix substring must not match a whole line.' );
		$this->assertFalse( is_allowed( 'jane-doe', '' ), 'Empty body should not match.' );
	}

	/**
	 * is_allowed accepts the RAW allowlist file (guard line included) so the
	 * loader can pass the buffer straight in without copying the body out. Any
	 * slug after the newline-terminated guard still matches; words inside the
	 * guard line itself do not.
	 */
	public function test_is_allowed_matches_slugs_in_a_raw_guarded_file() {
		$raw = "<?php exit; // post-404-shield allowlist — do not edit by hand.\n"
			. "jane-doe\neric-bouvet\nbert-stephani\n";

		$this->assertTrue( is_allowed( 'jane-doe', $raw ), 'First slug after the guard should match.' );
		$this->assertTrue( is_allowed( 'bert-stephani', $raw ), 'Last slug should match.' );
		$this->assertTrue( is_allowed( 'eric-bouvet', $raw ), 'Interior slug should match.' );
		$this->assertFalse( is_allowed( 'exit', $raw ), 'A word inside the guard line must not match.' );
		$this->assertFalse( is_allowed( 'zzz-not-real', $raw ), 'Absent slug should not match.' );
	}

	/**
	 * Paths that must NOT be matched (fall through / fail-open).
	 *
	 * @return array<string, array{0:string}>
	 */
	public function provide_non_matching_paths(): array {
		return [
			'reserved page (slash)'   => [ '/global/team/member/' ],
			'reserved page (bare)'    => [ '/global/team/member' ],
			'uppercase slug'          => [ '/global/team/Jane-Doe/' ],
			'percent-encoded (CJK)'   => [ '/global/team/%e9%8b%a4%e7%94%b0/' ],
			'underscore not allowed'  => [ '/global/team/jane_doe/' ],
			'wrong base'              => [ '/global/member/jane-doe/' ],
			'no locale segment'       => [ '/team/jane-doe/' ],
			'bad locale (not xx-xx)'  => [ '/wp-admin/team/jane-doe/' ],
			'three-letter locale'     => [ '/fra/team/jane-doe/' ],
			'root'                    => [ '/' ],
			'empty'                   => [ '' ],
			'homepage locale'         => [ '/global/' ],
		];
	}

	/**
	 * Only an explicit, at-least-as-preferred text/markdown counts; browsers
	 * (html first, any-type wildcard) never do.
	 *
	 * @dataProvider provide_accept_headers
	 *
	 * @param string $accept Accept header.
	 * @param bool   $expect Whether Markdown is preferred.
	 */
	public function test_prefers_markdown( string $accept, bool $expect ) {
		$this->assertSame( $expect, prefers_markdown( $accept ) );
	}

	/**
	 * Accept headers and whether each prefers Markdown.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provide_accept_headers(): array {
		return [
			'plain markdown'           => [ 'text/markdown', true ],
			'markdown first, upper'    => [ 'TEXT/Markdown, text/html;q=0.9', true ],
			'equal preference'         => [ 'text/markdown, text/html', true ],
			'html preferred'           => [ 'text/html, text/markdown;q=0.5', false ],
			'xhtml preferred'          => [ 'application/xhtml+xml;q=1, text/markdown;q=0.8', false ],
			'markdown refused'         => [ 'text/markdown;q=0', false ],
			'browser'                  => [ 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false ],
			'wildcard only'            => [ '*/*', false ],
			'empty'                    => [ '', false ],
			'markdown beats low html'  => [ 'text/html;q=0.1, text/markdown;q=0.2', true ],
		];
	}
}
