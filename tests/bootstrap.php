<?php
/**
 * PHPUnit bootstrap.
 *
 * The plugin has no autoloader (its bootstrap require_once's each file), so each
 * test requires exactly the plugin files it exercises. All that is left to
 * provide is the handful of WordPress functions those files call, stubbed with
 * core's behaviour.
 *
 * @package Post404Shield\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Core's recursive mkdir, reduced to what the tests need.
	 *
	 * @param string $target Directory path.
	 *
	 * @return bool
	 */
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Core's trailingslashit().
	 *
	 * @param string $value Path or URL.
	 *
	 * @return string
	 */
	function trailingslashit( string $value ): string {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Core's untrailingslashit().
	 *
	 * @param string $value Path or URL.
	 *
	 * @return string
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * No hooks in unit tests: every filter returns its input.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value to filter.
	 *
	 * @return mixed
	 */
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * No options in unit tests: always the default.
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default.
	 *
	 * @return mixed
	 */
	function get_option( string $name, $default_value = false ) {
		return $default_value;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * No translations in unit tests.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core's signature.
		return $text;
	}
}
