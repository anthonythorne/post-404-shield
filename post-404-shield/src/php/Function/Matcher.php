<?php
/**
 * Pure path-matching helpers for the post 404 shield.
 *
 * No WordPress, no side effects — safe to require from the pre-boot front-end
 * bootstrap (wp-config Tier 1) and to unit-test in isolation. Generic over the
 * URL base + reserved slugs so every managed post type reuses them, and over
 * the LOCALE PATTERN: the language-prefix segment is a config-supplied regex
 * body (e.g. `[a-z]{2}-[a-z]{2}|global`), or '' for sites with no language
 * directories — the shape difference between a multilingual site and a
 * single-language one is config, not code.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Function/Matcher.php
 *
 * @package Post404Shield\Function
 */

declare(strict_types=1);

namespace Post404Shield;

/**
 * Match a `/{locale}/{base}/{slug}[/{extra}...]` path and split out its parts.
 *
 * When $locale_pattern is non-empty the prefix is REQUIRED — a bare
 * `/{base}/{slug}/` deliberately fails to match and falls through to WordPress
 * (on a multilingual site the language plugin owns those URLs). When it is '' there is NO prefix
 * segment at all and the returned locale is the literal `default`. The base may
 * be multi-segment (`products/shoes`). Named captures, so a future pattern
 * change can never silently renumber the groups; a preg engine error (a pattern
 * the compile-time validation could not foresee) is treated as a non-match —
 * fall through, fail-open. Returns null for a non-matching path or a reserved
 * slug; a malformed (non-ASCII/uppercase) entry slug also falls through.
 *
 * @param string   $path           URL path with the query string already stripped.
 * @param string   $url_base       The (non-localised) base, e.g. `team` or `products/shoes`.
 * @param string[] $reserved       Slugs that are reserved / other post types, never shielded.
 * @param string   $locale_pattern Regex BODY for the locale segment, or '' for none.
 *
 * @return array{locale:string, slug:string, extra:string[]}|null Parts, or null.
 */
function match_entry( string $path, string $url_base, array $reserved = [], string $locale_pattern = '' ): ?array {
	$prefix  = '' === $locale_pattern ? '' : '(?<locale>' . $locale_pattern . ')/';
	$pattern = '#^/' . $prefix . preg_quote( $url_base, '#' ) . '/(?<slug>[a-z0-9-]+)(?<extra>(?:/[^/]+)*)/?$#';

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a config-supplied pattern must fall through, not warn.
	$result = @preg_match( $pattern, $path, $matches );
	if ( 1 !== $result ) {
		return null;
	}

	// A degenerate pattern body (e.g. an unterminated class swallowing the
	// group syntax) can compile into something that matches WITHOUT the named
	// captures — treat that as a non-match too (fail-open).
	if ( ! isset( $matches['slug'] ) || '' === $matches['slug'] ) {
		return null;
	}

	if ( in_array( $matches['slug'], $reserved, true ) ) {
		return null;
	}

	$extra = trim( $matches['extra'] ?? '', '/' );

	return [
		'locale' => isset( $matches['locale'] ) && '' !== $matches['locale'] ? $matches['locale'] : 'default',
		'slug'   => $matches['slug'],
		'extra'  => '' === $extra ? [] : explode( '/', $extra ),
	];
}

/**
 * Is a slug reserved — exact entry, or a `prefix*` wildcard entry?
 *
 * Operator-typed reserved slugs are exact. DERIVED reserved slugs (built from
 * redirect sources) can also be a within-segment prefix: a regex redirect like
 * `^products/shoes/trail-(.*)$` yields the literal `trail-` plus a trailing `*`,
 * because the pattern matches a family of slugs rather than one. A prefix entry
 * therefore has to prefix-match, and the wildcard is only ever honoured as a
 * TRAILING character — never mid-string — so a slug can never be reserved by an
 * accidental `*` inside a value.
 *
 * Pure: no WordPress. Runs pre-boot on every shielded request.
 *
 * @param string   $slug     Captured slug.
 * @param string[] $reserved Reserved entries (exact, or `prefix*`).
 *
 * @return bool True when the slug is reserved.
 */
function slug_is_reserved( string $slug, array $reserved ): bool {
	foreach ( $reserved as $entry ) {
		if ( ! is_string( $entry ) || '' === $entry ) {
			continue;
		}
		if ( '*' !== substr( $entry, -1 ) ) {
			if ( $entry === $slug ) {
				return true;
			}
			continue;
		}
		$prefix = substr( $entry, 0, -1 );
		if ( '' !== $prefix && 0 === strpos( $slug, $prefix ) ) {
			return true;
		}
	}
	return false;
}


/**
 * Match a path under a BLOCKED base — a base with no real content at all
 * (`mode => 'block'` in config), where everything is a bot target. Unlike
 * match_entry(), the bare base itself matches (`/de-de/old-section` and
 * `/de-de/old-section/`), as does any depth below it; there is no slug to
 * extract. Locale-prefix semantics are identical to match_entry(). Returns the
 * locale (for the themed 404) or null for a non-match.
 *
 * @param string $path           URL path with the query string already stripped.
 * @param string $url_base       The (non-localised) blocked base, e.g. `old-section`.
 * @param string $locale_pattern Regex BODY for the locale segment, or '' for none.
 *
 * @return array{locale:string}|null Locale, or null to fall through.
 */
function match_blocked_base( string $path, string $url_base, string $locale_pattern = '' ): ?array {
	$prefix  = '' === $locale_pattern ? '' : '(?<locale>' . $locale_pattern . ')/';
	$pattern = '#^/' . $prefix . preg_quote( $url_base, '#' ) . '(?:/.*)?$#';

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a config-supplied pattern must fall through, not warn.
	$result = @preg_match( $pattern, $path, $matches );
	if ( 1 !== $result ) {
		return null;
	}

	return [ 'locale' => isset( $matches['locale'] ) && '' !== $matches['locale'] ? $matches['locale'] : 'default' ];
}

/**
 * Strip WordPress's trailing sub-routes off a segment list before an allowlist
 * membership test, so page 2 of a real post (or its feed/embed) passes with its
 * parent instead of 404ing (fail-closed guard b).
 *
 * Two classes, gated separately:
 *   - core content sub-routes (`feed`, `feed/{format}`, `embed`, `trackback`,
 *     and the `attachment/{name}` marker — WordPress's canonical attachment
 *     URL under a parent whose slug would otherwise collide, e.g. a numeric
 *     media name: `/{parent}/attachment/1/`) are ALWAYS stripped — they are
 *     never part of a content path;
 *   - pagination sub-routes (`page/N`, `comment-page-N`, and a trailing bare
 *     numeric segment — WordPress's `<!--nextpage-->` form `/{path}/2/`) are
 *     stripped only while $allow_pagination is true (the per-entry checkbox).
 *     Unticked, they count as ordinary path segments, so fake pagination under
 *     a type that never paginates fast-404s.
 *
 * One layer only (mirrors the loader's historical behaviour); a bare numeric is
 * never stripped when it is the ONLY segment (it would erase the slug itself).
 * The deliberate fail-open trade: a fake child path ending in a number falls
 * through to WordPress (slow 404) instead of fast-404ing — never the reverse.
 *
 * @param string[] $segments         Path segments, no empties.
 * @param bool     $allow_pagination Strip pagination sub-routes too (default on).
 * @param string[] $endpoints        Rewrite endpoint names that apply after a
 *                                   content path (the artifact's
 *                                   excluded_bases.endpoints).
 *
 * @return string[] Remaining segments (possibly empty — the sub-route alone).
 */
function strip_trailing_sub_routes( array $segments, bool $allow_pagination = true, array $endpoints = [] ): array {
	// A rewrite endpoint (add_rewrite_endpoint() on pages/permalinks: AMP,
	// shop account screens…) sits after a real path and may carry a value
	// (`/{path}/orders/2/`): everything from it on belongs to WordPress.
	if ( [] !== $endpoints ) {
		$total = count( $segments );
		for ( $k = 1; $k < $total; $k++ ) {
			if ( in_array( $segments[ $k ], $endpoints, true ) ) {
				return array_slice( $segments, 0, $k );
			}
		}
	}

	$count = count( $segments );
	$last  = $segments[ $count - 1 ] ?? '';
	$prev  = $segments[ $count - 2 ] ?? '';

	if ( 'feed' === $prev || 'attachment' === $prev ) {
		array_splice( $segments, -2 );
	} elseif ( $allow_pagination && 'page' === $prev && 1 === preg_match( '/^\d+$/', $last ) ) {
		array_splice( $segments, -2 );
	} elseif ( in_array( $last, [ 'feed', 'embed', 'trackback' ], true ) ) {
		array_splice( $segments, -1 );
	} elseif ( in_array( $last, [ 'rdf', 'rss', 'rss2', 'atom' ], true ) ) {
		// WordPress routes the bare feed format too: `/{path}/rss2/` is that
		// path's feed, as `/{path}/feed/rss2/` is — and a lone `/rss2/` is the
		// site feed (core's rule wins over a page of that name). Stripped to
		// nothing, it passes.
		array_splice( $segments, -1 );
	} elseif ( $allow_pagination && 1 === preg_match( '/^comment-page-\d+$/', $last ) ) {
		array_splice( $segments, -1 );
	} elseif ( $allow_pagination && $count > 1 && 1 === preg_match( '/^\d+$/', $last ) ) {
		array_splice( $segments, -1 );
	}

	return $segments;
}

/**
 * Root catch-all decision — the whole-site allowlist mode for root-dwelling
 * types (`page`, and `post` under a bare permalink structure).
 *
 * Root mode has no base to scope it, so this function is the arbiter of every
 * public URL not claimed by a based entry or an exclusion. The contract is
 * FAIL-OPEN HARD: any doubt — unusual characters, an excluded namespace, an
 * empty segment list, a locale mismatch — returns `pass` (WordPress owns the
 * request). Only a clean, slug-shaped path that misses every candidate
 * allowlist is `blocked`.
 *
 * Decision order:
 *   1. locale strip per $locale_pattern (required prefix when non-empty —
 *      composable for locale-directory sites; '' = no prefix segment);
 *   2. `/` itself, or any segment outside `[a-z0-9_-]` (dots, uppercase,
 *      percent-encodings, unicode) → pass — bots enumerate ASCII slugs, and
 *      everything else is not this shield's business;
 *   3. a hardcoded core floor (wp-admin, wp-json, wp-content, wp-includes,
 *      the bake probe path) → pass, regardless of what the artifact says;
 *   4. an all-digit first segment (date archives `/2026/…`, front-page
 *      `<!--nextpage-->` `/2/`) → pass;
 *   5. any $excluded_bases entry whose segments prefix the path (a single
 *      trailing `*` prefix-matches its final segment: `sitemap*`) → pass;
 *   6. per-candidate membership: sub-routes stripped per the entry's
 *      allow_pagination flag, then the joined path is tested against the
 *      entry's allowlist buffer → `allowed` on the first hit;
 *   7. no hit → `blocked`, attributed to the FIRST candidate's type (root
 *      ownership is inherently ambiguous; the loader orders `page` first).
 *
 * A candidate's `body` may be the allowlist buffer itself or a callable that
 * returns it (`?string`). The loader passes callables so an allowlist file is
 * read only when step 6 reaches it: steps 1–5 answer most requests without
 * reading any, and a hit on the first candidate skips the rest. Each read costs
 * about a millisecond on network storage, so that is most of this function's
 * real cost. A callable is invoked at most once. If one returns null, root
 * mode is not in place (a missing or empty allowlist) and the result is
 * `pass`: without every buffer, a miss cannot justify a block.
 *
 * @param string $path           URL path with the query string already stripped.
 * @param array  $excluded_bases Flat list of excluded base entries (floor + derived + operator, pre-flattened).
 * @param array  $entries        Ordered candidates: {type: string, allow_pagination: bool, body: string|callable(): ?string} each.
 * @param string $locale_pattern Regex BODY for the locale segment, or '' for none.
 *
 * @return array{outcome: 'pass'|'allowed'|'blocked', type: string, locale: string}
 */
function match_root( string $path, array $excluded_bases, array $entries, string $locale_pattern = '' ): array {
	$pass   = static function ( string $locale ): array {
		return [
			'outcome' => 'pass',
			'type'    => '',
			'locale'  => $locale,
		];
	};
	$locale = 'default';

	if ( '' !== $locale_pattern ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a config-supplied pattern must fall through, not warn.
		$result = @preg_match( '#^/(?<locale>' . $locale_pattern . ')(?<rest>/.*)?$#', $path, $matches );
		if ( 1 !== $result || ! isset( $matches['locale'] ) || '' === $matches['locale'] ) {
			return $pass( $locale );
		}
		$locale = $matches['locale'];
		$path   = $matches['rest'] ?? '/';
	}

	$segments = [];
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment ) {
			continue;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $segment ) ) {
			return $pass( $locale );
		}
		$segments[] = $segment;
	}
	if ( [] === $segments ) {
		return $pass( $locale );
	}

	// Core floor, enforced in code — even a hostile-but-valid artifact cannot
	// take these namespaces away from WordPress.
	if ( in_array( $segments[0], [ 'wp-admin', 'wp-json', 'wp-content', 'wp-includes', 'post-shield-404-probe' ], true ) ) {
		return $pass( $locale );
	}

	// Date archives (`/2026/…`) and front-page <!--nextpage--> (`/2/`).
	if ( 1 === preg_match( '/^\d+$/', $segments[0] ) ) {
		return $pass( $locale );
	}

	foreach ( $excluded_bases as $excluded ) {
		if ( ! is_string( $excluded ) || '' === $excluded ) {
			continue;
		}
		$wild = str_ends_with( $excluded, '*' );
		$raw  = $wild ? substr( $excluded, 0, -1 ) : $excluded;
		if ( '' === $raw ) {
			continue;
		}
		$excluded_segments = explode( '/', $raw );
		$length            = count( $excluded_segments );
		if ( count( $segments ) < $length ) {
			continue;
		}
		$matched = true;
		for ( $i = 0; $i < $length - 1; $i++ ) {
			if ( $segments[ $i ] !== $excluded_segments[ $i ] ) {
				$matched = false;
				break;
			}
		}
		if ( ! $matched ) {
			continue;
		}
		$tail = $excluded_segments[ $length - 1 ];
		if ( $wild ? str_starts_with( $segments[ $length - 1 ], $tail ) : $segments[ $length - 1 ] === $tail ) {
			return $pass( $locale );
		}
	}

	$first_type = '';
	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$body = $entry['body'] ?? null;
		if ( ! is_string( $body ) && is_callable( $body ) ) {
			$body = $body();
			if ( null === $body ) {
				return $pass( $locale ); // A missing or empty allowlist: root mode is not in place.
			}
		}
		if ( ! is_string( $body ) || '' === $body ) {
			continue; // An empty candidate can never justify a block (fail-open).
		}
		$type = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
		if ( '' === $first_type && '' !== $type ) {
			$first_type = $type;
		}
		$stripped = strip_trailing_sub_routes( $segments, ! isset( $entry['allow_pagination'] ) || false !== $entry['allow_pagination'], (array) ( $entry['endpoints'] ?? [] ) );
		if ( [] === $stripped ) {
			return $pass( $locale ); // The sub-route alone (`/feed/`, `/page/2/`) — WordPress owns it.
		}
		// A caller replaying thousands of paths (the root preflight) may pass
		// `set`, the same lines as a hash map, so each lookup is O(1) instead
		// of a scan of the whole body. The loader never does; it reads one path.
		$line  = implode( '/', $stripped );
		$found = isset( $entry['set'] ) && is_array( $entry['set'] ) ? isset( $entry['set'][ $line ] ) : is_allowed( $line, $body );
		if ( $found ) {
			return [
				'outcome' => 'allowed',
				'type'    => $type,
				'locale'  => $locale,
			];
		}
	}

	if ( '' === $first_type ) {
		return $pass( $locale ); // No usable candidate at all — root mode is inert.
	}

	return [
		'outcome' => 'blocked',
		'type'    => $first_type,
		'locale'  => $locale,
	];
}

/**
 * Determine whether a slug is present in a newline-delimited allowlist buffer.
 *
 * Matches a whole line, so `eric` does not match `eric-bouvet`. Uses substring
 * search rather than building an array on every request, so the hot miss path
 * (the bot flood) allocates nothing beyond the small needle — a 100 KB+ list
 * costs one SIMD scan, not an explode into thousands of array entries.
 *
 * Accepts EITHER the raw allowlist file (the `<?php exit;` guard line included)
 * OR a guard-stripped body. The first branch matches a slug on the very first
 * line (stripped body); the second matches any slug wrapped in "\n…\n", which
 * covers every slug in the raw file too, since the guard line is itself newline-
 * terminated. So the loader can pass the raw buffer straight in, with no copy.
 * Works unchanged for full-path allowlists — a "line" is then a slash-joined
 * hierarchical path instead of a single slug.
 *
 * @param string $slug Slug (or full sub-path) to test.
 * @param string $body Allowlist buffer, one entry per line, each terminated by
 *                     "\n". May be the raw file (guard line first) or stripped.
 *
 * @return bool True when the entry is a known, publishable line.
 */
function is_allowed( string $slug, string $body ): bool {
	return str_starts_with( $body, $slug . "\n" )
		|| str_contains( $body, "\n" . $slug . "\n" );
}

/**
 * Whether an Accept header prefers Markdown over HTML. `text/markdown` must be
 * named explicitly with q > 0 AND be at least as preferred as `text/html` /
 * `application/xhtml+xml`. Browsers never qualify: the any-type wildcard is
 * not a request for Markdown, and their explicit `text/html` outranks it.
 *
 * Pure — used by the pre-boot 404 emitter, so no WordPress functions.
 *
 * @param string $accept Raw Accept header value.
 *
 * @return bool
 */
function prefers_markdown( string $accept ): bool {
	$markdown = -1.0;
	$html     = -1.0;
	foreach ( explode( ',', strtolower( $accept ) ) as $candidate ) {
		$params = explode( ';', $candidate );
		$type   = trim( (string) array_shift( $params ) );
		$q      = 1.0;
		foreach ( $params as $param ) {
			$param = trim( $param );
			if ( 0 === strpos( $param, 'q=' ) ) {
				$q = (float) substr( $param, 2 );
			}
		}
		if ( 'text/markdown' === $type ) {
			$markdown = max( $markdown, $q );
		} elseif ( 'text/html' === $type || 'application/xhtml+xml' === $type ) {
			$html = max( $html, $q );
		}
	}
	return $markdown > 0.0 && $markdown >= $html;
}

/**
 * The based-entry decision for one request — what the pre-boot loader does,
 * returned as data instead of emitted.
 *
 * The single source of truth for the based stage: the loader acts on the
 * result (headers, TTLs, the 404 body, the redirect) and the root preflight
 * replays it. Two hand-written copies of this tree drifted twice before it
 * was shared, and each drift was a gate that could not see what the loader
 * would do.
 *
 * Entries are walked in config order and the FIRST entry/base that claims
 * the path decides. A base whose substring matches but whose precise match
 * fails does NOT claim it — the next base, then the next entry, may (a
 * return there was a live shield bypass).
 *
 * Markers: `blocked-denied-base`, `allowed-reserved-slug`,
 * `allowed-known-slug`, `blocked-unknown-slug`, `allowed-deep-path`,
 * `blocked-deep-path`, `redirect-deep-path` (with `location`), and `pass` —
 * claimed, but handed to WordPress with no marker (missing or empty
 * allowlist, a bare sub-route, or a redirect target that cannot be trusted).
 * Null means no entry claimed the path: the root stage decides next.
 *
 * Pure: the only I/O is through $allowlist, which the loader backs with a
 * lazy file read and the preflight with bodies already in memory.
 *
 * @param string                    $uri            Request URI, query included (substring pre-checks, redirect query).
 * @param string                    $path           Its path component.
 * @param array<string|int, mixed>  $entries        Validated config entries, in config order.
 * @param callable(string): ?string $allowlist      Raw allowlist (guard line + lines) for a type; null when missing or unreadable.
 * @param string                    $locale_pattern Locale pattern body ('' = none).
 * @param string[]                  $endpoints      Rewrite endpoints stripped like sub-routes.
 *
 * @return array{marker: string, key: string|int, type: string, locale: string, location: string}|null
 */
function decide_based( string $uri, string $path, array $entries, callable $allowlist, string $locale_pattern, array $endpoints = [] ): ?array {
	foreach ( $entries as $key => $settings ) {
		if ( ! is_array( $settings ) || ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) ) {
			continue;
		}
		$type     = (string) ( $settings['post_type'] ?? $key );
		$decision = static function ( string $marker, string $locale = '', string $location = '' ) use ( $key, $type ): array {
			return [
				'marker'   => $marker,
				'key'      => $key,
				'type'     => $type,
				'locale'   => $locale,
				'location' => $location,
			];
		};

		// mode=block: the bare base and anything under it, at any depth.
		if ( 'block' === ( $settings['mode'] ?? 'allowlist' ) ) {
			foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
				if ( false === strpos( $uri, '/' . $base ) ) {
					continue;
				}
				$blocked = match_blocked_base( $path, (string) $base, $locale_pattern );
				if ( null !== $blocked ) {
					return $decision( 'blocked-denied-base', (string) $blocked['locale'] );
				}
			}
			continue;
		}

		foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
			if ( false === strpos( $uri, '/' . $base . '/' ) ) {
				continue;
			}
			$match = match_entry( $path, (string) $base, [], $locale_pattern );
			if ( null === $match ) {
				continue; // Substring only — let a later base or entry claim it.
			}

			// Both reserved buckets, wildcard-aware: operator-typed (exact) and
			// derived from redirect plugins (may carry `prefix*`).
			$reserved = array_merge(
				(array) ( $settings['reserved_allowlist'] ?? [] ),
				(array) ( $settings['reserved_derived'] ?? [] )
			);
			if ( [] !== $reserved && slug_is_reserved( $match['slug'], $reserved ) ) {
				return $decision( 'allowed-reserved-slug' );
			}

			// Missing, unreadable or empty allowlist → WordPress (fail-open).
			// O(1) emptiness test: only the byte after the guard's newline.
			$raw = $allowlist( $type );
			if ( null === $raw ) {
				return $decision( 'pass' );
			}
			$guard_end = strpos( $raw, "\n" );
			if ( false === $guard_end || ! isset( $raw[ $guard_end + 1 ] ) || "\n" === $raw[ $guard_end + 1 ] ) {
				return $decision( 'pass' );
			}

			$allow_pagination = ! isset( $settings['allow_pagination'] ) || false !== $settings['allow_pagination'];
			$segments         = strip_trailing_sub_routes( array_merge( [ $match['slug'] ], $match['extra'] ), $allow_pagination, $endpoints );
			if ( [] === $segments ) {
				return $decision( 'pass' ); // A sub-route alone (`/{base}/feed/`) — WordPress owns it.
			}

			// match=full-path: the whole sub-path is exact-matched; no depth policy.
			if ( 'full-path' === ( $settings['match'] ?? 'slug' ) ) {
				// The builder only writes `[a-z0-9_-]` segments, so a percent-
				// encoded (non-ASCII) or mixed-case segment can never be listed —
				// WordPress judges it, exactly as match_root() does.
				foreach ( $segments as $segment ) {
					if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $segment ) ) {
						return $decision( 'pass' );
					}
				}
				return is_allowed( implode( '/', $segments ), $raw )
					? $decision( 'allowed-known-slug' )
					: $decision( 'blocked-unknown-slug', (string) $match['locale'] );
			}

			if ( ! is_allowed( $match['slug'], $raw ) ) {
				return $decision( 'blocked-unknown-slug', (string) $match['locale'] );
			}

			$depth_allowed = $settings['depth_allowed'] ?? null;
			if ( null === $depth_allowed || count( $segments ) - 1 <= (int) $depth_allowed ) {
				return $decision( 'allowed-known-slug' );
			}

			$action = $settings['depth_action'] ?? 'passthrough';
			if ( 'passthrough' === $action ) {
				return $decision( 'allowed-deep-path' );
			}
			if ( '404' === $action ) {
				return $decision( 'blocked-deep-path', (string) $match['locale'] );
			}

			// 'redirect': 301 to the truncation, query string kept. A locale the
			// operator's pattern captured is only trusted if it is a plain
			// segment — a custom character class can span `/` and turn the target
			// into `//evil.com/…` — and a target that could leave the site fails
			// open rather than redirect.
			if ( 'default' !== $match['locale'] && 1 !== preg_match( '/^[a-z0-9-]+$/', (string) $match['locale'] ) ) {
				return $decision( 'pass' );
			}
			$kept   = array_slice( $match['extra'], 0, (int) $depth_allowed );
			$target = ( 'default' === $match['locale'] ? '' : '/' . $match['locale'] ) . '/' . $base . '/' . $match['slug'];
			if ( [] !== $kept ) {
				$target .= '/' . implode( '/', $kept );
			}
			$target .= '/';
			$query   = parse_url( $uri, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure and pre-boot: wp_parse_url() may not exist.
			if ( is_string( $query ) && '' !== $query ) {
				$target .= '?' . $query;
			}
			$target = str_replace( [ "\r", "\n", "\0" ], '', $target ); // Header-injection guard.
			if ( 0 === strpos( $target, '//' ) || 0 === strpos( $target, '/\\' ) ) {
				return $decision( 'pass' );
			}
			return $decision( 'redirect-deep-path', (string) $match['locale'], $target );
		}
	}
	return null;
}
