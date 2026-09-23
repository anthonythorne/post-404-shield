<?php
/**
 * Post 404 Shield — which requests need the generator (the WRITE path).
 *
 * Pure PHP, no WordPress calls: the mu-plugin loader passes in what it knows,
 * so this stays testable with nothing but the language.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Function/LoadContext.php
 *
 * @package Post404Shield
 */

declare(strict_types=1);

namespace Post404Shield;

/**
 * Whether this request needs bootstrap.php, the generator.
 *
 * The generator builds the allowlists, bakes the themed 404, runs the settings
 * screen and owns the shield's cron events. A plain page view uses none of it,
 * yet loading it everywhere cost about 6 ms per uncached request on a
 * production site: ten class files, four controllers and a config read. It is
 * needed wherever something can change what it builds from, or where one of
 * its hooks answers:
 *   - wp-admin, including admin-ajax and admin-post (the settings screen, saves);
 *   - a cron run, WP-CLI or XML-RPC;
 *   - REST, by path or by `?rest_route=` (the block editor saves through it);
 *   - any method other than GET or HEAD (a front-end form can create a post);
 *   - `/robots.txt` (its filter adds the probe path's Disallow line);
 *   - the bake probe's own loopback (`?post_shield_bake=`).
 * The loader counts ALTERNATE_WP_CRON as a cron run, because it runs cron inside
 * an ordinary page request.
 *
 * Erring towards true is always safe: it is the old behaviour.
 *
 * @param array<string, mixed> $server      The request's $_SERVER.
 * @param bool                 $is_admin    is_admin().
 * @param bool                 $is_cron     wp_doing_cron(), or ALTERNATE_WP_CRON.
 * @param bool                 $is_cli      WP-CLI.
 * @param bool                 $is_xmlrpc   XMLRPC_REQUEST.
 * @param string               $rest_prefix rest_get_url_prefix(), e.g. `wp-json`.
 *
 * @return bool
 */
function generator_needed( array $server, bool $is_admin, bool $is_cron, bool $is_cli, bool $is_xmlrpc, string $rest_prefix ): bool {
	if ( $is_admin || $is_cron || $is_cli || $is_xmlrpc ) {
		return true;
	}

	$method = strtoupper( is_string( $server['REQUEST_METHOD'] ?? null ) ? $server['REQUEST_METHOD'] : 'GET' );
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return true;
	}

	$uri = is_string( $server['REQUEST_URI'] ?? null ) ? $server['REQUEST_URI'] : '';
	if ( '' === $uri ) {
		return true; // Nothing to judge by: keep the old behaviour.
	}
	$path = parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress here.
	$path = is_string( $path ) ? $path : '';

	$prefix = trim( $rest_prefix, '/' );
	if ( '' !== $prefix && false !== strpos( $path . '/', '/' . $prefix . '/' ) ) {
		return true;
	}
	if ( str_ends_with( $path, '/robots.txt' ) ) {
		return true;
	}

	$query = parse_url( $uri, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure, no WordPress here.
	if ( is_string( $query ) && '' !== $query ) {
		parse_str( $query, $params );
		if ( isset( $params['rest_route'] ) || isset( $params['post_shield_bake'] ) ) {
			return true;
		}
	}

	return false;
}
