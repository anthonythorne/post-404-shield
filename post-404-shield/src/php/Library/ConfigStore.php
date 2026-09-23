<?php
/**
 * Persists the shield config: option (edit surface) + generated artifact (runtime).
 *
 * The lifecycle mirrors the shield's other generated files: the DB option
 * `post_shield_config` is the SOURCE the admin UI edits; the flat artifact
 * `uploads/post-404-shield/config.php` (guard line + JSON, text-read only, see
 * Function/ConfigReader.php) is the RUNTIME TRUTH the pre-boot loader and the
 * generator read. Every save validates, updates the option FIRST (the self-heal
 * regenerates from it), rotates the outgoing artifact to a timestamped revision,
 * atomically writes the new artifact, and prunes revisions beyond retention.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Library/ConfigStore.php
 *
 * @package Post404Shield\Library
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * Validates, writes, rotates, restores and imports the shield config.
 */
class ConfigStore {

	/**
	 * Option holding the editable config (main site only, autoload no).
	 */
	public const OPTION = 'post_shield_config';

	/**
	 * Option holding the revision-retention count (10–100 in steps of 10).
	 */
	public const KEEP_OPTION = 'post_shield_config_keep';

	/**
	 * Default revision retention.
	 */
	public const DEFAULT_KEEP = 10;

	/**
	 * Option row used as the save mutex: a plain INSERT against the UNIQUE
	 * option_name key (see acquire_lock()), valued `<unix time>:<owner token>`.
	 */
	private const LOCK_OPTION = 'post_shield_config_write_lock';

	/**
	 * Seconds after which a crashed save's lock is considered stale.
	 */
	private const LOCK_TTL = 30;

	/**
	 * Filename stamp format for phased-out revisions (UTC).
	 */
	private const STAMP_FORMAT = 'Ymd-His';

	/**
	 * The hardcoded FLOOR of globally-excluded bases (root-pages v2): core and
	 * meta routes root matching may never decide, snapshotted into every
	 * artifact and shown greyed (not removable) on the settings page. The
	 * loader additionally hardcodes the clean-charset subset in match_root() —
	 * dotted rows are belt-and-braces there (any dotted path already falls
	 * through the root matcher's segment charset).
	 */
	public const FLOOR_EXCLUDED_BASES = [
		// Core directories + the REST tree. `wp-json` (first-segment) covers
		// the ENTIRE REST surface — every namespace (`wp/v2`, `wc/v3`,
		// `rankmath/v1`, `contact-form-7/v1`, …) at once; individual REST
		// routes are never listed. The `?rest_route=` fallback form rides on
		// `/` (empty path), which root matching passes structurally.
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'.well-known',
		// Core PHP endpoints (all dot-bearing, so the root matcher's charset
		// guard already passes them — listed for portability + documentation,
		// and as defence if that guard is ever loosened).
		'wp-login.php',
		'xmlrpc.php',
		'wp-cron.php',
		'wp-trackback.php',
		'wp-comments-post.php',
		'wp-signup.php',
		'wp-activate.php',
		'wp-links-opml.php',
		'wp-mail.php',
		// Well-known static files + sitemaps (dot-bearing or wildcard).
		'robots.txt',
		'favicon.ico',
		'ads.txt',
		'app-ads.txt',
		'sitemap*',
		'wp-sitemap*',
		'sitemap_index.xml',
		// The shield's own bake probe path.
		'post-shield-404-probe',
	];

	/**
	 * Option holding the last root-preflight result (S6), for the status card.
	 */
	public const PREFLIGHT_OPTION = 'post_shield_last_preflight';

	/**
	 * Fingerprint of the redirect sources the derived reserved slugs were last
	 * built from. Rank Math (and the others) fire no add/update hook, so change
	 * detection is how the derived bucket stays current.
	 *
	 * @var string
	 */
	public const REDIRECT_FP_OPTION = 'post_shield_redirect_fingerprint';

	/**
	 * Version of the redirect-source reduction (variants + reducer). Salts the
	 * fingerprint: bump it whenever a change would derive different slugs from
	 * the same sources, so every site re-derives on its next nightly tick.
	 *
	 * @var int
	 */
	private const REDIRECT_DERIVATION_VERSION = 2;

	/**
	 * Optional rebuild seam for the match-mode-switch ordering (SPEC §3.2c).
	 * Signature: fn( string[] $post_types, array $entries ): void — rebuild the
	 * named effective CPTs' allowlists against the given (candidate) entries.
	 * Injected by the bootstrap; absent in pure unit contexts.
	 *
	 * @var callable|null
	 */
	private $rebuild_handler = null;

	/**
	 * Optional preflight seam (S6, root-pages v2). Signature:
	 * fn( array $candidate ): string[] — walk real URLs through the would-be
	 * loader decision against the CANDIDATE config and return every URL that
	 * would 404 (empty = safe). Injected by the bootstrap; absent in pure unit
	 * contexts. write() runs it on any save that leaves root mode ACTIVE and
	 * aborts the save when it reports would-blocks (unless forced).
	 *
	 * @var callable|null
	 */
	private $preflight_handler = null;

	/**
	 * Based-entry coverage gate, injected by bootstrap: fn( array $candidate,
	 * ?array $current_entries ): array{checked:int, breaks:array, homes:array}.
	 * See BasedPreflight.
	 *
	 * @var callable|null
	 */
	private $coverage_handler = null;

	/**
	 * Redirect source rows read this request (see redirect_sources()); null
	 * until first read.
	 *
	 * @var array<int, array{pattern: string, regex: bool}>|null
	 */
	private $redirect_sources_cache = null;

	/**
	 * This instance's value in the lock row, or '' when it holds no lock. The
	 * owner token lets release and re-verification touch only our own row.
	 *
	 * @var string
	 */
	private $lock_value = '';

	/**
	 * The locale pattern body a config document puts in play: '' when its
	 * locale mode is `none`, absent, or its pattern fails validation (so an
	 * unvalidated custom pattern never reaches a regex before validate() runs).
	 *
	 * @param array<string, mixed>|null $config Config document.
	 *
	 * @return string
	 */
	public static function locale_pattern_of( ?array $config ): string {
		$locale = is_array( $config ) ? ( $config['locale'] ?? null ) : null;
		if ( ! is_array( $locale ) || 'none' === ( $locale['mode'] ?? 'none' ) ) {
			return '';
		}
		$pattern = is_string( $locale['pattern'] ?? null ) ? $locale['pattern'] : '';
		return \Post404Shield\locale_pattern_is_valid( $pattern ) ? $pattern : '';
	}

	/**
	 * Inject the root-preflight handler used by write()'s S6 gate.
	 *
	 * @param callable $handler fn( array $candidate ): string[] would-block URLs.
	 *
	 * @return void
	 */
	public function set_preflight_handler( callable $handler ): void {
		$this->preflight_handler = $handler;
	}

	/**
	 * Inject the based-entry coverage gate (see BasedPreflight). Unset in pure
	 * unit contexts, where there are no real URLs to replay.
	 *
	 * @param callable $handler fn( array $candidate, ?array $current_entries ): array.
	 *
	 * @return void
	 */
	public function set_coverage_handler( callable $handler ): void {
		$this->coverage_handler = $handler;
	}

	/**
	 * Inject the allowlist rebuild handler used for synchronous mode-switch
	 * rebuilds inside write().
	 *
	 * @param callable $handler fn( string[] $post_types, array $entries ): void.
	 *
	 * @return void
	 */
	public function set_rebuild_handler( callable $handler ): void {
		$this->rebuild_handler = $handler;
	}

	/**
	 * Absolute path to the shield's uploads directory.
	 *
	 * @return string
	 */
	public function artifact_dir(): string {
		return \Post404Shield\shield_dir();
	}

	/**
	 * Absolute path to the runtime config artifact.
	 *
	 * @return string
	 */
	public function artifact_path(): string {
		return $this->artifact_dir() . '/config.php';
	}

	/**
	 * The editable config from the option, or null when never saved.
	 *
	 * @return array<string, mixed>|null
	 */
	public function option(): ?array {
		$value = get_option( self::OPTION, null );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * The validated runtime config from the artifact, or null (missing/corrupt).
	 *
	 * @return array<string, mixed>|null
	 */
	public function artifact(): ?array {
		return \Post404Shield\read_config( $this->artifact_path() );
	}

	/**
	 * Revision retention, clamped to the legal 10–100 step-10 range.
	 *
	 * @return int
	 */
	public function keep(): int {
		$keep = (int) get_option( self::KEEP_OPTION, self::DEFAULT_KEEP );
		if ( $keep < 10 || $keep > 100 || 0 !== $keep % 10 ) {
			return self::DEFAULT_KEEP;
		}
		return $keep;
	}

	/**
	 * Persist a new retention value (validated to the same range).
	 *
	 * @param int $keep Revisions to keep.
	 *
	 * @return bool True when the value was legal and stored.
	 */
	public function update_keep( int $keep ): bool {
		if ( ! $this->keep_is_valid( $keep ) ) {
			return false;
		}
		update_option( self::KEEP_OPTION, $keep, false );
		return true;
	}

	/**
	 * Range check for the revision-retention value, WITHOUT persisting it.
	 *
	 * Split out so a save can reject an illegal value up front and still defer
	 * the write until the config pipeline has actually succeeded — a rejected
	 * save must not leave retention changed behind a "nothing was saved" banner.
	 *
	 * @param int $keep Candidate retention.
	 *
	 * @return bool True when 10–100 in steps of 10.
	 */
	public function keep_is_valid( int $keep ): bool {
		return $keep >= 10 && $keep <= 100 && 0 === $keep % 10;
	}

	// --- Root mode derivations (root-pages v2) ---------------------------------

	/**
	 * The built-in `post` type's derived URL base, parsed from Settings →
	 * Permalinks (requirement 1: the operator never types a base for post/page).
	 *
	 * @return array{supported: bool, base: string} base '' = root-level posts.
	 */
	public function post_base_info(): array {
		return \Post404Shield\permalink_post_base( (string) get_option( 'permalink_structure', '' ) );
	}

	/**
	 * The COMPUTED root-dweller set (S1): every content type whose URLs live at
	 * the site root. `page` always (no base by nature); `post` only when the
	 * permalink structure yields no static base. Never hardcoded per-site: a
	 * `/blog/%postname%/` structure makes `post` a normal based type instead.
	 *
	 * @return string[] Post types that must enable together in root mode.
	 */
	public function root_dweller_types(): array {
		$types = [ 'page' ];
		$info  = $this->post_base_info();
		if ( $info['supported'] && '' === $info['base'] ) {
			$types[] = 'post';
		}
		return $types;
	}

	/**
	 * The AUTO-DERIVED globally-excluded bases — recomputed from WordPress
	 * itself at read time, never stored stale (the artifact carries a SNAPSHOT
	 * taken at save time; the status card compares the two for staleness).
	 *
	 * Sources: `$wp_rewrite` bases (pagination, author, search, comments,
	 * comment pagination, feed), the core `embed` endpoint, category/tag bases,
	 * every registered taxonomy rewrite slug, every registered post type's
	 * rewrite slug and string `has_archive`, and the derived post base when
	 * posts are NOT root-dwellers. Date archives are handled structurally (an
	 * all-digit first segment always passes the root matcher) rather than
	 * listed.
	 *
	 * @param string $locale_pattern Locale pattern body ('' = none) — redirect
	 *                               sources are stored locale-free, because
	 *                               match_root() strips the locale first.
	 *
	 * @return string[] Deduped, charset-filtered excluded bases.
	 */
	public function derived_excluded_bases( string $locale_pattern = '' ): array {
		global $wp_rewrite;

		$bases = [];
		if ( is_object( $wp_rewrite ) ) {
			foreach ( [ 'pagination_base', 'author_base', 'search_base', 'comments_base', 'comments_pagination_base', 'feed_base' ] as $property ) {
				if ( isset( $wp_rewrite->$property ) && is_string( $wp_rewrite->$property ) ) {
					$bases[] = trim( $wp_rewrite->$property, '/' );
				}
			}
		}
		$bases[] = 'embed';

		$category_base = (string) get_option( 'category_base', '' );
		$bases[]       = '' !== trim( $category_base, '/' ) ? trim( $category_base, '/' ) : 'category';
		$tag_base      = (string) get_option( 'tag_base', '' );
		$bases[]       = '' !== trim( $tag_base, '/' ) ? trim( $tag_base, '/' ) : 'tag';

		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( get_taxonomies( [], 'objects' ) as $taxonomy ) {
				if ( is_array( $taxonomy->rewrite ?? null ) && ! empty( $taxonomy->rewrite['slug'] ) ) {
					$bases[] = trim( (string) $taxonomy->rewrite['slug'], '/' );
				}
			}
		}
		if ( function_exists( 'get_post_types' ) ) {
			foreach ( get_post_types( [], 'objects' ) as $type_object ) {
				if ( is_array( $type_object->rewrite ?? null ) && ! empty( $type_object->rewrite['slug'] ) ) {
					$bases[] = trim( (string) $type_object->rewrite['slug'], '/' );
				}
				if ( is_string( $type_object->has_archive ?? null ) && '' !== $type_object->has_archive ) {
					$bases[] = trim( $type_object->has_archive, '/' );
				}
			}
		}

		$info = $this->post_base_info();
		if ( $info['supported'] && '' !== $info['base'] ) {
			$bases[] = $info['base'];
		}

		// WordPress's OWN rewrite table is the authoritative map of custom
		// routes: every rule with a literal leading segment
		// (`schema-preview(/…)`, an AMP endpoint, a plugin's add_rewrite_rule,
		// a WooCommerce endpoint) names a namespace WordPress routes and the
		// shield must never decide. Content rules are capture-group-prefixed
		// (`(.?.+?)/…`) and yield no base, so page/post space is never
		// excluded — this can only add pass-throughs. Recomputed each save, so
		// a newly-activated plugin's routes are covered on the next save (the
		// staleness hint flags the drift meanwhile).
		foreach ( $this->rewrite_rule_bases() as $rewrite_base ) {
			$bases[] = $rewrite_base;
		}

		// WordPress-layer redirect sources are REAL URLs (they serve 301s
		// inside WordPress, so WordPress must receive the request) — a
		// pre-boot 404 on one silently kills the redirect. Ingested here so
		// they pass root matching, the status card's staleness hint flags a
		// rule added after the last save, and the preflight probes them.
		foreach ( $this->redirect_source_bases( $locale_pattern ) as $redirect_base ) {
			$bases[] = $redirect_base;
		}

		/**
		 * Extension seam: another redirect manager (or theme routing layer)
		 * can add its own route surface to the derived exclusions.
		 *
		 * @param string[] $bases Derived excluded bases so far.
		 */
		$bases = (array) apply_filters( 'post_shield_derived_excluded_bases', $bases );

		$clean = [];
		foreach ( $bases as $base ) {
			if ( \Post404Shield\excluded_base_is_valid( $base ) && ! in_array( $base, $clean, true ) ) {
				$clean[] = $base;
			}
		}
		return $clean;
	}

	/**
	 * Every reserved-route base WordPress's rewrite table claims: the leading
	 * literal segment of each rewrite rule (see rewrite_pattern_base()).
	 *
	 * The rewrite table is the ground truth for "what root path resolves to
	 * something" — so this generically covers custom plugin/theme routes
	 * (`schema-preview`, AMP, WooCommerce endpoints, any add_rewrite_rule)
	 * without listing them. Content rules yield no base (capture-group prefix),
	 * so it never excludes real page/post space. Runs at save time only.
	 *
	 * @return string[]
	 */
	private function rewrite_rule_bases(): array {
		global $wp_rewrite;

		if ( ! isset( $wp_rewrite ) || ! is_object( $wp_rewrite ) || ! method_exists( $wp_rewrite, 'wp_rewrite_rules' ) ) {
			return [];
		}
		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( ! is_array( $rules ) ) {
			return [];
		}
		$bases = [];
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$base = \Post404Shield\rewrite_pattern_base( $pattern );
			if ( '' !== $base ) {
				$bases[ $base ] = true;
			}
		}
		return array_keys( $bases );
	}

	/**
	 * Redirect-plugin SOURCES from every supported plugin, reduced to
	 * excluded-base literals so the shield passes them through to WordPress and
	 * the plugin serves its 301 (root mode 404s pre-boot, before any in-WP
	 * redirect can fire — so a redirect source that is not excluded is silently
	 * broken). This is what lets a site keep managing redirects in its plugin
	 * of choice instead of moving them to the edge.
	 *
	 * Each source is first rewritten by the pure redirect_source_variants()
	 * (leading locale stripped, literal alternations expanded — the common
	 * `^([a-z]{2}-[a-z]{2}|global)/…` idiom otherwise reduces to nothing), then
	 * reduced by redirect_pattern_base() (multi-segment preserved — see its
	 * docblock) and charset-validated. Output is locale-free, which is what both
	 * consumers compare against: match_root() strips the locale before its
	 * exclusion test, and allowlists are locale-agnostic.
	 *
	 * @param string $locale_pattern Locale pattern body ('' = no locale segment).
	 *
	 * @return string[]
	 */
	private function redirect_source_bases( string $locale_pattern = '' ): array {
		$bases = [];
		foreach ( $this->redirect_sources() as $source ) {
			foreach ( \Post404Shield\redirect_source_variants( $source['pattern'], $source['regex'], $locale_pattern ) as $variant ) {
				// Lower-cased AFTER literal extraction, so regex escapes are read
				// with their original meaning first. The shield only ever matches
				// lower-case slugs (an upper-case request fails its charset guard and
				// falls through to WordPress untouched), so a source stored as
				// `Old-Event` is only at risk on its lower-case variant — which is
				// exactly what a case-insensitive redirect plugin answers, and what
				// old links and bots hit. Rejecting it instead left that variant on
				// a pre-boot 404 with no signal at all.
				$base = strtolower( \Post404Shield\redirect_pattern_base( $variant, $source['regex'] ) );
				if ( '' !== $base && \Post404Shield\excluded_base_is_valid( $base ) ) {
					$bases[ $base ] = true;
				}
			}
		}
		return array_keys( $bases );
	}

	/**
	 * Every redirect SOURCE row from every supported plugin plus the
	 * `post_shield_redirect_sources` filter, unreduced. Memoised per INSTANCE:
	 * one save needs it three times (derived bucket, excluded-bases snapshot,
	 * fingerprint), and all three must see the same read. A test that changes
	 * the sources mid-request needs a fresh ConfigStore to see the change.
	 *
	 * Each reader is independent, guarded (plugin active + storage present) and
	 * wrapped so one plugin's failure can never break the others or the wider
	 * derivation. New plugins slot in as another reader here, or hook the
	 * `post_shield_redirect_sources` / `post_shield_derived_excluded_bases`
	 * filters.
	 *
	 * @return array<int, array{pattern: string, regex: bool}>
	 */
	private function redirect_sources(): array {
		if ( null !== $this->redirect_sources_cache ) {
			return $this->redirect_sources_cache;
		}

		$readers = [
			'rank_math_redirect_sources',
			'redirection_plugin_redirect_sources',
			'yoast_premium_redirect_sources',
			'safe_redirect_manager_redirect_sources',
			'aioseo_redirect_sources',
			'simple_301_redirect_sources',
		];

		$sources = [];
		foreach ( $readers as $reader ) {
			try {
				foreach ( $this->$reader() as $source ) {
					$sources[] = $source;
				}
			} catch ( \Throwable $e ) {
				error_log( '[post-404-shield] redirect reader ' . $reader . ' failed (ignored): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		/**
		 * Extension seam for a redirect plugin not natively supported. Each
		 * item is a `['pattern' => string, 'regex' => bool]` source row. A
		 * throwing hooked callback is contained (a save must never fatal on a
		 * third-party filter) and simply leaves the native sources unchanged.
		 *
		 * @param array<int, array{pattern: string, regex: bool}> $sources Normalised redirect sources.
		 */
		try {
			$sources = (array) apply_filters( 'post_shield_redirect_sources', $sources );
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] post_shield_redirect_sources filter threw (ignored): ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// The ONE place a source is checked: readers hand back rows as they
		// find them, and anything that is not a non-blank string pattern —
		// from a reader or from the filter — is dropped here.
		$clean = [];
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || ! is_string( $source['pattern'] ?? null ) || '' === trim( $source['pattern'] ) ) {
				continue;
			}
			$clean[] = [
				'pattern' => $source['pattern'],
				'regex'   => ! empty( $source['regex'] ),
			];
		}
		$this->redirect_sources_cache = $clean;
		return $clean;
	}

	/**
	 * Rank Math → Redirections active sources. `sources` is a serialised list
	 * of `['pattern', 'comparison']`; exact/start are literal, regex is a
	 * regex, contains/end cannot map to a prefix and are skipped.
	 *
	 * @return array<int, array{pattern: string, regex: bool}>
	 */
	private function rank_math_redirect_sources(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! in_array( 'redirections', (array) get_option( 'rank_math_modules', [] ), true ) ) {
			return [];
		}
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( "SELECT sources FROM {$table} WHERE status = 'active'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$sources = [];
		foreach ( (array) $rows as $serialized ) {
			$decoded = maybe_unserialize( (string) $serialized );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $source ) {
				if ( ! is_array( $source ) || ! is_string( $source['pattern'] ?? null ) ) {
					continue;
				}
				$comparison = (string) ( $source['comparison'] ?? 'exact' );
				if ( ! in_array( $comparison, [ 'exact', 'start', 'regex' ], true ) ) {
					continue; // contains/end can't map to a path prefix.
				}
				// `start` is a string-prefix match — append a `*` so the
				// reducer treats it as a within-segment prefix (`/promo` start
				// must cover `/promotional/`, not just `/promo/`).
				$pattern = 'start' === $comparison ? $source['pattern'] . '*' : $source['pattern'];
				$sources[] = [
					'pattern' => $pattern,
					'regex'   => 'regex' === $comparison,
				];
			}
		}
		return $sources;
	}

	/**
	 * Redirection (John Godley) enabled URL redirects: `redirection_items.url`
	 * with the per-row `regex` flag.
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}>
	 */
	private function redirection_plugin_redirect_sources(): array {
		return $this->table_redirect_sources( 'redirection_items', 'url', 'regex', "status = 'enabled' AND match_type = 'url'" );
	}

	/**
	 * Redirect sources from a plugin table that stores one source column and
	 * one regex flag per row — the shape Redirection and AIOSEO share.
	 *
	 * Every argument is a literal from this class, never input: they are
	 * interpolated as identifiers and a WHERE clause, which prepare() cannot
	 * bind.
	 *
	 * @param string $table       Table name without the prefix.
	 * @param string $pattern_col Source column.
	 * @param string $regex_col   Regex flag column.
	 * @param string $where       Row filter.
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}>
	 */
	private function table_redirect_sources( string $table, string $pattern_col, string $regex_col, string $where ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}
		$table = $wpdb->prefix . $table;
		if ( ! $this->table_exists( $table ) ) {
			return [];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT {$pattern_col} AS pattern, {$regex_col} AS is_regex FROM {$table} WHERE {$where}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$sources = [];
		foreach ( (array) $rows as $row ) {
			$sources[] = [
				'pattern' => $row->pattern ?? null,
				'regex'   => ! empty( $row->is_regex ),
			];
		}
		return $sources;
	}

	/**
	 * Yoast SEO Premium redirects — read only while Yoast SEO Premium is
	 * ACTIVE. ALL of them live in the single option
	 * `wpseo-premium-redirects-base` — an array of objects, each with
	 * `origin` (the source), `url`, `type`, and `format` ('plain'|'regex').
	 * (Structure confirmed against Rank Math's own Yoast importer.)
	 *
	 * The active gate matters: removing Yoast leaves that option behind, and a
	 * dead plugin's redirect list is stale data — by then those URLs are
	 * usually redirected elsewhere (edge rules, another plugin) and often to
	 * newer targets, so reserving slugs from it only weakens the shield.
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}> Unchecked; redirect_sources() filters.
	 */
	private function yoast_premium_redirect_sources(): array {
		if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) && ! class_exists( 'WPSEO_Premium', false ) ) {
			return [];
		}
		$rows = get_option( 'wpseo-premium-redirects-base', [] );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$sources = [];
		foreach ( $rows as $redirect ) {
			if ( ! is_array( $redirect ) ) {
				continue;
			}
			$sources[] = [
				'pattern' => $redirect['origin'] ?? null,
				'regex'   => 'regex' === ( $redirect['format'] ?? 'plain' ),
			];
		}
		return $sources;
	}

	/**
	 * Safe Redirect Manager rules: the `redirect_rule` CPT, source in
	 * `_redirect_rule_from` (may carry a `*` wildcard) with the
	 * `_redirect_rule_from_regex` flag.
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}> Unchecked; redirect_sources() filters.
	 */
	private function safe_redirect_manager_redirect_sources(): array {
		if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'redirect_rule' ) ) {
			return [];
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return [];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT frm.meta_value AS source, COALESCE( rgx.meta_value, '0' ) AS is_regex
			 FROM {$wpdb->postmeta} frm
			 INNER JOIN {$wpdb->posts} p ON p.ID = frm.post_id AND p.post_type = 'redirect_rule' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->postmeta} rgx ON rgx.post_id = frm.post_id AND rgx.meta_key = '_redirect_rule_from_regex'
			 WHERE frm.meta_key = '_redirect_rule_from'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$sources = [];
		foreach ( (array) $rows as $row ) {
			$sources[] = [
				'pattern' => $row->source ?? null,
				'regex'   => ! empty( $row->is_regex ), // '0' is empty().
			];
		}
		return $sources;
	}

	/**
	 * All in One SEO redirects: the `aioseo_redirects` table, `source_url`
	 * with the `regex` flag, enabled rows only. Best-effort (the AIOSEO Pro
	 * schema was not live-verified) — if the columns differ the query yields
	 * nothing and AIOSEO redirects are simply not ingested (that site can use
	 * the `post_shield_redirect_sources` filter); it never breaks other
	 * readers.
	 *
	 * @return array<int, array{pattern: string, regex: bool}>
	 */
	private function aioseo_redirect_sources(): array {
		return $this->table_redirect_sources( 'aioseo_redirects', 'source_url', 'regex', 'enabled = 1' );
	}

	/**
	 * Simple 301 Redirects: the `301_redirects` option, a plain `old => new`
	 * map (keys are the sources).
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}> Unchecked; redirect_sources() filters.
	 */
	private function simple_301_redirect_sources(): array {
		$map = get_option( '301_redirects', [] );
		if ( ! is_array( $map ) ) {
			return [];
		}
		$sources = [];
		foreach ( array_keys( $map ) as $origin ) {
			$sources[] = [
				'pattern' => $origin,
				'regex'   => false,
			];
		}
		return $sources;
	}

	/**
	 * Whether a database table exists (cheap, guarded).
	 *
	 * @param string $table Fully-prefixed table name.
	 *
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Reserved slugs DERIVED from the site's redirect plugins, per entry.
	 *
	 * A redirect exists precisely because a slug no longer has a post behind it,
	 * while an allowlist is built from posts that DO exist — so a redirect whose
	 * source sits on a shielded base is answered by the pre-boot 404 and its 301
	 * never fires. Deriving those sources as reserved slugs makes the shield pass
	 * them through to WordPress so the redirect plugin can answer.
	 *
	 * Exact sources yield an exact slug. A regex/starts-with source yields the
	 * literal prefix plus `*` (see `redirect_pattern_base()`), matched by
	 * `slug_is_reserved()`. Sources deeper than one segment under a base are
	 * skipped: slug mode only ever matches a single segment.
	 *
	 * Kept in its own bucket so a rebuild can replace it wholesale without
	 * touching operator-typed reserved slugs.
	 *
	 * @param array<string, mixed> $entries        Candidate entries.
	 * @param string               $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return array<string, string[]> Entry key => derived reserved slugs.
	 */
	public function derived_reserved_slugs( array $entries, string $locale_pattern ): array {
		// Locale-free already: redirect_source_variants() drops a leading locale
		// (literal or group). Allowlists are locale-agnostic, so one reserved
		// slug authorises the URL in every locale — wider than the redirect
		// itself, but only ever in the fail-OPEN direction.
		$sources = $this->redirect_source_bases( $locale_pattern );
		if ( [] === $sources ) {
			return [];
		}

		$derived = [];
		foreach ( $sources as $source ) {
			$wildcard = '*' === substr( (string) $source, -1 );
			$path     = trim( rtrim( (string) $source, '*' ), '/' );
			if ( '' === $path ) {
				continue;
			}

			foreach ( $entries as $key => $settings ) {
				if ( ! is_array( $settings ) || true === ( $settings['root'] ?? false ) ) {
					continue;
				}
				if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
					continue;
				}
				if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
					continue;
				}
				foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
					if ( ! is_string( $base ) || '' === $base || 0 !== strpos( $path . '/', $base . '/' ) ) {
						continue;
					}
					$slug = trim( substr( $path, strlen( $base ) ), '/' );
					// Single segment only, and the same charset the matcher captures.
					if ( '' === $slug || false !== strpos( $slug, '/' ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
						continue;
					}
					$derived[ $key ][] = $wildcard ? $slug . '*' : $slug;
				}
			}
		}

		foreach ( $derived as $key => $slugs ) {
			$slugs = array_values( array_unique( $slugs ) );
			sort( $slugs );
			$derived[ $key ] = $slugs;
		}
		return $derived;
	}

	/**
	 * Write the derived reserved slugs onto their entries, replacing whatever
	 * the previous snapshot held.
	 *
	 * @param array<string, mixed> $entries        Candidate entries.
	 * @param string               $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return array<string, mixed> Entries carrying a fresh `reserved_derived`.
	 */
	public function apply_derived_reserved( array $entries, string $locale_pattern ): array {
		$derived = $this->derived_reserved_slugs( $entries, $locale_pattern );
		foreach ( $entries as $key => $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$slugs = $derived[ $key ] ?? [];
			if ( [] === $slugs ) {
				unset( $entries[ $key ]['reserved_derived'] );
				continue;
			}
			$entries[ $key ]['reserved_derived'] = $slugs;
		}
		return $entries;
	}

	/**
	 * A stable fingerprint of every active redirect source on the site.
	 *
	 * Rank Math's `Db::add()`/`update()` write straight through and fire no
	 * action (only deletion has a hook), so there is nothing to subscribe to.
	 * Comparing this against the stored value is how a newly added redirect is
	 * noticed — cheap enough to run on the daily tick, and it changes if any
	 * source is added, edited, deactivated or removed.
	 *
	 * Hashes the RAW source rows, not their reductions: a source the reducer
	 * cannot read must still move the fingerprint when it is added. Salted with
	 * REDIRECT_DERIVATION_VERSION so a deploy that changes how sources are
	 * reduced re-derives on the next nightly tick instead of waiting for a save.
	 *
	 * @return string Hash, or '' when no redirect source is readable.
	 */
	public function redirect_fingerprint(): string {
		$rows = [];
		foreach ( $this->redirect_sources() as $source ) {
			$rows[] = ( $source['regex'] ? 'regex:' : 'plain:' ) . $source['pattern'];
		}
		if ( [] === $rows ) {
			return '';
		}
		$rows = array_values( array_unique( $rows ) );
		sort( $rows );
		return md5( 'derivation-v' . self::REDIRECT_DERIVATION_VERSION . "\n" . implode( "\n", $rows ) );
	}

	/**
	 * The full excluded-bases snapshot persisted into the artifact: hardcoded
	 * floor + live-derived rows + the operator's own rows (validated, deduped
	 * against the other two buckets).
	 *
	 * @param array  $operator       Operator-added rows (from the settings textarea).
	 * @param string $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return array{floor: string[], derived: string[], operator: string[]}
	 */
	public function excluded_bases_snapshot( array $operator, string $locale_pattern = '' ): array {
		$floor   = self::FLOOR_EXCLUDED_BASES;
		$derived = $this->derived_excluded_bases( $locale_pattern );

		$clean = [];
		foreach ( $operator as $base ) {
			if ( ! is_string( $base ) || '' === $base ) {
				continue;
			}
			if ( in_array( $base, $floor, true ) || in_array( $base, $derived, true ) || in_array( $base, $clean, true ) ) {
				continue;
			}
			$clean[] = $base;
		}

		return [
			'floor'    => $floor,
			'derived'  => $derived,
			'operator' => $clean,
		];
	}

	/**
	 * Whether a set of entries contains at least one ENABLED root entry (root
	 * mode active). Pure — config-only.
	 *
	 * @param array $entries Config entries.
	 *
	 * @return bool
	 */
	public function has_enabled_root_entries( array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || true !== ( $entry['root'] ?? false ) ) {
				continue;
			}
			if ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ) {
				return true;
			}
		}
		return false;
	}

	// --- Validation -----------------------------------------------------------

	/**
	 * Validate a candidate config document for saving.
	 *
	 * Returns EVERY failure (the admin banner lists them all), plus non-blocking
	 * warnings (unregistered CPTs — matches the existing rebuild guard, which
	 * warns rather than blocks). An empty `errors` array means the document may
	 * be persisted.
	 *
	 * @param array<string, mixed> $config Candidate document (version/locale/entries).
	 *
	 * @return array{errors: string[], warnings: string[]}
	 */
	public function validate( array $config ): array {
		$errors   = [];
		$warnings = [];

		$entries = $config['entries'] ?? null;
		if ( ! is_array( $entries ) ) {
			return [
				'errors'   => [ __( 'Config has no entries list.', 'post-404-shield' ) ],
				'warnings' => [],
			];
		}

		// Locale block (SPEC §3.3): mode enum; pattern charset/length/compile.
		$locale = $config['locale'] ?? [];
		$mode   = is_array( $locale ) ? ( $locale['mode'] ?? null ) : null;
		if ( ! in_array( $mode, [ 'none', 'wpml-directory', 'custom' ], true ) ) {
			$errors[] = __( 'Locale mode must be one of: none, wpml-directory, custom.', 'post-404-shield' );
		} elseif ( 'none' !== $mode ) {
			$pattern = (string) ( $locale['pattern'] ?? '' );
			if ( ! \Post404Shield\locale_pattern_is_valid( $pattern ) ) {
				$errors[] = __( 'Locale pattern is invalid: lowercase letters, digits, [] {} | , - only (no parentheses, no # or \\), max 200 chars, and it must compile.', 'post-404-shield' );
			}
		}

		// Root-dweller facts (root-pages v2, S1). Only resolvable inside a booted
		// WordPress; in pure unit contexts the structural checks still run but
		// the permalink-derived rules are skipped.
		$in_wp        = function_exists( 'get_option' );
		$root_dwellers = $in_wp ? $this->root_dweller_types() : null;
		$post_info     = $in_wp ? $this->post_base_info() : null;

		$seen_bases         = [];
		$enabled_root_types = [];
		foreach ( $entries as $key => $entry ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $key ) || ! is_array( $entry ) ) {
				$errors[] = __( 'An entry has a malformed key.', 'post-404-shield' );
				continue;
			}
			$label         = $key;
			$entry_mode    = $entry['mode'] ?? 'allowlist';
			$entry_enabled = ! isset( $entry['enabled'] ) || false !== $entry['enabled'];
			$entry_root    = true === ( $entry['root'] ?? false );
			if ( ! in_array( $entry_mode, [ 'allowlist', 'block' ], true ) ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: mode must be "allowlist" or "block".', 'post-404-shield' ), $label );
				// Reported once; the checks below take a string (strict_types), so a
				// hand-staged non-string mode must not fatal the save instead.
				$entry_mode = '';
			}
			if ( isset( $entry['root'] ) && ! is_bool( $entry['root'] ) ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: root must be a boolean.', 'post-404-shield' ), $label );
			}
			if ( isset( $entry['allow_pagination'] ) && ! is_bool( $entry['allow_pagination'] ) ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: the pagination allowance must be a boolean.', 'post-404-shield' ), $label );
			}

			// Root entries (root-pages v2) + the unsupported-permalink refusal —
			// split out to keep this function's complexity in bounds.
			foreach ( $this->root_entry_errors( $label, $entry, $entry_mode, $entry_enabled, $entry_root, $root_dwellers, $post_info ) as $root_error ) {
				$errors[] = $root_error;
			}
			if ( $entry_root && $entry_enabled ) {
				$enabled_root_types[] = (string) ( $entry['post_type'] ?? $key );
			}

			// Bases: ≥1, charset, no leading/trailing slash; collected for the
			// cross-entry uniqueness/overlap check below. ENABLED entries only —
			// a disabled entry is inert in the loader, so NOTHING about its bases
			// may ever block a save: disabling IS the remediation for a bad entry
			// (empty, reserved, malformed, overlapping — all irrelevant while off).
			// Root entries are structurally base-less and skip this block.
			$bases = $entry['url_base'] ?? [];
			$bases = is_array( $bases ) ? $bases : [];
			if ( $entry_enabled && ! $entry_root ) {
				if ( [] === $bases ) {
					/* translators: %s: entry key. */
					$errors[] = sprintf( __( '%s: at least one URL base is required.', 'post-404-shield' ), $label );
				}
				foreach ( $bases as $base ) {
					if ( ! \Post404Shield\url_base_is_valid( $base ) ) {
						/* translators: 1: entry key, 2: the offending base. */
						$errors[] = sprintf( __( '%1$s: base "%2$s" is invalid — lowercase letters, digits, hyphens and internal slashes only (no leading/trailing slash).', 'post-404-shield' ), $label, is_scalar( $base ) ? (string) $base : gettype( $base ) );
						continue;
					}
					// A base must never squat on a WordPress-reserved URL namespace: a
					// `page` base turns root /page/N/ pagination into pre-boot 404s
					// (seen live), and the rest would intercept core routes the same way.
					// First segment only — deeper collisions (e.g. docs/feed) are fine.
					$first_segment = explode( '/', (string) $base )[0];
					if ( in_array( $first_segment, \Post404Shield\reserved_namespaces(), true ) ) {
						/* translators: 1: entry key, 2: the offending base, 3: the reserved namespace. */
						$errors[] = sprintf( __( '%1$s: base "%2$s" collides with the WordPress-reserved "%3$s" URL namespace (pagination/core routes) and cannot be shielded. Untick the entry to disable it (a disabled entry saves fine), or change the base.', 'post-404-shield' ), $label, (string) $base, $first_segment );
						continue;
					}
					$seen_bases[] = [ $label, (string) $base ];
				}
			}

			foreach ( $this->entry_field_errors( $label, $entry ) as $field_error ) {
				$errors[] = $field_error;
			}

			if ( 'block' === $entry_mode ) {
				continue;
			}

			// Allowlist-mode extras: statuses must exist; CPT registration warns.
			$post_type = (string) ( $entry['post_type'] ?? $key );
			if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $post_type ) ) {
				/* translators: 1: entry key, 2: post type. */
				$warnings[] = sprintf( __( '%1$s: post type "%2$s" is not registered — its allowlist will be empty and the base fails open.', 'post-404-shield' ), $label, $post_type );
			}
			if ( isset( $entry['post_status'] ) && is_array( $entry['post_status'] ) && function_exists( 'get_post_stati' ) ) {
				$known = get_post_stati();
				foreach ( $entry['post_status'] as $status ) {
					if ( ! is_string( $status ) || ! isset( $known[ $status ] ) ) {
						/* translators: 1: entry key, 2: the status. */
						$errors[] = sprintf( __( '%1$s: post status "%2$s" does not exist.', 'post-404-shield' ), $label, is_scalar( $status ) ? (string) $status : gettype( $status ) );
					}
				}
			}
		}

		// S1 — the root completeness invariant (root-pages v2, ENFORCED): root
		// matching decides every URL not owned by a base or exclusion, so it may
		// only be active when EVERY root-dwelling type contributes its
		// allowlist. Enabling any root type requires all of them in the same
		// save. The dweller set is computed (never hardcoded) per site — and an
		// UNSUPPORTED permalink structure makes it incomputable: post URLs are
		// then shaped by dynamic tags (`/%category%/%postname%/` puts them at
		// `/{term}/{slug}/` — first segments no exclusion can enumerate), so
		// root mode as a whole must refuse, not just the post entry.
		if ( [] !== $enabled_root_types && null !== $root_dwellers ) {
			if ( null !== $post_info && ! $post_info['supported'] ) {
				$errors[] = __( 'Root matching cannot be enabled under this permalink structure: the post URL shape cannot be derived (only static segments before %postname% are supported), so completeness cannot be guaranteed and real post URLs could be 404d. Fix Settings → Permalinks first.', 'post-404-shield' );
			}
			foreach ( array_diff( $root_dwellers, $enabled_root_types ) as $missing ) {
				/* translators: %s: the missing root-dwelling post type. */
				$errors[] = sprintf( __( 'Root matching decides all unclaimed URLs; "%s" also lives at the site root and must be enabled in the same save — otherwise its every URL would 404.', 'post-404-shield' ), $missing );
			}
		}

		foreach ( $this->excluded_bases_errors( $config ) as $excluded_error ) {
			$errors[] = $excluded_error;
		}

		// Bases unique and non-overlapping ACROSS all entries incl. blocks:
		// overlap = one base's segment list is a prefix of another's, compared
		// segment-wise (so `stories` overlaps `stories/deep` but not `storiesx`).
		$count = count( $seen_bases );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				[ $label_a, $a ] = $seen_bases[ $i ];
				[ $label_b, $b ] = $seen_bases[ $j ];
				$seg_a           = explode( '/', $a );
				$seg_b           = explode( '/', $b );
				$prefix_len      = min( count( $seg_a ), count( $seg_b ) );
				if ( array_slice( $seg_a, 0, $prefix_len ) === array_slice( $seg_b, 0, $prefix_len ) ) {
					/* translators: 1: first entry key, 2: first base, 3: second entry key, 4: second base. */
					$errors[] = sprintf( __( 'Bases overlap: %1$s (%2$s) and %3$s (%4$s) — one is a segment-prefix of the other.', 'post-404-shield' ), $label_a, $a, $label_b, $b );
				}
			}
		}

		return [
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		];
	}

	/**
	 * Scalar-field errors for one entry: match/depth enums, numeric ranges,
	 * reserved-slug charset. Split from validate() to keep its complexity in
	 * bounds.
	 *
	 * @param string               $label Entry key (for messages).
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string[] Errors.
	 */
	private function entry_field_errors( string $label, array $entry ): array {
		$errors = [];

		if ( ! in_array( $entry['match'] ?? 'slug', [ 'slug', 'full-path' ], true ) ) {
			/* translators: %s: entry key. */
			$errors[] = sprintf( __( '%s: match must be "slug" or "full-path".', 'post-404-shield' ), $label );
		}
		if ( ! in_array( $entry['depth_action'] ?? 'passthrough', [ 'passthrough', '404', 'redirect' ], true ) ) {
			/* translators: %s: entry key. */
			$errors[] = sprintf( __( '%s: depth action must be passthrough, 404 or redirect.', 'post-404-shield' ), $label );
		}
		foreach ( [ 'depth_allowed', 'cache_ttl', 'edge_ttl' ] as $field ) {
			if ( isset( $entry[ $field ] ) && ( ! is_int( $entry[ $field ] ) || $entry[ $field ] < 0 ) ) {
				/* translators: 1: entry key, 2: field name. */
				$errors[] = sprintf( __( '%1$s: %2$s must be a whole number ≥ 0, or empty.', 'post-404-shield' ), $label, $field );
			}
		}
		if ( isset( $entry['reserved_allowlist'] ) && is_array( $entry['reserved_allowlist'] ) ) {
			foreach ( $entry['reserved_allowlist'] as $slug ) {
				if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					/* translators: 1: entry key, 2: the offending slug. */
					$errors[] = sprintf( __( '%1$s: reserved slug "%2$s" is invalid — lowercase letters, digits and hyphens only.', 'post-404-shield' ), $label, is_scalar( $slug ) ? (string) $slug : gettype( $slug ) );
				}
			}
		}

		return $errors;
	}

	/**
	 * Structural errors for one entry's root-pages fields: root entries are
	 * allowlist-mode, base-less, full-path, and only for computed root-dweller
	 * types; an enabled `post` entry under an unsupported permalink structure
	 * is refused in any shape (fail open — the shield cannot enumerate those
	 * URLs). Split from validate() to keep its complexity in bounds.
	 *
	 * @param string        $label         Entry key (for messages).
	 * @param array         $entry         The entry.
	 * @param string        $entry_mode    Resolved mode.
	 * @param bool          $entry_enabled Resolved enabled flag.
	 * @param bool          $entry_root    Resolved root flag.
	 * @param string[]|null $root_dwellers Computed dweller set (null outside WP).
	 * @param array|null    $post_info     Parsed post base {supported, base} (null outside WP).
	 *
	 * @return string[] Errors.
	 */
	private function root_entry_errors( string $label, array $entry, string $entry_mode, bool $entry_enabled, bool $entry_root, ?array $root_dwellers, ?array $post_info ): array {
		$errors = [];

		if ( $entry_root ) {
			$root_type = (string) ( $entry['post_type'] ?? $label );
			if ( 'allowlist' !== $entry_mode ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: a root entry must be an allowlist entry.', 'post-404-shield' ), $label );
			}
			if ( [] !== array_filter( (array) ( $entry['url_base'] ?? [] ) ) ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: a root entry has no URL base — its base is the site root by definition.', 'post-404-shield' ), $label );
			}
			if ( 'full-path' !== ( $entry['match'] ?? 'full-path' ) ) {
				/* translators: %s: entry key. */
				$errors[] = sprintf( __( '%s: a root entry always matches full paths.', 'post-404-shield' ), $label );
			}
			// Dweller membership is SITE STATE (the permalink structure), not
			// shape — ENABLED entries only, or permalink drift would brick the
			// documented emergency rollback (the disable-all save must always
			// land; disabling IS the remediation).
			if ( $entry_enabled && null !== $root_dwellers && ! in_array( $root_type, $root_dwellers, true ) ) {
				/* translators: 1: entry key, 2: post type. */
				$errors[] = sprintf( __( '%1$s: "%2$s" is not a root-dwelling type on this site — its URLs live under a base, so it must be shielded as a normal based entry.', 'post-404-shield' ), $label, $root_type );
			}
		}

		if ( $entry_enabled && 'allowlist' === $entry_mode
			&& 'post' === (string) ( $entry['post_type'] ?? $label )
			&& null !== $post_info && ! $post_info['supported']
		) {
			/* translators: %s: entry key. */
			$errors[] = sprintf( __( '%s: unsupported permalink structure — the post base cannot be derived (only static segments before %%postname%% are supported), so posts cannot be shielded and fail open. Untick the entry.', 'post-404-shield' ), $label );
		}

		return $errors;
	}

	/**
	 * Shape + charset errors for the excluded-bases document (root-pages v2).
	 * The floor/derived buckets are snapshotted by write() itself, so a failure
	 * here means a bug or a hand-crafted document — reject loudly.
	 *
	 * @param array<string, mixed> $config Candidate document.
	 *
	 * @return string[] Errors.
	 */
	private function excluded_bases_errors( array $config ): array {
		if ( ! isset( $config['excluded_bases'] ) ) {
			return [];
		}
		$excluded = $config['excluded_bases'];
		if ( ! is_array( $excluded ) ) {
			return [ __( 'The excluded-bases list is malformed.', 'post-404-shield' ) ];
		}
		$errors = [];
		foreach ( [ 'floor', 'derived', 'operator' ] as $bucket ) {
			foreach ( (array) ( $excluded[ $bucket ] ?? [] ) as $base ) {
				if ( ! \Post404Shield\excluded_base_is_valid( $base ) ) {
					/* translators: 1: the offending excluded base, 2: bucket name. */
					$errors[] = sprintf( __( 'Excluded base "%1$s" (%2$s) is invalid — lowercase letters, digits, dots, hyphens and underscores only (optional trailing *), no leading/trailing slash.', 'post-404-shield' ), is_scalar( $base ) ? (string) $base : gettype( $base ), $bucket );
				}
			}
		}
		return $errors;
	}

	// --- Save pipeline ---------------------------------------------------------

	/**
	 * The full save pipeline: validate → lock → stage → archive → option →
	 * swap → prune.
	 *
	 * Nothing a reader can see changes until the final swap: the new artifact
	 * is staged to a temp file and the live one COPIED to its revision name, so
	 * `config.php` is never missing — a request loading mid-save sees the old
	 * config or the new one, never none (with none, it would also unschedule
	 * the rebuild jobs; see bootstrap.php). Every failure before the swap
	 * leaves option and artifact untouched, so "nothing was saved" is true; a
	 * failed swap puts the option back. The mutex is re-verified just before
	 * the option write, so a save whose rebuilds/preflight outlived LOCK_TTL
	 * and lost its lock to a second save stops instead of racing it.
	 *
	 * @param array<string, mixed> $config       Candidate document (entries + locale; meta re-stamped here).
	 * @param string               $generated_by Display label for the artifact meta (admin-visible).
	 * @param array<string, mixed> $flags        Optional: `keep` => int applies that retention to this save's
	 *                                           prune; `force_preflight` => true lets a save land despite
	 *                                           root-preflight would-blocks (CLI --force for a knowing operator).
	 *
	 * @return array{ok: bool, errors: string[], warnings: string[]}
	 */
	public function write( array $config, string $generated_by, array $flags = [] ): array {
		// Root-pages v2: the floor + derived excluded-bases buckets are
		// SNAPSHOTTED from live WordPress on every save (operator rows are kept
		// from the candidate) — restores therefore refresh a stale snapshot
		// automatically, and only the pre-boot loader ever reads the stored
		// copy. Skipped in pure unit contexts (no WordPress to derive from).
		$redirect_fp = null;
		if ( function_exists( 'get_option' ) ) {
			$locale_pattern           = self::locale_pattern_of( $config );
			$operator                 = (array) ( $config['excluded_bases']['operator'] ?? [] );
			$config['excluded_bases'] = $this->excluded_bases_snapshot( $operator, $locale_pattern );

			// Reserved slugs derived from the site's redirect plugins are
			// snapshotted the same way and for the same reason: the pre-boot
			// loader can only read what the artifact carries. Replaced
			// wholesale, so a removed redirect drops out; operator-typed
			// reserved slugs live in a different key and are never touched.
			$config['entries'] = $this->apply_derived_reserved( (array) ( $config['entries'] ?? [] ), $locale_pattern );

			// What the derived bucket was built from. Persisted ONLY once the
			// artifact carrying it has actually been written (see below): a
			// save rejected by validation, refused by the lock, or failing at
			// the artifact write must not record a sync that never happened —
			// the nightly job would then treat the stale bucket as current and
			// skip the new redirect indefinitely.
			$redirect_fp = $this->redirect_fingerprint();
		}

		$validated = $this->validate( $config );
		if ( [] !== $validated['errors'] ) {
			return [
				'ok'       => false,
				'errors'   => $validated['errors'],
				'warnings' => $validated['warnings'],
			];
		}

		$config['version']      = 1;
		$config['generated_at'] = gmdate( 'c' );
		$config['generated_by'] = $generated_by;

		// Belt-and-braces: the runtime reader must accept what we persist. A
		// mismatch here is a bug in validate(), not a user error — refuse loudly.
		if ( ! \Post404Shield\config_is_valid( $config ) ) {
			return [
				'ok'       => false,
				'errors'   => [ __( 'Internal error: the config failed the runtime invariant check — nothing was saved.', 'post-404-shield' ) ],
				'warnings' => $validated['warnings'],
			];
		}

		// Everything from here to the swap runs under the lock, and the lock is
		// released in the finally — including when a rebuild or preflight hook
		// throws, which would otherwise leave it held for LOCK_TTL.
		$staged = null;
		try {
			if ( ! $this->acquire_lock() ) {
				return [
					'ok'       => false,
					'errors'   => [ __( 'Another save is in progress — try again in a moment.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
				];
			}

			// Mode-switch ordering (SPEC §3.2c). The invariant: a FULL-PATH artifact
			// must never read a slug-format allowlist — nested real pages would 404
			// with a cacheable TTL. So an entry becoming full-path rebuilds its list
			// BEFORE the artifact swap (a full-path list is harmless under the old
			// slug artifact — top-level lines still match), and an entry leaving
			// full-path rebuilds AFTER the swap (a slug artifact reads a full-path
			// list safely for the instant in between). Applies to restores too —
			// they run through this same pipeline.
			[ $rebuild_before, $rebuild_after ] = $this->match_switch_rebuilds( $config );
			if ( null !== $this->rebuild_handler && [] !== $rebuild_before ) {
				( $this->rebuild_handler )( $rebuild_before, $config['entries'] );
			}

			// S6 — the root-preflight coverage gate. Any save that leaves root mode
			// ACTIVE walks real URLs through the exact would-be loader decision
			// (against the candidate config + the just-rebuilt allowlists above); a
			// non-empty would-block list ABORTS the save. `force_preflight` (the CLI
			// --force) is the knowing-operator override. Runs after the pre-swap
			// rebuilds so the lists it measures are the lists the loader will read.
			if ( null !== $this->preflight_handler && $this->has_enabled_root_entries( $config['entries'] ) ) {
				$would_block = ( $this->preflight_handler )( $config );
				if ( [] !== $would_block && empty( $flags['force_preflight'] ) ) {
					$block_errors = [
						/* translators: %d: number of URLs the root preflight would 404. */
						sprintf( __( 'Root preflight FAILED: %d real URL(s) would be served a pre-boot 404 — nothing was saved.', 'post-404-shield' ), count( $would_block ) ),
					];
					foreach ( array_slice( $would_block, 0, 10 ) as $blocked_url ) {
						/* translators: %s: a URL the root preflight would 404. */
						$block_errors[] = sprintf( __( 'Would block: %s', 'post-404-shield' ), $blocked_url );
					}
					return [
						'ok'       => false,
						'errors'   => $block_errors,
						'warnings' => $validated['warnings'],
					];
				}
				if ( [] === $would_block ) {
					$validated['warnings'][] = __( 'Root preflight passed — no real URL would be blocked.', 'post-404-shield' );
				} else {
					/* translators: %d: number of URLs the root preflight would 404 (forced save). */
					$validated['warnings'][] = sprintf( __( 'Root preflight reported %d would-block URL(s) but the save was FORCED.', 'post-404-shield' ), count( $would_block ) );
				}
			}

			// Based-entry coverage gate. Validation rejects a MALFORMED base; it
			// cannot know a well-formed one is WRONG. So every save that enables
			// or changes a based entry replays that entry's real URLs through the
			// loader's own decision against this candidate, and refuses if any
			// real URL would 404 or be redirected away. A save that changes no
			// based entry — the self-heal, a CLI write of the stored option —
			// runs nothing. `force_preflight` (CLI --force) overrides, as above.
			if ( null !== $this->coverage_handler ) {
				$current_doc = $this->option();
				$coverage    = ( $this->coverage_handler )( $config, is_array( $current_doc['entries'] ?? null ) ? $current_doc['entries'] : null );
				$breaks      = (array) ( $coverage['breaks'] ?? [] );
				if ( [] !== $breaks && empty( $flags['force_preflight'] ) ) {
					$coverage_errors = [
						sprintf(
							/* translators: 1: number of real URLs that would 404, 2: number checked. */
							__( 'Coverage check FAILED: %1$d of %2$d real URLs checked would be served a 404 by this change.', 'post-404-shield' ),
							count( $breaks ),
							(int) ( $coverage['checked'] ?? 0 )
						),
					];
					foreach ( (array) ( $coverage['homes'] ?? [] ) as $entry_key => $home ) {
						$coverage_errors[] = sprintf(
							/* translators: 1: entry name, 2: the URL base its posts really use. */
							__( '%1$s: its posts live under /%2$s/ — check the URL base.', 'post-404-shield' ),
							(string) $entry_key,
							(string) $home
						);
					}
					foreach ( array_slice( $breaks, 0, 10 ) as $break ) {
						$coverage_errors[] = sprintf(
							/* translators: 1: URL, 2: shield decision, 3: entry name. */
							__( 'Would break: %1$s (%2$s, %3$s)', 'post-404-shield' ),
							(string) $break['url'],
							(string) $break['marker'],
							(string) $break['entry']
						);
					}
					return [
						'ok'       => false,
						'errors'   => $coverage_errors,
						'warnings' => $validated['warnings'],
					];
				}
				$depth_hits = (array) ( $coverage['depth'] ?? [] );
				if ( [] !== $depth_hits ) {
					$validated['warnings'][] = sprintf(
						/* translators: 1: number of real URLs, 2: an example URL, 3: its decision. */
						_n(
							'%1$d real URL sits deeper than its entry\'s depth limit and the depth policy will act on it (e.g. %2$s → %3$s). That is intended if the entry folds child pages into their parent.',
							'%1$d real URLs sit deeper than their entry\'s depth limit and the depth policy will act on them (e.g. %2$s → %3$s). That is intended if the entry folds child pages into their parent.',
							count( $depth_hits ),
							'post-404-shield'
						),
						count( $depth_hits ),
						(string) $depth_hits[0]['url'],
						(string) $depth_hits[0]['marker']
					);
				}
				if ( (int) ( $coverage['checked'] ?? 0 ) > 0 ) {
					$validated['warnings'][] = [] === $breaks
						/* translators: %d: number of real URLs checked. */
						? sprintf( __( 'Coverage check passed — %d real URLs of the changed post types still resolve.', 'post-404-shield' ), (int) $coverage['checked'] )
						/* translators: %d: number of real URLs the forced save breaks. */
						: sprintf( __( 'Coverage check reported %d broken real URL(s) but the save was FORCED.', 'post-404-shield' ), count( $breaks ) );
				}
			}

			// Stage the new artifact and archive the live one BEFORE anything a
			// reader can see changes: a failure in either leaves the option and
			// the live artifact exactly as they were.
			$staged = $this->stage_artifact( $config );
			if ( null === $staged ) {
				return [
					'ok'       => false,
					'errors'   => [ __( 'Could not write the new config artifact — nothing was saved.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
				];
			}
			$revision = $this->archive_artifact();
			if ( null === $revision ) {
				return [
					'ok'       => false,
					'errors'   => [ __( 'Could not archive the current config as a revision — nothing was saved.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
				];
			}

			// Commit point. A save whose rebuilds/preflight outlived LOCK_TTL may
			// have had its lock broken by another save, which then owns the swap.
			if ( ! $this->refresh_lock() ) {
				$this->discard_revision( $revision );
				return [
					'ok'       => false,
					'errors'   => [ __( 'Another save took over while this one was running — nothing was saved. Reload the page and check the current settings.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
				];
			}

			$previous = get_option( self::OPTION, null );
			update_option( self::OPTION, $config, false );
			if ( ! $this->publish_artifact( $staged ) ) {
				// Keep the option describing the artifact readers actually get.
				if ( is_array( $previous ) ) {
					update_option( self::OPTION, $previous, false );
				} else {
					delete_option( self::OPTION );
				}
				$this->discard_revision( $revision );
				return [
					'ok'       => false,
					'errors'   => [ __( 'Could not swap in the new config artifact — nothing was saved.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
				];
			}
			$staged = null; // Renamed into place; nothing left to clean up.

			// The artifact now carries the derived bucket, so it is now true
			// that it was built from these redirect sources.
			if ( null !== $redirect_fp ) {
				update_option( self::REDIRECT_FP_OPTION, $redirect_fp, false );
			}

			$this->prune_revisions( isset( $flags['keep'] ) ? (int) $flags['keep'] : null );
		} finally {
			if ( null !== $staged && file_exists( $staged ) ) {
				unlink( $staged ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
			$this->release_lock();
		}

		if ( null !== $this->rebuild_handler && [] !== $rebuild_after ) {
			( $this->rebuild_handler )( $rebuild_after, $config['entries'] );
		}

		return [
			'ok'       => true,
			'errors'   => [],
			'warnings' => $validated['warnings'],
		];
	}

	/**
	 * Which effective CPTs need a synchronous allowlist rebuild for a candidate
	 * config, split by WHEN (relative to the artifact swap). Compares against
	 * the CURRENT ARTIFACT — the loader's live view: an enabled full-path entry
	 * whose type is not already serving full-path rebuilds before the swap; an
	 * enabled slug entry whose type was serving full-path rebuilds after it.
	 *
	 * @param array<string, mixed> $candidate Candidate config document.
	 *
	 * @return array{0: string[], 1: string[]} [before, after] effective CPT lists.
	 */
	private function match_switch_rebuilds( array $candidate ): array {
		$current = $this->artifact();

		// The loader's current per-CPT format: only enabled allowlist entries count.
		$current_full_path = [];
		if ( null !== $current ) {
			foreach ( $current['entries'] as $key => $entry ) {
				if ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) {
					continue;
				}
				if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
					continue;
				}
				$cpt                       = (string) ( $entry['post_type'] ?? $key );
				$current_full_path[ $cpt ] = 'full-path' === ( $entry['match'] ?? 'slug' );
			}
		}

		$before = [];
		$after  = [];
		foreach ( (array) ( $candidate['entries'] ?? [] ) as $key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) ) {
				continue;
			}
			if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$cpt       = (string) ( $entry['post_type'] ?? $key );
			$wants_fp  = 'full-path' === ( $entry['match'] ?? 'slug' );
			$serves_fp = $current_full_path[ $cpt ] ?? false;
			if ( $wants_fp && ! $serves_fp ) {
				$before[] = $cpt;
			} elseif ( ! $wants_fp && $serves_fp ) {
				$after[] = $cpt;
			}
		}

		return [ array_values( array_unique( $before ) ), array_values( array_unique( $after ) ) ];
	}

	/**
	 * Atomically write the artifact from a config document — no option update,
	 * no rotation, no pruning. This is the SELF-HEAL primitive (regenerate a
	 * missing/corrupt artifact from the option) and write()'s final step.
	 *
	 * @param array<string, mixed> $config Validated document.
	 *
	 * @return bool True on success.
	 */
	public function write_artifact( array $config ): bool {
		$staged = $this->stage_artifact( $config );
		return null !== $staged && $this->publish_artifact( $staged );
	}

	/**
	 * Write a config document to a temp file beside the artifact, ready for
	 * publish_artifact()'s atomic rename. Nothing a reader sees changes.
	 *
	 * @param array<string, mixed> $config Validated document.
	 *
	 * @return string|null The temp file path, or null on failure (nothing left behind).
	 */
	private function stage_artifact( array $config ): ?string {
		$file = $this->artifact_path();
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$this->harden_directory( $dir );

		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return null;
		}
		// `__halt_compiler();` stops PHP parsing there: without it PHP parses the
		// whole file BEFORE running `exit`, so a direct hit on the JSON body is a
		// Parse error (500 + log line) rather than an empty response. Readers
		// skip line 1 either way.
		$content = "<?php exit; __halt_compiler(); // post-404-shield generated config — do not edit by hand.\n" . $json . "\n";

		// Named temp file in the SAME directory so rename() is an atomic swap.
		$tmp     = $file . '.' . getmypid() . '.tmp';
		$written = file_put_contents( $tmp, $content ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		// A SHORT write is not an error to file_put_contents — a full disk or a
		// quota stop returns a byte count, not false. Renaming that into place
		// would atomically publish a truncated artifact: the JSON fails to
		// decode and the shield switches off sitewide.
		if ( false === $written || strlen( $content ) !== $written ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
			return null;
		}
		return $tmp;
	}

	/**
	 * Atomically swap a staged temp file in as the live artifact.
	 *
	 * @param string $staged Temp file from stage_artifact().
	 *
	 * @return bool True on success; on failure the temp file is removed and the
	 *              live artifact is untouched.
	 */
	private function publish_artifact( string $staged ): bool {
		if ( rename( $staged, $this->artifact_path() ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename
			return true;
		}
		if ( file_exists( $staged ) ) {
			unlink( $staged ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		return false;
	}

	/**
	 * Archive the live artifact (if any) under its phased-out revision name,
	 * `config-<YYYYMMDD-HHMMSS>.php` (UTC, the moment it was REPLACED). A
	 * same-second collision appends `-2`, `-3`, … so revisions are never
	 * silently overwritten.
	 *
	 * COPIED, not renamed: the live `config.php` stays in place until the new
	 * one is renamed over it, so the loader never finds the artifact missing.
	 *
	 * @return string|null The revision path, '' when there was no live artifact,
	 *                     or null when it could not be archived.
	 */
	private function archive_artifact(): ?string {
		$file = $this->artifact_path();
		if ( ! file_exists( $file ) ) {
			return '';
		}

		$dir    = dirname( $file );
		$stamp  = gmdate( self::STAMP_FORMAT );
		$target = $dir . '/config-' . $stamp . '.php';
		$suffix = 2;
		while ( file_exists( $target ) ) {
			$target = $dir . '/config-' . $stamp . '-' . $suffix . '.php';
			++$suffix;
		}

		if ( ! copy( $file, $target ) ) {
			if ( file_exists( $target ) ) {
				unlink( $target ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
			return null;
		}
		return $target;
	}

	/**
	 * Remove a revision archived by a save that then did not land — it is a
	 * copy of the still-live artifact and would only crowd out real history.
	 *
	 * @param string $revision Path from archive_artifact() ('' = none made).
	 *
	 * @return void
	 */
	private function discard_revision( string $revision ): void {
		if ( '' !== $revision && file_exists( $revision ) ) {
			unlink( $revision ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
	}

	/**
	 * Delete revisions beyond the retention count, newest kept. Only files
	 * matching the strict revision name pattern are ever touched — the current
	 * `config.php` cannot match it and is never prune-eligible.
	 *
	 * @param int|null $keep Retention to apply, for a save that has not yet
	 *                       persisted its new value; null reads the stored one.
	 *
	 * @return void
	 */
	public function prune_revisions( ?int $keep = null ): void {
		$revisions = $this->revision_files();
		// An explicit value is the retention the CURRENT save is applying —
		// without it prune reads the stored option, which the admin only writes
		// after write() returns, so a retention change would take effect one
		// save late.
		$limit = ( null !== $keep && $this->keep_is_valid( $keep ) ) ? $keep : $this->keep();
		foreach ( array_slice( $revisions, $limit ) as $path ) {
			unlink( $path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
	}

	// --- Revisions --------------------------------------------------------------

	/**
	 * Absolute paths of every revision file, newest first (the stamp is
	 * lexicographically sortable).
	 *
	 * @return string[]
	 */
	private function revision_files(): array {
		$files = glob( $this->artifact_dir() . '/config-*.php' );
		if ( ! is_array( $files ) ) {
			return [];
		}
		$files = array_values(
			array_filter(
				$files,
				static function ( string $path ): bool {
					return 1 === preg_match( '/^config-\d{8}-\d{6}(-\w+)?\.php$/', basename( $path ) );
				}
			)
		);
		// NOT rsort() on the path: same-second revisions carry a `-2`, `-3`, …
		// suffix, and lexicographically `.php` (0x2E) sorts after `-2.php`
		// (0x2D) — so the UNSUFFIXED (oldest) file would rank newest — while
		// `-10` would sort below `-2`. Both mis-orderings feed prune_revisions(),
		// which slices from the top, so the wrong revisions get deleted. Sort on
		// the parsed stamp first, then the numeric suffix.
		usort(
			$files,
			static function ( string $a, string $b ): int {
				$key = static function ( string $path ): array {
					$matched = [];
					preg_match( '/^config-(\d{8}-\d{6})(?:-(\w+))?\.php$/', basename( $path ), $matched );
					$suffix = $matched[2] ?? '';
					return [
						$matched[1] ?? '',
						ctype_digit( $suffix ) ? (int) $suffix : 0,
						$suffix,
					];
				};
				$key_a = $key( $a );
				$key_b = $key( $b );
				// Descending: newest stamp, then highest same-second suffix.
				return [ $key_b[0], $key_b[1], $key_b[2] ] <=> [ $key_a[0], $key_a[1], $key_a[2] ];
			}
		);
		return $files;
	}

	/**
	 * Revision metadata for display: stamp, path, and the generated_at/by read
	 * out of each file (best-effort — a corrupt revision still lists, marked so
	 * the admin can see it rather than wonder where it went).
	 *
	 * @return array<int, array{stamp: string, path: string, generated_at: string, generated_by: string, valid: bool}>
	 */
	public function revisions(): array {
		$out = [];
		foreach ( $this->revision_files() as $path ) {
			$stamp = preg_replace( '/^config-(.+)\.php$/', '$1', basename( $path ) );
			$meta  = [
				'stamp'        => (string) $stamp,
				'path'         => $path,
				'generated_at' => '',
				'generated_by' => '',
				'valid'        => false,
			];

			$config = \Post404Shield\read_config( $path );
			if ( null !== $config ) {
				$meta['valid']        = true;
				$meta['generated_at'] = is_string( $config['generated_at'] ?? null ) ? $config['generated_at'] : '';
				$meta['generated_by'] = is_string( $config['generated_by'] ?? null ) ? $config['generated_by'] : '';
			}

			$out[] = $meta;
		}
		return $out;
	}

	/**
	 * Read one revision's validated config. The stamp is strictly validated and
	 * the resolved path must realpath() inside the shield uploads dir before
	 * any read — belt-and-braces against traversal via a crafted stamp.
	 *
	 * @param string $stamp Revision stamp (`YYYYMMDD-HHMMSS[-suffix]`).
	 *
	 * @return array<string, mixed>|null The revision's config, or null.
	 */
	public function read_revision( string $stamp ): ?array {
		if ( 1 !== preg_match( '/^\d{8}-\d{6}(-\w+)?$/', $stamp ) ) {
			return null;
		}
		$path = realpath( $this->artifact_dir() . '/config-' . $stamp . '.php' );
		$dir  = realpath( $this->artifact_dir() );
		if ( false === $path || false === $dir || 0 !== strpos( $path, $dir . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return \Post404Shield\read_config( $path );
	}

	/**
	 * Restore a revision: its payload is run through the SAME save pipeline as
	 * any other save — fresh generated_at/by, the outgoing config rotated to a
	 * new revision, retention pruned.
	 *
	 * @param string $stamp        Revision stamp.
	 * @param string $generated_by Display label for the restored artifact's meta.
	 *
	 * @return array{ok: bool, errors: string[], warnings: string[]}
	 */
	public function restore( string $stamp, string $generated_by ): array {
		$config = $this->read_revision( $stamp );
		if ( null === $config ) {
			return [
				'ok'       => false,
				'errors'   => [ __( 'That revision does not exist or is not a valid config.', 'post-404-shield' ) ],
				'warnings' => [],
			];
		}
		unset( $config['generated_at'], $config['generated_by'] );
		return $this->write( $config, $generated_by );
	}

	// --- Legacy import -----------------------------------------------------------

	/**
	 * Repair a missing or broken artifact from the option, or the option from
	 * the artifact.
	 *
	 * - Artifact valid, option missing (a database restored from backup):
	 *   rehydrate the option from the artifact, never let them fight.
	 * - Artifact missing or invalid, option valid: regenerate the artifact —
	 *   root mode with its snapshots refreshed but no preflight (it walks every
	 *   real URL), otherwise through the full save pipeline, with a one-minute
	 *   backoff so concurrent requests don't all queue on the lock.
	 * - Option present but invalid: logged, nothing written.
	 * - Nothing anywhere: inert until an operator configures the site.
	 *
	 * Callers: admin_init and the daily health cron (see bootstrap.php). Needs
	 * every custom post type and status registered, so it must run after init.
	 *
	 * @return void
	 */
	public function self_heal(): void {
		$artifact = $this->artifact();
		$option   = $this->option();

		if ( null !== $artifact ) {
			if ( null === $option ) {
				// 4. Artifact valid, option missing (e.g. DB restored from backup):
				// rehydrate the option FROM the artifact — never let them fight.
				update_option( self::OPTION, $artifact, false );
				error_log( '[post-404-shield] self-heal: rehydrated the config option from the artifact (option was missing).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}

		// 2. Artifact missing or invalid, option valid → regenerate the artifact.
		if ( null !== $option && \Post404Shield\config_is_valid( $option ) ) {
			// Root mode: the S6 preflight inside write() walks every real URL —
			// far too heavy for a request, so it is the one step skipped here.
			// The two snapshots are NOT skipped: they are cheap, and root mode
			// depends on them. A staged option predates both, so publishing it
			// verbatim leaves the loader with no excluded bases (root mode then
			// never engages) and no derived reserved slugs — the same defect the
			// based path below fixes by going through write().
			if ( $this->has_enabled_root_entries( (array) ( $option['entries'] ?? [] ) ) ) {
				$healed                   = $option;
				$heal_pattern             = self::locale_pattern_of( $healed );
				$healed['excluded_bases'] = $this->excluded_bases_snapshot( (array) ( $healed['excluded_bases']['operator'] ?? [] ), $heal_pattern );
				$healed['entries']        = $this->apply_derived_reserved( (array) ( $healed['entries'] ?? [] ), $heal_pattern );
				if ( ! \Post404Shield\config_is_valid( $healed ) ) {
					$healed = $option;
				}
				if ( $this->write_artifact( $healed ) ) {
					// Keep the option in step with what was published, so the two
					// never disagree about the snapshots.
					if ( $healed !== $option ) {
						update_option( self::OPTION, $healed, false );
					}
					error_log( '[post-404-shield] self-heal: regenerated the config artifact from the option (root mode: snapshots refreshed, no preflight).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return;
			}

			// Claimed BEFORE trying, cleared on success. The first requests after
			// a deploy arrive together; this keeps them from all piling into
			// write() and all but one losing the lock. It is kept only on
			// FAILURE, so a heal that keeps failing costs one attempt a minute
			// rather than the full save path on every request — while a fresh
			// loss after a good heal still recovers on the very next request.
			if ( false !== get_transient( 'post_shield_heal_backoff' ) ) {
				return;
			}
			set_transient( 'post_shield_heal_backoff', 1, MINUTE_IN_SECONDS );

			// The full save pipeline, not a bare file write. The option goes
			// through validate() (reserved namespaces, overlaps — F10), and the
			// derived reserved slugs are computed (F15: a staged option predates
			// them, so a bare write published an artifact without them and the
			// shielded redirects stayed dead until the nightly sync). The swap is
			// one rename under the lock.
			$result = $this->write( $option, 'self-heal (artifact was missing or invalid)' );
			if ( $result['ok'] ) {
				delete_transient( 'post_shield_heal_backoff' );
				error_log( '[post-404-shield] self-heal: regenerated the config artifact through the full save pipeline.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( '[post-404-shield] self-heal FAILED, next attempt in a minute: ' . implode( ' | ', $result['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		if ( null !== $option ) {
			error_log( '[post-404-shield] self-heal: the config option exists but is INVALID — it cannot regenerate the artifact. Re-save Settings → Post 404 Shield.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// 3. Nothing anywhere: no artifact, no option. The shield stays INERT
		// until an operator configures it — deliberately, there is no automatic
		// seed from the legacy committed config.
		//
		// An environment is brought onto the artifact model by staging its
		// `post_shield_config` option BEFORE the code lands (branch 2 then
		// regenerates the artifact on the first request), or afterwards with
		// `wp post-shield config import-legacy`. Both are explicit operator
		// actions. Nothing reconfigures a site as a side effect of deploying.
	}

	/**
	 * Normalise the legacy committed config array into a schema-v1 document.
	 *
	 * Pure (no WP, no writes) so it is unit-testable and reusable by the CLI.
	 * Deltas applied: string url_base → array; entries sharing one CPT collapse
	 * into ONE entry whose url_base lists every base in first-appearance order
	 * (reserved lists and statuses unioned); `match: slug` added everywhere.
	 * Behaviour-bearing values are preserved EXACTLY — a missing depth_allowed
	 * stays null (unlimited/passthrough), never a UI default — so the seeded
	 * artifact is a wire-level no-op against the legacy loader.
	 *
	 * @param array<string, array<string, mixed>> $legacy         Legacy config array.
	 * @param string                              $locale_mode    This repo's locale mode (none|wpml-directory|custom).
	 * @param string                              $locale_pattern Locale pattern body ('' when mode is none).
	 *
	 * @return array<string, mixed> Schema-v1 document (unstamped; write() adds meta).
	 */
	public function import_legacy( array $legacy, string $locale_mode, string $locale_pattern ): array {
		$entries  = [];
		$cpt_keys = [];

		foreach ( $legacy as $key => $settings ) {
			$key     = (string) $key;
			$enabled = ! ( isset( $settings['enabled'] ) && false === $settings['enabled'] );
			$mode    = (string) ( $settings['mode'] ?? 'allowlist' );
			$base    = (string) ( $settings['url_base'] ?? '' );

			if ( 'block' === $mode ) {
				$entries[ $key ] = [
					'enabled'   => $enabled,
					'mode'      => 'block',
					'post_type' => null,
					'url_base'  => [ $base ],
					'cache_ttl' => isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : null,
				];
				continue;
			}

			$post_type = (string) ( $settings['post_type'] ?? $key );
			$reserved  = array_values( array_filter( (array) ( $settings['reserved_allowlist'] ?? [] ), 'is_string' ) );
			$statuses  = array_values( array_filter( (array) ( $settings['post_status'] ?? [ 'publish' ] ), 'is_string' ) );

			// Entries sharing one CPT (category-segmented bases) collapse into the
			// FIRST entry's position — bases append in legacy order, so the loader's
			// early-return iteration order is preserved for every enabled base.
			//
			// The legacy loader decided enabled PER BASE; the v1 schema has one
			// `enabled` per entry. So each base is recorded against its own
			// legacy switch and the entry is resolved once all siblings are seen
			// (below): a disabled sibling's base must not become shielded just
			// because another sibling is on.
			if ( isset( $cpt_keys[ $post_type ] ) ) {
				$target                                   = $cpt_keys[ $post_type ];
				$entries[ $target ]['base_switches'][]    = [ $base, $enabled ];
				$entries[ $target ]['reserved_allowlist'] = array_values( array_unique( array_merge( $entries[ $target ]['reserved_allowlist'], $reserved ) ) );
				$entries[ $target ]['post_status']        = array_values( array_unique( array_merge( $entries[ $target ]['post_status'], $statuses ) ) );
				$entries[ $target ]['merged_keys'][]      = $key;
				continue;
			}

			$cpt_keys[ $post_type ] = $key;
			$entries[ $key ]        = [
				'enabled'            => $enabled,
				'mode'               => 'allowlist',
				'post_type'          => $post_type,
				'url_base'           => [ $base ],
				'reserved_allowlist' => $reserved,
				'post_status'        => $statuses,
				'match'              => 'slug',
				'depth_allowed'      => isset( $settings['depth_allowed'] ) && null !== $settings['depth_allowed'] ? (int) $settings['depth_allowed'] : null,
				'depth_action'       => (string) ( $settings['depth_action'] ?? 'passthrough' ),
				'cache_ttl'          => isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : null,
				'edge_ttl'           => isset( $settings['edge_ttl'] ) ? (int) $settings['edge_ttl'] : null,
				'merged_keys'        => [ $key ],
				'base_switches'      => [ [ $base, $enabled ] ],
			];
		}

		// Resolve each merged entry's single switch from its bases' legacy ones.
		// Any base on → the entry is on and shields ONLY the bases that were on.
		// All off → the entry is off and keeps every base, so the operator loses
		// nothing by importing a fully disabled type.
		foreach ( $entries as $key => $entry ) {
			if ( ! isset( $entry['base_switches'] ) ) {
				continue;
			}
			$bases_on  = [];
			$bases_all = [];
			foreach ( $entry['base_switches'] as [ $base, $on ] ) {
				$bases_all[] = $base;
				if ( $on ) {
					$bases_on[] = $base;
				}
			}
			$entries[ $key ]['enabled']  = [] !== $bases_on;
			$entries[ $key ]['url_base'] = [] !== $bases_on ? $bases_on : $bases_all;
			unset( $entries[ $key ]['base_switches'] );
		}

		// Re-key merged entries to the trimmed common key prefix (e.g. the four
		// support-compatibility-* entries become `support-compatibility`), then
		// drop the bookkeeping field.
		$final = [];
		foreach ( $entries as $key => $entry ) {
			$new_key = $key;
			if ( isset( $entry['merged_keys'] ) ) {
				if ( count( $entry['merged_keys'] ) > 1 ) {
					$prefix = $this->common_prefix( $entry['merged_keys'] );
					$prefix = rtrim( $prefix, '-' );
					if ( '' !== $prefix && 1 === preg_match( '/^[a-z0-9_-]+$/', $prefix ) ) {
						$new_key = $prefix;
					} elseif ( is_string( $entry['post_type'] ) && '' !== $entry['post_type'] ) {
						$new_key = $entry['post_type'];
					}
				}
				unset( $entry['merged_keys'] );
			}
			$final[ $new_key ] = $entry;
		}

		return [
			'version' => 1,
			'locale'  => [
				'mode'    => $locale_mode,
				'pattern' => $locale_pattern,
			],
			'entries' => $final,
		];
	}

	/**
	 * Longest common prefix of a list of strings.
	 *
	 * @param string[] $strings Non-empty list.
	 *
	 * @return string
	 */
	private function common_prefix( array $strings ): string {
		$prefix = (string) array_shift( $strings );
		foreach ( $strings as $string ) {
			while ( '' !== $prefix && 0 !== strpos( $string, $prefix ) ) {
				$prefix = substr( $prefix, 0, -1 );
			}
		}
		return $prefix;
	}

	// --- Housekeeping --------------------------------------------------------------

	/**
	 * Remove stale config temp files left by a crashed save (older than a day).
	 * Called by the daily cron sweep.
	 *
	 * @return void
	 */
	public function sweep_tmp(): void {
		$files = glob( $this->artifact_dir() . '/config*.tmp' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			if ( is_file( $file ) && ( time() - (int) filemtime( $file ) ) > DAY_IN_SECONDS ) {
				unlink( $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
		}
	}

	/**
	 * Acquire the save mutex.
	 *
	 * NOT add_option(): that is a cache-backed get_option() existence check
	 * followed by `INSERT … ON DUPLICATE KEY UPDATE`, which succeeds whether or
	 * not the row already exists — so two concurrent saves can both be told
	 * they acquired it, interleave rotate/write, and leave the option and the
	 * artifact describing different configs. This goes straight at the UNIQUE
	 * key on `option_name` with a plain INSERT: exactly one writer can win, and
	 * a duplicate-key failure IS the "someone else holds it" signal.
	 *
	 * A lock older than LOCK_TTL (a crashed save) is broken once — and only the
	 * exact value observed is deleted, so a lock re-acquired in between is
	 * never stolen. A LIVE save that simply ran past LOCK_TTL can lose its lock
	 * this way too; write() re-verifies with refresh_lock() at its commit point,
	 * so only one of the two ever swaps the artifact.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock(): bool {
		if ( $this->insert_lock_row() ) {
			return true;
		}

		global $wpdb;
		$held = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- mutex read must bypass the object cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		// `<time>:<token>`; a bare `<time>` is a lock written before owner tokens.
		$held_at = (int) explode( ':', $held, 2 )[0];
		if ( $held_at > 0 && ( time() - $held_at ) > self::LOCK_TTL ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
				$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $held )
			);
			$this->flush_lock_cache();
			return $this->insert_lock_row();
		}
		return false;
	}

	/**
	 * The atomic half of acquire_lock(): one plain INSERT against the UNIQUE
	 * `option_name` key. Errors are suppressed because a duplicate key is the
	 * expected contended outcome, not a fault.
	 *
	 * @return bool True when this process created the row.
	 */
	private function insert_lock_row(): bool {
		global $wpdb;
		$value      = $this->new_lock_value();
		$suppressed = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic mutex cannot go through add_option().
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				$value
			)
		);
		$wpdb->suppress_errors( $suppressed );
		$this->flush_lock_cache();
		if ( ! $inserted ) {
			return false;
		}
		$this->lock_value = $value;
		return true;
	}

	/**
	 * Prove this instance still holds the lock, and restart its TTL. Called at
	 * write()'s commit point: a save whose rebuilds/preflight ran past LOCK_TTL
	 * may have been broken by a second save, which then owns the swap.
	 *
	 * A fresh token every time, so the UPDATE always changes the row — MySQL
	 * reports 0 affected rows for an UPDATE that writes the same value, which
	 * would read as "lost" within the same second.
	 *
	 * @return bool True when the lock is still ours (now re-stamped).
	 */
	private function refresh_lock(): bool {
		if ( '' === $this->lock_value ) {
			return false;
		}
		global $wpdb;
		$fresh   = $this->new_lock_value();
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paired with insert_lock_row().
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$fresh,
				self::LOCK_OPTION,
				$this->lock_value
			)
		);
		$this->flush_lock_cache();
		if ( 1 !== (int) $updated ) {
			$this->lock_value = ''; // Not ours any more — never release someone else's.
			return false;
		}
		$this->lock_value = $fresh;
		return true;
	}

	/**
	 * A lock row value: the current time (for stale detection) and a random
	 * owner token.
	 *
	 * @return string
	 */
	private function new_lock_value(): string {
		return time() . ':' . wp_generate_password( 20, false );
	}

	/**
	 * Drop the object-cache entries for the lock row, which the direct queries
	 * above bypass.
	 *
	 * @return void
	 */
	private function flush_lock_cache(): void {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Release the save mutex — only if this instance holds it. Deleting by
	 * owner value means a save whose lock was broken as stale can never delete
	 * the lock of the save that took over, and a failed acquire releases
	 * nothing.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		if ( '' === $this->lock_value ) {
			return;
		}
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paired with insert_lock_row().
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $this->lock_value )
		);
		$this->lock_value = '';
		$this->flush_lock_cache();
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
