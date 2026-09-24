<?php
/**
 * Plugin Name: Post 404 Shield Bootstrap.
 * Description: Loads the post-404-shield generator (WRITE) where it is needed, and the Tier-2 failsafe read path. The primary read path runs earlier via wp-config.php (Tier 1) — see post-404-shield/docs/WP-CONFIG-SETUP.md.
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

// Generator (WRITE path) — builds the allowlists the front-end reads, bakes the
// themed 404 and runs the settings screen. A plain page view uses none of it, so
// it loads only where generator_needed() says so: admin, cron, WP-CLI, REST,
// XML-RPC, non-GET requests, /robots.txt and the bake probe — or, on any other
// request, the moment something writes a post. If that check is missing or
// fails, the generator loads as it always did.
$post_shield_generator = __DIR__ . '/post-404-shield/bootstrap.php';
$post_shield_context   = __DIR__ . '/post-404-shield/src/php/Function/LoadContext.php';
$post_shield_needed    = true;

if ( is_readable( $post_shield_context ) ) {
	try {
		require_once $post_shield_context;
		if ( function_exists( 'Post404Shield\\generator_needed' ) ) {
			$post_shield_needed = \Post404Shield\generator_needed(
				$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing check, never output or stored.
				is_admin(),
				wp_doing_cron() || ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ),
				defined( 'WP_CLI' ) && WP_CLI,
				defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
				function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json'
			);
		}
	} catch ( \Throwable $e ) {
		$post_shield_needed = true;
		error_log( '[post-404-shield] load-context check failed, loading the generator: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

$post_shield_load = static function () use ( $post_shield_generator ): void {
	if ( ! is_readable( $post_shield_generator ) ) {
		return;
	}
	try {
		require_once $post_shield_generator;
	} catch ( \Throwable $e ) {
		error_log( '[post-404-shield] generator bootstrap failed to load: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
};

if ( $post_shield_needed ) {
	$post_shield_load();
} else {
	// A page view normally writes nothing, but a plugin can publish during one
	// — PublishPress Revisions publishes a due scheduled revision inline when
	// its WP-Cron scheduling is off. The first post-write hook loads the
	// generator; its own listeners, at later priorities, then run in that same
	// dispatch, so the new or renamed slug is appended at once.
	foreach ( [ 'transition_post_status', 'post_updated', 'save_post', 'add_attachment', 'edit_attachment', 'before_delete_post', 'wp_trash_post', 'revision_applied' ] as $post_shield_write_hook ) {
		add_action( $post_shield_write_hook, $post_shield_load, PHP_INT_MIN, 0 );
	}
	// A route change on a page view — a plugin flushing the rewrite rules
	// after an update, a term saved — queues the one-minute re-check the
	// generator would, without loading it: the cron run that follows does.
	foreach ( [ 'created_term', 'edited_term', 'delete_term', 'update_option_rewrite_rules', 'add_option_rewrite_rules' ] as $post_shield_route_hook ) {
		add_action(
			$post_shield_route_hook,
			static function (): void {
				if ( false === wp_next_scheduled( 'post_shield_revalidate' ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'post_shield_revalidate' );
				}
			},
			10,
			0
		);
	}
	// PublishPress fires this filter BEFORE it writes the revision over the
	// live post: the generator must be loaded by then to note the addresses
	// the revision may move. A filter, so its value passes through untouched.
	add_filter(
		'revisionary_apply_revision_data',
		static function ( $data ) use ( $post_shield_load ) {
			$post_shield_load();
			return $data;
		},
		PHP_INT_MIN,
		1
	);
}
