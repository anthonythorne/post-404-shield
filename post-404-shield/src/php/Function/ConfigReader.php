<?php
/**
 * Pure reader for the generated shield config artifact.
 *
 * The artifact (`uploads/post-404-shield/config.php`) is line 1 `<?php exit;`
 * (the guard a direct HTTP hit executes) followed by a single JSON document.
 * It is NEVER include()d or executed — this reader treats it as text: read,
 * strip the guard line, json_decode, validate. A poisoned or corrupt file is
 * inert: it fails validation and the shield switches off (fail-open).
 *
 * Validation deliberately re-enforces the SAVE-TIME invariants, not just JSON
 * shape: text-reading kills code execution, but a well-formed hostile JSON is
 * still dangerous (`mode: block` on a real base = cacheable sitewide 404s; a
 * hostile locale pattern = matcher chaos). Any violation returns null.
 *
 * No WordPress, no side effects — safe to require from the pre-boot front-end
 * bootstrap (wp-config Tier 1) and to unit-test in isolation.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Function/ConfigReader.php
 *
 * @package Post404Shield\Function
 */

declare(strict_types=1);

namespace Post404Shield;

/**
 * Do a locale pattern's character classes admit only `a-z`, `0-9` and `-`?
 *
 * A range must run letter-to-letter or digit-to-digit, so no class can span
 * the punctuation that changes a URL's shape (`/`, `.`, `:`…). An unclosed
 * `[` is rejected outright.
 *
 * @param string $pattern Locale-pattern body (already charset-checked).
 *
 * @return bool
 */
function locale_pattern_classes_are_safe( string $pattern ): bool {
	$len = strlen( $pattern );
	for ( $i = 0; $i < $len; $i++ ) {
		if ( ']' === $pattern[ $i ] ) {
			return false; // A `]` outside any class.
		}
		if ( '[' !== $pattern[ $i ] ) {
			continue;
		}
		$end = strpos( $pattern, ']', $i + 1 );
		if ( false === $end || $i + 1 === $end ) {
			return false; // Unclosed, or empty `[]`.
		}
		$body = substr( $pattern, $i + 1, $end - $i - 1 );
		$blen = strlen( $body );
		for ( $j = 0; $j < $blen; $j++ ) {
			$c = $body[ $j ];
			if ( 1 !== preg_match( '/^[a-z0-9-]$/', $c ) ) {
				return false; // `,` `{` `}` `|` `[` are not locale characters.
			}
			// `a-z` style range: a hyphen with a character on both sides.
			if ( $j + 2 < $blen && '-' === $body[ $j + 1 ] ) {
				$from         = $c;
				$to           = $body[ $j + 2 ];
				$both_letters = ctype_lower( $from ) && ctype_lower( $to );
				$both_digits  = ctype_digit( $from ) && ctype_digit( $to );
				if ( ( ! $both_letters && ! $both_digits ) || $from > $to ) {
					return false;
				}
				$j += 2;
			}
		}
		$i = $end;
	}
	return true;
}

/**
 * Are a locale pattern's `{n}` / `{n,m}` quantifiers few and small?
 *
 * @param string $pattern Locale-pattern body (already charset-checked).
 *
 * @return bool At most 4 quantifiers, every bound 10 or less, and every `{`
 *              actually a well-formed quantifier.
 */
function locale_pattern_quantifiers_are_bounded( string $pattern ): bool {
	$braces = substr_count( $pattern, '{' );
	if ( substr_count( $pattern, '}' ) !== $braces ) {
		return false;
	}
	$found = preg_match_all( '/\{(\d+)(?:,(\d*))?\}/', $pattern, $m, PREG_SET_ORDER );
	if ( $found !== $braces || $found > 4 ) {
		return false; // A stray brace, or too many quantifiers.
	}
	foreach ( $m as $q ) {
		$min = (int) $q[1];
		$max = isset( $q[2] ) && '' !== $q[2] ? (int) $q[2] : $min;
		if ( $min > 10 || $max > 10 || ( isset( $q[2] ) && '' === $q[2] ) ) {
			return false; // `{n,}` is unbounded.
		}
	}
	return true;
}

/**
 * Validate a locale-pattern BODY (the regex fragment for the language prefix
 * segment, no delimiters/anchors — e.g. `[a-z]{2}-[a-z]{2}|global`).
 *
 * Charset allowlist with NO parentheses: a pattern containing its own groups
 * would shift the matcher's captures. `#` and backslashes are excluded by the
 * same charset (no delimiter breakout, no escapes), and so are `+`, `*` and
 * `?`, which leaves `{n,m}` as the only quantifier. Length-capped, and it must
 * actually compile inside the matcher's own wrapper.
 *
 * Two further rules, because the charset alone was not enough (L2):
 *
 * - Every character class holds only `a-z`, `0-9` and a literal hyphen, and a
 *   range runs letter-to-letter or digit-to-digit. `[,-z]{1,20}` passed the
 *   charset, but `,-z` spans `/`, `.` and `:`, so the locale capture could be
 *   `/evil.com` — an open redirect through `redirect-deep-path`.
 * - At most four `{…}` quantifiers, each bounded at 10. Chained overlapping
 *   repeats backtrack catastrophically without any parentheses: six chained
 *   `[a-z]{0,20}` exhausted PCRE's backtrack limit on a 60-character path,
 *   on the pre-boot hot path.
 *
 * Real locale patterns (`[a-z]{2}-[a-z]{2}|global`) sit far inside both.
 *
 * @param string $pattern Locale-pattern body.
 *
 * @return bool True when the pattern is safe to hand to the matcher.
 */
function locale_pattern_is_valid( string $pattern ): bool {
	if ( '' === $pattern || strlen( $pattern ) > 200 ) {
		return false;
	}
	if ( 1 !== preg_match( '/^[a-z0-9\[\]{}|,\-]+$/', $pattern ) ) {
		return false;
	}
	if ( ! locale_pattern_classes_are_safe( $pattern ) || ! locale_pattern_quantifiers_are_bounded( $pattern ) ) {
		return false;
	}
	// Must compile inside the exact wrapper Matcher.php uses.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern raises a warning; false is the signal we want.
	return false !== @preg_match( '#^/(' . $pattern . ')/#', '/probe/' );
}

/**
 * Whether a value is a valid url_base entry: `[a-z0-9/-]` only, no leading or
 * trailing slash, no empty segment (`//`). The charset already excludes `.`
 * (no traversal) and whitespace.
 *
 * @param mixed $base Candidate base.
 *
 * @return bool
 */
function url_base_is_valid( $base ): bool {
	return is_string( $base )
		&& 1 === preg_match( '#^[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $base );
}

/**
 * Whether a value is a valid GLOBALLY-EXCLUDED base entry (the reserved-route
 * skip-list persisted under the artifact's top-level `excluded_bases` key).
 *
 * Broader than url_base_is_valid(): dots are allowed (`.well-known`,
 * `robots.txt`), underscores are allowed, and a single trailing `*` turns the
 * FINAL segment into a prefix match (`sitemap*`, `wp-sitemap*`). A segment may
 * start with at most one dot and must contain a non-dot character, so `..`
 * (traversal) can never validate. No leading/trailing slash, no empty segment.
 *
 * @param mixed $base Candidate excluded-base entry.
 *
 * @return bool
 */
function excluded_base_is_valid( $base ): bool {
	return is_string( $base )
		&& 1 === preg_match( '#^(?:\.?[a-z0-9_-][a-z0-9._-]*)(?:/\.?[a-z0-9_-][a-z0-9._-]*)*\*?$#', $base )
		&& false === strpos( $base, '..' );
}

/**
 * Extract the leading LITERAL path segment of a WordPress rewrite-rule regex
 * — the reserved-route base it claims — or '' when the rule is prefixed by a
 * capture group / wildcard (i.e. it matches the whole content namespace, not a
 * reserved route).
 *
 * This is how root mode learns about CUSTOM routes generically: every rewrite
 * rule with a literal prefix (`schema-preview(/…)`, `category/…`, `robots\.txt`)
 * names a namespace WordPress itself routes and the shield must never decide.
 * Page/post rules are capture-group-prefixed (`(.?.+?)/…`, `([^/]+)/…`), so
 * this returns '' for them — real content space is never excluded, and the
 * worst a mis-parse can do is over-exclude (fail-open), never under.
 *
 * Escaped literals (`\.`, `\/`) are honoured so file routes survive
 * (`robots\.txt` → `robots.txt`); the run ends at the first path separator or
 * unescaped regex metacharacter. Pure string logic, no WordPress.
 *
 * @param string $pattern A rewrite-rule regex (the array KEY of the rewrite table).
 *
 * @return string The literal base, or '' when there is no literal prefix.
 */
function rewrite_pattern_base( string $pattern ): string {
	$pattern   = ltrim( $pattern, '^' );
	$metachars = '()[]{}.*+?|$ ';
	$out       = '';
	$len       = strlen( $pattern );
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $pattern[ $i ];
		if ( '/' === $char ) {
			break; // First path separator ends the leading segment.
		}
		if ( '\\' === $char ) {
			$next = $pattern[ $i + 1 ] ?? '';
			if ( '' !== $next && ! ctype_alnum( $next ) ) {
				$out .= $next; // Escaped literal (e.g. \. → .).
				++$i;
				continue;
			}
			// A class escape (\d, \w, \s, \b …) or a backreference is not a
			// literal: the run ends here, and a cut inside a segment is a prefix.
			if ( '' !== $out ) {
				$out .= '*';
			}
			break;
		}
		if ( false !== strpos( $metachars, $char ) ) {
			// The literal run is over. Two cases must widen rather than narrow
			// the exclusion (over-excluding is fail-open; under-excluding 404s
			// a real route), mirroring redirect_pattern_base(). First, a
			// quantifier that can match zero times (`?`, `*`, `{0,…}`) makes the
			// character before it optional — `events?/…` must cover /event/12/ —
			// so drop it. Second, a cut in the MIDDLE of a segment is a prefix
			// — `event-([0-9]+)` is every `event-…` — so mark it with a trailing
			// `*`. A group that opens with a separator (`schema-preview(/(.*))?`)
			// and the end anchor `$` are segment boundaries, not prefixes.
			if ( in_array( $char, [ '?', '*', '{' ], true ) && '' !== $out ) {
				$out = substr( $out, 0, -1 );
			}
			$boundary = '$' === $char;
			if ( '(' === $char ) {
				$rest     = substr( $pattern, $i + 1 );
				$rest     = 0 === strpos( $rest, '?:' ) ? substr( $rest, 2 ) : $rest;
				$boundary = '' !== $rest && '/' === $rest[0];
			}
			if ( ! $boundary && '' !== $out ) {
				$out .= '*';
			}
			break;
		}
		$out .= $char;
	}
	// WordPress anchors rewrite rules at the start only (`#^{rule}#`): a rule
	// that is literal to its end matches everything that starts with it.
	if ( '' !== $out && $i >= $len ) {
		$out .= '*';
	}
	return $out;
}

/**
 * Reduce a redirect plugin's SOURCE (a plain path, a wildcard path, or a regex)
 * to the excluded-base literal that lets it pass root matching.
 *
 * Unlike rewrite_pattern_base(), this KEEPS the full multi-segment path — a
 * redirect source `services/home-care` must exclude ONLY
 * that path (and its children, via the loader's segment-prefix match), never
 * the real `/services/` tree above it. Reducing to the first
 * segment would silently un-shield a whole content branch, so the leading
 * literal run keeps `/` as a real separator and stops only at a true
 * metacharacter / wildcard:
 *   - plain (`/old-page/`)         → `old-page` (exact + children);
 *   - boundary wildcard (`/news/*`) → `news` (whole segment; children covered
 *                                    by the loader's segment-prefix match);
 *   - INTRA-segment wildcard (`/product-*`, a "starts-with" source) →
 *                                    `product-*` (a TRAILING `*`, which the
 *                                    loader prefix-matches on the final
 *                                    segment — so `/product-123/` is excluded;
 *                                    without it the redirect would be killed);
 *   - regex (`^/news/(.+)$`)       → `news` (escapes honoured; stops at `(`);
 *   - regex mid-segment (`^/product-.*$`) → `product-*`.
 * Returns '' when there is no usable literal prefix (`^/(.+)-old$` → '').
 * Pure string logic, no WordPress.
 *
 * @param string $source   The plugin's stored source pattern.
 * @param bool   $is_regex Whether the source is a regular expression.
 *
 * @return string The excluded-base literal, or '' when none.
 */
function redirect_pattern_base( string $source, bool $is_regex ): string {
	$source    = ltrim( trim( $source ), '^' );
	$metachars = $is_regex ? '()[]{}.*+?|$ ' : '*? ';
	$out       = '';
	$truncated = false;
	$len       = strlen( $source );
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $source[ $i ];
		if ( $is_regex && '\\' === $char ) {
			$next = $source[ $i + 1 ] ?? '';
			if ( '' !== $next && ! ctype_alnum( $next ) ) {
				$out .= $next; // Escaped literal (\. \/).
				++$i;
				// Literal to the very end, unanchored: a prefix (see below).
				if ( $i === $len - 1 && '/' !== $next ) {
					$truncated = true;
				}
				continue;
			}
			// A class escape (\d, \w, \s, \b …) or a backreference is not a
			// literal: the run ends here, and a cut inside a segment is a prefix.
			$truncated = '' !== $out && '/' !== substr( $out, -1 );
			break;
		}
		if ( false !== strpos( $metachars, $char ) ) {
			// A regex quantifier that can match ZERO times (`?`, `*`, `{0,…}`)
			// makes the character before it optional: `colou?r` must reduce to
			// `colo*`, not `colou*`, or `/color…` is never reserved.
			if ( $is_regex && in_array( $char, [ '?', '*', '{' ], true ) && '' !== $out && '/' !== substr( $out, -1 ) ) {
				$out = substr( $out, 0, -1 );
			}
			// Stopped at a metacharacter / wildcard. `$` is the regex
			// end-anchor — the literal is then the WHOLE (exact) match, no
			// prefix. Any other metachar that cut the run MID-segment (the
			// current segment has begun — the last kept char is not a
			// separator) means a within-segment PREFIX, so flag a trailing `*`.
			$truncated = '$' !== $char && '' !== $out && '/' !== substr( $out, -1 );
			break;
		}
		$out .= $char; // '/' is a literal path separator — kept.
		// An unanchored regex that is literal to the end matches as a PREFIX
		// (`^/promo` matches /promotional-offer/), so it is one — unless it
		// ended on a separator, which the loader's segment match covers.
		if ( $is_regex && $i === $len - 1 && '/' !== $char ) {
			$truncated = true;
		}
	}
	$base = trim( $out, '/' );
	if ( '' === $base ) {
		return '';
	}
	return $truncated ? $base . '*' : $base;
}

/**
 * Rewrite one redirect SOURCE into the locale-free source(s) the reducer can
 * read — run BEFORE redirect_pattern_base().
 *
 * Two shapes the reducer alone cannot see past, both common redirect-plugin idioms:
 *   - a leading LOCALE segment, literal (`ja-jp/special/x/`) or a regex group
 *     (`^([a-z]{2}-[a-z]{2}|global)/products/…`, optionally `(?:…/)?`). The
 *     reducer stops at the `(` and yields nothing, so the redirect was never
 *     reserved; and a literal locale kept in an excluded base can never match,
 *     because match_root() strips the locale before comparing. Stripped when
 *     the group body IS the configured pattern, or every alternative is a
 *     literal that matches it. Locale-agnostic is wider than the redirect, but
 *     only ever in the fail-open direction.
 *   - a pure-literal ALTERNATION (`stories/(video|technology|tips)/?$`):
 *     expanded into one source per alternative (capped), since each is a
 *     real redirected URL. Anything else — nested groups, classes, an
 *     optional group — is left for the reducer to cut at the metachar.
 *
 * Pure string logic, no WordPress.
 *
 * @param string $source         The plugin's stored source pattern.
 * @param bool   $is_regex       Whether the source is a regular expression.
 * @param string $locale_pattern Locale pattern body ('' = no locale segment).
 *
 * @return string[] One or more sources, leading `^` and `/` removed.
 */
function redirect_source_variants( string $source, bool $is_regex, string $locale_pattern = '' ): array {
	$source = ltrim( ltrim( trim( $source ), '^' ), '/' );

	if ( '' !== $locale_pattern ) {
		$is_locale = static function ( string $token ) use ( $locale_pattern ): bool {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a config-supplied pattern must read as "no match".
			return 1 === preg_match( '/^[a-z0-9-]+$/', $token ) && 1 === @preg_match( '#^(?:' . $locale_pattern . ')$#', $token );
		};

		if ( $is_regex && 1 === preg_match( '#^\((?:\?:)?([^()\\\\]+)\)(\?)?/#', $source, $group ) ) {
			// `(locale)/rest`, or `(?:locale/)?rest` is not this shape — see below.
			$alternatives = explode( '|', $group[1] );
			$all_locale   = [] !== $alternatives && count( $alternatives ) === count( array_filter( $alternatives, $is_locale ) );
			if ( empty( $group[2] ) && ( $group[1] === $locale_pattern || $all_locale ) ) {
				$source = substr( $source, strlen( $group[0] ) );
			}
		} elseif ( $is_regex && 1 === preg_match( '#^\((?:\?:)?([^()\\\\]+)/\)\?#', $source, $group ) ) {
			// Optional locale segment: `(?:locale/)?rest`.
			$alternatives = explode( '|', $group[1] );
			if ( $group[1] === $locale_pattern || count( $alternatives ) === count( array_filter( $alternatives, $is_locale ) ) ) {
				$source = substr( $source, strlen( $group[0] ) );
			}
		} else {
			$slash = strpos( $source, '/' );
			if ( false !== $slash && $is_locale( substr( $source, 0, $slash ) ) ) {
				$source = substr( $source, $slash + 1 );
			}
		}

		// Any other leading group that is a locale in its own words — a
		// capture reused as `$1` (`([a-z]{2}-[a-z]{2})`, `(\w{2}-\w{2})`), a
		// reordered alternation: stripped when EVERY alternative matches a
		// locale this site uses, and the group can never match across a `/`.
		// Only ever wider, in the fail-open direction.
		if ( $is_regex && 1 === preg_match( '#^\((?:\?:)?((?:[^()\\\\]|\\\\.)+)\)(\?)?/#', $source, $group ) ) {
			$samples = array_values( array_filter( array_merge( [ 'en-us', 'ja-jp', 'en-gb', 'de-de', 'global' ], explode( '|', $locale_pattern ) ), $is_locale ) );
			$matches = static function ( string $regex, string $subject ): bool {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a plugin-supplied pattern must read as "no match".
				return 1 === @preg_match( '#^(?:' . $regex . ')$#', $subject );
			};
			$all = [] !== $samples;
			foreach ( explode( '|', $group[1] ) as $alternative ) {
				$one = false;
				foreach ( $samples as $sample ) {
					if ( $matches( $alternative, $sample ) ) {
						$one = true;
						break;
					}
				}
				$all = $all && $one;
			}
			if ( $all && ! $matches( $group[1], 'en-us/x' ) && ! $matches( $group[1], 'x/y' ) ) {
				$source = substr( $source, strlen( $group[0] ) );
			}
		}
	}

	if ( ! $is_regex ) {
		return [ $source ];
	}

	// First group in the source: expand it when it is a plain, un-quantified
	// alternation of literals. Escapes before it are fine; the reducer honours them.
	if ( 1 !== preg_match( '#^((?:[^()\\\\]|\\\\.)*)\((?:\?:)?([a-z0-9_|-]+)\)(?![?*+{])(.*)$#s', $source, $parts ) ) {
		return [ $source ];
	}
	// An empty alternative (`(a|)`) is a real match too — kept, not filtered.
	$alternatives = array_values( array_unique( explode( '|', $parts[2] ) ) );
	if ( count( $alternatives ) > 50 ) {
		return [ $source ];
	}
	$variants = [];
	foreach ( $alternatives as $alternative ) {
		$variants[] = $parts[1] . $alternative . $parts[3];
	}
	return $variants;
}

/**
 * Parse a permalink structure into the built-in `post` type's URL base.
 *
 * The operator never types a base for `post`/`page` — it is DERIVED from
 * Settings → Permalinks: the leading STATIC segments before the first %tag%
 * are the base (`/blog/%postname%/` → `blog`); no static segments
 * (`/%postname%/`) means posts are ROOT-DWELLERS (base ''). A structure whose
 * first dynamic tag is anything but %postname% — or that carries extra tags
 * after it, or plain (non-pretty) permalinks — is UNSUPPORTED: the shield
 * cannot enumerate those URL shapes, so post shielding must refuse to enable
 * (fail-open). Pure string parsing, no WordPress.
 *
 * @param string $structure The `permalink_structure` option value.
 *
 * @return array{supported: bool, base: string} base '' = root-level posts.
 */
function permalink_post_base( string $structure ): array {
	$unsupported = [
		'supported' => false,
		'base'      => '',
	];

	$structure = trim( $structure );
	if ( '' === $structure ) {
		return $unsupported; // Plain permalinks — no pretty post URLs at all.
	}

	$static = [];
	foreach ( explode( '/', trim( $structure, '/' ) ) as $index => $segment ) {
		if ( false === strpos( $segment, '%' ) ) {
			$static[] = $segment;
			continue;
		}
		// First dynamic segment: it must be exactly %postname%, and it must be
		// the LAST segment — anything after it changes the URL shape.
		$is_last = count( explode( '/', trim( $structure, '/' ) ) ) - 1 === $index;
		if ( '%postname%' !== $segment || ! $is_last ) {
			return $unsupported;
		}
		$base = implode( '/', $static );
		if ( '' !== $base && ! url_base_is_valid( $base ) ) {
			return $unsupported;
		}
		return [
			'supported' => true,
			'base'      => $base,
		];
	}

	return $unsupported; // No %postname% anywhere.
}

/**
 * WordPress-reserved first URL segments a base may never claim.
 *
 * The single source for BOTH the save-time validate() in ConfigStore and the
 * runtime invariant below. They used to keep separate copies, and only the
 * save-time one knew the list — so a staged or hand-edited artifact with a
 * block entry on `wp-json` passed the reader, and REST went to a cacheable
 * pre-boot 404 that the settings screen could not fix. `page` is the root
 * pagination namespace (/page/N/); the rest are core routes.
 *
 * @return string[]
 */
function reserved_namespaces(): array {
	return [
		'page',
		'comments',
		'feed',
		'embed',
		'trackback',
		'wp-json',
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-login.php',
		'xmlrpc.php',
	];
}

/**
 * Does a base's first segment claim a WordPress-reserved namespace?
 *
 * @param string $base A url_base, e.g. `products/shoes`.
 *
 * @return bool
 */
function base_is_reserved_namespace( string $base ): bool {
	$first = explode( '/', trim( $base, '/' ) )[0];
	return in_array( $first, reserved_namespaces(), true );
}

/**
 * Is a value absent, or an array of strings that each match a pattern?
 *
 * The shape of every optional per-entry list (reserved slugs, derived reserved
 * slugs, post statuses). Split out of config_is_valid() so that function stays
 * under the cyclomatic-complexity ceiling.
 *
 * @param mixed  $value   The entry field, or null when absent.
 * @param string $pattern Regex each item must match.
 *
 * @return bool True when absent or well-formed.
 */
function optional_string_list_is_valid( $value, string $pattern ): bool {
	if ( null === $value ) {
		return true;
	}
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $item ) {
		if ( ! is_string( $item ) || 1 !== preg_match( $pattern, $item ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Are a BASED (non-root) entry's bases acceptable at runtime?
 *
 * An enabled entry matches, so each base must be sound and none may claim a
 * WordPress-reserved namespace (F10). A disabled entry never matches, so only
 * its shape is checked (L1) — see the comments at the call site's history.
 *
 * @param array<string, mixed> $entry Entry settings.
 *
 * @return bool
 */
function based_entry_bases_are_valid( array $entry ): bool {
	$bases   = $entry['url_base'] ?? null;
	$enabled = ! ( isset( $entry['enabled'] ) && false === $entry['enabled'] );
	if ( ! $enabled ) {
		return null === $bases || is_array( $bases );
	}
	if ( ! is_array( $bases ) || [] === $bases ) {
		return false;
	}
	foreach ( $bases as $base ) {
		if ( ! url_base_is_valid( $base ) || base_is_reserved_namespace( (string) $base ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Whether a decoded config document satisfies every runtime invariant.
 *
 * Pure and cheap (string/array checks only) — this runs on EVERY shielded
 * request pre-boot. Split from read_config() so the write side can assert the
 * exact same invariants before persisting an artifact.
 *
 * @param mixed $config Decoded JSON document.
 *
 * @return bool True when the document is safe for the loader to act on.
 */
function config_is_valid( $config ): bool {
	if ( ! is_array( $config ) || 1 !== ( $config['version'] ?? null ) ) {
		return false;
	}
	if ( ! isset( $config['entries'] ) || ! is_array( $config['entries'] ) ) {
		return false;
	}

	// Locale block: mode enum + a valid pattern whenever a prefix is in play.
	$locale = $config['locale'] ?? null;
	if ( ! is_array( $locale ) ) {
		return false;
	}
	$mode = $locale['mode'] ?? null;
	if ( ! in_array( $mode, [ 'none', 'wpml-directory', 'custom' ], true ) ) {
		return false;
	}
	if ( 'none' !== $mode && ! locale_pattern_is_valid( (string) ( $locale['pattern'] ?? '' ) ) ) {
		return false;
	}

	// Optional top-level excluded_bases snapshot (root mode's reserved-route
	// skip-list): three buckets, every entry charset-checked. The loader treats
	// a missing/malformed snapshot as "root mode inert" — but a PRESENT one
	// must be sound, or the whole document is rejected.
	if ( isset( $config['excluded_bases'] ) ) {
		$excluded = $config['excluded_bases'];
		if ( ! is_array( $excluded ) ) {
			return false;
		}
		foreach ( [ 'floor', 'derived', 'operator' ] as $bucket ) {
			if ( ! isset( $excluded[ $bucket ] ) || ! is_array( $excluded[ $bucket ] ) ) {
				return false;
			}
			foreach ( $excluded[ $bucket ] as $base ) {
				if ( ! excluded_base_is_valid( $base ) ) {
					return false;
				}
			}
		}
		// Optional: rewrite endpoint names, stripped like sub-routes.
		if ( isset( $excluded['endpoints'] ) ) {
			if ( ! is_array( $excluded['endpoints'] ) ) {
				return false;
			}
			foreach ( $excluded['endpoints'] as $endpoint ) {
				if ( ! is_string( $endpoint ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $endpoint ) ) {
					return false;
				}
			}
		}
	}

	foreach ( $config['entries'] as $key => $entry ) {
		// The key (and post_type below) end up as a path component of the
		// per-type allowlist file — charset-check them, never trust them.
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $key ) || ! is_array( $entry ) ) {
			return false;
		}
		if ( isset( $entry['enabled'] ) && ! is_bool( $entry['enabled'] ) ) {
			return false;
		}
		if ( ! in_array( $entry['mode'] ?? 'allowlist', [ 'allowlist', 'block' ], true ) ) {
			return false;
		}
		if ( isset( $entry['post_type'] ) && ( ! is_string( $entry['post_type'] ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $entry['post_type'] ) ) ) {
			return false;
		}

		// Root entries (root-pages v2): no URL base by nature — the loader's
		// root catch-all owns them. Constrained hard: allowlist mode only,
		// full-path matching only, and url_base must be EMPTY (a root entry
		// with a base would be two entries fighting over one URL space).
		$is_root = $entry['root'] ?? false;
		if ( ! is_bool( $is_root ) ) {
			return false;
		}

		$bases = $entry['url_base'] ?? null;
		if ( $is_root ) {
			if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				return false;
			}
			if ( null !== $bases && [] !== $bases ) {
				return false;
			}
			if ( 'full-path' !== ( $entry['match'] ?? 'full-path' ) ) {
				return false;
			}
		} elseif ( ! based_entry_bases_are_valid( $entry ) ) {
			// Enabled: sound bases, none on a reserved namespace — a block entry
			// on `wp-json` / `wp-admin` would 404 REST or the admin pre-boot (F10).
			// Disabled: shape only — it never matches, unticking is the documented
			// remedy, and checking its bases made one disabled typo block every
			// future save with an unattributed "Internal error" (L1).
			return false;
		}

		if ( ! in_array( $entry['match'] ?? 'slug', [ 'slug', 'full-path' ], true ) ) {
			return false;
		}

		// Pagination allowance (per-type checkbox, default on) — bool when set.
		if ( isset( $entry['allow_pagination'] ) && ! is_bool( $entry['allow_pagination'] ) ) {
			return false;
		}
		if ( ! in_array( $entry['depth_action'] ?? 'passthrough', [ 'passthrough', '404', 'redirect' ], true ) ) {
			return false;
		}

		// Int-or-null numerics (JSON ints only — floats/strings are violations).
		foreach ( [ 'depth_allowed', 'cache_ttl', 'edge_ttl' ] as $field ) {
			if ( isset( $entry[ $field ] ) && ( ! is_int( $entry[ $field ] ) || $entry[ $field ] < 0 ) ) {
				return false;
			}
		}

		// `reserved_derived` is machine-built from the redirect plugins and may
		// carry a trailing `*` (a prefix family, see slug_is_reserved()); it
		// always passes when the shield wrote it — this guards a hand-edited or
		// corrupt artifact.
		if ( ! optional_string_list_is_valid( $entry['reserved_allowlist'] ?? null, '/^[a-z0-9-]+$/' )
			|| ! optional_string_list_is_valid( $entry['reserved_derived'] ?? null, '/^[a-z0-9_-]+\*?$/' )
			|| ! optional_string_list_is_valid( $entry['post_status'] ?? null, '/^[a-z0-9_-]+$/' ) ) {
			return false;
		}
	}

	return true;
}

/**
 * The longest a shield 404 may be cached, in seconds (one day). Browsers keep
 * a cached 404 for as long as they were told, and no purge reaches them. The
 * loader clamps to it; a save warns about a longer one. Deliberately not an
 * artifact invariant: one saved before the cap existed must keep working.
 */
const MAX_TTL = 86400;

/**
 * Absolute path of the shield's data directory: `wp-content/uploads/post-404-shield`.
 *
 * The ONE place it is worked out. The pre-boot loader reads from it before
 * WordPress (and wp_upload_dir()) exists, so every writer — config artifact,
 * allowlists, baked 404 pages, probe token — must write to exactly the same
 * place, or the loader reads a directory nothing writes to while the settings
 * screen reports the shield active. Deriving it from this file's location
 * works pre-boot and at mu-plugin load alike, and unlike wp_upload_dir() it
 * neither stats nor creates the month folder on every call, and cannot be
 * moved by a custom UPLOADS setting the loader would not see.
 *
 * __DIR__ is wp-content/mu-plugins/post-404-shield/src/php/Function, so
 * wp-content is five levels up.
 *
 * @return string
 */
function shield_dir(): string {
	return dirname( __DIR__, 5 ) . '/uploads/post-404-shield';
}

/**
 * Temp-file path for an atomic write of a generated file: the same directory
 * (so rename() is an atomic swap), unique per process, and ending in `.php`,
 * so a leftover from a killed process runs its `<?php exit;` guard when
 * requested over HTTP instead of being served as plain text.
 *
 * @param string $file Destination path, e.g. `…/allowlist.php`.
 *
 * @return string e.g. `…/allowlist.1234.tmp.php`.
 */
function temp_path( string $file ): string {
	return (string) preg_replace( '/\.php$/', '', $file ) . '.' . getmypid() . '.tmp.php';
}

/**
 * Whether a base name is a temp file from temp_path() — or the older
 * `<name>.php.<pid>.tmp` shape, so leftovers from before are swept too.
 *
 * @param string $name Base name.
 *
 * @return bool
 */
function is_temp_file_name( string $name ): bool {
	return 1 === preg_match( '/\.\d+\.tmp(?:\.php)?$/', $name );
}

/**
 * Per-request memo slot for one artifact path.
 *
 * Holds the raw bytes last read, their decoded document and, once computed,
 * their validity. Keyed on the CONTENT rather than on stat() identity: stat
 * has one-second resolution, so an in-place rewrite of the same size within
 * a second would look unchanged, whereas comparing bytes cannot be fooled.
 * PHP statics last one request, so nothing here can outlive the request that
 * read it.
 *
 * @param string $file Absolute path to the artifact.
 *
 * @return array{raw: ?string, document: ?array<string, mixed>, valid: ?bool}
 */
function &config_memo_slot( string $file ): array {
	static $slots = [];
	if ( ! isset( $slots[ $file ] ) ) {
		$slots[ $file ] = [
			'raw'      => null,
			'document' => null,
			'valid'    => null,
		];
	}
	return $slots[ $file ];
}

/**
 * Decode the config artifact WITHOUT validating it — memoised per request.
 *
 * Text-read only: strip the `<?php exit;` guard line and json_decode the
 * rest. Null when the file is missing, unreadable, lacks the guard or holds
 * malformed JSON.
 *
 * The file is read every time (cheap) but decoded only when its bytes differ
 * from the last read, so the second read in a request — pre-boot loader, then
 * the write side at mu-plugin load — skips the decode and, via read_config(),
 * the validation too.
 *
 * Callers that act on the document MUST validate it: use read_config(), or
 * config_shape_is_valid() for the loader's pre-filter only.
 *
 * @param string      $file Absolute path to the artifact.
 * @param string|null $raw  Its bytes, when the caller has just read them.
 *
 * @return array<string, mixed>|null The decoded document, or null.
 */
function read_config_document( string $file, ?string $raw = null ): ?array {
	$slot = &config_memo_slot( $file );

	if ( null === $raw ) {
		$raw = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
	}
	if ( false === $raw ) {
		$slot = [
			'raw'      => null,
			'document' => null,
			'valid'    => null,
		];
		return null;
	}
	if ( $raw === $slot['raw'] ) {
		return $slot['document'];
	}

	$document = null;
	// Line 1 must be the `<?php exit;` guard; the JSON document follows it.
	$newline = strpos( $raw, "\n" );
	if ( false !== $newline && 0 === strpos( $raw, '<?php' ) ) {
		$decoded  = json_decode( substr( $raw, $newline + 1 ), true );
		$document = is_array( $decoded ) ? $decoded : null;
	}

	$slot = [
		'raw'      => $raw,
		'document' => $document,
		'valid'    => null,
	];
	return $document;
}

/**
 * The loader's pre-filter for a config: whether root mode is on (every URI is
 * then shield business), and the URI substrings that make a request shield
 * business otherwise — `/{base}/` per enabled allowlist base, `/{base}` per
 * blocked one (so the bare base itself is caught). The writer stores it on
 * the artifact's guard line; the loader recomputes it from an artifact that
 * predates that.
 *
 * @param array<string, mixed> $config Config document.
 *
 * @return array{root: bool, needles: string[]}
 */
function prefilter_for( array $config ): array {
	$root    = false;
	$needles = [];
	foreach ( (array) ( $config['entries'] ?? [] ) as $settings ) {
		if ( ! is_array( $settings ) || ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) ) {
			continue;
		}
		$is_block = 'block' === ( $settings['mode'] ?? 'allowlist' );
		if ( ! $is_block && true === ( $settings['root'] ?? false ) ) {
			$root = true;
		}
		foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
			if ( is_string( $base ) && '' !== $base ) {
				$needles[] = '/' . $base . ( $is_block ? '' : '/' );
			}
		}
	}
	return [
		'root'    => $root,
		'needles' => array_values( array_unique( $needles ) ),
	];
}

/**
 * The pre-filter stored on an artifact's guard line (` prefilter:{json}` after
 * `__halt_compiler();`, where PHP never parses), or null when the line carries
 * none or it is malformed — the caller then decodes the document as before.
 * It is written in the same atomic write as the document, so it cannot be
 * stale; and a wrong one could only send a request to WordPress (fail-open)
 * or through the full check.
 *
 * @param string $raw The artifact's bytes.
 *
 * @return array{root: bool, needles: string[]}|null
 */
function config_prefilter( string $raw ): ?array {
	$newline = strpos( $raw, "\n" );
	$line    = false === $newline ? $raw : substr( $raw, 0, $newline );
	$at      = strpos( $line, ' prefilter:' );
	if ( false === $at ) {
		return null;
	}
	$decoded = json_decode( substr( $line, $at + strlen( ' prefilter:' ) ), true );
	if ( ! is_array( $decoded ) || ! is_bool( $decoded['root'] ?? null ) || ! is_array( $decoded['needles'] ?? null ) ) {
		return null;
	}
	foreach ( $decoded['needles'] as $needle ) {
		if ( ! is_string( $needle ) || '' === $needle ) {
			return null;
		}
	}
	return [
		'root'    => $decoded['root'],
		'needles' => $decoded['needles'],
	];
}

/**
 * Whether a request URI is shield business under a pre-filter: root mode, the
 * bake probe path (always the shield's), or a URI containing a needle.
 *
 * @param array{root: bool, needles: string[]} $prefilter From prefilter_for() or config_prefilter().
 * @param string                               $uri       Request URI.
 *
 * @return bool
 */
function prefilter_matches( array $prefilter, string $uri ): bool {
	if ( $prefilter['root'] || false !== strpos( $uri, 'post-shield-404-probe' ) ) {
		return true;
	}
	foreach ( $prefilter['needles'] as $needle ) {
		if ( false !== strpos( $uri, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Validate the document read_config_document() last read — WITHOUT reading again.
 *
 * By design, read_config() always re-reads the file: comparing bytes is how the
 * memo catches an in-place rewrite. But the pre-boot loader has just read and
 * decoded the file a few lines earlier in the same request, and a second read
 * costs about a millisecond on network storage. This validates the bytes the
 * loader already holds, and records the verdict in the same memo slot, so a
 * later read_config() of an unchanged file in this request skips validation too.
 *
 * @param string $file Absolute path to the artifact.
 *
 * @return array<string, mixed>|null The validated document, or null when nothing
 *                                    was read or it is invalid.
 */
function read_config_validated_in_memory( string $file ): ?array {
	$slot = &config_memo_slot( $file );
	if ( null === $slot['document'] ) {
		return null;
	}
	if ( null === $slot['valid'] ) {
		$slot['valid'] = config_is_valid( $slot['document'] );
	}
	return $slot['valid'] ? $slot['document'] : null;
}

/**
 * Whether a decoded document has the TYPES the loader's pre-filter reads.
 *
 * Deliberately not validation: no regexes, no charset or reserved-namespace
 * checks. It only guarantees the pre-filter cannot fatal on a malformed
 * document (`strpos()` on an array under strict_types, say) — the pre-boot
 * loader must never fatal. Everything the loader goes on to ACT on is fully
 * validated by read_config() first, so outcomes are identical: a request that
 * is not shield business exits without acting whether or not the document is
 * valid.
 *
 * @param mixed $config Decoded JSON document.
 *
 * @return bool
 */
function config_shape_is_valid( $config ): bool {
	if ( ! is_array( $config ) || ! isset( $config['entries'], $config['locale'] ) ) {
		return false;
	}
	if ( ! is_array( $config['entries'] ) || ! is_array( $config['locale'] ) || ! is_string( $config['locale']['mode'] ?? null ) ) {
		return false;
	}
	if ( isset( $config['locale']['pattern'] ) && ! is_string( $config['locale']['pattern'] ) ) {
		return false;
	}
	foreach ( $config['entries'] as $entry ) {
		if ( ! is_array( $entry ) ) {
			return false;
		}
		if ( ( isset( $entry['enabled'] ) && ! is_bool( $entry['enabled'] ) )
			|| ( isset( $entry['root'] ) && ! is_bool( $entry['root'] ) )
			|| ( isset( $entry['mode'] ) && ! is_string( $entry['mode'] ) )
			|| ( isset( $entry['post_type'] ) && ! is_string( $entry['post_type'] ) ) ) {
			return false;
		}
		if ( isset( $entry['url_base'] ) ) {
			if ( ! is_array( $entry['url_base'] ) ) {
				return false;
			}
			foreach ( $entry['url_base'] as $base ) {
				if ( ! is_string( $base ) ) {
					return false;
				}
			}
		}
	}
	return true;
}

/**
 * Read and validate the generated config artifact.
 *
 * The decoded document (read_config_document()) plus every runtime
 * invariant (config_is_valid).
 * Returns null on ANY doubt — unreadable file, missing/never-generated
 * artifact, non-PHP first line, malformed JSON, or an invariant violation —
 * and the caller treats null as "shield not in place" (fail-open). The
 * validity verdict is memoised with the bytes it was computed for, so a
 * second call in the same request costs one file read.
 *
 * @param string $file Absolute path to the artifact.
 *
 * @return array<string, mixed>|null The validated config document, or null.
 */
function read_config( string $file ): ?array {
	$document = read_config_document( $file );
	if ( null === $document ) {
		return null;
	}
	$slot = &config_memo_slot( $file );
	if ( null === $slot['valid'] ) {
		$slot['valid'] = config_is_valid( $document );
	}
	return $slot['valid'] ? $document : null;
}
