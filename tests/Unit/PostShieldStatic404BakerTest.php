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
require_once __DIR__ . '/../../post-404-shield/src/php/Library/Static404Baker.php';

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
}
