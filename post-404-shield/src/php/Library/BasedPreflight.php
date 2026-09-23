<?php
/**
 * Based-entry coverage gate: before a save lands, replay real URLs of every
 * entry it changes through the loader's own decision, and report any real
 * URL the new config would break.
 *
 * Validation can only reject a base that is MALFORMED. It cannot know a
 * well-formed base is WRONG — `products` where the posts live at
 * `products/detail`, say — and a wrong base is the costliest mistake an
 * operator can make here: every real post under it becomes a fake slug, and
 * pages that happen to sit beneath it 404 too. The only reliable test is the
 * one the loader will run, so this replays real permalinks through
 * decide_based() against the candidate config and the allowlists that config
 * would build.
 *
 * Scope is deliberately the entries the save CHANGES (newly enabled, or a
 * base, matching, depth, status or pagination change), so an ordinary save
 * stays fast and a save that changes nothing — the self-heal, a CLI
 * `config write` of the stored option — never runs it.
 *
 * @package Post404Shield
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * Real-URL coverage gate for based (non-root) entries.
 */
final class BasedPreflight {

	/**
	 * Most-recently published posts sampled per changed entry.
	 */
	private const SAMPLE_RECENT = 25;

	/**
	 * Oldest published posts sampled per changed entry (long-lived URLs).
	 */
	private const SAMPLE_OLDEST = 5;

	/**
	 * Published pages sampled per base, among those living beneath it.
	 */
	private const SAMPLE_PAGES = 10;

	/**
	 * Decisions that mean the config does not recognise a real URL at all:
	 * its own slug reads as fake, or its base is denied. The signature of a
	 * wrong base — these refuse the save.
	 */
	private const BREAKING = [ 'blocked-unknown-slug', 'blocked-denied-base' ];

	/**
	 * Decisions the entry's own depth policy makes about a real, deeper URL
	 * (a child gallery folded into its parent, say). A deliberate operator
	 * choice, so they are reported, never refused.
	 */
	private const DEPTH = [ 'redirect-deep-path', 'blocked-deep-path' ];

	/**
	 * The fields whose change can alter which real URLs an entry blocks.
	 * `reserved_allowlist` counts: removing a reserved slug can 404 the page it
	 * protected. `reserved_derived` does not — it is re-derived from the redirect
	 * plugins on every write, and a derived slug leaves only when its redirect has.
	 */
	private const BEHAVIOUR_FIELDS = [ 'post_type', 'url_base', 'match', 'depth_allowed', 'depth_action', 'post_status', 'published_only', 'allow_pagination', 'mode', 'reserved_allowlist' ];

	/**
	 * An entry field's value with the loader's defaults applied, so an absent
	 * key and its default compare equal — a config written by import_legacy()
	 * omits allow_pagination, which the screen then saves as true, and without
	 * this every entry would read as changed on the first save.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param string               $field Field name.
	 * @param string|int           $key   Entry key (the post_type default).
	 *
	 * @return mixed
	 */
	private static function normalised( array $entry, string $field, $key ) {
		switch ( $field ) {
			case 'post_type':
				return (string) ( $entry['post_type'] ?? $key );
			case 'url_base':
				return array_values( (array) ( $entry['url_base'] ?? [] ) );
			case 'match':
				return (string) ( $entry['match'] ?? 'slug' );
			case 'depth_action':
				return (string) ( $entry['depth_action'] ?? 'passthrough' );
			case 'allow_pagination':
				return ! isset( $entry['allow_pagination'] ) || false !== $entry['allow_pagination'];
			case 'published_only':
				return ! empty( $entry['published_only'] );
			case 'mode':
				return (string) ( $entry['mode'] ?? 'allowlist' );
			case 'post_status':
			case 'reserved_allowlist':
				$list = array_values( array_unique( array_map( 'strval', (array) ( $entry[ $field ] ?? ( 'post_status' === $field ? [ 'publish' ] : [] ) ) ) ) );
				sort( $list );
				return $list;
			default:
				return $entry[ $field ] ?? null;
		}
	}

	/**
	 * Keys of the enabled based entries a candidate adds or changes relative
	 * to the stored config.
	 *
	 * Pure: exposed so the scoping rule is unit-testable on its own.
	 *
	 * @param array<string|int, mixed>      $candidate Candidate entries.
	 * @param array<string|int, mixed>|null $current   Stored entries (null = no config yet).
	 *
	 * @return array<int, string|int>
	 */
	public static function changed_keys( array $candidate, ?array $current ): array {
		$keys = [];
		foreach ( $candidate as $key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) ) {
				continue;
			}
			if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) || true === ( $entry['root'] ?? false ) ) {
				continue; // Blocked bases have no real URLs; root entries have their own gate.
			}
			$before = is_array( $current[ $key ] ?? null ) ? $current[ $key ] : null;
			if ( null === $before || ( isset( $before['enabled'] ) && false === $before['enabled'] ) ) {
				$keys[] = $key;
				continue;
			}
			foreach ( self::BEHAVIOUR_FIELDS as $field ) {
				if ( self::normalised( $entry, $field, $key ) !== self::normalised( $before, $field, $key ) ) {
					$keys[] = $key;
					break;
				}
			}
		}
		return $keys;
	}

	/**
	 * Replay real URLs of the given entries through the candidate config.
	 *
	 * Three kinds of real URL are replayed per changed entry:
	 * - a sample of its posts in EVERY publicly viewable status it had before
	 *   or has now — a save that drops a status (Discontinued, say) must replay
	 *   the posts it is about to stop recognising;
	 * - published pages that live beneath its bases;
	 * - each reserved slug the save removes, when real content still carries
	 *   that slug.
	 *
	 * @param array<string, mixed>          $candidate Candidate config document.
	 * @param array<int, string|int>        $keys      Entry keys to check (changed_keys()).
	 * @param array<string|int, mixed>|null $current   Stored entries, when there are any.
	 *
	 * @return array{checked: int, breaks: array<int, array{url: string, marker: string, entry: string}>, depth: array<int, array{url: string, marker: string, entry: string}>, homes: array<string, string>, unclaimed: array<string, string>}
	 *         `breaks` refuse the save; `depth` are real URLs the depth policy
	 *         acts on (reported only); `homes`: per entry, the base its real
	 *         URLs actually use when that differs; `unclaimed`: entries whose
	 *         bases claim none of their real URLs (refuse — the base is wrong).
	 */
	public function run( array $candidate, array $keys, ?array $current = null ): array {
		$entries = (array) ( $candidate['entries'] ?? [] );
		$pattern = ConfigStore::locale_pattern_of( $candidate );
		$builder = new AllowlistBuilder( $entries );

		$bodies = [];
		$read   = static function ( string $type ) use ( $builder, &$bodies ): ?string {
			if ( ! array_key_exists( $type, $bodies ) ) {
				$lines           = $builder->lines_for( $type );
				$bodies[ $type ] = [] === $lines ? '' : "<?php exit;\n" . implode( "\n", $lines ) . "\n";
			}
			return $bodies[ $type ];
		};

		$checked   = 0;
		$breaks    = [];
		$depth     = [];
		$homes     = [];
		$unclaimed = [];
		$lang      = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_current_language', null ) : null;

		try {
			foreach ( $keys as $key ) {
				$entry = $entries[ $key ] ?? null;
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$before = is_array( $current[ $key ] ?? null ) ? $current[ $key ] : [];
				$type   = (string) ( $entry['post_type'] ?? $key );
				$bases  = array_values( array_filter( (array) ( $entry['url_base'] ?? [] ), 'is_string' ) );

				$statuses = self::viewable_statuses(
					array_merge(
						(array) ( $entry['post_status'] ?? [ 'publish' ] ),
						[] === $before ? [] : (array) ( $before['post_status'] ?? [ 'publish' ] )
					)
				);
				$paths    = $this->real_paths( $type, $bases, $statuses );

				$seen    = [];
				$claimed = 0;
				$sampled = 0;
				foreach ( $paths as $path ) {
					++$checked;
					++$sampled;
					$marker = $this->record( $path, (string) $key, $entries, $read, $pattern, $breaks, $depth );
					if ( 'unclaimed' !== $marker ) {
						++$claimed;
					}
					$home = self::base_of( $path, $pattern );
					if ( '' !== $home ) {
						$seen[ $home ] = ( $seen[ $home ] ?? 0 ) + 1;
					}
				}

				// Reserved slugs this save removes, where real content still
				// carries the slug: the page they protected would now read as fake.
				$locale  = [] !== $paths ? self::locale_of( $paths[0], $pattern ) : '';
				$removed = array_diff(
					array_map( 'strval', (array) ( $before['reserved_allowlist'] ?? [] ) ),
					array_map( 'strval', (array) ( $entry['reserved_allowlist'] ?? [] ) )
				);
				foreach ( $removed as $slug ) {
					if ( ! $this->slug_has_content( $slug ) ) {
						continue;
					}
					foreach ( $bases as $base ) {
						++$checked;
						$this->record( ( '' === $locale ? '' : '/' . $locale ) . '/' . $base . '/' . $slug . '/', (string) $key, $entries, $read, $pattern, $breaks, $depth );
					}
				}

				// Where this type's URLs really live, when the configured bases
				// miss it — the fix an operator needs, not just the symptom.
				if ( [] !== $seen ) {
					arsort( $seen );
					$top = (string) array_key_first( $seen );
					if ( ! in_array( $top, $bases, true ) ) {
						$homes[ (string) $key ] = $top;
					}
				}
				// Real URLs, and not one under the entry's bases: the base is
				// wrong. The entry would shield nothing, so a "passed" would lie.
				if ( $sampled > 0 && 0 === $claimed ) {
					$unclaimed[ (string) $key ] = $homes[ (string) $key ] ?? '';
				}
			}
		} finally {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'wpml_switch_language', $lang );
			}
		}

		return [
			'checked'   => $checked,
			'breaks'    => $breaks,
			'depth'     => $depth,
			'homes'     => $homes,
			'unclaimed' => $unclaimed,
		];
	}

	/**
	 * Decide one real URL and file it as a break or a depth note.
	 *
	 * @param string                    $path    URL path.
	 * @param string                    $key     Entry key it was replayed for.
	 * @param array<string|int, mixed>  $entries Candidate entries.
	 * @param callable(string): ?string $read    Allowlist reader.
	 * @param string                    $pattern Locale pattern body.
	 * @param array<int, array>         $breaks  Breaks (appended).
	 * @param array<int, array>         $depth   Depth notes (appended).
	 *
	 * @return string The decision marker, or `unclaimed`.
	 */
	private function record( string $path, string $key, array $entries, callable $read, string $pattern, array &$breaks, array &$depth ): string {
		$decision = \Post404Shield\decide_based( $path, $path, $entries, $read, $pattern );
		$marker   = null === $decision ? 'unclaimed' : (string) $decision['marker'];
		$hit      = [
			'url'    => $path,
			'marker' => $marker,
			'entry'  => $key,
		];
		if ( in_array( $marker, self::BREAKING, true ) ) {
			$breaks[] = $hit;
		} elseif ( in_array( $marker, self::DEPTH, true ) ) {
			$depth[] = $hit;
		}
		return $marker;
	}

	/**
	 * The statuses whose posts anyone can view, from a list; `publish` always.
	 * A private or draft post has no public pretty permalink to replay.
	 *
	 * @param array<int, mixed> $statuses Candidate statuses.
	 *
	 * @return string[]
	 */
	private static function viewable_statuses( array $statuses ): array {
		$out = [ 'publish' ];
		foreach ( $statuses as $status ) {
			$status = (string) $status;
			if ( '' === $status || in_array( $status, $out, true ) ) {
				continue;
			}
			if ( function_exists( 'is_post_status_viewable' ) ? is_post_status_viewable( $status ) : false ) {
				$out[] = $status;
			}
		}
		return $out;
	}

	/**
	 * Whether a published post or page (any type) carries this slug.
	 *
	 * @param string $slug Slug.
	 *
	 * @return bool
	 */
	private function slug_has_content( string $slug ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || '' === $slug ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' LIMIT 1", $slug ) );
	}

	/**
	 * The locale segment a URL path starts with, when the pattern accepts it.
	 *
	 * @param string $path           URL path.
	 * @param string $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return string
	 */
	private static function locale_of( string $path, string $locale_pattern ): string {
		$first = (string) strtok( ltrim( $path, '/' ), '/' );
		if ( '' === $locale_pattern || '' === $first ) {
			return '';
		}
		return 1 === @preg_match( '#^(?:' . $locale_pattern . ')$#', $first ) ? $first : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- operator pattern; a bad one must not warn.
	}

	/**
	 * Real, public URL paths to replay for an entry: a sample of its own
	 * published posts, plus published pages that live beneath its bases —
	 * a base set too shallow claims those pages as fake slugs.
	 *
	 * @param string   $type     Effective post type.
	 * @param string[] $bases    The entry's URL bases.
	 * @param string[] $statuses Publicly viewable statuses to sample.
	 *
	 * @return string[]
	 */
	private function real_paths( string $type, array $bases, array $statuses = [ 'publish' ] ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return [];
		}

		// Per status, so a status with few posts is sampled at all rather than
		// crowded out by thousands of published ones.
		$ids = [];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $statuses as $status ) {
			$ids = array_merge(
				$ids,
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_name <> '' ORDER BY ID DESC LIMIT %d",
						$type,
						$status,
						self::SAMPLE_RECENT
					)
				),
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_name <> '' ORDER BY ID ASC LIMIT %d",
						$type,
						$status,
						self::SAMPLE_OLDEST
					)
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$paths = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$path = $this->public_path( $id, $type );
			if ( null !== $path ) {
				$paths[] = $path;
			}
		}

		// Pages beneath each base. The page's own path segments name it, so
		// look for its last base segment first, then confirm the full URI.
		// Skipped when the base IS a post type's own URL prefix: WordPress
		// routes /{base}/{anything}/ to that post type, so such a page is
		// normally unreachable, and replaying it would refuse a save over a URL
		// nobody can reach. A site that routes a page there with its own
		// rewrite rule protects it with a reserved slug — and removing that
		// slug is replayed separately (see run()).
		$post_type_prefixes = self::post_type_prefixes();
		foreach ( $bases as $base ) {
			if ( in_array( $base, $post_type_prefixes, true ) ) {
				continue;
			}
			$leaf = basename( $base );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$parents = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_name = %s",
					$leaf
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = 0;
			foreach ( $parents as $parent ) {
				if ( get_page_uri( (int) $parent ) !== $base ) {
					continue;
				}
				foreach ( (array) get_children(
					[
						'post_parent' => (int) $parent,
						'post_type'   => 'page',
						'post_status' => 'publish',
						'numberposts' => self::SAMPLE_PAGES,
						'fields'      => 'ids',
					]
				) as $child ) {
					$path = $this->public_path( (int) $child, 'page' );
					if ( null !== $path && $found < self::SAMPLE_PAGES ) {
						$paths[] = $path;
						++$found;
					}
				}
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * URL prefixes registered post types claim through their rewrite slug.
	 *
	 * @return string[]
	 */
	private static function post_type_prefixes(): array {
		$prefixes = [];
		foreach ( get_post_types( [ '_builtin' => false ], 'objects' ) as $object ) {
			$slug = is_array( $object->rewrite ) ? trim( (string) ( $object->rewrite['slug'] ?? '' ), '/' ) : '';
			if ( '' !== $slug ) {
				$prefixes[] = $slug;
			}
		}
		return $prefixes;
	}

	/**
	 * A post's public URL path in its own language, or null when it has no
	 * pretty permalink (query-string links never reach the shield).
	 *
	 * @param int    $id   Post ID.
	 * @param string $type Post type.
	 *
	 * @return string|null
	 */
	private function public_path( int $id, string $type ): ?string {
		$lang = apply_filters(
			'wpml_element_language_code',
			null,
			[
				'element_id'   => $id,
				'element_type' => $type,
			]
		);
		if ( is_string( $lang ) && '' !== $lang ) {
			do_action( 'wpml_switch_language', $lang );
		}
		$link = get_permalink( $id );
		if ( ! is_string( $link ) || '' === $link || false !== strpos( $link, '?' ) ) {
			return null;
		}
		$path = (string) wp_parse_url( $link, PHP_URL_PATH );
		return '' === $path ? null : $path;
	}

	/**
	 * The base a URL path lives under: the path minus its locale segment and
	 * its last segment (the slug). '' for a path with nothing in between.
	 *
	 * @param string $path           URL path.
	 * @param string $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return string
	 */
	public static function base_of( string $path, string $locale_pattern ): string {
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		if ( '' !== $locale_pattern && [] !== $segments && 1 === @preg_match( '#^(?:' . $locale_pattern . ')$#', $segments[0] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- operator pattern; a bad one must not warn.
			array_shift( $segments );
		}
		array_pop( $segments );
		return implode( '/', $segments );
	}
}
