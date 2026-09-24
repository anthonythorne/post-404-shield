<?php
/**
 * Unit tests for the post 404 shield Static404Baker markup sanitiser.
 *
 * Covers the pure seam only: sanitize_markup(), which must strip every trace of
 * the bake probe path (the language switcher leaked it to crawlers as real
 * anchors) and the probe token from captured markup. The HTTP capture and
 * filesystem writes are exercised by the ddev e2e, not here.
 *
 * File Path: build-tools/tests/unit-testing/unit/PostShieldStatic404BakerTest.php
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\Static404Baker;

// Require ONLY the class file: it declares the class with no top-level side
// effects, and sanitize_markup() is a pure static.
require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/Static404Baker.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Controller/PostShield404Controller.php';

/**
 * Test class for Static404Baker::sanitize_markup().
 */
class PostShieldStatic404BakerTest extends TestCase {

	/**
	 * Language-switcher anchors pointing at the probe path are rewritten to the
	 * locale homepage, with and without the trailing slash, across locales.
	 *
	 * @return void
	 */
	public function test_sanitize_rewrites_probe_links_to_locale_home() {
		$html = '<a href="https://www.example.com/pt-br/post-shield-404-probe/">Brazil</a>'
			. '<a href="https://www.example.com/ko-kr/post-shield-404-probe">Korea</a>'
			. '<a href="https://www.example.com/global/team/">Real link</a>';

		$clean = Static404Baker::sanitize_markup( $html );

		$this->assertStringNotContainsString( 'post-shield-404-probe', $clean, 'No probe reference may survive.' );
		$this->assertStringContainsString( 'https://www.example.com/pt-br/"', $clean, 'Trailing-slash link → locale home.' );
		$this->assertStringContainsString( 'https://www.example.com/ko-kr/"', $clean, 'Bare link → locale home.' );
		$this->assertStringContainsString( '/global/team/', $clean, 'Unrelated links untouched.' );
	}

	/**
	 * The robots.txt rule covers the probe under a locale and at the root
	 * (locale mode none), read the way crawlers read `*`.
	 *
	 * @return void
	 */
	public function test_robots_rule_covers_every_probe_shape(): void {
		$robots = ( new \ReflectionClass( \Post404Shield\Controller\PostShield404Controller::class ) )->newInstanceWithoutConstructor()->disallow_probe_path( '' );
		$this->assertSame( 1, preg_match( '/^Disallow: (\S+)$/m', $robots, $rule ) );
		$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $rule[1], '#' ) ) . '#';
		$this->assertMatchesRegularExpression( $pattern, '/pt-br/post-shield-404-probe/' );
		$this->assertMatchesRegularExpression( $pattern, '/post-shield-404-probe/', 'Locale mode none probes at the root.' );
		$this->assertDoesNotMatchRegularExpression( $pattern, '/global/about/' );
	}

	/**
	 * The probe token is scrubbed wherever it appears; an empty token is a no-op.
	 *
	 * @return void
	 */
	public function test_sanitize_scrubs_token() {
		$token = 'aabbccddeeff00112233445566778899';
		$html  = '<meta data-x="?post_shield_bake=' . $token . '"><p>body</p>';

		$this->assertStringNotContainsString( $token, Static404Baker::sanitize_markup( $html, $token ) );
		$this->assertSame( $html, Static404Baker::sanitize_markup( $html, '' ), 'Empty token: markup unchanged.' );
	}

	/**
	 * Locales come from the site's own pattern, not a hard-coded shape. A site
	 * with two-letter codes used to bake nothing but the default page.
	 *
	 * @return void
	 */
	public function test_select_locales_follows_the_configured_pattern(): void {
		$codes = [ 'en', 'fr', 'de-de', 'Bad Code', '../etc' ];

		$this->assertSame( [ 'en', 'fr' ], Static404Baker::select_locales( $codes, '[a-z]{2}' ) );
		$this->assertSame( [ 'de-de' ], Static404Baker::select_locales( $codes, '[a-z]{2}-[a-z]{2}' ) );
		$this->assertSame(
			[ 'en', 'fr', 'de-de' ],
			Static404Baker::select_locales( $codes, '' ),
			'No pattern known: path-safe codes only, never a traversal.'
		);
		$this->assertSame( [ 'en' ], Static404Baker::select_locales( [ 'en', 'en' ], '[a-z]{2}' ), 'De-duplicated.' );
	}

	/**
	 * The default probe segment prefers the default language, then any
	 * accepted active language, then the pattern's first literal branch.
	 *
	 * @return void
	 */
	public function test_pick_default_segment_order(): void {
		$pattern = '[a-z]{2}-[a-z]{2}|intl';

		$this->assertSame( 'en-gb', Static404Baker::pick_default_segment( 'en-gb', [ 'fr-fr' ], $pattern ) );
		$this->assertSame( 'fr-fr', Static404Baker::pick_default_segment( 'EN', [ 'fr-fr' ], $pattern ), 'Rejected default falls through.' );
		$this->assertSame( 'intl', Static404Baker::pick_default_segment( null, [], $pattern ), 'WPML unavailable: the literal branch.' );
		$this->assertNull( Static404Baker::pick_default_segment( null, [], '[a-z]{2}' ), 'Nothing usable: no segment.' );
	}
}
