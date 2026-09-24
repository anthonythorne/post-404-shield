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
	 * Term archives per public taxonomy, and posts per other public type,
	 * sampled by other_public_sample().
	 */
	private const OTHER_SAMPLE = 3;

	/**
	 * Rewrite endpoints from the candidate's snapshot (set by run()).
	 *
	 * @var string[]
	 */
	private array $endpoints = [];

	/**
	 * Cap on extras lines walked (attachments can number in the thousands; a
	 * head+tail sample keeps the walk fast while still catching a broken
	 * union — a missing extras build fails EVERY sampled line, not one).
	 */
	private const EXTRAS_SAMPLE = 200;

	/**
	 * Newest and oldest published posts per root type whose REAL address
	 * (get_permalink()) joins the corpus.
	 */
	private const PERMALINK_RECENT = 25;
	private const PERMALINK_OLDEST = 5;

	/**
	 * Per root type, the PUBLIC statuses to measure: `publish`, plus every
	 * registered public status the live or the candidate config lists for
	 * it — so a save that drops one (an archive status) is measured against
	 * the posts it would stop serving, as the based coverage gate does.
	 *
	 * @param array<string, mixed> $entries Candidate entries.
	 *
	 * @return array<string, string[]> Effective CPT => statuses.
	 */
	private function corpus_statuses( array $entries ): array {
		$sources = [ $entries, $this->live_entries() ];
		$out     = [];
		foreach ( $sources as $source ) {
			foreach ( $source as $key => $settings ) {
				if ( ! is_array( $settings ) || true !== ( $settings['root'] ?? false ) ) {
					continue;
				}
				$type = (string) ( $settings['post_type'] ?? $key );
				foreach ( array_merge( [ 'publish' ], (array) ( $settings['post_status'] ?? [] ) ) as $status ) {
					$object = is_string( $status ) && function_exists( 'get_post_status_object' ) ? get_post_status_object( $status ) : null;
					if ( 'publish' === $status || ( null !== $object && ! $object->private && AllowlistBuilder::is_servable_status( $status ) ) ) {
						$out[ $type ][ (string) $status ] = true;
					}
				}
			}
		}
		return array_map( 'array_keys', $out );
	}

	/**
	 * The LIVE entries, as the based gate measures them: an option edited
	 * over WP-CLI and published with `config write` equals the candidate, so
	 * only the artifact still knows the statuses it is about to drop.
	 *
	 * @return array<string, mixed>
	 */
	private function live_entries(): array {
		$live = function_exists( '\Post404Shield\read_config' ) && function_exists( '\Post404Shield\shield_dir' )
			? \Post404Shield\read_config( \Post404Shield\shield_dir() . '/config.php' )
			: null;
		if ( null === $live && function_exists( 'get_option' ) ) {
			$live = get_option( ConfigStore::OPTION, null );
		}
		return is_array( $live['entries'] ?? null ) ? $live['entries'] : [];
	}

	/**
	 * The lines of posts that are served only because the LIVE config lists
	 * their status for a root type and the candidate no longer does: a
	 * would-block URL among them is a dropped status, which the operator can
	 * confirm, not a broken config.
	 *
	 * @param AllowlistBuilder                   $builder         Candidate builder.
	 * @param array<string, mixed>               $entries         Candidate entries.
	 * @param array<string, array<string, bool>> $sets      Candidate lines per effective CPT, as sets.
	 *
	 * @return array<string, array{0: string, 1: string}> Line => [ the dropped status, its post type ].
	 */
	private function dropped_status_lines( AllowlistBuilder $builder, array $entries, array $sets ): array {
		$listed = static function ( array $source ): array {
			$out = [];
			foreach ( $source as $key => $settings ) {
				if ( is_array( $settings ) && true === ( $settings['root'] ?? false ) && ( ! isset( $settings['enabled'] ) || false !== $settings['enabled'] ) ) {
					$type         = (string) ( $settings['post_type'] ?? $key );
					$out[ $type ] = array_merge( $out[ $type ] ?? [ 'publish' ], array_map( 'strval', (array) ( $settings['post_status'] ?? [] ) ) );
				}
			}
			return $out;
		};
		$now    = $listed( $entries );
		$lines  = [];
		foreach ( $listed( $this->live_entries() ) as $type => $was ) {
			if ( ! isset( $now[ $type ] ) ) {
				continue; // The type left root mode: not a status drop.
			}
			foreach ( array_diff( array_unique( $was ), $now[ $type ] ) as $status ) {
				foreach ( $builder->lines_for( (string) $type, [ (string) $status ] ) as $line ) {
					if ( ! isset( $sets[ $type ][ $line ] ) ) {
						$lines[ $line ] = [ (string) $status, (string) $type ];
					}
				}
			}
		}
		return $lines;
	}

	/**
	 * The allowlist line a probed URL stands for: its path without the
	 * locale and the trailing sub-routes the matcher strips (`page/N`, a bare
	 * page number, `comment-page-N`, a feed, a rewrite endpoint).
	 *
	 * @param string $url            URL path.
	 * @param string $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return string
	 */
	private function line_of( string $url, string $locale_pattern ): string {
		$path = trim( $url, '/' );
		if ( '' !== $locale_pattern ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an engine error on a config-supplied pattern must read as "no locale".
			$path = (string) @preg_replace( '#^(?:' . $locale_pattern . ')(?:/|$)#', '', $path );
		}
		return '' === $path ? '' : implode( '/', \Post404Shield\strip_trailing_sub_routes( explode( '/', $path ), true, $this->endpoints ) );
	}

	/**
	 * A sample of the other URLs WordPress serves, which root mode must pass:
	 * term archives of every public taxonomy (an SEO plugin can strip the
	 * category base, putting them at the root), and posts of every public
	 * type that is not a root type — types shielded under a base included, at
	 * their real address and in the statuses their entries list: while that
	 * stays under the base the based stage decides it, and once a plugin or
	 * theme drops the base it lands in the root stage, which is what this
	 * must catch.
	 *
	 * @param string[]             $root_types Effective CPTs of the enabled root entries.
	 * @param array<string, mixed> $entries    Candidate entries.
	 *
	 * @return string[] Paths with a trailing slash, locale prefix included.
	 */
	private function other_public_sample( array $root_types, array $entries ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || [] === $root_types || ! function_exists( 'get_taxonomies' ) ) {
			return [];
		}
		$path_of = static function ( $link ): ?string {
			if ( ! is_string( $link ) || '' === $link || false !== strpos( $link, '?' ) ) {
				return null;
			}
			$path = (string) wp_parse_url( $link, PHP_URL_PATH );
			return '' === $path ? null : '/' . trim( $path, '/' ) . ( '/' === $path ? '' : '/' );
		};

		$paths = [];
		foreach ( get_taxonomies( [ 'public' => true ], 'names' ) as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'number'     => self::OTHER_SAMPLE,
					'hide_empty' => true,
				]
			);
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$path = $path_of( get_term_link( $term ) );
				if ( null !== $path ) {
					$paths[] = $path;
				}
			}
		}
		// Every status WordPress serves at a post's address (discontinued,
		// private… as well as published).
		$served = array_values( array_filter( function_exists( 'get_post_stati' ) ? array_keys( (array) get_post_stati() ) : [ 'publish' ], static fn( $status ): bool => 'publish' === $status || ( null !== get_post_status_object( (string) $status ) && AllowlistBuilder::is_servable_status( (string) $status ) ) ) );
		// A type shielded under a base is sampled in the statuses its entries
		// list: its posts in any other status 404 under the base by design
		// (the status warning says so, reserving their slugs would confirm
		// them), and the listed ones show a dropped base as well.
		$listed = [];
		foreach ( $entries as $key => $entry ) {
			if ( is_array( $entry ) && true !== ( $entry['root'] ?? false ) && ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' ) ) {
				$type            = (string) ( $entry['post_type'] ?? $key );
				$listed[ $type ] = array_merge( $listed[ $type ] ?? [], \Post404Shield\effective_statuses( $entry ) );
			}
		}
		foreach ( get_post_types( [ 'public' => true ], 'names' ) as $type ) {
			if ( 'attachment' === $type || in_array( $type, $root_types, true ) ) {
				continue;
			}
			// Per status, so private rows never crowd the published ones out.
			foreach ( isset( $listed[ $type ] ) ? array_intersect( $served, $listed[ $type ] ) : $served as $status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_name <> '' ORDER BY ID DESC LIMIT %d", $type, $status, self::OTHER_SAMPLE ) ) as $id ) {
					$path = $path_of( AllowlistBuilder::permalink_as_reader( (int) $id ) );
					if ( null !== $path ) {
						$paths[] = $path;
					}
				}
			}
		}
		return $paths;
	}

	/**
	 * Real addresses of a sample of each root type's published posts, taken
	 * from get_permalink() — independent of the allowlist derivation the rest
	 * of the corpus shares with the builder. If the builder ever derives a
	 * line WordPress does not serve (a flat type with a stray post_parent, a
	 * filtered permalink), the real address misses the list and shows up as a
	 * would-block instead of passing unmeasured.
	 *
	 * @param string[]                $root_types Effective CPTs of the enabled root entries.
	 * @param array<string, string[]> $statuses   Effective CPT => public statuses to sample (corpus_statuses()).
	 *
	 * @return string[] Paths with a trailing slash, locale prefix included.
	 */
	private function permalink_sample( array $root_types, array $statuses = [] ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || [] === $root_types ) {
			return [];
		}

		$ids = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $root_types as $type ) {
			$orders = [
				'DESC' => self::PERMALINK_RECENT,
				'ASC'  => self::PERMALINK_OLDEST,
			];
			$in     = $statuses[ $type ] ?? [ 'publish' ];
			foreach ( $orders as $order => $limit ) {
				foreach ( (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN (" . implode( ', ', array_fill( 0, count( $in ), '%s' ) ) . ") AND post_name <> '' ORDER BY ID " . ( 'DESC' === $order ? 'DESC' : 'ASC' ) . ' LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built to match the list; the ORDER keyword is one of two literals.
						array_merge( [ $type ], $in, [ $limit ] )
					)
				) as $id ) {
					$ids[ (int) $id ] = $type;
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Each post's permalink in its own language (WPML), then back.
		$lang  = apply_filters( 'wpml_current_language', null );
		$paths = [];
		foreach ( $ids as $id => $type ) {
			$post_lang = apply_filters(
				'wpml_element_language_code',
				null,
				[
					'element_id'   => $id,
					'element_type' => $type,
				]
			);
			if ( is_string( $post_lang ) && '' !== $post_lang ) {
				\Post404Shield\switch_language( $post_lang );
			}
			$link = get_permalink( $id );
			if ( ! is_string( $link ) || '' === $link || false !== strpos( $link, '?' ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $link, PHP_URL_PATH );
			if ( '' !== $path ) {
				$paths[] = '/' . trim( $path, '/' ) . ( '/' === $path ? '' : '/' );
			}
		}
		if ( is_string( $lang ) && '' !== $lang ) {
			\Post404Shield\switch_language( $lang );
		}
		return $paths;
	}

	/**
	 * Walk the candidate config and report what root mode would 404.
	 *
	 * @param array<string, mixed> $candidate Candidate config document (entries
	 *                                        + excluded_bases snapshot present).
	 *
	 * @return array{checked: int, would_block: string[], warn_block: string[], dropped: array<string, string>, dropped_types: array<string, string>} `dropped`: the would-blocks a dropped status explains, URL => status (`dropped_types`: URL => its post type).
	 */
	public function run( array $candidate ): array {
		$entries = (array) ( $candidate['entries'] ?? [] );
		$builder = new AllowlistBuilder( $entries );

		$locale_mode    = (string) ( $candidate['locale']['mode'] ?? 'none' );
		$locale_pattern = 'none' === $locale_mode ? '' : (string) ( $candidate['locale']['pattern'] ?? '' );

		// Allowlist BODIES per effective CPT, built fresh from the database so
		// the walk measures what the loader will actually read after the save's
		// synchronous rebuilds.
		// Each list also as a hash set: the walk replays every published root
		// URL, and a substring scan of the whole body per probe made it
		// quadratic (pages x bytes) inside the save lock. match_root() uses the
		// set when given one; the loader never passes it.
		$bodies = [];
		$sets   = [];
		foreach ( array_keys( $builder->allowlist_type_map() ) as $post_type ) {
			$type_lines           = $builder->lines_for( $post_type );
			$bodies[ $post_type ] = $this->body_from_lines( $type_lines );
			$sets[ $post_type ]   = array_fill_keys( $type_lines, true );
		}
		$extras_lines = $builder->has_root_entries() ? $builder->root_extras_sample( self::EXTRAS_SAMPLE ) : [];
		$extras_body  = $this->body_from_lines( $extras_lines );
		$extras_set   = array_fill_keys( $extras_lines, true );

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
			$root_candidates[ $root_type ] = self::root_candidate( $root_type, $settings, $bodies[ $root_type ] ?? '', $sets[ $root_type ] ?? [] );
		}
		if ( isset( $root_candidates['page'] ) ) {
			$root_candidates = [ 'page' => $root_candidates['page'] ] + $root_candidates;
		}
		$match_candidates   = array_values( $root_candidates );
		$match_candidates[] = [
			'type'             => 'root-extras',
			'allow_pagination' => true,
			'body'             => $extras_body,
			'set'              => $extras_set,
		];
		// Endpoints are stripped like sub-routes, exactly as the loader does.
		$this->endpoints = array_values( array_filter( (array) ( $candidate['excluded_bases']['endpoints'] ?? [] ), 'is_string' ) );
		foreach ( $match_candidates as $candidate_index => $match_candidate ) {
			$match_candidates[ $candidate_index ]['endpoints'] = $this->endpoints;
		}

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

		// The probe corpus's root-type lines come from live content in every
		// PUBLIC status the live or the candidate config lists for the type —
		// a misconfigured entry (a status dropped, a wrong match) must shrink
		// the allowlist it is measured AGAINST, never the corpus it is measured
		// WITH. Private posts are root-extras lines, measured there.
		$corpus    = $this->corpus_statuses( $entries );
		$published = [];
		foreach ( array_keys( $root_candidates ) as $root_type ) {
			$published[ $root_type ] = $builder->lines_for( (string) $root_type, $corpus[ $root_type ] ?? [ 'publish' ] );
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
		$urls        = array_values( array_unique( array_merge( $urls, $this->permalink_sample( array_keys( $root_candidates ), $corpus ), $this->other_public_sample( array_keys( $root_candidates ), $entries ) ) ) );
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

		// Which would-blocks are a status this save drops (see dropped_status_lines()).
		$dropped       = [];
		$dropped_types = [];
		$dropped_lines = [] === $would_block ? [] : $this->dropped_status_lines( $builder, $entries, $sets );
		foreach ( $would_block as $url ) {
			$line = $this->line_of( $url, $locale_pattern );
			if ( isset( $dropped_lines[ $line ] ) ) {
				[ $dropped[ $url ], $dropped_types[ $url ] ] = $dropped_lines[ $line ];
			}
		}

		return [
			'checked'       => count( $urls ),
			'would_block'   => $would_block,
			'warn_block'    => $warn_block,
			'dropped'       => $dropped,
			'dropped_types' => $dropped_types,
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

		// The root stage runs whenever a root candidate exists. The loader's
		// own precondition — every root list non-empty — is not mirrored: an
		// empty list is measured as armed (run()), since one appended post
		// is all it takes, and the save is the only time this walk runs.
		$has_root = false;
		foreach ( $match_candidates as $match_candidate ) {
			$has_root = $has_root || 'root-extras' !== ( $match_candidate['type'] ?? '' );
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
			$locale_pattern,
			$this->endpoints
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
	 * One root type as the walk's matcher candidate. An empty list is
	 * measured ARMED: the loader leaves root matching inert while one is, but
	 * the first post appended arms it for the whole site, and no preflight
	 * runs then.
	 *
	 * @param string               $type     Root post type.
	 * @param array<string, mixed> $settings Its entry.
	 * @param string               $body     Its candidate list body ('' when empty).
	 * @param array<string, bool>  $set      Its lines as a set.
	 *
	 * @return array<string, mixed>
	 */
	private static function root_candidate( string $type, array $settings, string $body, array $set ): array {
		return [
			'type'             => $type,
			'allow_pagination' => ! isset( $settings['allow_pagination'] ) || false !== $settings['allow_pagination'],
			'body'             => self::armed_body( $body ),
			'set'              => $set,
		];
	}

	/**
	 * A root list's body as the walk measures it: an empty one as ARMED — a
	 * line no slug can match (`#` is outside the slug charset), since a guard
	 * and an empty line alone read as empty (list_is_empty()) — never as ''
	 * (inert).
	 *
	 * @param string $body Body from body_from_lines().
	 *
	 * @return string
	 */
	private static function armed_body( string $body ): string {
		return '' === $body ? "<?php exit;\n#armed\n" : $body;
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
