<?php
/**
 * Post 404 shield — front-end bootstrap (READ path, committed, pipeline-deployed).
 *
 * Runs the pre-render short-circuit. Loaded EARLY by one of two tiers:
 *   Tier 1 (primary):  wp-config.php, before WordPress boots — before the
 *                      wp_options autoload that stalls under the flood. Manual,
 *                      out-of-repo; see docs/WP-CONFIG-SETUP.md.
 *   Tier 2 (failsafe): the numbered post-404-shield mu-plugin bootstrap
 *                      (05-post-404-shield-bootstrap.php), at mu-plugin load,
 *                      ONLY IF Tier 1 didn't run (constant not set). Ships in the
 *                      pipeline, so the shield always works — just after the
 *                      options load, so it's softer.
 * It defines POST_SHIELD_LOADED on entry, and both tiers check that before
 * loading this file, so the check runs at most once per request.
 *
 * Config-driven — by the GENERATED artifact `uploads/post-404-shield/config.php`
 * (admin-saved, guard line + JSON, text-read via Function/ConfigReader.php;
 * never executed). No artifact, or an invalid one, means the shield is not in
 * place: every request falls through to WordPress exactly as if this plugin
 * were absent (fail-open). Per managed, enabled type:
 *   - fake top-level slug (not in the allowlist) → cacheable 404, any depth;
 *   - real slug within allowed_depth              → pass through to WordPress;
 *   - real slug deeper than allowed_depth         → 301 to the truncation.
 *
 * Pure PHP: no WordPress functions/constants required (it must work in the
 * pre-boot wp-config context). Every failure path returns — fail-open.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php
 *
 * @package Post404Shield\Loader
 */

declare(strict_types=1);

// Run once. Both tiers also check this before loading us; belt-and-braces.
if ( defined( 'POST_SHIELD_LOADED' ) ) {
	return;
}
define( 'POST_SHIELD_LOADED', true );

( static function (): void {

	// Absolute kill switch: define POST_SHIELD_DISABLED as true in wp-config.php
	// (above the Tier-1 require) and the shield never engages, config or not.
	if ( defined( 'POST_SHIELD_DISABLED' ) && POST_SHIELD_DISABLED ) {
		return;
	}

	// Only GET/HEAD can be an entry page load; hand everything else to WordPress.
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-boot, no WP sanitisers exist yet.
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return;
	}

	$uri = $_SERVER['REQUEST_URI'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pre-boot, no WP sanitisers exist yet.
	if ( ! is_string( $uri ) || '' === $uri ) {
		return;
	}

	// --- New Relic tagging (no-ops unless the NR PHP extension is loaded). ---
	// NRQL cannot read response headers, so the X-Post-Shield decision is
	// mirrored onto the transaction as custom attributes (`postShield`,
	// `postShieldType`) for the 404 dashboards. `wpAuthCookie` marks whether the
	// request carried a WordPress login cookie — set for EVERY GET/HEAD request
	// (shielded or not), so dashboards can split logged-in users from anonymous
	// traffic/bots without query-string guesswork.
	$nr_tag = static function ( string $outcome, string $post_type ): void {
		if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
			newrelic_add_custom_parameter( 'postShield', $outcome );
			newrelic_add_custom_parameter( 'postShieldType', $post_type );
		}
	};
	if ( function_exists( 'newrelic_add_custom_parameter' ) ) {
		$cookies = $_SERVER['HTTP_COOKIE'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence check only, never output.
		newrelic_add_custom_parameter( 'wpAuthCookie', is_string( $cookies ) && false !== strpos( $cookies, 'wordpress_logged_in_' ) );
	}

	// Fast path: a request that can never be shield business, whatever the config
	// says, goes straight to WordPress before anything is read from disk. Each file
	// read costs about a millisecond on network storage, and these are most of the
	// GET requests that reach PHP (REST, cron, the home page, feeds, sitemaps).
	// Three shapes qualify. `/` itself: root matching passes it and no base can be
	// empty. WordPress's own trees (wp-admin, wp-json, wp-content, wp-includes):
	// reserved namespaces no base may claim, and root matching's hard floor. A
	// first segment containing a dot (wp-login.php, wp-cron.php, xmlrpc.php,
	// robots.txt, sitemap XML, .well-known): bases and locale patterns are
	// dot-free, and root matching passes any segment outside [a-z0-9_-].
	// Passing early is the fail-open direction, so this can only ever hand
	// WordPress a request the full decision would also have handed it.
	$early_path = parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() does not exist pre-boot.
	if ( is_string( $early_path ) ) {
		$first_segment = explode( '/', ltrim( $early_path, '/' ), 2 )[0];
		if ( '' === $first_segment
			|| in_array( $first_segment, [ 'wp-admin', 'wp-json', 'wp-content', 'wp-includes' ], true )
			|| false !== strpos( $first_segment, '.' )
		) {
			return;
		}
	}

	// The generated config artifact is the ONLY runtime config source. It is
	// text-read and fully re-validated by the pure reader; missing, corrupt or
	// invariant-violating → null → the shield is not in place (fail-open).
	$reader_file = __DIR__ . '/src/php/Function/ConfigReader.php';
	if ( ! is_readable( $reader_file ) ) {
		return;
	}
	try {
		require_once $reader_file;
	} catch ( \Throwable ) {
		return;
	}
	if ( ! function_exists( 'Post404Shield\\read_config' ) || ! function_exists( 'Post404Shield\\config_shape_is_valid' ) || ! function_exists( 'Post404Shield\\shield_dir' ) ) {
		return;
	}

	// The shield's data directory — config artifact, allowlists, baked 404s,
	// probe token. shield_dir() is the one definition every writer uses too,
	// so the loader can never read a directory nothing writes to. Deliberately
	// not overridable: a POST_SHIELD_ALLOWLIST_DIR define once redirected this
	// reader while the writers ignored it.
	$allowlist_dir = \Post404Shield\shield_dir();
	// Decode + a types-only check first; the full (regex-heavy) validation
	// runs below, once the pre-filter says this request is shield business.
	// Most requests are not, and they exit without acting either way — so
	// deferring the validation changes no outcome, only what it costs.
	$config_file = $allowlist_dir . '/config.php';
	$config      = \Post404Shield\read_config_document( $config_file );
	if ( null === $config || ! \Post404Shield\config_shape_is_valid( $config ) ) {
		return;
	}
	$types = $config['entries'];

	// Locale-prefix pattern (regex BODY) from the artifact. Non-empty means the
	// prefix is REQUIRED (`/{xx-xx|global}/{base}/…`) and a bare `/{base}/…`
	// falls through to WordPress; '' means there is no locale segment at all.
	$locale_mode    = $config['locale']['mode'];
	$locale_pattern = 'none' === $locale_mode ? '' : (string) $config['locale']['pattern'];

	// Enabled ROOT entries (root-pages v2): allowlist-mode entries with no URL
	// base, evaluated LAST as the whole-site catch-all. Collected once here —
	// they make every URI potentially shield business (no base to pre-filter
	// on), and the catch-all below reuses the list. `page` is moved to the
	// front: a blocked root URL is inherently ambiguous, and `page` is the
	// documented owner of that ambiguity.
	$root_entries = [];
	foreach ( $types as $post_type => $settings ) {
		if ( true !== ( $settings['root'] ?? false ) ) {
			continue;
		}
		if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
			continue;
		}
		if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
			continue;
		}
		$root_entries[ (string) ( $settings['post_type'] ?? $post_type ) ] = $settings;
	}
	if ( isset( $root_entries['page'] ) ) {
		$root_entries = [ 'page' => $root_entries['page'] ] + $root_entries;
	}

	// Cheap pre-filter: exit before any parse_url/require unless the URI mentions
	// an enabled managed base — or the bake probe path, which is always shield
	// business regardless of entries. A mode=block base matches without the
	// trailing slash so the bare base itself (`/de-de/old-section`) is caught too.
	// With a root entry enabled there is nothing to pre-filter on — every URI
	// is potentially the catch-all's business.
	$relevant = [] !== $root_entries || false !== strpos( $uri, 'post-shield-404-probe' );
	foreach ( $types as $settings ) {
		if ( $relevant ) {
			break;
		}
		if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
			continue;
		}
		$is_block = 'block' === ( $settings['mode'] ?? 'allowlist' );
		foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
			$needle = '/' . $base . ( $is_block ? '' : '/' );
			if ( false !== strpos( $uri, $needle ) ) {
				$relevant = true;
				break;
			}
		}
	}
	if ( ! $relevant ) {
		return;
	}

	// Shield business: validate the whole document before acting on any of
	// it. Validated from memory — the bytes read above — so the file is not
	// read a second time in this request (about a millisecond on network
	// storage). A ConfigReader.php from an older release lacks that function;
	// fall back to read_config() rather than switching the shield off.
	$validated = function_exists( 'Post404Shield\\read_config_validated_in_memory' )
		? \Post404Shield\read_config_validated_in_memory( $config_file )
		: \Post404Shield\read_config( $config_file );
	if ( null === $validated ) {
		return;
	}

	// Path only — drop the query string before the precise match.
	$path = parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() does not exist pre-boot.
	if ( ! is_string( $path ) || '' === $path ) {
		return;
	}

	// Pure matcher. Missing/unreadable/unparsable → fall through.
	$matcher_file = __DIR__ . '/src/php/Function/Matcher.php';
	if ( ! is_readable( $matcher_file ) ) {
		return;
	}
	try {
		require_once $matcher_file;
	} catch ( \Throwable ) {
		return;
	}
	// Both checked: a Matcher.php from another release (a half-finished deploy)
	// must leave the shield off, never fatal the request.
	if ( ! function_exists( 'Post404Shield\\match_entry' ) || ! function_exists( 'Post404Shield\\decide_based' ) ) {
		return;
	}

	// Cache lifetime (seconds) for a pre-boot 404. Two very different cases.
	// Case 1 — a slug-derived 404 (blocked-unknown-slug, blocked-deep-path)
	// becomes a real page the moment an editor publishes that slug. The allowlist
	// appends instantly and PostShieldSyncController purges that path from the WP
	// Engine page cache / CDN on publish — but a *browser* that already cached the
	// 404 still honours `max-age` (WP Engine strips our `s-maxage`, so `max-age`
	// governs the browser too), and no purge can reach a browser. So keep it short.
	// Caching buys almost nothing here anyway: these URLs are near-unique (measured
	// ~1.06 requests per unique URL per 30 min) and the 404 already costs only ~11ms.
	// Case 2 — a `mode => 'block'` base can never hold content, so it caches hard.
	// Per-entry `cache_ttl` overrides both. 0 => `no-store`. Both globals can be
	// set in wp-config.php (read via defined(), so a constant there wins): for the
	// live Tier-2 path any position works; for Tier 1 place the define ABOVE the
	// shield's require line.
	$publishable_ttl = defined( 'POST_SHIELD_404_TTL' ) ? max( 0, (int) POST_SHIELD_404_TTL ) : 60;
	$denied_base_ttl = defined( 'POST_SHIELD_BLOCKED_BASE_TTL' ) ? max( 0, (int) POST_SHIELD_BLOCKED_BASE_TTL ) : 3600;
	// Optional SEPARATE CDN-edge TTL for publishable 404s. null = the edge follows
	// s-maxage like every other shared cache. Set it (e.g. 1) to cap ONLY the edge
	// while the origin keeps the longer, purgeable $publishable_ttl — see $emit_404.
	$publishable_edge_ttl = defined( 'POST_SHIELD_404_EDGE_TTL' ) ? max( 0, (int) POST_SHIELD_404_EDGE_TTL ) : null;

	// The `X-Post-Shield` response header records the decision, for debugging and
	// synthetic monitoring. Self-descriptive values (all outcomes):
	// - blocked-unknown-slug  slug not in the allowlist → pre-boot 404 (the flood).
	// - blocked-denied-base   any URL under a mode=block base → pre-boot 404.
	// - blocked-probe-path    the bake probe path without a valid token → pre-boot 404.
	// - allowed-known-slug    real slug within depth     → handed to WordPress.
	// - allowed-reserved-slug reserved slug (another post type's URL) → WordPress.
	// - allowed-deep-path     real slug too deep, action=passthrough → WordPress.
	// - blocked-deep-path     real slug too deep, action=404 → pre-boot 404.
	// - redirect-deep-path    real slug too deep, action=redirect → 301 to truncation.
	// (No header at all = the shield did not engage; WordPress handled it normally.)

	// Emit a 404 and stop. $marker records which block decision it was.
	// $ttl is the origin/shared cache lifetime (s-maxage) and the browser + CDN
	// default (max-age); 0 => no-store (never cached; the probe path).
	// $edge_ttl is OPTIONAL and for publishable outcomes only. When set, the CDN
	// edge and the browser are capped to this SHORTER value (via CDN-Cache-Control
	// + max-age) while the ORIGIN still holds the response for the full $ttl
	// (s-maxage) and stays purgeable per URL on publish. That is how "edge TTL 60s,
	// server TTL 10min" is expressed: a prematurely edge-cached 404 self-heals in
	// $edge_ttl s (there is no per-URL CDN purge), while the origin absorbs the
	// flood and is purged on publish. Needs the CDN to honour CDN-Cache-Control
	// (Cloudflare / WP Engine Advanced Network — VERIFY per env; WPE has been seen
	// rewriting cache headers). Harmless if the CDN ignores it.
	// Body: the baked, themed 404 for this locale if present, else the default
	// baked page, else a minimal inline stub — so a shielded 404 looks like the
	// real WordPress one. All pre-boot: static file reads only, no WordPress. The
	// locale is re-checked against a strict charset before it touches a file path:
	// a permissive CUSTOM locale pattern (e.g. a class range spanning `/`) could
	// otherwise capture a traversal-shaped string.
	$emit_404 = static function ( string $marker, string $locale, int $ttl = 0, ?int $edge_ttl = null ) use ( $allowlist_dir ): void {
		if ( ! headers_sent() ) {
			http_response_code( 404 );
			header( 'Content-Type: text/html; charset=UTF-8' );
			if ( $ttl > 0 ) {
				// Origin (a shared cache reading s-maxage, not CDN-Cache-Control)
				// always caches for $ttl; the browser + CDN follow $edge_ttl if given.
				$public_ttl = null !== $edge_ttl ? $edge_ttl : $ttl;
				header( 'Cache-Control: public, max-age=' . $public_ttl . ', s-maxage=' . $ttl );
				if ( null !== $edge_ttl ) {
					header( $edge_ttl > 0 ? 'CDN-Cache-Control: max-age=' . $edge_ttl : 'CDN-Cache-Control: no-store' );
				}
			} else {
				header( 'Cache-Control: no-store, max-age=0' );
			}
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-Post-Shield: ' . $marker );
		}

		// An agent that prefers Markdown gets the SITE's short Markdown pointer
		// back into the site instead of the themed HTML page — a dead URL is
		// where it most needs a route onward. Opt-in: the site provides
		// wp-content/mu-plugins/post-404-shield-markdown-404.md (beside this
		// plugin, so a sync never touches it); no file, no change. `{base_url}`
		// in it becomes this request's validated https origin. Status stays 404.
		//
		// NEVER cacheable: CDNs rarely vary their cache key on Accept, so a
		// stored Markdown 404 could later reach a browser on the same URL. This
		// overrides the cache headers set above, deliberately.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- pre-boot (no WordPress); parsed, never output.
		if ( '' !== $accept && \Post404Shield\prefers_markdown( $accept ) ) {
			$markdown_file = dirname( __DIR__ ) . '/post-404-shield-markdown-404.md';
			$markdown      = is_readable( $markdown_file ) ? file_get_contents( $markdown_file ) : false; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
			if ( is_string( $markdown ) && '' !== $markdown ) {
				if ( ! headers_sent() ) {
					header( 'Content-Type: text/markdown; charset=UTF-8' );
					header( 'Cache-Control: no-store, max-age=0' );
					header( 'CDN-Cache-Control: no-store' );
					header( 'Vary: Accept' );
				}
				// The host only ever reaches the BODY, never a header — but validate
				// it anyway, falling back to site-relative links if it looks wrong.
				$host     = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- validated by the pattern below.
				$base_url = 1 === preg_match( '/^[A-Za-z0-9.-]{1,253}$/', $host ) ? 'https://' . $host : '';
				echo str_replace( '{base_url}', $base_url, $markdown ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text Markdown body, not HTML.
				exit;
			}
		}

		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $locale ) ) {
			$locale = 'default';
		}
		foreach ( [ $allowlist_dir . '/404/' . $locale . '.html', $allowlist_dir . '/404/default.html' ] as $page ) {
			if ( is_readable( $page ) ) {
				$html = file_get_contents( $page ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
				if ( is_string( $html ) && '' !== $html ) {
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-baked themed 404 markup.
					exit;
				}
			}
		}
		echo "<!doctype html>\n<title>404 Not Found</title>\n<h1>Not Found</h1>\n";
		exit;
	};

	// The bake probe path is INTERNAL: crawlers discovered it via language-
	// switcher links in previously-baked markup and hammered it with full,
	// uncacheable WordPress renders. Only a request carrying the current probe
	// token (the baker's own loopback; token lives in a guarded uploads file and
	// never appears in markup) may reach WordPress — everyone else gets the cheap
	// baked 404. Entry-independent: this guard applies even with all types off.
	$probe = \Post404Shield\match_blocked_base( $path, 'post-shield-404-probe', $locale_pattern );
	if ( null !== $probe ) {
		$token_ok = false;
		$query    = parse_url( $uri, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() does not exist pre-boot.
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $params );
			$sent = isset( $params['post_shield_bake'] ) && is_string( $params['post_shield_bake'] ) ? $params['post_shield_bake'] : '';
			if ( '' !== $sent ) {
				$token_file = $allowlist_dir . '/probe-token.php';
				if ( is_readable( $token_file ) ) {
					$raw = file_get_contents( $token_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
					if ( is_string( $raw ) ) {
						$newline = strpos( $raw, "\n" );
						if ( false !== $newline ) {
							$stored   = trim( substr( $raw, $newline ) );
							$token_ok = '' !== $stored && hash_equals( $stored, $sent );
						}
					}
				}
			}
		}
		if ( ! $token_ok ) {
			$nr_tag( 'blocked-probe-path', 'probe' );
			$emit_404( 'blocked-probe-path', $probe['locale'], 0 ); // Internal path — never cache.
		}
		return; // Valid token — the baker's own loopback; let WordPress render it.
	}

	// --- Based entries -------------------------------------------------------
	// The decision itself lives in decide_based() (Matcher.php), shared with
	// the root preflight so the gate replays exactly what runs here. This block
	// only carries it out: marker header, New Relic tag, cache lifetimes, the
	// 404 body or the redirect. $emit_404 always exits.
	$read_allowlist = static function ( string $type ) use ( $allowlist_dir ): ?string {
		$file = $allowlist_dir . '/' . $type . '/allowlist.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
		return false === $raw ? null : $raw;
	};
	$decision = \Post404Shield\decide_based( $uri, $path, $types, $read_allowlist, $locale_pattern );
	if ( null !== $decision ) {
		$settings    = $types[ $decision['key'] ];
		$shield_type = $decision['type'];
		$marker      = $decision['marker'];
		// Per-entry lifetimes; null => the per-outcome defaults above.
		$entry_ttl      = isset( $settings['cache_ttl'] ) ? max( 0, (int) $settings['cache_ttl'] ) : null;
		$entry_edge_ttl = isset( $settings['edge_ttl'] ) ? max( 0, (int) $settings['edge_ttl'] ) : null;

		switch ( $marker ) {
			case 'pass':
				return; // Claimed, but WordPress decides — no marker.

			case 'blocked-denied-base':
				// Nothing can ever be published under a denied base: cache hard.
				$nr_tag( $marker, $shield_type );
				$emit_404( $marker, $decision['locale'], $entry_ttl ?? $denied_base_ttl );
				return;

			case 'blocked-unknown-slug':
			case 'blocked-deep-path':
				// Short TTLs: publishing the slug (or a child) makes the URL real.
				$nr_tag( $marker, $shield_type );
				$emit_404( $marker, $decision['locale'], $entry_ttl ?? $publishable_ttl, $entry_edge_ttl ?? $publishable_edge_ttl );
				return;

			case 'redirect-deep-path':
				$nr_tag( $marker, $shield_type );
				if ( ! headers_sent() ) {
					header( 'X-Post-Shield: redirect-deep-path' );
					header( 'Location: ' . $decision['location'], true, 301 );
				}
				exit;

			default:
				// allowed-known-slug, allowed-reserved-slug, allowed-deep-path:
				// hand off to WordPress, marked so debugging can tell it apart
				// from "the shield never ran".
				$nr_tag( $marker, $shield_type );
				if ( ! headers_sent() ) {
					header( 'X-Post-Shield: ' . $marker );
				}
				return;
		}
	}

	// --- Root catch-all (root-pages v2) --------------------------------------
	// Runs LAST, only when no based entry claimed the URI above (an allowed or
	// blocked based decision returned/exited already; a based fall-through lands
	// here but is handed straight back to WordPress via the excluded bases —
	// every configured base is re-excluded below, belt-and-braces on top of the
	// derived snapshot). Root mode is the arbiter of every URL not owned by a
	// base or an exclusion, so each precondition failing open is deliberate:
	// no enabled root entry, a missing/malformed excluded_bases snapshot, or an
	// unreadable/EMPTY per-type allowlist all mean "root mode is not in place".
	if ( [] === $root_entries ) {
		return;
	}
	if ( ! function_exists( 'Post404Shield\\match_root' ) ) {
		return;
	}

	$excluded = $config['excluded_bases'] ?? null;
	if ( ! is_array( $excluded ) ) {
		return;
	}
	$excluded_flat = [];
	foreach ( [ 'floor', 'derived', 'operator' ] as $bucket ) {
		$rows = $excluded[ $bucket ] ?? null;
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( is_string( $row ) && '' !== $row ) {
				$excluded_flat[] = $row;
			}
		}
	}
	// Every configured entry's bases (enabled or not, block or allowlist) are
	// excluded from root matching regardless of the snapshot: a disabled type's
	// URLs and a based entry's fall-throughs always belong to WordPress. A ROOT
	// entry's reserved slugs join the same list — at the root, "reserved" means
	// "this first segment is someone else's URL space; always pass it through".
	foreach ( $types as $settings ) {
		foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
			if ( is_string( $base ) && '' !== $base ) {
				$excluded_flat[] = $base;
			}
		}
		if ( true === ( $settings['root'] ?? false ) ) {
			foreach ( (array) ( $settings['reserved_allowlist'] ?? [] ) as $reserved_slug ) {
				if ( is_string( $reserved_slug ) && '' !== $reserved_slug ) {
					$excluded_flat[] = $reserved_slug;
				}
			}
		}
	}

	// The union candidates, in order (page first — the ambiguity owner). Each is
	// a loader, read only if match_root() reaches it: its cheap checks answer most
	// requests without any read, and a hit on `page` skips the rest. That keeps a
	// real page view to one allowlist read instead of every one (about a
	// millisecond each on network storage).
	// Each enabled root type MUST contribute a readable, NON-EMPTY allowlist, or
	// root mode is inert: with no base to scope it, matching against a missing or
	// empty list would 404 the whole site. A loader returns null for that, and
	// match_root() then passes the request through (fail-open) — it can only
	// block after every loader has returned a usable list.
	$candidates = [];
	foreach ( $root_entries as $root_type => $settings ) {
		$allowlist_file = $allowlist_dir . '/' . $root_type . '/allowlist.php';
		$candidates[]   = [
			'type'             => (string) $root_type,
			'allow_pagination' => ! isset( $settings['allow_pagination'] ) || false !== $settings['allow_pagination'],
			'body'             => static function () use ( $allowlist_file ): ?string {
				if ( ! is_readable( $allowlist_file ) ) {
					return null;
				}
				$raw = file_get_contents( $allowlist_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
				if ( false === $raw ) {
					return null;
				}
				$guard_end = strpos( $raw, "\n" );
				if ( false === $guard_end || ! isset( $raw[ $guard_end + 1 ] ) || "\n" === $raw[ $guard_end + 1 ] ) {
					return null;
				}
				return $raw;
			},
		];
	}

	// The root-extras union member: attachment URIs + old slugs, folded in
	// automatically (never an operator row). The FILE must exist — a missing
	// build means the union is incomplete and root mode may not block anything —
	// but an EMPTY file is a legitimate state (no attachments, no renames).
	$extras_file  = $allowlist_dir . '/root-extras/allowlist.php';
	$candidates[] = [
		'type'             => 'root-extras',
		'allow_pagination' => true,
		'body'             => static function () use ( $extras_file ): ?string {
			if ( ! is_readable( $extras_file ) ) {
				return null;
			}
			$raw = file_get_contents( $extras_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
			return false === $raw ? null : $raw;
		},
	];

	$decision = \Post404Shield\match_root( $path, $excluded_flat, $candidates, $locale_pattern );

	if ( 'allowed' === $decision['outcome'] ) {
		$nr_tag( 'allowed-known-slug', $decision['type'] );
		if ( ! headers_sent() ) {
			header( 'X-Post-Shield: allowed-known-slug' );
		}
		return;
	}

	if ( 'blocked' === $decision['outcome'] ) {
		// TTL overrides come from the FIRST root entry (`page` — a blocked root
		// URL has no single owning type; documented in HOW-IT-WORKS).
		$first_settings = reset( $root_entries );
		$root_ttl       = isset( $first_settings['cache_ttl'] ) ? max( 0, (int) $first_settings['cache_ttl'] ) : null;
		$root_edge_ttl  = isset( $first_settings['edge_ttl'] ) ? max( 0, (int) $first_settings['edge_ttl'] ) : null;
		$nr_tag( 'blocked-unknown-slug', $decision['type'] );
		$emit_404( 'blocked-unknown-slug', $decision['locale'], $root_ttl ?? $publishable_ttl, $root_edge_ttl ?? $publishable_edge_ttl );
	}
} )();
