<?php
/**
 * Bakes the theme's real 404 page to a static file the pre-boot loader can serve.
 *
 * The front-end read path runs before WordPress boots, so it cannot render the
 * theme — but a bare "Not Found" stub looks nothing like the site's 404. This
 * baker captures the genuine themed 404 once per locale (via a loopback request
 * to a neutral, non-shielded path that WordPress 404s normally) and writes it to
 * `uploads/post-404-shield/404/<locale>.html`. The loader then serves that markup
 * for a shielded 404, so it is indistinguishable from a real one.
 *
 * `default.html` (baked from the default language) is the fallback the loader uses
 * when a request's own locale has not been baked.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Library/Static404Baker.php
 *
 * @package Post404Shield\Library
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * Captures the themed 404 per locale into static files for the pre-boot loader.
 */
class Static404Baker {

	/**
	 * A path with no real content, so WordPress renders its normal themed 404 —
	 * what we capture. The pre-boot loader BLOCKS this path for everyone except
	 * requests carrying the probe token (see probe_token()), so crawlers that
	 * discover it get the cheap baked 404 instead of a full render.
	 */
	public const PROBE_PATH = 'post-shield-404-probe';

	/**
	 * Attempts per locale before giving up (transient renders can wp_die).
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Backoff between attempts, in microseconds.
	 */
	private const RETRY_BACKOFF_US = 300000;

	/**
	 * Why the last bake attempt failed, for CLI diagnostics (silent otherwise).
	 *
	 * @var string
	 */
	private string $last_reason = '';

	/**
	 * Locale mode from the generated config (`none` | `wpml-directory` |
	 * `custom`), or null for auto-detect (WPML present → directory mode). Drives
	 * the probe-URL shape: a language-directory site probes
	 * `/{segment}/post-shield-404-probe/`; `locale.mode: none` probes
	 * `/post-shield-404-probe/` with no language segment at all.
	 *
	 * @var string|null
	 */
	private ?string $locale_mode;

	/**
	 * Locale pattern body from the config artifact ('' = none/unknown).
	 *
	 * @var string
	 */
	private string $locale_pattern;

	/**
	 * Construct the baker.
	 *
	 * @param string|null $locale_mode    Locale mode from the config artifact, or
	 *                                    null to auto-detect from WPML presence.
	 * @param string      $locale_pattern Locale pattern body from the config
	 *                                    artifact; '' when none or not yet known.
	 */
	public function __construct( ?string $locale_mode = null, string $locale_pattern = '' ) {
		$this->locale_mode    = $locale_mode;
		$this->locale_pattern = $locale_pattern;
	}

	/**
	 * Whether a language code may be used as a locale segment: path-safe, and —
	 * when the site's locale pattern is known — one the loader will match.
	 *
	 * The loader looks up `404/{locale}.html` using the locale IT matched, so a
	 * file baked for a code the pattern rejects is never served, and a code the
	 * pattern accepts but this filter rejected falls back to the default page:
	 * a visitor gets the 404 in the wrong language. The pattern is therefore
	 * the only correct filter — never a hard-coded shape.
	 *
	 * @param string $code           Candidate language code.
	 * @param string $locale_pattern Locale pattern body ('' = path-safety only).
	 *
	 * @return bool
	 */
	public static function segment_ok( string $code, string $locale_pattern ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $code ) ) {
			return false;
		}
		if ( '' === $locale_pattern ) {
			return true;
		}
		// Compiled as the matchers compile it; validation rules out delimiter breakout.
		return 1 === @preg_match( '#^(?:' . $locale_pattern . ')$#', $code ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an operator pattern; a bad one must fail closed, not warn.
	}

	/**
	 * The language codes to bake a page for, in order, de-duplicated.
	 *
	 * @param string[] $codes          Active language codes.
	 * @param string   $locale_pattern Locale pattern body.
	 *
	 * @return string[]
	 */
	public static function select_locales( array $codes, string $locale_pattern ): array {
		$locales = [];
		foreach ( $codes as $code ) {
			$code = (string) $code;
			if ( self::segment_ok( $code, $locale_pattern ) && ! in_array( $code, $locales, true ) ) {
				$locales[] = $code;
			}
		}
		return $locales;
	}

	/**
	 * The locale segment for the DEFAULT bake's probe.
	 *
	 * The site's default language when the pattern accepts it; otherwise the
	 * first active language it accepts; otherwise the pattern's first literal
	 * branch (so a WPML hiccup still yields the same probe shape); otherwise no
	 * segment.
	 *
	 * @param string|null $default        WPML default language, if known.
	 * @param string[]    $active         Active language codes.
	 * @param string      $locale_pattern Locale pattern body.
	 *
	 * @return string|null
	 */
	public static function pick_default_segment( ?string $default, array $active, string $locale_pattern ): ?string {
		if ( null !== $default && self::segment_ok( $default, $locale_pattern ) ) {
			return $default;
		}
		$accepted = self::select_locales( $active, $locale_pattern );
		if ( [] !== $accepted ) {
			return $accepted[0];
		}
		foreach ( explode( '|', $locale_pattern ) as $branch ) {
			if ( 1 === preg_match( '/^[a-z0-9-]+$/', $branch ) ) {
				return $branch;
			}
		}
		return null;
	}

	/**
	 * URL language segment for the DEFAULT bake's probe, or null for none.
	 * Resolved lazily (bakes run post-init, when WPML is loaded — the
	 * constructor runs at mu-plugin load, when it is not): mode `none` → no
	 * segment; otherwise pick_default_segment().
	 *
	 * @return string|null
	 */
	private function default_probe_segment(): ?string {
		$mode = $this->locale_mode;
		if ( null === $mode ) {
			$mode = defined( 'ICL_SITEPRESS_VERSION' ) ? 'wpml-directory' : 'none';
		}
		if ( 'none' === $mode ) {
			return null;
		}
		$default = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_default_language', null ) : null;
		return self::pick_default_segment( is_string( $default ) ? $default : null, $this->active_codes(), $this->locale_pattern );
	}

	/**
	 * Active WPML language codes, unfiltered. Empty when WPML is not present.
	 *
	 * @return string[]
	 */
	private function active_codes(): array {
		$langs = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_active_languages', null ) : null;
		if ( ! is_array( $langs ) ) {
			return [];
		}
		$codes = [];
		foreach ( $langs as $key => $lang ) {
			$codes[] = is_array( $lang ) && isset( $lang['code'] ) ? (string) $lang['code'] : (string) $key;
		}
		return $codes;
	}

	/**
	 * Bake the themed 404 for every active WPML language, plus the `default`
	 * fallback. One static file per language so a shielded 404 is served in the
	 * visitor's own language.
	 *
	 * @return int Number of pages successfully written.
	 */
	public function bake_all(): int {
		$written = $this->bake( 'default' ) ? 1 : 0;
		foreach ( $this->get_locales() as $locale ) {
			if ( $this->bake( $locale ) ) {
				++$written;
			}
		}
		return $written;
	}

	/**
	 * Active WPML language codes, which are also the URL locale segments,
	 * filtered to the ones the site's locale pattern accepts (see segment_ok())
	 * so a code can never introduce anything unexpected into a file path, and
	 * no page is baked that the loader would never serve. Empty when WPML is
	 * not present.
	 *
	 * @return string[]
	 */
	public function get_locales(): array {
		return self::select_locales( $this->active_codes(), $this->locale_pattern );
	}

	/**
	 * Bake the themed 404 for a locale.
	 *
	 * @param string $locale URL locale, or `default` to capture the default
	 *                       language into the fallback file.
	 *
	 * @return bool True when a page was captured and written.
	 */
	public function bake( string $locale = 'default' ): bool {
		// Retry: under a full bake_all() sweep a render can transiently wp_die()
		// ("WordPress › Error"); a moment later it succeeds. A few attempts make the
		// sweep reliable without ever caching a bad page.
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 ) {
				usleep( self::RETRY_BACKOFF_US ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions.usleep_usleep -- CLI/cron background bake, not a request path.
			}

			$response = $this->remote_get( $this->probe_url( $locale ) );
			if ( is_wp_error( $response ) ) {
				$this->last_reason = 'request failed: ' . $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			// Only trust the genuine themed 404: a 404 status, a full HTML document,
			// and NOT the wp_die() error page (`id="error-page"`). Record why not, so
			// the CLI can explain a failure instead of reporting a silent 0. The
			// captured markup is sanitised before writing: the theme's language
			// switcher renders "this page in every locale" links, which on the probe
			// URL meant 49 crawlable probe links on every baked 404 — bingbot found
			// and hammered them. sanitize_markup() rewrites those to the locale
			// homepage and scrubs the probe token.
			if ( 404 !== $code ) {
				$this->last_reason = sprintf( 'probe returned HTTP %d, not 404 — password-protected env? define POST_SHIELD_BAKE_AUTH', $code );
				continue;
			}
			if ( '' === $body || false === strpos( $body, '</html>' ) ) {
				$this->last_reason = 'probe response was not a full HTML document';
				continue;
			}
			if ( false !== strpos( $body, 'id="error-page"' ) ) {
				$this->last_reason = 'probe captured a wp_die() error page';
				continue;
			}

			return $this->write_atomically(
				self::sanitize_markup( $body, $this->probe_token() ),
				$this->get_file( $locale )
			);
		}

		return false;
	}

	/**
	 * The reason the last failed bake gave up (empty if the last bake succeeded).
	 *
	 * @return string
	 */
	public function last_reason(): string {
		return $this->last_reason;
	}

	/**
	 * GET a URL, preferring VIP's hardened helper when the platform provides it
	 * (so this ports cleanly to WordPress VIP) and falling back to core
	 * wp_remote_get() elsewhere, e.g. WP Engine. Both return a response array or a
	 * failure value the caller treats as fail-safe.
	 *
	 * @param string $url URL to fetch.
	 *
	 * @return array|\WP_Error|string Response array, WP_Error, or fallback value.
	 */
	private function remote_get( string $url ) {
		$args = [
			'timeout'     => 3,
			'redirection' => 0,
			'sslverify'   => false,
		];

		// Authenticate past a password-protected environment (e.g. a WPE dev/staging
		// install) so the loopback reaches the themed 404 instead of a 401 auth wall.
		// Define POST_SHIELD_BAKE_AUTH as "user:pass" in that env's wp-config; leave
		// it undefined on production, which isn't protected.
		if ( defined( 'POST_SHIELD_BAKE_AUTH' ) && is_string( POST_SHIELD_BAKE_AUTH ) && '' !== POST_SHIELD_BAKE_AUTH ) {
			$args['headers'] = [ 'Authorization' => 'Basic ' . base64_encode( POST_SHIELD_BAKE_AUTH ) ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
		}

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			// Args, in order: url, fallback value, error threshold, timeout, retry, request args.
			return vip_safe_wp_remote_get( $url, '', 3, 3, 1, $args );
		}

		return wp_remote_get( $url, $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- vip_safe_wp_remote_get() used above when available; this is the non-VIP fallback.
	}

	/**
	 * Loopback URL for a neutral 404 in the given locale. A cache-buster keeps the
	 * edge from returning a cached 404. `default` captures the default language.
	 *
	 * Built from the RAW `home` option, deliberately NOT home_url(): WPML filters
	 * home_url() to the CURRENT request's language, and in a cron/web context
	 * (weekly cron, the settings-page button) the current language is the default —
	 * so every locale's probe URL was rewritten to the default-language directory
	 * and all 50 files baked the GLOBAL 404. The raw option is unfiltered; we
	 * append the locale segment ourselves. (CLI bakes were unaffected, which is why
	 * `wp post-shield bake-404` looked correct while cron bakes were not.)
	 *
	 * The `default` bake's segment is locale-mode-driven (constructor): the
	 * default-language directory on a WPML site, or NO segment at all when
	 * `locale.mode` is `none` — the loader requires the same shape.
	 *
	 * @param string $locale URL locale or `default`.
	 *
	 * @return string
	 */
	private function probe_url( string $locale ): string {
		$segment = ( 'default' === $locale ) ? $this->default_probe_segment() : $locale;
		$home    = rtrim( (string) get_option( 'home' ), '/' );
		$prefix  = null !== $segment && '' !== $segment ? '/' . $segment : '';
		return $home . $prefix . '/' . self::PROBE_PATH . '/?post_shield_bake=' . rawurlencode( $this->probe_token() );
	}

	/**
	 * Absolute path to the probe-token file. Guarded like the allowlists — a
	 * `<?php exit;` first line with the token on line 2 — so a direct HTTP hit
	 * reveals nothing, while the pre-boot loader can byte-read it.
	 *
	 * @return string
	 */
	public function get_token_file(): string {
		return \Post404Shield\shield_dir() . '/probe-token.php';
	}

	/**
	 * The shared probe secret. The baker appends it to every probe URL; the
	 * pre-boot loader lets a probe request through to WordPress ONLY when the
	 * token matches — anyone else (crawlers that discovered the path) gets the
	 * cheap baked 404. Generated randomly on first use and persisted; deleting
	 * the file simply mints a new token on the next bake. Never appears in
	 * baked markup (sanitize_markup scrubs it).
	 *
	 * @return string
	 */
	public function probe_token(): string {
		$file = $this->get_token_file();
		if ( is_readable( $file ) ) {
			$raw = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
			if ( is_string( $raw ) && false !== strpos( $raw, "\n" ) ) {
				$token = trim( substr( $raw, (int) strpos( $raw, "\n" ) ) );
				if ( '' !== $token ) {
					return $token;
				}
			}
		}

		$token = bin2hex( random_bytes( 16 ) );
		$dir   = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return $token; // Unwritable: still usable for this run; next bake retries.
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}
		$tmp = $file . '.' . getmypid() . '.tmp';
		// `__halt_compiler();` so PHP stops parsing at the guard (see ConfigStore::stage_artifact()).
		if ( false !== file_put_contents( $tmp, "<?php exit; __halt_compiler(); // post-404-shield probe token — do not edit.\n" . $token . "\n" ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			rename( $tmp, $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename
		}

		return $token;
	}

	/**
	 * Strip every trace of the probe path — and the probe token — from captured
	 * markup before it is written. The theme's language switcher renders "this
	 * page in every locale" links, which on the probe URL produced dozens of
	 * crawlable `/{locale}/post-shield-404-probe/` anchors on every baked 404;
	 * rewriting them to `/{locale}/` points the switcher at the locale homepage
	 * instead (the right destination from a 404 anyway) and stops crawlers ever
	 * learning the probe path again. Pure: unit-testable in isolation.
	 *
	 * @param string $html  Captured 404 markup.
	 * @param string $token Probe token to scrub (optional).
	 *
	 * @return string
	 */
	public static function sanitize_markup( string $html, string $token = '' ): string {
		$html = str_replace( '/' . self::PROBE_PATH . '/', '/', $html );
		$html = str_replace( '/' . self::PROBE_PATH, '/', $html );
		if ( '' !== $token ) {
			$html = str_replace( $token, '', $html );
		}
		return $html;
	}

	/**
	 * Absolute path to a locale's baked 404 file.
	 *
	 * @param string $locale URL locale or `default`.
	 *
	 * @return string
	 */
	public function get_file( string $locale ): string {
		return \Post404Shield\shield_dir() . '/404/' . $locale . '.html';
	}

	/**
	 * Write the captured HTML atomically (temp file + rename) so the loader never
	 * reads a partial page, and harden the directory against listing.
	 *
	 * @param string $html Captured 404 markup.
	 * @param string $file Absolute destination path.
	 *
	 * @return bool True on success.
	 */
	public function write_atomically( string $html, string $file ): bool {
		$dir = dirname( $file );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$this->harden_directory( $dir );

		$tmp = $file . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, $html ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			return false;
		}

		if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename
			unlink( $tmp ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			return false;
		}

		return true;
	}

	/**
	 * Drop an index.php into a directory to prevent listing.
	 *
	 * @param string $dir Directory to harden.
	 *
	 * @return void
	 */
	private function harden_directory( string $dir ): void {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		}
	}
}
