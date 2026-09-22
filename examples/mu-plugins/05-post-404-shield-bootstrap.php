<?php
/**
 * Plugin Name: Post 404 Shield Bootstrap.
 * Description: Loads the post-404-shield generator (WRITE) and the Tier-2 failsafe read path. The primary read path runs earlier via wp-config.php (Tier 1) — see post-404-shield/docs/WP-CONFIG-SETUP.md.
 * Version:     1.0
 * Author:      The Code Company
 *
 * File Path: wp-content/mu-plugins/05-post-404-shield-bootstrap.php
 *
 * @package Post404Shield
 */

/**
 * Tier-2 failsafe read path.
 *
 * The primary read path is Tier 1: wp-config.php requires the front-end
 * bootstrap before WordPress boots, which defines POST_SHIELD_LOADED. If that
 * line was dropped (e.g. during a migration), POST_SHIELD_LOADED is NOT set
 * here, so we run the same front-end bootstrap now — at mu-plugin load, after
 * the options load. Softer than Tier 1, but it keeps the shield working with
 * zero manual steps. When Tier 1 ran, the constant is set and we do nothing.
 */
if ( ! defined( 'POST_SHIELD_LOADED' ) ) {
	$post_shield_front_end = __DIR__ . '/post-404-shield/bootstrap-front-end-post-404-shield.php';
	if ( is_readable( $post_shield_front_end ) ) {
		// Fail-open even against a corrupt/half-deployed file. The bootstrap's logic
		// runs inside an IIFE that executes DURING this require, so this one catch
		// covers a parse error AND any runtime fatal — the shield can never 500 the
		// site at mu-plugin load, only silently decline to run.
		try {
			require_once $post_shield_front_end;
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] front-end bootstrap failed to load: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

// Generator (WRITE path) — builds the allowlists the front-end reads.
$post_shield_generator = __DIR__ . '/post-404-shield/bootstrap.php';

if ( is_readable( $post_shield_generator ) ) {
	try {
		require_once $post_shield_generator;
	} catch ( \Throwable $e ) {
		error_log( '[post-404-shield] generator bootstrap failed to load: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
