<?php
/**
 * Unit tests for the post-404-shield PURE functions (no WordPress required).
 *
 * Run: php wp-content/build-tools/tests/php/shield-pure-functions.test.php
 * (host PHP or `ddev exec php …`). Exit 0 = green; any failure prints the
 * assertion and exits 1. Kept as plain PHP on purpose — the functions under
 * test are the pre-boot loader's dependencies and must stay runnable with
 * nothing but the language.
 *
 * File Path: wp-content/build-tools/tests/php/shield-pure-functions.test.php
 *
 * @package Post404Shield\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Function/LoadContext.php';

$failures = 0;
$checks   = 0;

/**
 * Assert equality, report on failure.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    What is being asserted.
 *
 * @return void
 */
function check( $expected, $actual, string $label ): void {
	global $failures, $checks;
	++$checks;
	if ( $expected !== $actual ) {
		++$failures;
		echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

// --- strip_trailing_sub_routes ----------------------------------------------

check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'page', '2' ] ), 'strip: page/N pair' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'feed', 'atom' ] ), 'strip: feed/format pair' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'attachment', '1' ] ), 'strip: core attachment/name marker (numeric)' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'attachment', 'photo-x' ] ), 'strip: core attachment/name marker (slug)' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'attachment', '1' ], false ), 'strip: attachment marker strips even with pagination off' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'feed' ] ), 'strip: trailing feed' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'embed' ] ), 'strip: trailing embed' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'trackback' ] ), 'strip: trailing trackback' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'comment-page-3' ] ), 'strip: comment-page-N' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', '2' ] ), 'strip: bare-numeric nextpage' );
check( [ 'a', 'b' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'b' ] ), 'strip: ordinary segments untouched' );
check( [ '2' ], Post404Shield\strip_trailing_sub_routes( [ '2' ] ), 'strip: single numeric segment is the slug, never stripped' );
check( [], Post404Shield\strip_trailing_sub_routes( [ 'page', '2' ] ), 'strip: pure pagination empties out' );
check( [], Post404Shield\strip_trailing_sub_routes( [ 'feed' ] ), 'strip: pure feed empties out' );
// Pagination OFF: pagination shapes count as ordinary segments; core sub-routes still strip.
check( [ 'a', 'page', '2' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'page', '2' ], false ), 'strip off: page/N kept' );
check( [ 'a', '2' ], Post404Shield\strip_trailing_sub_routes( [ 'a', '2' ], false ), 'strip off: bare numeric kept' );
check( [ 'a', 'comment-page-3' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'comment-page-3' ], false ), 'strip off: comment-page kept' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'feed' ], false ), 'strip off: feed still strips' );
check( [ 'a' ], Post404Shield\strip_trailing_sub_routes( [ 'a', 'embed' ], false ), 'strip off: embed still strips' );
check( [ 'about', 'team' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'team', 'rss2' ] ), 'strip: bare feed format (rss2)' );
check( [ 'about' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'atom' ] ), 'strip: bare feed format (atom)' );
check( [], Post404Shield\strip_trailing_sub_routes( [ 'rss2' ] ), 'strip: a lone feed format is the site feed, stripped (passes)' );
check( [ 'about' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'attachment', '1234', 'embed' ] ), 'strip: an attachment page embed' );
check( [ 'about' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'attachment', '1234', 'feed' ] ), 'strip: an attachment page comments feed' );
check( [ 'my-account' ], Post404Shield\strip_trailing_sub_routes( [ 'my-account', 'orders', '2' ], true, [ 'orders', 'amp' ] ), 'strip: endpoint with a value' );
check( [ 'about' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'amp' ], true, [ 'amp' ] ), 'strip: bare endpoint' );
check( [ 'amp', 'x' ], Post404Shield\strip_trailing_sub_routes( [ 'amp', 'x' ], true, [ 'amp' ] ), 'strip: endpoint name as the FIRST segment is content, kept' );
check( [ 'about', 'amp' ], Post404Shield\strip_trailing_sub_routes( [ 'about', 'amp' ] ), 'strip: no endpoints configured, nothing stripped' );

// --- excluded_base_is_valid ---------------------------------------------------

check( true, Post404Shield\excluded_base_is_valid( 'wp-admin' ), 'excluded: plain' );
check( true, Post404Shield\excluded_base_is_valid( 'robots.txt' ), 'excluded: dotted file' );
check( true, Post404Shield\excluded_base_is_valid( '.well-known' ), 'excluded: leading dot' );
check( true, Post404Shield\excluded_base_is_valid( 'sitemap*' ), 'excluded: trailing wildcard' );
check( true, Post404Shield\excluded_base_is_valid( 'wp_stream_alerts' ), 'excluded: underscore' );
check( true, Post404Shield\excluded_base_is_valid( 'a/b' ), 'excluded: multi-segment' );
check( true, Post404Shield\excluded_base_is_valid( 'a/b*' ), 'excluded: multi-segment wildcard' );
check( false, Post404Shield\excluded_base_is_valid( '..' ), 'excluded: traversal rejected' );
check( false, Post404Shield\excluded_base_is_valid( 'a/../b' ), 'excluded: embedded traversal rejected' );
check( false, Post404Shield\excluded_base_is_valid( '/lead' ), 'excluded: leading slash rejected' );
check( false, Post404Shield\excluded_base_is_valid( 'trail/' ), 'excluded: trailing slash rejected' );
check( false, Post404Shield\excluded_base_is_valid( '*' ), 'excluded: bare wildcard rejected' );
check( false, Post404Shield\excluded_base_is_valid( 'UPPER' ), 'excluded: uppercase rejected' );
check( false, Post404Shield\excluded_base_is_valid( '' ), 'excluded: empty rejected' );
check( false, Post404Shield\excluded_base_is_valid( 42 ), 'excluded: non-string rejected' );

// --- permalink_post_base ------------------------------------------------------

check( [ 'supported' => true, 'base' => '' ], Post404Shield\permalink_post_base( '/%postname%/' ), 'permalink: bare structure = root-dweller' );
check( [ 'supported' => true, 'base' => 'blog' ], Post404Shield\permalink_post_base( '/blog/%postname%/' ), 'permalink: static base' );
check( [ 'supported' => true, 'base' => 'blog/archive' ], Post404Shield\permalink_post_base( '/blog/archive/%postname%/' ), 'permalink: multi-segment static base' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '/%category%/%postname%/' ), 'permalink: dynamic tag before postname unsupported' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '/%year%/%monthnum%/%postname%/' ), 'permalink: date structure unsupported' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '' ), 'permalink: plain permalinks unsupported' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '/blog/%postname%.html' ), 'permalink: suffixed postname unsupported' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '/%postname%/%post_id%/' ), 'permalink: trailing tag unsupported' );
check( [ 'supported' => false, 'base' => '' ], Post404Shield\permalink_post_base( '/blog/' ), 'permalink: no postname tag unsupported' );

// --- rewrite_pattern_base (custom-route derivation) ---------------------------

check( 'schema-preview', Post404Shield\rewrite_pattern_base( 'schema-preview(/(.*))?/?$' ), 'rewrite: root-anchored plugin route' );
check( 'schema-preview', Post404Shield\rewrite_pattern_base( '^schema-preview(/(.*))?/?$' ), 'rewrite: leading caret stripped' );
check( 'category', Post404Shield\rewrite_pattern_base( 'category/(.+?)/schema-preview/?$' ), 'rewrite: literal first segment before slash' );
check( 'robots.txt', Post404Shield\rewrite_pattern_base( 'robots\.txt$' ), 'rewrite: escaped dot = literal file route' );
check( 'index.php', Post404Shield\rewrite_pattern_base( 'index\.php/foo' ), 'rewrite: escaped-dot core route' );
check( 'sitemap_index.xml', Post404Shield\rewrite_pattern_base( 'sitemap_index\.xml$' ), 'rewrite: underscore + escaped dot' );
check( 'amp', Post404Shield\rewrite_pattern_base( 'amp/?$' ), 'rewrite: AMP endpoint' );
check( '', Post404Shield\rewrite_pattern_base( '(.?.+?)/schema-preview(/(.*))?/?$' ), 'rewrite: capture-group prefix = content space, NOT excluded' );
check( '', Post404Shield\rewrite_pattern_base( '([^/]+)/attachment/([^/]+)/?$' ), 'rewrite: char-class prefix yields nothing' );
check( '', Post404Shield\rewrite_pattern_base( '(.?.+?)(?:/([0-9]+))?/?$' ), 'rewrite: the page catch-all yields nothing' );
check( '', Post404Shield\rewrite_pattern_base( '' ), 'rewrite: empty pattern' );
check( 'feed', Post404Shield\rewrite_pattern_base( 'feed/(feed|rdf|rss|rss2|atom)/?$' ), 'rewrite: feed base' );
check( 'wc-api', Post404Shield\rewrite_pattern_base( 'wc-api/v([1-3]{1})/?$' ), 'rewrite: WooCommerce API endpoint' );
check( 'event-*', Post404Shield\rewrite_pattern_base( '^event-([0-9]+)/?$' ), 'rewrite: mid-segment cut is a prefix' );
check( 'event*', Post404Shield\rewrite_pattern_base( 'events?/([^/]+)/?$' ), 'rewrite: optional trailing char dropped, prefix kept' );
check( 'colo*', Post404Shield\rewrite_pattern_base( 'colou?r/(.+)$' ), 'rewrite: optional inner char dropped' );
check( 'schema-preview', Post404Shield\rewrite_pattern_base( 'schema-preview(?:/(.*))?/?$' ), 'rewrite: non-capturing group opening with a separator is a boundary' );
check( 'exact-route', Post404Shield\rewrite_pattern_base( 'exact-route$' ), 'rewrite: end anchor is exact, no prefix' );
check( 'old-*', Post404Shield\rewrite_pattern_base( '^old-\\d+/?$' ), 'rewrite: a class escape is not a literal letter' );

// --- redirect_pattern_base (redirect-plugin source reduction) -----------------

// Plain sources: the WHOLE path is kept (multi-segment) — a redirect source
// must never over-exclude its parent tree.
check( 'old-page', Post404Shield\redirect_pattern_base( '/old-page/', false ), 'redirect: plain single segment' );
check( 'services/old-thing', Post404Shield\redirect_pattern_base( '/services/old-thing/', false ), 'redirect: plain MULTI-segment kept whole (no over-exclusion)' );
check( 'contact-us', Post404Shield\redirect_pattern_base( 'contact-us', false ), 'redirect: plain, no slashes' );
check( 'news', Post404Shield\redirect_pattern_base( '/news/*', false ), 'redirect: BOUNDARY wildcard → whole segment (children via segment-prefix)' );
// INTRA-segment wildcards / starts-with must emit a trailing `*` or the
// redirect source is silently pre-boot-404'd (the confirmed fail-closed bug).
check( 'product-*', Post404Shield\redirect_pattern_base( '/product-*', false ), 'redirect: INTRA-segment wildcard → trailing * (prefix-matches /product-123/)' );
check( 'promo*', Post404Shield\redirect_pattern_base( 'promo*', false ), 'redirect: starts-with (Rank Math start → pattern.*) → promo*' );
check( 'downloads/file-*', Post404Shield\redirect_pattern_base( '/downloads/file-*', false ), 'redirect: multi-segment intra-segment wildcard' );
// Regex sources: leading literal run, escapes honoured, stop at first metachar.
check( 'news', Post404Shield\redirect_pattern_base( '^/news/(.+)$', true ), 'redirect: regex, boundary group → whole segment' );
check( 'product-*', Post404Shield\redirect_pattern_base( '^/product-.*$', true ), 'redirect: regex MID-segment → trailing *' );
check( 'our-services', Post404Shield\redirect_pattern_base( '^/our-services/?$', true ), 'redirect: regex, trailing /?$ trimmed' );
check( 'products/legacy', Post404Shield\redirect_pattern_base( '^/products/legacy/[0-9]+/?$', true ), 'redirect: regex MULTI-segment literal prefix kept' );
check( 'old.html', Post404Shield\redirect_pattern_base( '^/old\.html$', true ), 'redirect: regex escaped dot = literal file' );
check( '', Post404Shield\redirect_pattern_base( '^/(.+)-old/?$', true ), 'redirect: regex with no literal prefix → empty (not excluded)' );
check( '', Post404Shield\redirect_pattern_base( '', false ), 'redirect: empty source' );
check( '', Post404Shield\redirect_pattern_base( '/', false ), 'redirect: bare slash → empty' );
// A zero-or-more quantifier makes the char before it optional: `colou?r`
// matches `color`, so the prefix must stop before the `u`.
check( 'colo*', Post404Shield\redirect_pattern_base( '^colou?r-guide/?$', true ), 'redirect: `?` drops the optional char before it' );
check( 'old-page*', Post404Shield\redirect_pattern_base( '^old-pages?/(.*)$', true ), 'redirect: trailing optional `s` dropped' );
check( 'news*', Post404Shield\redirect_pattern_base( '^/news-?(.*)$', true ), 'redirect: optional hyphen dropped (covers /newsletter)' );
check( 'trail-*', Post404Shield\redirect_pattern_base( '^trail-+$', true ), 'redirect: `+` keeps the char (at least one)' );
check( 'news', Post404Shield\redirect_pattern_base( '^news/?$', true ), 'redirect: `/?` after a separator drops nothing' );
check( 'faq*', Post404Shield\redirect_pattern_base( 'faq?x=1', false ), 'redirect: plain-source `?` is not a quantifier (no char dropped)' );
check( 'sale-*', Post404Shield\redirect_pattern_base( '^/sale\\-', true ), 'redirect: unanchored, ending in an escaped literal: a prefix' );
check( 'shop*', Post404Shield\rewrite_pattern_base( '^shop' ), 'rewrite: literal to its end is a prefix (rules anchor at the start only)' );
check( [ 'stories/old/?$' ], Post404Shield\redirect_source_variants( '^/([a-z]{2}-[a-z]{2})/stories/old/?$', true, '[a-z]{2}-[a-z]{2}|global' ), 'variants: a locale capture written differently is stripped' );
check( [ 'stories/y/?$' ], Post404Shield\redirect_source_variants( '^/(\\w{2}-\\w{2})/stories/y/?$', true, '[a-z]{2}-[a-z]{2}|global' ), 'variants: an escape-class locale group is stripped' );
check( [ '(.*)/stories/z/?$' ], Post404Shield\redirect_source_variants( '^/(.*)/stories/z/?$', true, '[a-z]{2}-[a-z]{2}|global' ), 'variants: a group that can span a slash is kept' );
check( true, Post404Shield\list_is_empty( "<?php exit;\n\n" ), 'empty list: guard + empty line' );
check( true, Post404Shield\list_is_empty( "<?php exit;\n" ), 'empty list: guard alone' );
check( false, Post404Shield\list_is_empty( "<?php exit;\n\nnew-slug\n" ), 'not empty: a first append to an empty list' );
check( false, Post404Shield\list_is_empty( "<?php exit;\na\n" ), 'not empty: one line' );
check( 'old-product-*', Post404Shield\redirect_pattern_base( '^/old-product-\\d+/?$', true ), 'redirect: \\d ends the literal run mid-segment' );
check( 'promo*', Post404Shield\redirect_pattern_base( '^/promo', true ), 'redirect: an unanchored literal regex is a prefix' );
check( 'promo', Post404Shield\redirect_pattern_base( '^/promo/', true ), 'redirect: an unanchored regex ending on a separator is the segment' );
check( 'a.b', Post404Shield\redirect_pattern_base( '^/a\\.b$', true ), 'redirect: an escaped dot stays literal' );

// --- redirect_source_variants (locale strip + alternation expansion) ----------

$lp = '[a-z]{2}-[a-z]{2}|global';
// This site's house idiom: a leading locale capture group. Without the strip
// the reducer stops at `(` and the redirect is never reserved.
check( [ 'products/shoes/trail-3-2/?$' ], Post404Shield\redirect_source_variants( '^([a-z]{2}-[a-z]{2}|global)/products/shoes/trail-3-2/?$', true, $lp ), 'variants: locale group equal to the configured pattern is stripped' );
check( [ 'stories/x/?$' ], Post404Shield\redirect_source_variants( '^(?:en-us|en-gb)/stories/x/?$', true, $lp ), 'variants: non-capturing group of literal locales stripped' );
check( [ 'stories/x/?$' ], Post404Shield\redirect_source_variants( '^(?:en-us/)?stories/x/?$', true, $lp ), 'variants: optional locale segment stripped' );
check( [ 'foo/x', 'bar/x' ], Post404Shield\redirect_source_variants( '(foo|bar)/x', true, $lp ), 'variants: a non-locale leading group is NOT stripped (expanded instead)' );
check( [ '(en-us|Nope1)/x' ], Post404Shield\redirect_source_variants( '(en-us|Nope1)/x', true, $lp ), 'variants: a group mixing a locale with a non-literal is left alone' );
check( [ 'special/imaging-solution/' ], Post404Shield\redirect_source_variants( 'ja-jp/special/imaging-solution/', false, $lp ), 'variants: literal locale segment stripped from a plain source' );
check( [ 'special/x/?$' ], Post404Shield\redirect_source_variants( '^global/special/x/?$', true, $lp ), 'variants: literal locale segment stripped from a regex' );
check( [ 'about-us/' ], Post404Shield\redirect_source_variants( '/about-us/', false, '' ), 'variants: no locale pattern → unchanged' );
check( [ 'ja-jp/x/' ], Post404Shield\redirect_source_variants( 'ja-jp/x/', false, '' ), 'variants: no locale pattern → locale-looking segment kept' );
// Pure-literal alternations expand; anything else is left to the reducer.
check( [ 'stories/video/?$', 'stories/tips/?$' ], Post404Shield\redirect_source_variants( '^([a-z]{2}-[a-z]{2}|global)/stories/(video|tips)/?$', true, $lp ), 'variants: literal alternation expanded after the locale strip' );
check( [ 'stories/(video|tips)?/?$' ], Post404Shield\redirect_source_variants( '^stories/(video|tips)?/?$', true, $lp ), 'variants: quantified group NOT expanded' );
check( [ 'guides/(guide-.+?)/?$' ], Post404Shield\redirect_source_variants( '^guides/(guide-.+?)/?$', true, $lp ), 'variants: non-literal group NOT expanded' );
check( [ 'a/x', 'a/' ], Post404Shield\redirect_source_variants( 'a/(x|)', true, $lp ), 'variants: empty alternative kept' );
// End to end: variants → reducer, as ConfigStore runs them.
$reduce = static function ( string $src, bool $rx ) use ( $lp ): array {
	return array_map( static fn( $v ) => Post404Shield\redirect_pattern_base( $v, $rx ), Post404Shield\redirect_source_variants( $src, $rx, $lp ) );
};
check( [ 'products/shoes/trail-3-2' ], $reduce( '^([a-z]{2}-[a-z]{2}|global)/products/shoes/trail-3-2/?$', true ), 'e2e: locale-group redirect reduces to its real path' );
check( [ 'products/accessories/finder', 'products/accessories/grip' ], $reduce( '^([a-z]{2}-[a-z]{2}|global)/products/accessories/(finder|grip)/?$', true ), 'e2e: alternation yields one path per alternative' );
check( [ 'products/shoes/accessories-*' ], $reduce( '^([a-z]{2}-[a-z]{2}|global)/products/shoes/accessories-[1-9]/?$', true ), 'e2e: class mid-segment → prefix' );
check( [ 'special/imaging-solution' ], $reduce( 'ja-jp/special/imaging-solution/', false ), 'e2e: literal-locale source loses its locale (root exclusions compare locale-stripped)' );

// The trailing-* base must validate + prefix-match the final segment in match_root.
check( true, Post404Shield\excluded_base_is_valid( 'product-*' ), 'redirect: product-* is a valid excluded base' );
check( true, Post404Shield\excluded_base_is_valid( 'downloads/file-*' ), 'redirect: multi-segment trailing-* valid' );
$rx_entries = [ [ 'type' => 'page', 'allow_pagination' => true, 'body' => "<?php exit;\ncontact\n" ] ];
check( 'pass:', root_outcome( '/product-123/', $rx_entries, [ 'product-*' ] ), 'redirect: /product-123/ excluded by product-* (prefix) → passes to WP' );
check( 'blocked:page', root_outcome( '/product-123/', $rx_entries, [ 'product' ] ), 'redirect: base "product" (no *) does NOT exclude /product-123/ (the bug, without the fix)' );

// --- match_root ---------------------------------------------------------------

$page_body = "<?php exit;\ncontact\nabout-us\nabout-us/meet-our-team\nlatest\n";
$post_body = "<?php exit;\nbetter-support\npolicy-reform\n";
$xtra_body = "<?php exit;\nold-slug\nabout-us/old-child\nsome-image_01\n";
$entries   = [
	[
		'type'             => 'page',
		'allow_pagination' => true,
		'body'             => $page_body,
	],
	[
		'type'             => 'post',
		'allow_pagination' => true,
		'body'             => $post_body,
	],
	[
		'type'             => 'root-extras',
		'allow_pagination' => true,
		'body'             => $xtra_body,
	],
];
$excluded  = [ 'page', 'author', 'category', 'tag', 'search', 'feed', 'comments', 'embed', 'team', 'sitemap*', 'robots.txt' ];

/**
 * Shorthand: run match_root and return "outcome:type".
 *
 * @param string $path            Path.
 * @param array  $custom_entries  Candidates.
 * @param array  $custom_excluded Exclusions.
 * @param string $locale          Locale pattern.
 *
 * @return string
 */
function root_outcome( string $path, array $custom_entries, array $custom_excluded, string $locale = '' ): string {
	$result = Post404Shield\match_root( $path, $custom_excluded, $custom_entries, $locale );
	return $result['outcome'] . ':' . $result['type'];
}

// Pass class.
check( 'pass:', root_outcome( '/', $entries, $excluded ), 'root: site root passes' );
check( 'pass:', root_outcome( '/wp-admin/anything/', $entries, $excluded ), 'root: hardcoded floor passes' );
check( 'pass:', root_outcome( '/wp-json/wp/v2/', $entries, $excluded ), 'root: wp-json floor passes' );
check( 'pass:', root_outcome( '/2026/', $entries, $excluded ), 'root: year archive passes' );
check( 'pass:', root_outcome( '/2/', $entries, $excluded ), 'root: front-page nextpage passes' );
check( 'pass:', root_outcome( '/page/2/', $entries, $excluded ), 'root: pagination namespace excluded' );
check( 'pass:', root_outcome( '/author/annabelle/', $entries, $excluded ), 'root: author excluded' );
check( 'pass:', root_outcome( '/sitemap_index.xml', $entries, $excluded ), 'root: dotted path passes (charset guard)' );
check( 'pass:', root_outcome( '/sitemapfoo/', $entries, $excluded ), 'root: wildcard exclusion prefix-matches' );
check( 'pass:', root_outcome( '/Contact/', $entries, $excluded ), 'root: uppercase passes (charset guard)' );
check( 'pass:', root_outcome( '/autisti%e2%80%8bc/', $entries, $excluded ), 'root: percent-encoded passes (charset guard)' );
check( 'pass:', root_outcome( '/team/zzz/', $entries, $excluded ), 'root: based-entry namespace excluded (fall-through never reaches root)' );
check( 'pass:', root_outcome( '/feed/', $entries, $excluded ), 'root: feed excluded' );

// Allowed class.
check( 'allowed:page', root_outcome( '/contact/', $entries, $excluded ), 'root: top-level page allowed' );
check( 'allowed:page', root_outcome( '/about-us/meet-our-team/', $entries, $excluded ), 'root: nested page allowed' );
check( 'allowed:post', root_outcome( '/better-support/', $entries, $excluded ), 'root: post slug allowed' );
check( 'allowed:root-extras', root_outcome( '/old-slug/', $entries, $excluded ), 'root: old slug allowed via extras' );
check( 'allowed:root-extras', root_outcome( '/some-image_01/', $entries, $excluded ), 'root: underscore attachment slug allowed' );
check( 'allowed:page', root_outcome( '/latest/page/2/', $entries, $excluded ), 'root: posts-page pagination stripped then allowed' );
check( 'allowed:post', root_outcome( '/better-support/2/', $entries, $excluded ), 'root: nextpage stripped then allowed' );
check( 'allowed:page', root_outcome( '/contact/feed/', $entries, $excluded ), 'root: page feed stripped then allowed' );

check( 'allowed:post', root_outcome( '/better-support/attachment/1/', $entries, $excluded ), 'root: attachment-marker URL passes with its parent' );

// Blocked class (type = first candidate: page).
check( 'blocked:page', root_outcome( '/casd/', $entries, $excluded ), 'root: fake top-level blocked' );
check( 'blocked:page', root_outcome( '/casd/attachment/1/', $entries, $excluded ), 'root: attachment marker under a FAKE parent still blocks' );
check( 'blocked:page', root_outcome( '/contact/asd/', $entries, $excluded ), 'root: fake child of real page blocked' );
check( 'blocked:page', root_outcome( '/zzz/deep/deeper/', $entries, $excluded ), 'root: deep fake blocked' );

// Pagination checkbox OFF: pagination segments count as ordinary segments.
$strict = $entries;
foreach ( $strict as $i => $e ) {
	$strict[ $i ]['allow_pagination'] = false;
}
check( 'blocked:page', root_outcome( '/latest/page/2/', $strict, $excluded ), 'root strict: page/N no longer stripped → blocked' );
check( 'blocked:page', root_outcome( '/better-support/2/', $strict, $excluded ), 'root strict: bare numeric no longer stripped → blocked' );
check( 'allowed:page', root_outcome( '/contact/feed/', $strict, $excluded ), 'root strict: core feed still stripped' );
check( 'pass:', root_outcome( '/page/2/', $strict, $excluded ), 'root strict: excluded namespace still passes' );

// Locale composability (the multilingual shape).
check( 'allowed:page', root_outcome( '/en-au/contact/', $entries, $excluded, '[a-z]{2}-[a-z]{2}' ), 'root locale: prefixed path allowed' );
check( 'pass:', root_outcome( '/contact/', $entries, $excluded, '[a-z]{2}-[a-z]{2}' ), 'root locale: bare path falls through when prefix required' );
check( 'blocked:page', root_outcome( '/en-au/casd/', $entries, $excluded, '[a-z]{2}-[a-z]{2}' ), 'root locale: prefixed fake blocked' );

// No usable candidates: inert.
check( 'pass:', root_outcome( '/casd/', [], $excluded ), 'root: zero candidates = inert' );
check(
	'pass:',
	root_outcome(
		'/casd/',
		[
			[
				'type'             => 'page',
				'allow_pagination' => true,
				'body'             => '',
			],
		],
		$excluded
	),
	'root: empty-body candidates = inert (an empty list can never justify a block)'
);

// Multi-segment exclusion.
check( 'pass:', root_outcome( '/support/compat/x/', $entries, [ 'support/compat' ] ), 'root: multi-segment exclusion prefix-matches' );
check( 'blocked:page', root_outcome( '/support/other/', $entries, [ 'support/compat' ] ), 'root: multi-segment exclusion is segment-exact' );

// --- config_is_valid (root entries + excluded_bases) ---------------------------

$base_doc = [
	'version' => 1,
	'locale'  => [
		'mode'    => 'none',
		'pattern' => '',
	],
	'entries' => [],
];

$valid_root = $base_doc;
$valid_root['entries']['page'] = [
	'enabled'          => true,
	'mode'             => 'allowlist',
	'post_type'        => 'page',
	'root'             => true,
	'url_base'         => [],
	'post_status'      => [ 'publish' ],
	'match'            => 'full-path',
	'allow_pagination' => true,
];
$valid_root['excluded_bases'] = [
	'floor'    => [ 'wp-admin', 'robots.txt', 'sitemap*' ],
	'derived'  => [ 'page', 'author' ],
	'operator' => [ 'ads.txt' ],
];
check( true, Post404Shield\config_is_valid( $valid_root ), 'config: root entry + excluded_bases valid' );

$bad = $valid_root;
$bad['entries']['page']['url_base'] = [ 'page' ];
check( false, Post404Shield\config_is_valid( $bad ), 'config: root entry with a base rejected' );

$bad = $valid_root;
$bad['entries']['page']['match'] = 'slug';
check( false, Post404Shield\config_is_valid( $bad ), 'config: root entry with slug match rejected' );

$bad = $valid_root;
$bad['entries']['page']['mode'] = 'block';
check( false, Post404Shield\config_is_valid( $bad ), 'config: root block entry rejected' );

$bad = $valid_root;
$bad['entries']['page']['allow_pagination'] = 'yes';
check( false, Post404Shield\config_is_valid( $bad ), 'config: non-bool allow_pagination rejected' );

$bad = $valid_root;
$bad['excluded_bases']['operator'] = [ '../etc' ];
check( false, Post404Shield\config_is_valid( $bad ), 'config: traversal excluded base rejected' );

$bad = $valid_root;
$bad['excluded_bases'] = [ 'floor' => [] ];
check( false, Post404Shield\config_is_valid( $bad ), 'config: excluded_bases missing buckets rejected' );

$legacy = $base_doc;
$legacy['entries']['member'] = [
	'enabled'     => true,
	'mode'        => 'allowlist',
	'post_type'   => 'member',
	'url_base'    => [ 'team' ],
	'post_status' => [ 'publish' ],
	'match'       => 'full-path',
];
check( true, Post404Shield\config_is_valid( $legacy ), 'config: pre-root (v1-shape) document still valid' );

$bad = $legacy;
$bad['entries']['member']['url_base'] = [];
check( false, Post404Shield\config_is_valid( $bad ), 'config: based entry still requires a base' );

// --- match_entry / is_allowed regressions --------------------------------------

$m = Post404Shield\match_entry( '/team/some-slug/', 'team' );
check( 'some-slug', $m['slug'] ?? null, 'match_entry: unchanged happy path' );
check( null, Post404Shield\match_entry( '/team/Some_Slug/', 'team' ), 'match_entry: charset fall-through unchanged' );
check( true, Post404Shield\is_allowed( 'a_b', "<?php exit;\na_b\n" ), 'is_allowed: underscore line matches' );

// --- slug_is_reserved (derived reserved slugs, exact + prefix) -----------------

$res = [ 'member', 'summit-milano2025', 'trail-*' ];
check( true,  Post404Shield\slug_is_reserved( 'member', $res ),        'reserved: exact entry matches' );
check( true,  Post404Shield\slug_is_reserved( 'summit-milano2025', $res ), 'reserved: exact redirect source matches' );
check( true,  Post404Shield\slug_is_reserved( 'trail-5', $res ),                'reserved: prefix entry matches a family member' );
check( true,  Post404Shield\slug_is_reserved( 'trail-', $res ),              'reserved: prefix entry matches the bare prefix' );
check( false, Post404Shield\slug_is_reserved( 'road-half', $res ),               'reserved: unrelated slug not reserved' );
check( false, Post404Shield\slug_is_reserved( 'xt5', $res ),                 'reserved: prefix does not match across a missing hyphen' );
check( false, Post404Shield\slug_is_reserved( 'team', $res ),       'reserved: exact entry does not prefix-match' );
check( false, Post404Shield\slug_is_reserved( 'anything', [] ),              'reserved: empty list reserves nothing' );
check( false, Post404Shield\slug_is_reserved( 'ab', [ 'a*b' ] ),             'reserved: only a TRAILING star is a wildcard' );
check( false, Post404Shield\slug_is_reserved( 'anything', [ '*' ] ),         'reserved: a bare star reserves nothing' );
check( false, Post404Shield\slug_is_reserved( 'x', [ '', null ] ),           'reserved: empty/non-string entries ignored' );

// --- config_is_valid: disabled entries (L1) + reserved namespaces (F10) ---------

$entry = static function ( array $over = [] ): array {
	return array_merge(
		[
			'enabled'     => true,
			'mode'        => 'allowlist',
			'post_type'   => 'story',
			'url_base'    => [ 'stories' ],
			'post_status' => [ 'publish' ],
			'match'       => 'slug',
		],
		$over
	);
};
$doc_with = static function ( array $e ) use ( $base_doc ): array {
	$d = $base_doc;
	$d['entries']['t'] = $e;
	return $d;
};

// L1 — a DISABLED entry never matches, so its base can't make the doc invalid.
check( true,  Post404Shield\config_is_valid( $doc_with( $entry( [ 'enabled' => false, 'url_base' => [ 'Products/Cameras' ] ] ) ) ), 'L1: disabled entry with a malformed base is still a valid doc' );
check( true,  Post404Shield\config_is_valid( $doc_with( $entry( [ 'enabled' => false, 'url_base' => [] ] ) ) ),                   'L1: disabled entry with an empty base is still a valid doc' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'enabled' => false, 'url_base' => 'stories' ] ) ) ),            'L1: disabled entry keeps the SHAPE check (url_base must be an array)' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [ 'Products/Cameras' ] ] ) ) ),                    'L1: an ENABLED malformed base is still rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [] ] ) ) ),                                         'L1: an ENABLED entry still needs a base' );

// F10 — an ENABLED entry may not claim a WordPress-reserved namespace.
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'mode' => 'block', 'url_base' => [ 'wp-json' ] ] ) ) ),     'F10: enabled block entry on wp-json rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [ 'wp-admin' ] ] ) ) ),                       'F10: enabled allowlist entry on wp-admin rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [ 'page/sub' ] ] ) ) ),                       'F10: first segment decides — page/sub rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [ 'stories', 'feed' ] ] ) ) ),                'F10: one reserved base among several rejects the entry' );
check( true,  Post404Shield\config_is_valid( $doc_with( $entry( [ 'enabled' => false, 'mode' => 'block', 'url_base' => [ 'wp-json' ] ] ) ) ), 'F10: a DISABLED reserved base cannot match, so it is allowed' );
check( true,  Post404Shield\config_is_valid( $doc_with( $entry( [ 'url_base' => [ 'pages-archive' ] ] ) ) ),                  'F10: a base merely STARTING with a reserved word is fine' );
check( true,  Post404Shield\config_is_valid( $doc_with( $entry() ) ),                                                          'F10: an ordinary base is fine' );

// The canonical list itself.
check( true,  in_array( 'wp-json', Post404Shield\reserved_namespaces(), true ), 'reserved_namespaces(): contains wp-json' );
check( true,  in_array( 'page', Post404Shield\reserved_namespaces(), true ),    'reserved_namespaces(): contains page' );
check( true,  Post404Shield\base_is_reserved_namespace( 'wp-json/wp/v2' ),       'base_is_reserved_namespace: nested under wp-json' );
check( true,  Post404Shield\base_is_reserved_namespace( '/wp-admin/' ),          'base_is_reserved_namespace: slashes trimmed' );
check( false, Post404Shield\base_is_reserved_namespace( 'news' ),                'base_is_reserved_namespace: ordinary base' );

// reserved_derived is now charset-checked by the reader.
check( true,  Post404Shield\config_is_valid( $doc_with( $entry( [ 'reserved_derived' => [ 'trail-*', 'old_slug', 'summit-milano2025' ] ] ) ) ), 'reserved_derived: exact, underscore and trailing-star entries accepted' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'reserved_derived' => [ 'a*b' ] ] ) ) ),    'reserved_derived: mid-string star rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'reserved_derived' => [ '*' ] ] ) ) ),      'reserved_derived: bare star rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'reserved_derived' => [ '../x' ] ] ) ) ),   'reserved_derived: traversal rejected' );
check( false, Post404Shield\config_is_valid( $doc_with( $entry( [ 'reserved_derived' => 'trail-*' ] ) ) ),       'reserved_derived: must be an array' );

// --- locale_pattern_is_valid: open redirect + ReDoS (L2) ----------------------

foreach ( [ '[a-z]{2}-[a-z]{2}|global', '[a-z]{2}|global', '[a-z0-9]{2,5}', 'en|de|fr', '[a-z-]{2,10}' ] as $lp ) {
	check( true, Post404Shield\locale_pattern_is_valid( $lp ), "L2: real locale pattern still accepted: $lp" );
}
check( false, Post404Shield\locale_pattern_is_valid( '[,-z]{1,20}' ),        'L2: class range spanning / rejected (open redirect)' );
check( false, Post404Shield\locale_pattern_is_valid( '[,-z]{1,30}|global' ), 'L2: spanning range rejected inside an alternation too' );
check( false, Post404Shield\locale_pattern_is_valid( '[9-a]{2}' ),           'L2: cross-type range rejected' );
check( false, Post404Shield\locale_pattern_is_valid( '[z-a]{2}' ),           'L2: reversed range rejected' );
check( false, Post404Shield\locale_pattern_is_valid( '[a-z,]{2}' ),          'L2: non-locale character in a class rejected' );
check( false, Post404Shield\locale_pattern_is_valid( '[a-z' ),               'L2: unclosed class rejected' );
check( false, Post404Shield\locale_pattern_is_valid( '[a-z]{2}]' ),          'L2: stray ] rejected' );
check( false, Post404Shield\locale_pattern_is_valid( str_repeat( '[a-z]{0,20}', 6 ) ), 'L2: six chained quantifiers rejected (ReDoS)' );
check( false, Post404Shield\locale_pattern_is_valid( '[a-z]{0,20}' ),        'L2: a bound over 10 rejected' );
check( false, Post404Shield\locale_pattern_is_valid( '[a-z]{2,}' ),          'L2: an unbounded {n,} rejected' );
check( false, Post404Shield\locale_pattern_is_valid( str_repeat( '[a-z]{1}', 5 ) ), 'L2: five quantifiers rejected' );
check( true,  Post404Shield\locale_pattern_is_valid( str_repeat( '[a-z]{0,10}', 4 ) ), 'L2: the worst pattern still allowed is accepted' );

// ...and that worst allowed pattern really is cheap now.
$worst = str_repeat( '[a-z]{0,10}', 4 );
$t0    = microtime( true );
$rv    = @preg_match( '#^/(?<locale>' . $worst . ')/stories/#', '/' . str_repeat( 'a', 200 ) . '!/stories/x/' );
check( true, false !== $rv && ( microtime( true ) - $t0 ) < 0.05, 'L2: worst allowed pattern does not exhaust the backtrack limit' );

// The reader rejects a document carrying the spanning pattern, so the loader
// never builds a redirect from it (fail-open: the shield simply stays off).
$bad_locale                      = $base_doc;
$bad_locale['locale']            = [ 'mode' => 'custom', 'pattern' => '[,-z]{1,20}' ];
check( false, Post404Shield\config_is_valid( $bad_locale ), 'L2: config_is_valid rejects a document with the spanning pattern' );


// --- match_root: lazy allowlist bodies ------------------------------------------
// The loader passes each allowlist as a callable so a file is read only when the
// decision reaches it. These pin that: no read for a path the cheap checks settle,
// reading stops at the first hit, and a missing list (null) can never block.

/**
 * Build lazy candidates that record which loaders ran.
 *
 * @param array<string, ?string> $bodies Type => body (null = missing file).
 * @param array<int, string>     $calls  Filled with the types whose loader ran.
 *
 * @return array<int, array<string, mixed>>
 */
function lazy_entries( array $bodies, array &$calls ): array {
	$out = [];
	foreach ( $bodies as $type => $body ) {
		$out[] = [
			'type'             => $type,
			'allow_pagination' => true,
			'body'             => static function () use ( $type, $body, &$calls ): ?string {
				$calls[] = $type;
				return $body;
			},
		];
	}
	return $out;
}

$lazy_bodies = [
	'page'        => $page_body,
	'post'        => $post_body,
	'root-extras' => $xtra_body,
];

$calls = [];
check( 'pass:', root_outcome( '/wp-json/wp/v2/', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: floor path passes' );
check( [], $calls, 'lazy: floor path reads no allowlist' );

$calls = [];
check( 'pass:', root_outcome( '/sitemap_index.xml', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: dotted path passes' );
check( [], $calls, 'lazy: dotted path reads no allowlist' );

$calls = [];
check( 'pass:', root_outcome( '/author/annabelle/', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: excluded base passes' );
check( [], $calls, 'lazy: excluded base reads no allowlist' );

$calls = [];
check( 'allowed:page', root_outcome( '/contact/', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: page hit allowed' );
check( [ 'page' ], $calls, 'lazy: a page hit reads only the page allowlist' );

$calls = [];
check( 'allowed:post', root_outcome( '/better-support/', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: post hit allowed' );
check( [ 'page', 'post' ], $calls, 'lazy: a post hit stops before the extras' );

$calls = [];
check( 'blocked:page', root_outcome( '/casd/', lazy_entries( $lazy_bodies, $calls ), $excluded ), 'lazy: unknown slug blocked' );
check( [ 'page', 'post', 'root-extras' ], $calls, 'lazy: a block reads every allowlist, each once' );

$calls         = [];
$missing_post  = $lazy_bodies;
$missing_post['post'] = null;
check( 'pass:', root_outcome( '/casd/', lazy_entries( $missing_post, $calls ), $excluded ), 'lazy: a missing allowlist can never justify a block' );
check( 'allowed:page', root_outcome( '/contact/', lazy_entries( $missing_post, $calls ), $excluded ), 'lazy: a hit before the missing list still passes through' );

$calls         = [];
$empty_extras  = $lazy_bodies;
$empty_extras['root-extras'] = '';
check( 'blocked:page', root_outcome( '/casd/', lazy_entries( $empty_extras, $calls ), $excluded ), 'lazy: an empty extras file is legitimate and still lets a miss block' );

// A string body that happens to name a PHP function is data, never a loader.
$named = [
	[
		'type'             => 'page',
		'allow_pagination' => true,
		'body'             => 'strlen',
	],
];
check( 'blocked:page', root_outcome( '/casd/', $named, $excluded ), 'lazy: a string body is never called' );

// --- generator_needed -------------------------------------------------------------

/**
 * Shorthand for a GET/HEAD/POST request with no special context.
 *
 * @param string $uri    REQUEST_URI.
 * @param string $method REQUEST_METHOD.
 *
 * @return bool
 */
function gen_needed( string $uri, string $method = 'GET' ): bool {
	return Post404Shield\generator_needed(
		[
			'REQUEST_METHOD' => $method,
			'REQUEST_URI'    => $uri,
		],
		false,
		false,
		false,
		false,
		'wp-json'
	);
}

check( false, gen_needed( '/about-us/' ), 'generator: a page view skips it' );
check( false, gen_needed( '/' ), 'generator: the home page skips it' );
check( false, gen_needed( '/about-us/?utm_source=x' ), 'generator: a query string alone does not load it' );
check( false, gen_needed( '/about-us/', 'HEAD' ), 'generator: HEAD skips it' );
check( false, gen_needed( '/wp-login.php' ), 'generator: the login screen skips it' );
check( true, gen_needed( '/contact/', 'POST' ), 'generator: a front-end POST loads it (a form can create a post)' );
check( false, gen_needed( '/wp-json/wp/v2/pages/2' ), 'generator: a REST read by path skips it (the lazy hooks cover a write)' );
check( false, gen_needed( '/?rest_route=/wp/v2/pages' ), 'generator: a REST read by query skips it' );
check( true, gen_needed( '/wp-json/wp/v2/pages/2', 'PUT' ), 'generator: a REST write loads it' );
check( true, gen_needed( '/wp-json/wp/v2/pages/2?_method=DELETE' ), 'generator: a REST GET that overrides its method loads it' );
check( true, gen_needed( '/blog/wp-json/wp/v2/posts?_method=POST' ), 'generator: an override under a subdirectory install loads it' );
check( true, gen_needed( '/?rest_route=/wp/v2/pages/2&_method=DELETE' ), 'generator: an override by query route loads it' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-json/wp/v2/pages/2', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE' ], false, false, false, false, 'wp-json' ), 'generator: the override header loads it' );
check( false, gen_needed( '/about/?_method=DELETE' ), 'generator: _method outside REST means nothing' );
check( true, gen_needed( '/robots.txt' ), 'generator: robots.txt loads it (probe Disallow line)' );
check( true, gen_needed( '/en-au/post-shield-404-probe/?post_shield_bake=abc' ), 'generator: the bake probe loads it' );
check( false, gen_needed( '/wp-jsonx/' ), 'generator: a lookalike of the REST prefix does not load it' );
check( true, gen_needed( '' ), 'generator: an empty URI keeps the old behaviour' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_URI' => '/x/' ], true, false, false, false, 'wp-json' ), 'generator: wp-admin loads it' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_URI' => '/x/' ], false, true, false, false, 'wp-json' ), 'generator: a cron run loads it' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_URI' => '/x/' ], false, false, true, false, 'wp-json' ), 'generator: WP-CLI loads it' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_URI' => '/xmlrpc.php' ], false, false, false, true, 'wp-json' ), 'generator: XML-RPC loads it' );
check( true, Post404Shield\generator_needed( [ 'REQUEST_URI' => '/api/v1/x?_method=PUT' ], false, false, false, false, 'api' ), 'generator: a custom REST prefix is honoured' );

// --------------------------------------------------------------------------------

echo $failures > 0
	? "\n{$failures} of {$checks} assertions FAILED\n"
	: "OK — {$checks} assertions passed\n";
exit( $failures > 0 ? 1 : 0 );
