<?php
/**
 * Post 404 Shield — switching WPML's current language for a lookup.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Function/Language.php
 *
 * @package Post404Shield
 */

declare(strict_types=1);

namespace Post404Shield;

/**
 * Switch WPML's current language for a lookup (a post's permalink in its own
 * language), or back to the request's own with null. A no-op without WPML.
 *
 * Not the `wpml_switch_language` action: that also writes the language cookie,
 * and in wp-admin every call adds a Set-Cookie header to the response. The
 * save gates switch once per sampled post, so a save sent hundreds of them —
 * more than nginx's FastCGI header buffer holds, and the save came back as a
 * 502. A lookup changes nothing the browser should remember.
 *
 * @param string|null $code Language code, or null for the request's own.
 *
 * @return void
 */
function switch_language( ?string $code ): void {
	global $sitepress;
	if ( is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) ) {
		$sitepress->switch_lang( $code );
		return;
	}
	if ( null !== $code && function_exists( 'do_action' ) ) {
		do_action( 'wpml_switch_language', $code );
	}
}
