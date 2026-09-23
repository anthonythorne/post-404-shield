<?php
/**
 * Root-preflight coverage gate (S6, root-pages v2).
 *
 * Root mode's safety story is COMPLETENESS: with no base to scope it, the
 * shield becomes the arbiter of every URL not claimed by a based entry or an
 * exclusion, so hope is not a strategy — this class turns it into measurement.
 * It walks REAL URLs (every published page URI, every post line, the
 * attachment/old-slug extras, one representative URL per exclusion, every
 * based entry's first real line, real pagination shapes) through the EXACT
 * would-be loader decision — the same pure matcher functions the pre-boot
 * loader runs, against allowlist bodies built from the live database for the
 * CANDIDATE config — and reports every URL that would be served a pre-boot
 * 404. A non-empty report aborts the enabling save (ConfigStore::write()'s
 * preflight seam); the CLI command `wp post-shield root-preflight` runs the
 * same walk stand-alone as the production gate.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Library/RootPreflight.php
 *
 * @package Post404Shield\Library
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * Measures the would-be loader decision for a candidate config against real
 * site URLs.
 */
class RootPreflight {

	/**
	 * Cap on extras lines walked (attachments can number in the thousands; a
	 * head+tail sample keeps the walk fast while still catching a broken
	 * union — a missing extras build fails EVERY sampled line, not one).
	 */
	private const EXTRAS_SAMPLE = 200;

	/**
	 * Walk the candidate config and report what root mode would 404.
	 *
	 * @param array<string, mixed> $candidate Candidate config document (entries
	 *                                        + excluded_bases snapshot present).
	 *
	 * @return array{checked: int, would_block: string[], warn_block: string[]}
	 */
	public function run( array $candidate ): array {
		$entries = (array) ( $candidate['entries'] ?? [] );
		$builder = new AllowlistBuilder( $entries );

		$locale_mode    = (string) ( $candidate['locale']['mode'] ?? 'none' );
		$locale_pattern = 'none' === $locale_mode ? '' : (string) ( $candidate['locale']['pattern'] ?? '' );

		// Allowlist BODIES per effective CPT, built fresh from the database so
		// the walk measures what the loader will actually read after the save's
		// synchronous rebuilds.
		$bodies = [];
		foreach ( array_keys( $builder->allowlist_type_map() ) as $post_type ) {
			$bodies[ $post_type ] = $this->body_from_lines( $builder->lines_for( $post_type ) );
		}
		$extras_lines = $builder->has_root_entries() ? $builder->root_extras_lines() : [];
		$extras_body  = $this->body_from_lines( $extras_lines );

		// Root candidates, page first (mirrors the loader's ordering).
		$root_candidates = [];
		foreach ( $entries as $key => $settings ) {
			if ( ! is_array( $settings ) || true !== ( $settings['root'] ?? false ) ) {
				continue;
			}
			if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
				continue;
			}
			$root_type                     = (string) ( $settings['post_type'] ?? $key );
			$root_candidates[ $root_type ] = [
				'type'             => $root_type,
				'allow_pagination' => ! isset( $settings['allow_pagination'] ) || false !== $settings['allow_pagination'],
				'body'             => $bodies[ $root_type ] ?? '',
			];
		}
		if ( isset( $root_candidates['page'] ) ) {
			$root_candidates = [ 'page' => $root_candidates['page'] ] + $root_candidates;
		}
		$match_candidates   = array_values( $root_candidates );
		$match_candidates[] = [
			'type'             => 'root-extras',
			'allow_pagination' => true,
			'body'             => $extras_body,
		];

		// The flattened exclusion list, exactly as the loader assembles it:
		// snapshot buckets + every configured entry's bases.
		$excluded_flat = [];
		foreach ( [ 'floor', 'derived', 'operator' ] as $bucket ) {
			foreach ( (array) ( $candidate['excluded_bases'][ $bucket ] ?? [] ) as $row ) {
				if ( is_string( $row ) && '' !== $row ) {
					$excluded_flat[] = $row;
				}
			}
		}
		foreach ( $entries as $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}
			foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
				if ( is_string( $base ) && '' !== $base ) {
					$excluded_flat[] = $base;
				}
			}
			// Root reserved slugs pass through, mirroring the loader exactly.
			if ( true === ( $settings['root'] ?? false ) ) {
				foreach ( (array) ( $settings['reserved_allowlist'] ?? [] ) as $reserved_slug ) {
					if ( is_string( $reserved_slug ) && '' !== $reserved_slug ) {
						$excluded_flat[] = $reserved_slug;
					}
				}
			}
		}

		// The probe corpus's root-type lines come from PUBLISHED content,
		// independently of the candidate config — a misconfigured entry (wrong
		// statuses, wrong match) must shrink the allowlist it is measured
		// AGAINST, never the corpus it is measured WITH.
		$published = [];
		foreach ( array_keys( $root_candidates ) as $root_type ) {
			$published[ $root_type ] = $builder->lines_for( (string) $root_type, [ 'publish' ] );
		}

		// Two block classes, deliberately separated: a ROOT-stage block is what
		// this gate exists to stop (root mode 404ing a real URL) and ABORTS the
		// save; a BASED-entry block on a probe (e.g. a redirect source living
		// under a shielded base) is PRE-EXISTING v1 behaviour that root
		// activation does not change — reported as a warning with the remedy
		// (the entry's reserved slugs), never a root-save blocker.
		$would_block = [];
		$warn_block  = [];
		$urls        = $this->probe_urls( $candidate, $bodies, $root_candidates, $published, $extras_lines, $excluded_flat, $locale_pattern );
		foreach ( $urls as $url ) {
			$decision = $this->decide( $url, $entries, $bodies, $excluded_flat, $match_candidates, $locale_pattern );
			if ( 0 !== strpos( $decision['marker'], 'blocked' ) ) {
				continue;
			}
			if ( 'root' === $decision['stage'] ) {
				$would_block[] = $url;
			} else {
				$warn_block[] = $url;
			}
		}

		return [
			'checked'     => count( $urls ),
			'would_block' => $would_block,
			'warn_block'  => $warn_block,
		];
	}

	/**
	 * The probe corpus: real URLs whose shield decision must never be a block.
	 *
	 * @param array<string, mixed>    $candidate       Candidate config document.
	 * @param array<string, string>   $bodies          Allowlist bodies per effective CPT.
	 * @param array<string, array>    $root_candidates Root candidates keyed by type.
	 * @param array<string, string[]> $published       PUBLISHED lines per root type (the corpus source).
	 * @param string[]                $extras_lines    Root-extras union lines.
	 * @param string[]                $excluded_flat   Flattened exclusion list.
	 * @param string                  $locale_pattern  Locale pattern body ('' = none).
	 *
	 * @return string[] URL paths.
	 */
	private function probe_urls( array $candidate, array $bodies, array $root_candidates, array $published, array $extras_lines, array $excluded_flat, string $locale_pattern = '' ): array {
		$urls = [ '/' ];

		// Every PUBLISHED URL of every enabled ROOT type is corpus — sourced
		// from the live database, never from the candidate's own lists.
		foreach ( $root_candidates as $root_type => $root_candidate ) {
			$lines = $published[ $root_type ] ?? [];
			foreach ( $lines as $line ) {
				$urls[] = '/' . $line . '/';
			}
			// Real pagination shapes must survive the checkbox setting in force.
			if ( [] !== $lines && false !== $root_candidate['allow_pagination'] ) {
				$urls[] = '/' . $lines[0] . '/page/2/';
				$urls[] = '/' . $lines[0] . '/2/';
			}
		}

		// Attachment/old-slug extras (sampled head + tail).
		$sample = $extras_lines;
		if ( count( $sample ) > self::EXTRAS_SAMPLE ) {
			$half   = (int) ( self::EXTRAS_SAMPLE / 2 );
			$sample = array_merge( array_slice( $extras_lines, 0, $half ), array_slice( $extras_lines, -$half ) );
		}
		foreach ( $sample as $line ) {
			$urls[] = '/' . $line . '/';
		}

		// One representative URL set per exclusion: the bare namespace, a child,
		// and a paginated child (`/page/2/`, `/author/x/`, `/category/x/`, …).
		// Namespaces OWNED by a configured entry are skipped — a fake slug under
		// an enabled base is SUPPOSED to block (that is the based shield working;
		// its real slugs are probed separately below), and the shield's own
		// probe path blocks by design. The representatives measure only what
		// belongs to WordPress.
		$entry_bases = [];
		foreach ( (array) ( $candidate['entries'] ?? [] ) as $settings ) {
			if ( ! is_array( $settings ) || ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) ) {
				continue;
			}
			foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
				if ( is_string( $base ) && '' !== $base ) {
					$entry_bases[] = $base;
				}
			}
		}
		foreach ( array_unique( $excluded_flat ) as $excluded ) {
			$literal = rtrim( $excluded, '*' );
			if ( '' === $literal || 'post-shield-404-probe' === $literal || in_array( $literal, $entry_bases, true ) ) {
				continue;
			}
			$urls[] = '/' . $literal . '/';
			$urls[] = '/' . $literal . '/x/';
			$urls[] = '/' . $literal . '/2/';
		}

		// Date archives and the front page's <!--nextpage--> shape.
		$urls[] = '/' . gmdate( 'Y' ) . '/';
		$urls[] = '/2/';

		// Every enabled BASED entry's first real line under each of its bases.
		foreach ( (array) ( $candidate['entries'] ?? [] ) as $key => $settings ) {
			if ( ! is_array( $settings ) || true === ( $settings['root'] ?? false ) ) {
				continue;
			}
			if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
				continue;
			}
			if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$post_type = (string) ( $settings['post_type'] ?? $key );
			$lines     = $this->lines_from_body( (string) ( $bodies[ $post_type ] ?? '' ) );
			if ( [] === $lines ) {
				continue;
			}
			foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
				if ( is_string( $base ) && '' !== $base ) {
					$urls[] = '/' . $base . '/' . $lines[0] . '/';
				}
			}
		}

		$urls = array_values( array_unique( $urls ) );

		// With a locale pattern in force the loader's matchers REQUIRE the
		// locale segment: an unprefixed path matches nothing and passes. So
		// probes built bare would all pass and this gate could never abort a
		// save. Prefix each one with a locale the pattern accepts. One is
		// enough for correctness — allowlists and exclusions are
		// locale-agnostic — and a second exercises another branch of the
		// pattern where the site has one.
		if ( '' === $locale_pattern ) {
			return $urls;
		}
		$locales = $this->probe_locales( $locale_pattern );
		if ( [] === $locales ) {
			return $urls;
		}
		$prefixed = [];
		foreach ( $locales as $locale ) {
			foreach ( $urls as $url ) {
				$prefixed[] = '/' . $locale . $url;
			}
		}
		return $prefixed;
	}

	/**
	 * Locale segments to prefix probes with: real ones the pattern accepts.
	 *
	 * The site's own languages are preferred (the default first), because a
	 * probe should look like a URL the site actually serves. Literal branches
	 * of the pattern itself (`global` in `[a-z]{2}-[a-z]{2}|global`) are the
	 * fallback for a site without WPML. At most two, to keep the walk's cost
	 * linear in the corpus rather than in the language count.
	 *
	 * @param string $locale_pattern Locale pattern body.
	 *
	 * @return string[]
	 */
	private function probe_locales( string $locale_pattern ): array {
		$candidates = [];
		if ( function_exists( 'apply_filters' ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			if ( is_string( $default ) ) {
				$candidates[] = $default;
			}
			$active = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
			if ( is_array( $active ) ) {
				foreach ( array_keys( $active ) as $code ) {
					$candidates[] = (string) $code;
				}
			}
		}
		foreach ( explode( '|', $locale_pattern ) as $branch ) {
			if ( 1 === preg_match( '/^[a-z0-9-]+$/', $branch ) ) {
				$candidates[] = $branch;
			}
		}

		// Compiled the way the matchers compile it; validation already rules
		// out delimiter breakout (locale_pattern_is_valid()).
		$regex   = '#^(?:' . $locale_pattern . ')$#';
		$locales = [];
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate || in_array( $candidate, $locales, true ) ) {
				continue;
			}
			if ( 1 === @preg_match( $regex, $candidate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an operator pattern; a bad one must not fatal the save.
				$locales[] = $candidate;
			}
			if ( count( $locales ) >= 2 ) {
				break;
			}
		}
		return $locales;
	}

	/**
	 * The would-be loader decision for one path — the same evaluation order and
	 * the same pure functions as bootstrap-front-end-post-404-shield.php, minus
	 * the emit (a marker string is returned instead).
	 *
	 * @param string                $path             URL path.
	 * @param array<string, mixed>  $entries          Candidate entries.
	 * @param array<string, string> $bodies           Allowlist bodies per effective CPT.
	 * @param string[]              $excluded_flat    Flattened exclusion list.
	 * @param array<int, array>     $match_candidates Ordered root candidates incl. extras.
	 * @param string                $locale_pattern   Locale pattern body ('' = none).
	 *
	 * @return array{marker: string, stage: string} Marker (`blocked-*`, `allowed-*`, `pass`)
	 *                                              and which stage decided (probe|based|root|none).
	 */
	private function decide( string $path, array $entries, array $bodies, array $excluded_flat, array $match_candidates, string $locale_pattern ): array {
		if ( null !== \Post404Shield\match_blocked_base( $path, 'post-shield-404-probe', $locale_pattern ) ) {
			return [
				'marker' => 'blocked-probe-path',
				'stage'  => 'probe',
			];
		}

		// Mirror the loader's root preconditions exactly: at least one root
		// candidate, and EVERY root type contributing a non-empty body — any
		// empty list makes the whole root stage inert (fail-open), never a
		// selective 404.
		$has_root = false;
		foreach ( $match_candidates as $match_candidate ) {
			if ( 'root-extras' === ( $match_candidate['type'] ?? '' ) ) {
				continue;
			}
			if ( '' === ( $match_candidate['body'] ?? '' ) ) {
				$has_root = false;
				break;
			}
			$has_root = true;
		}

		// The based stage is the loader's own decision function, so this gate
		// cannot drift from what runs pre-boot. Bodies are already in memory;
		// a type with none reads as missing, exactly like a missing file.
		$based = \Post404Shield\decide_based(
			$path,
			$path,
			$entries,
			static function ( string $type ) use ( $bodies ): ?string {
				return $bodies[ $type ] ?? null;
			},
			$locale_pattern
		);
		if ( null !== $based ) {
			return [
				'marker' => $based['marker'],
				'stage'  => 'based',
			];
		}

		if ( ! $has_root ) {
			return [
				'marker' => 'pass',
				'stage'  => 'none',
			];
		}
		$decision = \Post404Shield\match_root( $path, $excluded_flat, $match_candidates, $locale_pattern );
		if ( 'blocked' === $decision['outcome'] ) {
			return [
				'marker' => 'blocked-unknown-slug',
				'stage'  => 'root',
			];
		}
		return [
			'marker' => 'allowed' === $decision['outcome'] ? 'allowed-known-slug' : 'pass',
			'stage'  => 'root',
		];
	}

	/**
	 * Assemble an allowlist BODY (guard line + one line each) from lines, the
	 * exact wire format is_allowed() scans.
	 *
	 * @param string[] $lines Allowlist lines.
	 *
	 * @return string
	 */
	private function body_from_lines( array $lines ): string {
		if ( [] === $lines ) {
			return ''; // The loader treats an empty list as fail-open inert; '' mirrors that.
		}
		return "<?php exit;\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * Lines back out of a body (skipping the guard line).
	 *
	 * @param string $body Allowlist body.
	 *
	 * @return string[]
	 */
	private function lines_from_body( string $body ): array {
		if ( '' === $body ) {
			return [];
		}
		$lines = explode( "\n", trim( $body ) );
		array_shift( $lines ); // The guard line.
		return array_values(
			array_filter(
				$lines,
				static function ( string $line ): bool {
					return '' !== $line;
				}
			)
		);
	}
}
