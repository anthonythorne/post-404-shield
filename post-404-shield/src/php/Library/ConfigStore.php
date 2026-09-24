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
	 * Set when root mode was switched off automatically (the site's URL
	 * structure no longer supports it); shown on the settings screen until an
	 * operator saves again.
	 */
	public const ROOT_OFF_OPTION = 'post_shield_root_switched_off';

	/**
	 * Warnings an automatic write reported (the nightly redirect sync, a
	 * permalink re-check): logged, and shown on the settings screen until an
	 * operator saves — nobody reads an automatic write's result otherwise.
	 */
	public const AUTO_WARNINGS_OPTION = 'post_shield_automatic_warnings';

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
		// Core's shortcut redirects (wp_redirect_admin_locations()): no
		// rewrite rule names them, so nothing else would derive them.
		'login',
		'admin',
		'dashboard',
	];

	/**
	 * Option holding the last root-preflight result (S6), for the status card.
	 */
	public const PREFLIGHT_OPTION = 'post_shield_last_preflight';

	/**
	 * Root-preflight would-blocks an operator accepted with a forced save. A
	 * later save refuses only on would-blocks NOT in this set, so accepting one
	 * does not brick every future root-active save (the nightly redirect sync
	 * included, which cannot force).
	 */
	public const PREFLIGHT_ACCEPTED_OPTION = 'post_shield_preflight_accepted';

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
	private const REDIRECT_DERIVATION_VERSION = 8;

	/**
	 * Optional rebuild seam for the match-mode-switch ordering (SPEC §3.2c).
	 * Signature: fn( string[] $post_types, array $entries, bool $root_switching_on = false ): true|string[]
	 * — rebuild the named effective CPTs' allowlists against the given
	 * (candidate) entries; true when all were written, else the names of the
	 * lists that could not be (any other value fails them all), and a
	 * ReadFailure when a database read failed (a retry, not a refusal).
	 * Injected by the bootstrap; absent in pure unit contexts.
	 *
	 * @var callable|null
	 */
	private $rebuild_handler = null;

	/**
	 * Warnings from deriving reserved slugs in the current write(), shown
	 * with its result.
	 *
	 * @var string[]
	 */
	private array $derivation_warnings = [];

	/**
	 * The (entry, status) drops the current write() confirmed so far: a
	 * refusal by the other gate offers them again with its own, so one
	 * confirmation can cover every drop the save makes.
	 *
	 * @var string[]
	 */
	private array $confirmed_drop_pairs = [];

	/**
	 * Messages in the current write()'s warnings that report a pass, not a
	 * problem — left out of the automatic warnings kept for the screen.
	 *
	 * @var string[]
	 */
	private array $passes = [];

	/**
	 * The lists the last pre-swap rebuild could not write, for its refusal.
	 *
	 * @var string[]
	 */
	private array $rebuild_failures = [];

	/**
	 * Optional preflight seam (S6, root-pages v2). Signature:
	 * fn( array $candidate ): array{would_block: string[], dropped: array<string, string>}
	 * — walk real URLs through the would-be loader decision against the
	 * CANDIDATE config and return every URL that would 404 (empty = safe), and
	 * which of them a status the save drops explains (URL => status); a plain
	 * list of URLs is read as would-blocks. Injected by the bootstrap; absent in pure unit
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
	 * Whether a redirect reader failed this request (threw, or its query
	 * errored) — as opposed to reading, correctly, that there are none.
	 *
	 * @var bool
	 */
	private bool $redirect_read_failed = false;

	/**
	 * Redirect sources read this request that no path can stand for — Rank
	 * Math's "contains" and "ends with" — kept to be warned about.
	 *
	 * @var array<int, array{pattern: string, comparison: string}>
	 */
	private array $unmappable_redirects = [];

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
	 * @param callable $handler fn( array $candidate ): array{would_block: string[], dropped: array<string, string>}.
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
	 * @param callable $handler fn( string[] $post_types, array $entries, bool $root_switching_on ): true|string[] —
	 *                          the lists that failed to write, when any did.
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
			// A category archive with its base stripped: `(news)/?$`.
			foreach ( \Post404Shield\rewrite_pattern_literal_group( $pattern ) as $literal ) {
				$bases[ $literal ] = true;
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
			foreach ( \Post404Shield\redirect_source_variants( self::source_pattern( $source ), $source['regex'], $locale_pattern ) as $variant ) {
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
	 * A redirect source as the derivation reads it. A plain one is lower-cased
	 * first: it holds no regex escape to misread, and a capitalised locale
	 * (`/Global/stories/old/`, stored by a case-insensitive redirect) must be
	 * stripped like a lower-case one, or the redirect is never placed under a
	 * base. A regex is lower-cased after its literal part is extracted.
	 *
	 * @param array{pattern: string, regex: bool} $source Redirect source.
	 *
	 * @return string
	 */
	private static function source_pattern( array $source ): string {
		return $source['regex'] ? (string) $source['pattern'] : strtolower( (string) $source['pattern'] );
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

		global $wpdb;
		$sources = [];
		foreach ( $readers as $reader ) {
			try {
				// Each query resets last_error, but a reader whose plugin is off
				// runs none: clear an earlier, unrelated failure first, so an
				// error seen after the reader is its own.
				if ( isset( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
					$wpdb->last_error = '';
				}
				foreach ( $this->$reader() as $source ) {
					$sources[] = $source;
				}
				if ( isset( $wpdb ) && is_string( $wpdb->last_error ?? null ) && '' !== $wpdb->last_error ) {
					$this->redirect_read_failed = true;
					error_log( '[post-404-shield] redirect reader ' . $reader . ' failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} catch ( \Throwable $e ) {
				$this->redirect_read_failed = true;
				error_log( '[post-404-shield] redirect reader ' . $reader . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

		// Active, with its Redirections module on: a deactivated plugin's
		// table and module option stay behind, and its rows redirect nothing.
		if ( ! isset( $wpdb ) || ! ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) )
			|| ! in_array( 'redirections', (array) get_option( 'rank_math_modules', [] ), true )
		) {
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
					// Contains / ends with match anywhere in a URL: no path
					// prefix stands for them. Kept, so the save can say so.
					$this->unmappable_redirects[] = [
						'pattern'    => $source['pattern'],
						'comparison' => $comparison,
					];
					continue;
				}
				// `start` is a string-prefix match — append a `*` so the
				// reducer treats it as a within-segment prefix (`/promo` start
				// must cover `/promotional/`, not just `/promo/`).
				$pattern   = 'start' === $comparison ? $source['pattern'] . '*' : $source['pattern'];
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
		if ( ! defined( 'REDIRECTION_VERSION' ) && ! defined( 'REDIRECTION_FILE' ) ) {
			return []; // Not active: its table outlives it.
		}
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
				'regex'   => ! empty( $row->is_regex ), // The string zero counts as empty.
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
		if ( ! defined( 'AIOSEO_FILE' ) && ! function_exists( 'aioseo' ) ) {
			return []; // Not active: its table outlives it.
		}
		return $this->table_redirect_sources( 'aioseo_redirects', 'source_url', 'regex', 'enabled = 1' );
	}

	/**
	 * Simple 301 Redirects: the `301_redirects` option, a plain `old => new`
	 * map (keys are the sources).
	 *
	 * @return array<int, array{pattern: mixed, regex: bool}> Unchecked; redirect_sources() filters.
	 */
	private function simple_301_redirect_sources(): array {
		if ( ! defined( 'SIMPLE301REDIRECTS_VERSION' ) && ! class_exists( 'Simple301Redirects', false ) ) {
			return []; // Not active: its option outlives it.
		}
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
	 * `slug_is_reserved()`. A source deeper than one segment under a base
	 * reserves its FIRST segment: the matcher tests reserved slugs against the
	 * first segment at any depth, so that passes the source's whole family to
	 * WordPress — wider than the redirect, but only in the fail-open direction,
	 * and the only way its 301 can fire (depth policy would otherwise answer
	 * `/{base}/{slug}/{deeper}/` first).
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
					$tail = trim( substr( $path, strlen( $base ) ), '/' );
					$slug = explode( '/', $tail )[0];
					// A wildcard (always inside the LAST segment, see
					// redirect_pattern_base()) below the first segment leaves
					// that segment whole, so it is reserved exactly.
					$deep = false !== strpos( $tail, '/' );
					// The same charset the matcher captures.
					if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
						continue;
					}
					$derived[ $key ][] = $wildcard && ! $deep ? $slug . '*' : $slug;
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
	 * Reserved slugs DERIVED from WordPress's own rewrite table, per entry: a
	 * rule that starts `{base}/{literal}` is a route WordPress serves under the
	 * base — a category, tag or author archive and every `with_front` post
	 * type under a front like /blog/, an archive's pagination or feed — and
	 * none of those is a post slug, so the entry would 404 it. Its literal is
	 * reserved (with `*` when cut inside the segment). An entry at the
	 * permalink front also carries the date archives (`/blog/2026/…`), whose
	 * first segment is a number: every digit-led segment passes.
	 *
	 * @param array<string, mixed> $entries Candidate entries.
	 *
	 * @return array<string, string[]> Entry key => reserved slugs.
	 */
	private function route_reserved_slugs( array $entries ): array {
		global $wp_rewrite;
		if ( ! isset( $wp_rewrite ) || ! is_object( $wp_rewrite ) || ! method_exists( $wp_rewrite, 'wp_rewrite_rules' ) ) {
			return [];
		}
		$rules = array_filter( array_keys( (array) $wp_rewrite->wp_rewrite_rules() ), 'is_string' );
		$front = trim( (string) ( $wp_rewrite->front ?? '' ), '/' );

		$out = [];
		foreach ( $entries as $key => $settings ) {
			if ( ! is_array( $settings ) || ( isset( $settings['enabled'] ) && false === $settings['enabled'] )
				|| 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) || true === ( $settings['root'] ?? false )
			) {
				continue;
			}
			$slugs = [];
			foreach ( (array) ( $settings['url_base'] ?? [] ) as $base ) {
				if ( ! is_string( $base ) || '' === $base ) {
					continue;
				}
				foreach ( $rules as $pattern ) {
					$pattern = ltrim( \Post404Shield\unescape_slashes( $pattern ), '^' );
					if ( 0 !== strpos( $pattern, $base . '/' ) ) {
						continue;
					}
					$rest = substr( $pattern, strlen( $base ) + 1 );
					$slug = \Post404Shield\rewrite_pattern_base( $rest );
					// `{base}/(tips|video)/?$`: each literal is a route.
					foreach ( \Post404Shield\rewrite_pattern_literal_group( $rest ) as $literal ) {
						$first = explode( '/', $literal )[0];
						if ( 1 === preg_match( '/^[a-z0-9_-]+$/', $first ) ) {
							$slugs[ $first ] = true;
						}
					}
					if ( '' !== $slug && 1 === preg_match( '/^[a-z0-9_-]+\*?$/', $slug ) ) {
						$slugs[ $slug ] = true;
					} elseif ( 1 === preg_match( '#^\(?(?:\?:)?(?:\\\\d|\[0-9\])#', $rest ) ) {
						// A route led by a number (`(\d{4})/…`, legacy dated
						// URLs): every digit-led segment is WordPress's.
						foreach ( range( 0, 9 ) as $digit ) {
							$slugs[ $digit . '*' ] = true;
						}
					}
				}
				if ( '' !== $front && $base === $front ) {
					foreach ( range( 0, 9 ) as $digit ) {
						$slugs[ $digit . '*' ] = true;
					}
				}
			}
			if ( [] !== $slugs ) {
				// Keys like '2019' become ints: cast back, or the document
				// fails the reader's string-list check.
				$out[ (string) $key ] = array_map( 'strval', array_keys( $slugs ) );
			}
		}
		return $out;
	}

	/**
	 * Redirect sources whose literal part ends exactly at an entry's base and
	 * go on below it (`^/stories/(\d+)/?$`, `/stories/*`): no single slug to
	 * reserve. A digit-led remainder reserves every digit-led slug; anything
	 * else is a save warning (derivation_warnings), since the shield would
	 * answer those addresses before the redirect can. So is a regex source
	 * (each of its readings on its own) that names a shielded base but that no
	 * reading here could place — a shape the reducers do not know must never
	 * fail silently — and any redirect at or under a blocked section, which
	 * answers before any reserved slug is looked at.
	 *
	 * @param array<string, mixed> $entries        Candidate entries.
	 * @param string               $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return array<string, string[]> Entry key => reserved slugs.
	 */
	private function redirects_below_bases( array $entries, string $locale_pattern ): array {
		$based   = $this->enabled_bases( $entries, 'allowlist' );
		$blocked = $this->enabled_bases( $entries, 'block' );
		$root_on = false;
		foreach ( $entries as $settings ) {
			$root_on = $root_on || ( is_array( $settings ) && true === ( $settings['root'] ?? false )
				&& ( ! isset( $settings['enabled'] ) || false !== $settings['enabled'] ) );
		}

		$out = [];
		foreach ( $this->redirect_sources() as $source ) {
			// A regex not anchored at the start matches anywhere in the path.
			$anchored  = ! $source['regex'] || 0 === strpos( ltrim( (string) $source['pattern'] ), '^' );
			$root_miss = false;
			foreach ( \Post404Shield\redirect_source_variants( self::source_pattern( $source ), $source['regex'], $locale_pattern ) as $variant ) {
				$placed   = [];
				$consumed = 0;
				$reduced  = trim( rtrim( strtolower( \Post404Shield\redirect_pattern_base( $variant, $source['regex'], $consumed ) ), '*' ), '/' );
				$goes_on  = '' !== trim( (string) substr( $variant, $consumed ), '/?$' );
				// Root mode lets a redirect through by the literal path it
				// starts with (an excluded base): one with none (`^(.*)/amp/?$`),
				// or matching anywhere (unanchored), cannot be let through.
				$root_miss = $root_miss || ( $root_on && $source['regex'] && ( ! $anchored || ( '' === $reduced && $goes_on ) ) );
				foreach ( $based as $key => $bases ) {
					foreach ( $bases as $base ) {
						if ( '' !== $reduced && 0 === strpos( $reduced . '/', $base . '/' ) ) {
							$placed[ $key ] = true; // At or under the base: derived_reserved_slugs() read it.
						}
						// The literal stops ABOVE a multi-segment base and the
						// pattern goes on (`^products/([^/]+)/x-t3`): it may cover
						// addresses under the base that nothing can reserve.
						if ( '' !== $reduced && $reduced !== $base && 0 === strpos( $base . '/', $reduced . '/' ) && ! isset( $placed[ $key ] ) && $goes_on ) {
							$placed[ $key ]              = true;
							$this->derivation_warnings[] = sprintf(
								/* translators: 1: entry name, 2: the redirect source, 3: the URL base. */
								__( '%1$s: the redirect "%2$s" covers addresses under /%3$s/ that cannot be reserved one by one, so the shield answers them before the redirect can. Add the slugs it redirects from to Reserved slugs, or move the redirect to the edge.', 'post-404-shield' ),
								$this->entry_label( $key, (array) $entries[ $key ] ),
								(string) $source['pattern'],
								$base
							);
						}
					}
					if ( ! in_array( $reduced, $bases, true ) ) {
						continue;
					}
					// Where the literal run ended in the source itself: an
					// escape is two bytes of source for one of literal.
					$rest = ltrim( (string) substr( $variant, $consumed ), '/?' );
					// Only the base itself (`stories/?$`): nothing below it.
					if ( 1 === preg_match( '#^\$?$#', $rest ) ) {
						continue;
					}
					if ( 1 === preg_match( '#^\(?(?:\?:)?(?:\\\\d|\[0-9\])#', $rest ) ) {
						foreach ( range( 0, 9 ) as $digit ) {
							$out[ $key ][] = $digit . '*';
						}
						continue;
					}
					$this->derivation_warnings[] = sprintf(
						/* translators: 1: entry name, 2: the redirect source, 3: the URL base. */
						__( '%1$s: the redirect "%2$s" covers addresses under /%3$s/ that cannot be reserved one by one, so the shield answers them before the redirect can. Add the slugs it redirects from to Reserved slugs, or move the redirect to the edge.', 'post-404-shield' ),
						$this->entry_label( $key, (array) $entries[ $key ] ),
						(string) $source['pattern'],
						$reduced
					);
				}
				if ( $source['regex'] ) {
					$this->warn_unread_redirect( $source, $variant, $anchored, $placed, $based, $entries );
				}
				$this->warn_blocked_redirect( $source, $variant, $reduced, $goes_on, $anchored, $blocked );
			}
			if ( $root_miss ) {
				$this->derivation_warnings[] = sprintf(
					/* translators: %s: the redirect source. */
					__( 'Root mode: the redirect "%s" does not start with a literal path, so no excluded base can stand for it, and the shield may answer the addresses it matches before the redirect can. Start its pattern with the path it redirects from, add those addresses to the Pages & posts reserved slugs, or move it to the edge.', 'post-404-shield' ),
					(string) $source['pattern']
				);
			}
		}
		// Contains / ends-with redirects match anywhere in a URL: nothing can
		// be reserved for them. Say so when one names a shielded base, or in
		// root mode, where every address is the shield's.
		foreach ( $this->unmappable_redirects as $source ) {
			$text  = strtolower( (string) $source['pattern'] );
			$named = '';
			foreach ( array_merge( array_values( $based ), array_values( $blocked ) ) as $bases ) {
				foreach ( $bases as $base ) {
					if ( '' === $named && 1 === preg_match( '#(?<![a-z0-9_-])' . preg_quote( $base, '#' ) . '(?![a-z0-9_-])#', $text ) ) {
						$named = $base;
					}
				}
			}
			if ( '' === $named && ! $root_on ) {
				continue;
			}
			$this->derivation_warnings[] = sprintf(
				'end' === $source['comparison']
					/* translators: %s: the redirect source. */
					? __( 'The Rank Math redirect "%s" matches addresses that end with it, so no reserved slug can stand for it, and the shield may answer those addresses before the redirect can. Change it to Exact, Starts with or Regex, add the slugs it redirects from to Reserved slugs, or move it to the edge.', 'post-404-shield' )
					/* translators: %s: the redirect source. */
					: __( 'The Rank Math redirect "%s" matches addresses that contain it, so no reserved slug can stand for it, and the shield may answer those addresses before the redirect can. Change it to Exact, Starts with or Regex, add the slugs it redirects from to Reserved slugs, or move it to the edge.', 'post-404-shield' ),
				(string) $source['pattern']
			);
		}
		$this->derivation_warnings = array_values( array_unique( $this->derivation_warnings ) );
		return $out;
	}

	/**
	 * The URL bases of the enabled based entries in one mode, per entry.
	 *
	 * @param array<string, mixed> $entries Candidate entries.
	 * @param string               $mode    'allowlist' or 'block'.
	 *
	 * @return array<string, string[]>
	 */
	private function enabled_bases( array $entries, string $mode ): array {
		$out = [];
		foreach ( $entries as $key => $settings ) {
			if ( is_array( $settings ) && ( ! isset( $settings['enabled'] ) || false !== $settings['enabled'] )
				&& ( $settings['mode'] ?? 'allowlist' ) === $mode && true !== ( $settings['root'] ?? false )
			) {
				$out[ (string) $key ] = array_values( array_filter( (array) ( $settings['url_base'] ?? [] ), 'is_string' ) );
			}
		}
		return $out;
	}

	/**
	 * Whether a regex reading names a base: the base itself (or, unanchored,
	 * any trailing run of its segments — `compatibility/cameras` for
	 * support/compatibility/cameras — which is enough to cover it), also with
	 * optional characters and groups read away (`products?/cameras`), or its
	 * first segment followed by a group or class (`products/(cameras|lenses)`,
	 * `products/[a-z]+`), which may stand for the rest of it.
	 *
	 * @param string $variant  One reading of the source, locale-free.
	 * @param string $base     URL base.
	 * @param bool   $anchored Whether the source is anchored at the start.
	 *
	 * @return bool
	 */
	private function redirect_names_base( string $variant, string $base, bool $anchored ): bool {
		$text = strtolower( $variant );
		// Spelled with an optional character or a group inside a segment
		// (`products?/cameras`, `camera(s)?`): read without them too.
		$plain    = (string) preg_replace( '#[()?*+]#', '', (string) preg_replace( '#\(\?:#', '(', $text ) );
		$segments = explode( '/', $base );
		$runs     = $anchored ? [ $base ] : array_map( static fn( int $from ): string => implode( '/', array_slice( $segments, $from ) ), array_keys( $segments ) );
		foreach ( $runs as $run ) {
			foreach ( [ $text, $plain ] as $candidate ) {
				if ( 1 === preg_match( '#(?<![a-z0-9_-])' . preg_quote( $run, '#' ) . '(?![a-z0-9_-])#', $candidate ) ) {
					return true;
				}
			}
		}
		return count( $segments ) > 1 && 1 === preg_match( '#(?<![a-z0-9_-])' . preg_quote( $segments[0], '#' ) . '/[(\[]#', $text );
	}

	/**
	 * Warn about one reading of a regex redirect that names a shielded base
	 * but that no reading placed: nothing was reserved for it.
	 *
	 * @param array{pattern: string, regex: bool} $source   The redirect.
	 * @param string                              $variant  One reading of it, locale-free.
	 * @param bool                                $anchored Whether it is anchored at the start.
	 * @param array<string, bool>                 $placed   Entries this reading was placed under.
	 * @param array<string, string[]>             $based    Enabled shielded bases, per entry.
	 * @param array<string, mixed>                $entries  Candidate entries.
	 *
	 * @return void
	 */
	private function warn_unread_redirect( array $source, string $variant, bool $anchored, array $placed, array $based, array $entries ): void {
		foreach ( $based as $key => $bases ) {
			if ( isset( $placed[ $key ] ) ) {
				continue;
			}
			foreach ( $bases as $base ) {
				if ( $this->redirect_names_base( $variant, $base, $anchored ) ) {
					$this->derivation_warnings[] = sprintf(
						/* translators: 1: entry name, 2: the redirect source, 3: the URL base. */
						__( '%1$s: the redirect "%2$s" looks like it covers addresses under /%3$s/, but its pattern could not be read, so nothing was reserved for it and the shield may answer those addresses before the redirect can. Add the slugs it redirects from to Reserved slugs, or move the redirect to the edge.', 'post-404-shield' ),
						$this->entry_label( $key, (array) $entries[ $key ] ),
						(string) $source['pattern'],
						$base
					);
					break;
				}
			}
		}
	}

	/**
	 * Warn about a redirect at or under a blocked section: the block answers
	 * the bare base and everything under it before any reserved slug is
	 * looked at, so nothing can let the redirect's 301 through.
	 *
	 * @param array{pattern: string, regex: bool} $source   The redirect.
	 * @param string                              $variant  One reading of it, locale-free.
	 * @param string                              $reduced  Its literal part.
	 * @param bool                                $goes_on  Whether the pattern goes on past the literal part.
	 * @param bool                                $anchored Whether it is anchored at the start.
	 * @param array<string, string[]>             $blocked  Enabled blocked sections' bases, per entry.
	 *
	 * @return void
	 */
	private function warn_blocked_redirect( array $source, string $variant, string $reduced, bool $goes_on, bool $anchored, array $blocked ): void {
		foreach ( $blocked as $key => $bases ) {
			foreach ( $bases as $base ) {
				if ( '' !== $reduced && 0 === strpos( $reduced . '/', $base . '/' ) ) {
					$this->derivation_warnings[] = sprintf(
						/* translators: 1: blocked section name, 2: the redirect source, 3: the URL base. */
						__( 'Blocked section %1$s: the redirect "%2$s" is at or under /%3$s/, so the shield answers it with a 404 before the redirect can. Narrow or remove the block, or move the redirect to the edge.', 'post-404-shield' ),
						$key,
						(string) $source['pattern'],
						$base
					);
					return;
				}
				$above = '' !== $reduced && 0 === strpos( $base . '/', $reduced . '/' ) && $goes_on;
				if ( $above || ( $source['regex'] && $this->redirect_names_base( $variant, $base, $anchored ) ) ) {
					$this->derivation_warnings[] = sprintf(
						/* translators: 1: blocked section name, 2: the redirect source, 3: the URL base. */
						__( 'Blocked section %1$s: the redirect "%2$s" may cover addresses under /%3$s/, and the shield answers those with a 404 before the redirect can. Narrow or remove the block, or move the redirect to the edge.', 'post-404-shield' ),
						$key,
						(string) $source['pattern'],
						$base
					);
					return;
				}
			}
		}
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
		foreach ( $this->redirects_below_bases( $entries, $locale_pattern ) as $key => $slugs ) {
			$derived[ $key ] = array_merge( $derived[ $key ] ?? [], $slugs );
		}
		foreach ( $this->route_reserved_slugs( $entries ) as $key => $slugs ) {
			$merged = array_values( array_unique( array_merge( $derived[ $key ] ?? [], $slugs ) ) );
			sort( $merged );
			$derived[ $key ] = $merged;
		}
		foreach ( $entries as $key => $settings ) {
			if ( ! is_array( $settings ) ) {
				continue;
			}
			$slugs = array_values( array_unique( array_map( 'strval', $derived[ $key ] ?? [] ) ) );
			sort( $slugs );
			if ( [] === $slugs ) {
				unset( $entries[ $key ]['reserved_derived'] );
				continue;
			}
			$entries[ $key ]['reserved_derived'] = $slugs;
		}
		return $entries;
	}

	/**
	 * Whether a redirect reader failed this request, so what it read is
	 * incomplete — not the same as a site with no redirects.
	 *
	 * @return bool
	 */
	public function redirect_read_failed(): bool {
		$this->redirect_sources();
		return $this->redirect_read_failed;
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
	 * @return string Hash, or '' when there are none.
	 */
	public function redirect_fingerprint(): string {
		$rows = [];
		foreach ( $this->redirect_sources() as $source ) {
			$rows[] = ( $source['regex'] ? 'regex:' : 'plain:' ) . $source['pattern'];
		}
		// Unmappable ones too: adding one must re-save, so its warning shows.
		foreach ( $this->unmappable_redirects as $source ) {
			$rows[] = $source['comparison'] . ':' . $source['pattern'];
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
	 * floor + live-derived rows + the operator's own rows.
	 *
	 * Operator rows are kept exactly as typed (only blanks and repeats within
	 * the bucket go), even when they duplicate a floor or derived row. The
	 * operator bucket exists to guard against the derived one changing: drop
	 * a row because a redirect or rewrite rule happens to derive it today, and
	 * once that source goes the row is gone for good — the settings screen is
	 * refilled from the option — and root mode 404s the route. A duplicate is
	 * harmless to the matcher.
	 *
	 * @param array    $operator         Operator-added rows (from the settings textarea).
	 * @param string   $locale_pattern   Locale pattern body ('' = none).
	 * @param string[] $stored_endpoints Endpoints to keep when this request cannot read them.
	 *
	 * @return array{floor: string[], derived: string[], operator: string[], endpoints: string[]}
	 */
	public function excluded_bases_snapshot( array $operator, string $locale_pattern = '', array $stored_endpoints = [] ): array {
		$floor   = self::FLOOR_EXCLUDED_BASES;
		$derived = $this->derived_excluded_bases( $locale_pattern );

		// A request that changed the permalink settings re-initialised the
		// rewrite object, which empties its endpoint list until the next
		// request registers them again: keep the stored ones meanwhile.
		$endpoints = $this->rewrite_endpoints();
		if ( [] === $endpoints && $this->rewrite_reset_this_request() ) {
			$endpoints = array_values( array_filter( $stored_endpoints, 'is_string' ) );
		}

		$clean = [];
		foreach ( $operator as $base ) {
			if ( ! is_string( $base ) || '' === $base ) {
				continue;
			}
			if ( in_array( $base, $clean, true ) ) {
				continue;
			}
			$clean[] = $base;
		}

		return [
			'floor'     => $floor,
			'derived'   => $derived,
			'operator'  => $clean,
			'endpoints' => $endpoints,
			// The permalink post base this snapshot was taken under ('' at the
			// site root, null with no fixed base): a later write that finds it
			// moved keeps it passing (with_vacated_post_base_kept()).
			'post_base' => $this->post_base_info()['supported'] ? $this->post_base_info()['base'] : null,
		];
	}

	/**
	 * Whether this request changed the permalink settings — after which the
	 * rewrite object holds no endpoints, and its rules are the old ones until
	 * the next request flushes them.
	 *
	 * @return bool
	 */
	public function rewrite_reset_this_request(): bool {
		if ( ! function_exists( 'did_action' ) ) {
			return false;
		}
		return did_action( 'permalink_structure_changed' ) > 0
			|| did_action( 'update_option_category_base' ) > 0
			|| did_action( 'update_option_tag_base' ) > 0;
	}

	/**
	 * Rewrite endpoint names that apply after a content path — registered with
	 * add_rewrite_endpoint() on pages or permalinks (AMP, a shop's account
	 * screens…). WordPress routes `/{path}/{endpoint}[/{value}]/` to the page
	 * at {path}; the loader strips them like feeds, or root mode would 404 them
	 * and a based type would read them as a deeper path.
	 *
	 * @return string[]
	 */
	private function rewrite_endpoints(): array {
		global $wp_rewrite;
		$mask = ( defined( 'EP_PAGES' ) ? EP_PAGES : 4096 ) | ( defined( 'EP_PERMALINK' ) ? EP_PERMALINK : 1 );
		$out  = [];
		foreach ( (array) ( $wp_rewrite->endpoints ?? [] ) as $endpoint ) {
			if ( ! is_array( $endpoint ) || ! isset( $endpoint[0], $endpoint[1] ) ) {
				continue;
			}
			$name = (string) $endpoint[1];
			if ( ( (int) $endpoint[0] & $mask ) && 1 === preg_match( '/^[a-z0-9_-]+$/', $name ) && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		sort( $out );
		return $out;
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
		return self::enabled_root_rows( $entries );
	}

	/**
	 * Whether any root entry is switched on (root matching runs).
	 *
	 * @param array<mixed> $entries Config entries.
	 *
	 * @return bool
	 */
	private static function enabled_root_rows( array $entries ): bool {
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
	 * How an entry is named in messages: what its row on the settings screen
	 * shows. A blocked section is listed under its key; a shielded type under
	 * its post type's name with the post type itself (the row's chip) — never
	 * the internal key alone, which can differ from both after an import.
	 *
	 * @param string               $key   Entry key.
	 * @param array<string, mixed> $entry Entry settings.
	 *
	 * @return string
	 */
	public function entry_label( string $key, array $entry ): string {
		if ( 'block' === ( $entry['mode'] ?? 'allowlist' ) ) {
			return $key;
		}
		$type   = is_string( $entry['post_type'] ?? null ) ? $entry['post_type'] : $key;
		$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
		if ( null === $object || ! is_string( $object->labels->name ?? null ) || '' === $object->labels->name ) {
			return $type;
		}
		return sprintf( '%s (%s)', $object->labels->name, $type );
	}

	/**
	 * The key of the root entry a blocked root URL takes its cache times from,
	 * chosen as the loader and the root preflight choose it: the enabled root
	 * Pages entry, else the first enabled root entry. Posts usually comes
	 * first in the document (core registers it first), so document order is
	 * not the loader's order.
	 *
	 * @param array<mixed> $entries Config entries.
	 *
	 * @return string '' when no root entry is enabled.
	 */
	private static function root_ttl_entry_key( array $entries ): string {
		$first = '';
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || true !== ( $entry['root'] ?? false ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) || 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			if ( 'page' === (string) ( $entry['post_type'] ?? $key ) ) {
				return (string) $key;
			}
			$first = '' === $first ? (string) $key : $first;
		}
		return $first;
	}

	/**
	 * The cache-time warnings for one entry, modelled on what the loader
	 * sends: a time over a day is capped; and a browser/CDN time longer than
	 * the cache time keeps a launch URL's 404 in browsers after the post goes
	 * live (only the server cache is purged on publish) — unless the cache
	 * time is 0, which sends no-store and never reads the other. Blank times
	 * are the loader's defaults (60 s and POST_SHIELD_404_TTL; the edge time
	 * falls back to POST_SHIELD_404_EDGE_TTL).
	 *
	 * @param string               $label      Entry name.
	 * @param array<string, mixed> $entry      Entry.
	 * @param string               $entry_mode 'allowlist' or 'block'.
	 *
	 * @return string[]
	 */
	private static function ttl_warnings( string $label, array $entry, string $entry_mode ): array {
		$warnings = [];
		foreach ( [ 'cache_ttl', 'edge_ttl' ] as $field ) {
			if ( isset( $entry[ $field ] ) && is_int( $entry[ $field ] ) && $entry[ $field ] > \Post404Shield\MAX_TTL ) {
				/* translators: 1: entry name, 2: the longest cache time in seconds. */
				$warnings[] = sprintf( __( '%1$s: a cache time longer than %2$d seconds (a day) is capped to it — browsers keep a cached 404 as long as they are told, and nothing can recall it.', 'post-404-shield' ), $label, \Post404Shield\MAX_TTL );
			}
		}
		if ( 'block' === $entry_mode ) {
			return $warnings; // Blocks have one time; the edge time is for publishable 404s.
		}
		$cache_time = isset( $entry['cache_ttl'] ) && is_int( $entry['cache_ttl'] ) ? $entry['cache_ttl'] : ( defined( 'POST_SHIELD_404_TTL' ) ? max( 0, (int) POST_SHIELD_404_TTL ) : 60 );
		$edge_time  = isset( $entry['edge_ttl'] ) && is_int( $entry['edge_ttl'] ) ? $entry['edge_ttl'] : ( defined( 'POST_SHIELD_404_EDGE_TTL' ) ? max( 0, (int) POST_SHIELD_404_EDGE_TTL ) : null );
		if ( 0 < $cache_time && null !== $edge_time && $edge_time > $cache_time ) {
			$warnings[] = sprintf(
				/* translators: 1: entry name, 2: the browser/CDN time, 3: the cache time, in seconds. */
				__( '%1$s: browsers and the CDN keep its 404 for %2$d seconds, longer than the %3$d-second cache time; only the server cache is purged when a post is published, so a visitor can keep a new post\'s 404 that long. Set the browser and CDN time shorter.', 'post-404-shield' ),
				$label,
				$edge_time,
				$cache_time
			);
		}
		return $warnings;
	}

	/**
	 * One post type, one format: a type shielded at the root builds a
	 * full-path allowlist, a based entry for the same type a slug one, and the
	 * builder would take whichever entry comes first — a based `page` entry
	 * ahead of the root one would reduce the root page list to top-level slugs
	 * and 404 every child page. Based entries sharing a type likewise share
	 * one list and must agree on its format.
	 *
	 * @param array<string, mixed> $entries   Candidate entries.
	 * @param bool                 $root_only Only a based entry sharing a root type.
	 *
	 * @return string[] Errors.
	 */
	private function shared_type_errors( array $entries, bool $root_only = false ): array {
		$root_types  = [];
		$based_types = [];
		$formats     = [];
		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) || 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$type = (string) ( $entry['post_type'] ?? $key );
			if ( true === ( $entry['root'] ?? false ) ) {
				$root_types[ $type ] = true;
			} else {
				$based_types[ $type ] = true;
			}
			$formats[ $type ][ self::is_full_path( $entry ) ? 'full-path' : 'slug' ] = true;
		}
		$errors = [];
		foreach ( array_keys( array_intersect_key( $based_types, $root_types ) ) as $type ) {
			$errors[] = sprintf(
				/* translators: %s: post type name and slug. */
				__( '%s is shielded at the root on the Pages & posts tab, so it cannot also be shielded under a URL base. Switch one of them off.', 'post-404-shield' ),
				$this->entry_label( (string) $type, [ 'post_type' => (string) $type ] )
			);
		}
		if ( $root_only ) {
			return $errors;
		}
		// Entries sharing a post type share ONE list, so they must agree on its
		// format — otherwise one of them reads lines in the other's shape.
		foreach ( $formats as $type => $seen ) {
			if ( count( $seen ) > 1 && ! isset( $root_types[ $type ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: post type name and slug. */
					__( '%s has entries with different address matching (by name and by full path). They share one list, so set them the same.', 'post-404-shield' ),
					$this->entry_label( (string) $type, [ 'post_type' => (string) $type ] )
				);
			}
		}
		return $errors;
	}

	/**
	 * The locale block's errors: the mode enum, and a pattern that is text
	 * and, when a prefix is in play, valid.
	 *
	 * @param mixed $locale The document's `locale` block.
	 *
	 * @return string[]
	 */
	private static function locale_errors( $locale ): array {
		$mode = is_array( $locale ) ? ( $locale['mode'] ?? null ) : null;
		if ( is_array( $locale ) && isset( $locale['pattern'] ) && ! is_string( $locale['pattern'] ) ) {
			return [ __( 'Locale pattern must be text.', 'post-404-shield' ) ];
		}
		if ( ! in_array( $mode, [ 'none', 'wpml-directory', 'custom' ], true ) ) {
			return [ __( 'Locale mode must be one of: none, wpml-directory, custom.', 'post-404-shield' ) ];
		}
		if ( 'none' !== $mode && ! \Post404Shield\locale_pattern_is_valid( (string) ( $locale['pattern'] ?? '' ) ) ) {
			return [ __( 'Locale pattern is invalid: lowercase letters, digits, [] {} | , - only (no parentheses, no # or \\), max 200 chars, and it must compile.', 'post-404-shield' ) ];
		}
		return [];
	}

	/**
	 * The shape the pre-boot loader requires of an entry (config_shape_is_valid()),
	 * as errors that name it.
	 *
	 * @param string               $label Entry name.
	 * @param array<string, mixed> $entry Entry.
	 *
	 * @return string[]
	 */
	private static function entry_shape_errors( string $label, array $entry ): array {
		$errors = [];
		if ( isset( $entry['enabled'] ) && ! is_bool( $entry['enabled'] ) ) {
			/* translators: %s: entry name. */
			$errors[] = sprintf( __( '%s: "enabled" must be true or false.', 'post-404-shield' ), $label );
		}
		if ( isset( $entry['post_type'] ) && ( ! is_string( $entry['post_type'] ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $entry['post_type'] ) ) ) {
			/* translators: %s: entry name. */
			$errors[] = sprintf( __( '%s: the post type must be a lowercase post type name.', 'post-404-shield' ), $label );
		}
		// Lists, as the reader requires: a scalar (a legacy value, a WP-CLI
		// patch) is named here, not left to fail the write's final check.
		foreach ( [ 'url_base', 'post_status', 'reserved_allowlist' ] as $field ) {
			if ( isset( $entry[ $field ] ) && ( ! is_array( $entry[ $field ] ) || array_values( $entry[ $field ] ) !== $entry[ $field ] ) ) {
				/* translators: 1: entry name, 2: field name. */
				$errors[] = sprintf( __( '%1$s: %2$s must be a list.', 'post-404-shield' ), $label, $field );
			}
		}
		if ( isset( $entry['url_base'] ) && is_array( $entry['url_base'] ) && [] !== array_filter( $entry['url_base'], static fn( $b ) => ! is_string( $b ) ) ) {
			/* translators: %s: entry name. */
			$errors[] = sprintf( __( '%s: every URL base must be text.', 'post-404-shield' ), $label );
		}
		return $errors;
	}

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
		$errors = array_merge( $errors, self::locale_errors( $config['locale'] ?? [] ) );

		// Root-dweller facts (root-pages v2, S1). Only resolvable inside a booted
		// WordPress; in pure unit contexts the structural checks still run but
		// the permalink-derived rules are skipped.
		$in_wp         = function_exists( 'get_option' );
		$root_dwellers = $in_wp ? $this->root_dweller_types() : null;
		$post_info     = $in_wp ? $this->post_base_info() : null;

		$seen_bases         = [];
		$enabled_root_types = [];
		// The loader reads a root 404's cache times from this entry alone.
		$first_root = self::root_ttl_entry_key( $entries );
		foreach ( $entries as $key => $entry ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $key ) || ! is_array( $entry ) ) {
				$errors[] = __( 'An entry has a malformed key.', 'post-404-shield' );
				continue;
			}
			$label         = $this->entry_label( $key, $entry );
			$entry_mode    = $entry['mode'] ?? 'allowlist';
			$entry_enabled = ! isset( $entry['enabled'] ) || false !== $entry['enabled'];
			$entry_root    = true === ( $entry['root'] ?? false );
			if ( ! in_array( $entry_mode, [ 'allowlist', 'block' ], true ) ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: mode must be "allowlist" or "block".', 'post-404-shield' ), $label );
				// Reported once; the checks below take a string (strict_types), so a
				// hand-staged non-string mode must not fatal the save instead.
				$entry_mode = '';
			}
			// The loader's shape check, per entry, so the error names it.
			$errors = array_merge( $errors, self::entry_shape_errors( $label, $entry ) );
			if ( isset( $entry['root'] ) && ! is_bool( $entry['root'] ) ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: root must be a boolean.', 'post-404-shield' ), $label );
			}
			if ( isset( $entry['allow_pagination'] ) && ! is_bool( $entry['allow_pagination'] ) ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: the pagination allowance must be a boolean.', 'post-404-shield' ), $label );
			}

			// Root entries (root-pages v2) + the unsupported-permalink refusal —
			// split out to keep this function's complexity in bounds.
			foreach ( $this->root_entry_errors( $label, $entry + [ 'post_type' => $key ], $entry_mode, $entry_enabled, $entry_root, $root_dwellers, $post_info ) as $root_error ) {
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
					/* translators: %s: entry name. */
					$errors[] = sprintf( __( '%s: at least one URL base is required.', 'post-404-shield' ), $label );
				}
				foreach ( $bases as $base ) {
					if ( ! \Post404Shield\url_base_is_valid( $base ) ) {
						/* translators: 1: entry name, 2: the offending base. */
						$errors[] = sprintf( __( '%1$s: base "%2$s" is invalid — lowercase letters, digits, hyphens and internal slashes only (no leading/trailing slash).', 'post-404-shield' ), $label, is_scalar( $base ) ? (string) $base : gettype( $base ) );
						continue;
					}
					// A base must never squat on a WordPress-reserved URL namespace: a
					// `page` base turns root /page/N/ pagination into pre-boot 404s
					// (seen live), and the rest would intercept core routes the same way.
					// First segment only — deeper collisions (e.g. docs/feed) are fine.
					$first_segment = explode( '/', (string) $base )[0];
					if ( in_array( $first_segment, \Post404Shield\reserved_namespaces(), true ) ) {
						/* translators: 1: entry name, 2: the offending base, 3: the reserved namespace. */
						$errors[] = sprintf( __( '%1$s: base "%2$s" collides with the WordPress-reserved "%3$s" URL namespace (pagination/core routes) and cannot be shielded. Untick the entry to disable it (a disabled entry saves fine), or change the base.', 'post-404-shield' ), $label, (string) $base, $first_segment );
						continue;
					}
					$seen_bases[] = [ $label, (string) $base ];
				}
			}

			foreach ( $this->entry_field_errors( $label, $entry ) as $field_error ) {
				$errors[] = $field_error;
			}
			// The loader takes a root 404's times from the FIRST enabled root
			// entry only: another root row's times are never read.
			$ttls_read = true !== ( $entry['root'] ?? false ) || (string) $key === $first_root;
			foreach ( $ttls_read ? self::ttl_warnings( $label, $entry, (string) $entry_mode ) : [] as $ttl_warning ) {
				$warnings[] = $ttl_warning;
			}

			if ( 'block' === $entry_mode ) {
				continue;
			}

			// Allowlist-mode extras: statuses must exist; CPT registration warns.
			$post_type = (string) ( $entry['post_type'] ?? $key );
			if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $post_type ) ) {
				/* translators: 1: entry name, 2: post type. */
				$warnings[] = sprintf( __( '%1$s: post type "%2$s" is not registered — its allowlist will be empty and the base fails open.', 'post-404-shield' ), $label, $post_type );
			}
			if ( isset( $entry['post_status'] ) && is_array( $entry['post_status'] ) && function_exists( 'get_post_stati' ) ) {
				// An error only for an ENABLED entry. A status can stop being
				// registered under a site (a workflow plugin switched off), and a
				// disabled entry must still save — disabling is the remediation,
				// and the Disable button, restores and the self-heal all write
				// every entry.
				$known = get_post_stati();
				foreach ( $entry['post_status'] as $status ) {
					if ( $entry_enabled && is_string( $status ) && isset( $known[ $status ] ) && class_exists( AllowlistBuilder::class ) && ! AllowlistBuilder::is_servable_status( $status ) ) {
						/* translators: 1: entry name, 2: the status. */
						$warnings[] = sprintf( __( '%1$s: posts in status "%2$s" are never served at their own address (previews use ?p= links), so it is ignored — listing it would only let anyone confirm unreleased slugs.', 'post-404-shield' ), $label, $status );
					}
					if ( ! is_string( $status ) || ! isset( $known[ $status ] ) ) {
						$shown = is_scalar( $status ) ? (string) $status : gettype( $status );
						if ( $entry_enabled ) {
							/* translators: 1: entry name, 2: the status. */
							$errors[] = sprintf( __( '%1$s: post status "%2$s" does not exist.', 'post-404-shield' ), $label, $shown );
						} elseif ( ! is_string( $status ) || 1 !== preg_match( '/^[a-z0-9_-]+$/', $status ) ) {
							// Not a status name at all: the reader rejects it
							// whether the entry is on or not (no registered
							// status can be spelt so).
							/* translators: 1: entry name, 2: the value. */
							$errors[] = sprintf( __( '%1$s: "%2$s" is not a post status name.', 'post-404-shield' ), $label, $shown );
						} else {
							/* translators: 1: entry name, 2: the status. */
							$warnings[] = sprintf( __( '%1$s: post status "%2$s" is not registered; it is ignored while the entry is off.', 'post-404-shield' ), $label, $shown );
						}
					}
				}
			}
		}

		foreach ( $this->shared_type_errors( $entries ) as $shared_error ) {
			$errors[] = $shared_error;
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
					/* translators: 1: first entry name, 2: first base, 3: second entry name, 4: second base. */
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
	 * @param string               $label Entry name for messages (entry_label()).
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return string[] Errors.
	 */
	private function entry_field_errors( string $label, array $entry ): array {
		$errors = [];

		if ( ! in_array( $entry['match'] ?? 'slug', [ 'slug', 'full-path' ], true ) ) {
			/* translators: %s: entry name. */
			$errors[] = sprintf( __( '%s: match must be "slug" or "full-path".', 'post-404-shield' ), $label );
		}
		if ( ! in_array( $entry['depth_action'] ?? 'passthrough', [ 'passthrough', '404', 'redirect' ], true ) ) {
			/* translators: %s: entry name. */
			$errors[] = sprintf( __( '%s: depth action must be passthrough, 404 or redirect.', 'post-404-shield' ), $label );
		}
		foreach ( [ 'depth_allowed', 'cache_ttl', 'edge_ttl' ] as $field ) {
			if ( isset( $entry[ $field ] ) && ( ! is_int( $entry[ $field ] ) || $entry[ $field ] < 0 ) ) {
				/* translators: 1: entry name, 2: field name. */
				$errors[] = sprintf( __( '%1$s: %2$s must be a whole number ≥ 0, or empty.', 'post-404-shield' ), $label, $field );
			}
		}

		if ( isset( $entry['reserved_allowlist'] ) && is_array( $entry['reserved_allowlist'] ) ) {
			foreach ( $entry['reserved_allowlist'] as $slug ) {
				if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
					/* translators: 1: entry name, 2: the offending slug. */
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
	 * @param string        $label         Entry name for messages (entry_label()).
	 * @param array         $entry         The entry, `post_type` always set.
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
			$root_type = (string) $entry['post_type'];
			if ( 'allowlist' !== $entry_mode ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: a root entry must be an allowlist entry.', 'post-404-shield' ), $label );
			}
			// Any value at all — a callback-less array_filter() let "0" and ""
			// through, to fail later as an internal error.
			if ( [] !== (array) ( $entry['url_base'] ?? [] ) ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: a root entry has no URL base — its base is the site root by definition.', 'post-404-shield' ), $label );
			}
			if ( 'full-path' !== ( $entry['match'] ?? 'full-path' ) ) {
				/* translators: %s: entry name. */
				$errors[] = sprintf( __( '%s: a root entry always matches full paths.', 'post-404-shield' ), $label );
			}
			// Dweller membership is SITE STATE (the permalink structure), not
			// shape — ENABLED entries only, or permalink drift would brick the
			// documented emergency rollback (the disable-all save must always
			// land; disabling IS the remediation).
			if ( $entry_enabled && null !== $root_dwellers && ! in_array( $root_type, $root_dwellers, true ) ) {
				/* translators: 1: entry name, 2: post type. */
				$errors[] = sprintf( __( '%1$s: "%2$s" is not a root-dwelling type on this site — its URLs live under a base, so it must be shielded as a normal based entry.', 'post-404-shield' ), $label, $root_type );
			}
		}

		if ( $entry_enabled && 'allowlist' === $entry_mode
			&& 'post' === (string) $entry['post_type']
			&& null !== $post_info && ! $post_info['supported']
		) {
			/* translators: %s: entry name. */
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
		// Root matching takes the language folder off a path before it
		// compares the excluded bases, so an operator base that starts with
		// one can never match: say so, rather than 404 the route it names.
		$pattern = self::locale_pattern_of( $config );
		if ( '' !== $pattern && \Post404Shield\locale_pattern_is_valid( $pattern ) ) {
			foreach ( (array) ( $excluded['operator'] ?? [] ) as $base ) {
				if ( is_string( $base ) && 1 === preg_match( '#^(?:' . $pattern . ')$#', explode( '/', $base )[0] ) ) {
					/* translators: %s: the excluded base as typed. */
					$errors[] = sprintf( __( 'Excluded base "%s" starts with a language folder. Enter the address without it: the language is taken off before bases are compared.', 'post-404-shield' ), $base );
				}
			}
		}
		return $errors;
	}

	// --- Save pipeline ---------------------------------------------------------

	/**
	 * Fingerprint of the stored settings a form was built from, so a save can
	 * tell whether they changed since. Covers what the settings screen edits —
	 * entries, locale, the operator's excluded bases and retention — and leaves
	 * out what write() derives on its own (redirect-derived reserved slugs, the
	 * snapshot buckets, meta), which the nightly redirect sync refreshes
	 * without anyone editing.
	 *
	 * @param array<string, mixed>|null $document Stored config document.
	 *
	 * @return string
	 */
	public function revision_of( ?array $document ): string {
		$entries = [];
		foreach ( (array) ( $document['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) ) {
				unset( $entry['reserved_derived'] );
			}
			$entries[ $key ] = $entry;
		}
		return md5( (string) wp_json_encode( [ null === $document, $entries, $document['locale'] ?? null, $document['excluded_bases']['operator'] ?? null, $this->keep() ] ) );
	}

	/**
	 * The revision of what the settings screen edits: the option, or the
	 * artifact while there is no option.
	 *
	 * @return string
	 */
	public function current_revision(): string {
		return $this->revision_of( $this->option() ?? $this->artifact() );
	}

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
	 *                                           root-preflight would-blocks (CLI --force for a knowing operator);
	 *                                           `skip_root_preflight` => true skips the URL-walking root
	 *                                           preflight (the self-heal republishing an already-vetted option);
	 *                                           `expect_revision` => string refuses the save (`stale` => true)
	 *                                           unless the stored settings still have that current_revision() —
	 *                                           checked under the lock, so two forms cannot both pass it;
	 *                                           `fail_open` => true (an automatic write: a switch-off, a
	 *                                           re-snapshot, the redirect sync) turns the validation errors the
	 *                                           LIVE artifact already carries into warnings — never a new one,
	 *                                           and nothing when no artifact is live;
	 *                                           `shows_warnings` => true (the caller displays them) clears the
	 *                                           warnings kept from automatic writes; `keep_warnings` => true
	 *                                           keeps this one's for the settings screen, as an automatic
	 *                                           write's are;
	 *                                           `allow_status_drop` => true (CLI --force) lets a save drop any
	 *                                           status whose posts are live, a list of "entry:status" pairs
	 *                                           only those (the pairs a refusal listed, confirmed on screen);
	 *                                           `persist_keep` => int stores that retention under the lock;
	 *                                           `root_off_reason` => string says why, when the write leaves
	 *                                           every root entry switched off (the kept-settings notice);
	 *                                           `notes` => string[] are added to the warnings (what an
	 *                                           automatic write did beyond the obvious).
	 *
	 * @return array{ok: bool, errors: string[], warnings: string[], stale?: bool, retry?: bool} `retry` marks
	 *         a refusal that says nothing about the config (a busy or lost lock, a failed read).
	 */
	public function write( array $config, string $generated_by, array $flags = [] ): array {
		// Root-pages v2: the floor + derived excluded-bases buckets are
		// SNAPSHOTTED from live WordPress on every save (operator rows are kept
		// from the candidate) — restores therefore refresh a stale snapshot
		// automatically, and only the pre-boot loader ever reads the stored
		// copy. Skipped in pure unit contexts (no WordPress to derive from).
		$this->derivation_warnings  = [];
		$this->passes               = [];
		$this->confirmed_drop_pairs = [];
		// Before the snapshots, which would make a missing list an empty one:
		// a document with no entries would publish a shield that shields nothing.
		if ( ! is_array( $config['entries'] ?? null ) ) {
			return [
				'ok'       => false,
				'errors'   => [ __( 'Config has no entries list.', 'post-404-shield' ) ],
				'warnings' => [],
			];
		}
		// Root mode: a Posts base this save moves keeps passing to WordPress,
		// whoever saves (the settings screen, the CLI, a restore, the
		// automatic follow-up or switch-off), or every old post link gets a
		// pre-boot 404 instead of WordPress's 301 to the new address. Root
		// entries kept switched off count too: the base must still be kept
		// when root matching comes back on.
		if ( self::has_root_rows( (array) $config['entries'] ) ) {
			$live_doc = $this->artifact() ?? $this->option();
			if ( is_array( $live_doc ) ) {
				[ $config, $vacated ] = $this->with_vacated_post_base_kept( $live_doc, $config );
				$flags['notes']       = array_merge( (array) ( $flags['notes'] ?? [] ), array_map( [ self::class, 'vacated_base_note' ], $vacated ) );
			}
		}
		$redirect_fp = function_exists( 'get_option' ) ? $this->take_snapshots( $config ) : null;
		// Posts just left the site root: root-extras lists their slugs from
		// this save on (rebuilt after the swap, below).
		$posts_leaving_root = self::has_root_rows( (array) $config['entries'] )
			&& true === ( $config['excluded_bases']['posts_left_root'] ?? false )
			&& true !== ( ( $this->artifact() ?? [] )['excluded_bases']['posts_left_root'] ?? false );
		if ( $posts_leaving_root ) {
			$flags['notes'] = array_merge( (array) ( $flags['notes'] ?? [] ), [ __( 'Posts no longer live at the site root: root matching keeps every post\'s old /{slug}/ link reaching WordPress, which redirects it to the post\'s new address.', 'post-404-shield' ) ] );
		}

		$validated             = $this->validate( $config );
		$validated['warnings'] = array_merge( $validated['warnings'], $this->derivation_warnings, array_map( 'strval', (array) ( $flags['notes'] ?? [] ) ) );
		// A fail-open write is automatic: it disables entries or re-snapshots
		// what is live. An error the live artifact already carries — a status
		// whose plugin was switched off — must not keep it from landing, or a
		// stale snapshot stays live behind it. A NEW error still refuses, and
		// with no live artifact nothing is tolerated: the shield is off, and
		// publishing a config the self-heal refused would switch it on broken.
		// The runtime invariant below still holds.
		if ( ! empty( $flags['fail_open'] ) && [] !== $validated['errors'] ) {
			$live                  = $this->artifact();
			$carried               = null === $live ? [] : array_values( array_intersect( $validated['errors'], $this->validate( $live )['errors'] ) );
			$validated['errors']   = array_values( array_diff( $validated['errors'], $carried ) );
			$validated['warnings'] = array_merge( $validated['warnings'], $carried );
		}
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
			/**
			 * Fires just before a save takes the config lock — the last moment
			 * another save can commit unseen by this one's early checks.
			 *
			 * @param array<string, mixed> $config Candidate document.
			 */
			do_action( 'post_shield_before_save_lock', $config );
			if ( ! $this->acquire_lock() ) {
				return [
					'ok'       => false,
					'errors'   => [ __( 'Another save is in progress — try again in a moment.', 'post-404-shield' ) ],
					'warnings' => $validated['warnings'],
					'retry'    => true,
				];
			}
			$stale = $this->stale_result( $flags );
			if ( null !== $stale ) {
				return $stale;
			}

			// An automatic write that would publish what is already live — a
			// re-snapshot that found nothing new — archives nothing: a daily
			// no-op must not push the operator's revisions out of retention.
			// The option is brought in line, so it stops reading as stale.
			$live = ! empty( $flags['fail_open'] ) ? $this->artifact() : null;
			if ( null !== $live && self::same_document( self::as_loaded( $config ), self::as_loaded( $live ) ) ) {
				update_option( self::OPTION, $live, false );
				if ( null !== $redirect_fp ) {
					update_option( self::REDIRECT_FP_OPTION, $redirect_fp, false );
				}
				$this->record_warnings( $flags, $generated_by, $validated['warnings'] );
				return [
					'ok'        => true,
					'errors'    => [],
					'warnings'  => $validated['warnings'],
					'unchanged' => true,
				];
			}

			// S6 — the root-preflight coverage gate. Any save that leaves root mode
			// ACTIVE walks real URLs through the exact would-be loader decision
			// (against the candidate config, its allowlists built in memory from
			// the database); a non-empty would-block list ABORTS the save.
			// `force_preflight` (the CLI --force) is the knowing-operator override.
			if ( empty( $flags['skip_root_preflight'] ) && null !== $this->preflight_handler && $this->has_enabled_root_entries( $config['entries'] ) ) {
				$refused = $this->root_preflight_gate( $config, $flags, $validated['warnings'], $accepted_after );
				if ( null !== $refused ) {
					// Over a dropped status: the based gate's drops are shown in
					// the same refusal, so one confirmation covers all it lists.
					if ( ! empty( $refused['status_drop'] ) ) {
						$based_warnings = $validated['warnings'];
						$based          = $this->coverage_gate( $config, $flags, $based_warnings );
						// What the based gate confirmed is offered again with the
						// root drops, and what it says is shown, refusal or not.
						$refused['warnings']          = array_values( array_unique( array_merge( (array) $refused['warnings'], $based_warnings ) ) );
						$refused['status_drop_pairs'] = array_values( array_unique( array_merge( (array) ( $refused['status_drop_pairs'] ?? [] ), $this->confirmed_drop_pairs, (array) ( $based['status_drop_pairs'] ?? [] ) ) ) );
						if ( null !== $based ) {
							$refused['errors']      = array_merge( $refused['errors'], $based['errors'] );
							$refused['status_drop'] = ! empty( $based['status_drop'] );
						}
					}
					return $refused;
				}
			}

			// Based-entry coverage gate. Validation rejects a MALFORMED base; it
			// cannot know a well-formed one is WRONG. So every save that enables
			// or changes a based entry replays that entry's real URLs through the
			// loader's own decision against this candidate, and refuses if any
			// real URL would 404 or be redirected away. A save that changes no
			// based entry — the self-heal, a CLI write of the stored option —
			// runs nothing. `force_preflight` (CLI --force) overrides, as above.
			$refused = $this->coverage_gate( $config, $flags, $validated['warnings'] );
			if ( null !== $refused ) {
				return $refused;
			}

			// Mode-switch ordering (SPEC §3.2c). The invariant: a FULL-PATH artifact
			// must never read a slug-format allowlist — nested real pages would 404
			// with a cacheable TTL. So an entry becoming full-path rebuilds its list
			// BEFORE the artifact swap (a full-path list is harmless under the old
			// slug artifact — top-level lines still match), and an entry leaving
			// full-path rebuilds AFTER the swap (a slug artifact reads a full-path
			// list safely for the instant in between). Applies to restores too —
			// they run through this same pipeline.
			// Runs after both gates, which measure candidate lists in memory: a
			// save they refuse must not leave its rebuilt lists behind. A list
			// that failed to write stays in its old format, so the swap would put
			// a full-path artifact over a slug list: refuse instead.
			[ $rebuild_before, $rebuild_after ] = $this->match_switch_rebuilds( $config );
			$live_before                        = $this->artifact();
			$root_switching_on                  = $this->has_enabled_root_entries( $config['entries'] )
				&& ! ( null !== $live_before && $this->has_enabled_root_entries( (array) $live_before['entries'] ) );
			// The gates can outlast LOCK_TTL: no list is written for a save
			// that lost its lock (another save owns the lists now).
			if ( ( [] !== $rebuild_before || $root_switching_on || $posts_leaving_root ) && ! $this->refresh_lock() ) {
				return self::lock_lost( $validated['warnings'] );
			}
			if ( ! $this->rebuild_before_swap( $rebuild_before, $config, $posts_leaving_root ) ) {
				return [
					'ok'       => false,
					'errors'   => [
						sprintf(
							/* translators: %s: comma-separated list names (post types, root-extras). */
							__( 'Nothing was saved: the allowlist for %s could not be written, and the new config needs it first, or real URLs would get a 404. Check that the uploads/post-404-shield directory is writable, then save again.', 'post-404-shield' ),
							implode( ', ', $this->rebuild_failures )
						),
					],
					'warnings' => $validated['warnings'],
				];
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
				return self::lock_lost( $validated['warnings'] );
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

			// Retention too, under the lock: a concurrent save's early check
			// read it, so it must change only with the config it belongs to.
			if ( isset( $flags['persist_keep'] ) ) {
				$this->update_keep( (int) $flags['persist_keep'] );
			}

			// The artifact now carries the derived bucket, so it is now true
			// that it was built from these redirect sources.
			if ( isset( $accepted_after ) ) {
				update_option( self::PREFLIGHT_ACCEPTED_OPTION, $accepted_after, false );
			}
			// Root matching is on again — by a save, a restore or the CLI — so
			// the "switched off" state is over. Root entries that are all off
			// (Disable shield, a restored revision, a CLI write) are kept, as an
			// automatic switch-off keeps them: the next save from another tab
			// must not delete them (revalidate_root() records its own reason).
			if ( ! self::has_root_rows( (array) $config['entries'] ) || $this->has_enabled_root_entries( $config['entries'] ) ) {
				// Root on again, or no root settings left to keep: the state is over.
				delete_option( self::ROOT_OFF_OPTION );
			} elseif ( ! is_array( get_option( self::ROOT_OFF_OPTION ) ) ) {
				update_option(
					self::ROOT_OFF_OPTION,
					[
						'at'     => gmdate( 'c' ),
						'by'     => 'operator',
						'reason' => (string) ( $flags['root_off_reason'] ?? __( 'a save switched it off', 'post-404-shield' ) ),
						'errors' => [],
					],
					false
				);
			}
			if ( null !== $redirect_fp ) {
				update_option( self::REDIRECT_FP_OPTION, $redirect_fp, false );
			}

			$this->prune_revisions( isset( $flags['keep'] ) ? (int) $flags['keep'] : null );
		} catch ( ReadFailure $e ) {
			// A database read inside a gate or rebuild failed: never judge,
			// or swap, on a list built from "no rows".
			return [
				'ok'       => false,
				'errors'   => [ __( 'Nothing was saved: a database read failed while checking the change. Try again.', 'post-404-shield' ) . ' (' . $e->getMessage() . ')' ],
				'warnings' => $validated['warnings'],
				'retry'    => true,
			];
		} catch ( \Throwable $e ) {
			// A bug in a gate: refuse (nothing was swapped in), never fatal the
			// save, and never read it as a retry.
			error_log( '[post-404-shield] save check failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return [
				'ok'       => false,
				'errors'   => [ __( 'Nothing was saved: checking the change failed unexpectedly.', 'post-404-shield' ) . ' (' . $e->getMessage() . ')' ],
				'warnings' => $validated['warnings'],
			];
		} finally {
			if ( null !== $staged && file_exists( $staged ) ) {
				unlink( $staged ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
			}
			$this->release_lock();
		}

		// After the swap the save has landed: a rebuild that fails here keeps
		// its previous list, which the nightly rebuild replaces.
		try {
			if ( null !== $this->rebuild_handler && [] !== $rebuild_after ) {
				( $this->rebuild_handler )( $rebuild_after, $config['entries'], false, self::posts_left_root_of( $config ) );
			}
			// Once more for the types that became full-path, now that the artifact
			// saying so is live: a rebuild started from the OLD artifact (the daily
			// run, a queued job) could have replaced the pre-swap list with slug
			// lines between that rebuild and the swap, and the builders' guard only
			// knows the artifact that is live. From here on the guard refuses them.
			// A save that switched root mode on rebuilds root-extras once more
			// too: until the swap, the live artifact had root mode off, so the
			// media, old slugs and private pages made meanwhile were not appended.
			// So does a save after which posts no longer live at the root.
			if ( null !== $this->rebuild_handler && ( [] !== $rebuild_before || $root_switching_on || $posts_leaving_root ) ) {
				( $this->rebuild_handler )( $rebuild_before, $config['entries'], $root_switching_on || $posts_leaving_root, self::posts_left_root_of( $config ) );
			}
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] post-save rebuild: ' . $e->getMessage() . '; the previous lists stay until the nightly rebuild.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$this->record_warnings( $flags, $generated_by, $validated['warnings'] );
		return [
			'ok'       => true,
			'errors'   => [],
			'warnings' => $validated['warnings'],
		];
	}

	/**
	 * Keep an automatic write's warnings where someone will see them (the log,
	 * and the settings screen until the next operator save); an operator's own
	 * save shows its warnings itself, and clears the kept ones.
	 *
	 * @param array<string, mixed> $flags        write() flags.
	 * @param string               $generated_by The write's label.
	 * @param string[]             $warnings     Its warnings.
	 *
	 * @return void
	 */
	private function record_warnings( array $flags, string $generated_by, array $warnings ): void {
		// A write whose caller shows its warnings (the settings screen, the
		// CLI) supersedes the kept ones; an automatic one or a self-heal, which
		// nobody watches, keeps its own; anything else leaves them be.
		if ( empty( $flags['fail_open'] ) && empty( $flags['keep_warnings'] ) ) {
			if ( ! empty( $flags['shows_warnings'] ) ) {
				delete_option( self::AUTO_WARNINGS_OPTION );
			}
			return;
		}
		$warnings = array_values( array_diff( $warnings, $this->passes ) );
		if ( [] === $warnings ) {
			return;
		}
		error_log( '[post-404-shield] ' . $generated_by . ' — warnings: ' . implode( ' | ', $warnings ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		update_option(
			self::AUTO_WARNINGS_OPTION,
			[
				'at'       => gmdate( 'c' ),
				'by'       => $generated_by,
				'warnings' => array_values( array_map( 'strval', $warnings ) ),
			],
			false
		);
	}

	/**
	 * Whether a save confirms dropping a status from an entry: `true` (CLI
	 * --force) confirms every drop; a list confirms only the "entry:status"
	 * pairs it names — the ones a refusal listed, so a confirmation never
	 * covers a drop nobody was shown.
	 *
	 * @param array<string, mixed> $flags  write() flags.
	 * @param string               $entry  Entry key.
	 * @param string               $status Post status.
	 *
	 * @return bool
	 */
	private static function drop_is_confirmed( array $flags, string $entry, string $status ): bool {
		$allow = $flags['allow_status_drop'] ?? false;
		if ( true === $allow ) {
			return true;
		}
		return is_array( $allow ) && in_array( $entry . ':' . $status, array_map( 'strval', $allow ), true );
	}

	/**
	 * A status newly listed: anyone can now confirm its posts' slugs exist
	 * (the shield's header says allowed-known-slug), which a site hiding
	 * pre-launch content in it may not want.
	 *
	 * @param array<string, mixed> $config      Candidate document.
	 * @param array<string, mixed> $current_doc What is live.
	 * @param callable             $name        fn( entry key ): the entry's label.
	 *
	 * @return string[]
	 */
	private function newly_listed_warnings( array $config, array $current_doc, callable $name ): array {
		$warnings = [];
		foreach ( (array) ( $config['entries'] ?? [] ) as $entry_key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) || 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$live_entry = is_array( $current_doc['entries'][ $entry_key ] ?? null ) ? $current_doc['entries'][ $entry_key ] : [];
			$was        = isset( $live_entry['enabled'] ) && false === $live_entry['enabled'] ? [] : \Post404Shield\effective_statuses( $live_entry );
			foreach ( array_diff( \Post404Shield\effective_statuses( $entry ), $was, [ 'publish' ] ) as $added ) {
				$warnings[] = sprintf(
					/* translators: 1: entry name, 2: post status. */
					__( '%1$s now lists the status "%2$s": anyone can confirm its posts\' addresses exist, although WordPress decides whether to show them.', 'post-404-shield' ),
					$name( $entry_key ),
					$added
				);
			}
		}
		return $warnings;
	}

	/**
	 * The based-entry coverage gate (see write()): null when the save may go
	 * on, else the refusal. Adds its verdicts to the warnings.
	 *
	 * @param array<string, mixed> $config   Candidate document.
	 * @param array<string, mixed> $flags    write() flags.
	 * @param string[]             $warnings The save's warnings (appended).
	 *
	 * @return array<string, mixed>|null
	 */
	private function coverage_gate( array $config, array $flags, array &$warnings ): ?array {
		if ( null === $this->coverage_handler ) {
			return null;
		}
		// Measured against what is LIVE: an option edited over WP-CLI and
		// published with `config write` changes what the loader serves as
		// much as a settings save does. With no live artifact (a heal)
		// the stored option stands in.
		$current_doc = $this->artifact() ?? $this->option();
		$coverage    = ( $this->coverage_handler )( $config, is_array( $current_doc['entries'] ?? null ) ? $current_doc['entries'] : null );
		$breaks      = (array) ( $coverage['breaks'] ?? [] );
		$unclaimed   = (array) ( $coverage['unclaimed'] ?? [] );
		$name        = function ( $entry_key ) use ( $config ): string {
			$entry = $config['entries'][ (string) $entry_key ] ?? [];
			return $this->entry_label( (string) $entry_key, is_array( $entry ) ? $entry : [] );
		};

		foreach ( $this->newly_listed_warnings( $config, is_array( $current_doc ) ? $current_doc : [], $name ) as $newly_listed ) {
			$warnings[] = $newly_listed;
		}

		// Dropping a status whose posts are live is refused unless confirmed
		// (the settings screen's checkbox, or CLI --force): a status a site
		// uses to hide content may be one the operator means to drop.
		$dropped = [];
		if ( [] !== $breaks ) {
			$confirmed = static fn( array $b ): bool => isset( $b['status'] ) && self::drop_is_confirmed( $flags, (string) ( $b['entry'] ?? '' ), (string) $b['status'] );
			$dropped   = array_values( array_filter( $breaks, $confirmed ) );
			$breaks    = array_values( array_filter( $breaks, static fn( array $b ): bool => ! $confirmed( $b ) ) );
			foreach ( $dropped as $drop ) {
				$this->confirmed_drop_pairs[] = (string) ( $drop['entry'] ?? '' ) . ':' . (string) $drop['status'];
			}
			if ( [] !== $dropped ) {
				$which      = array_values( array_unique( array_map( static fn( array $b ): string => $name( $b['entry'] ?? '' ) . ' — "' . (string) $b['status'] . '"', $dropped ) ) );
				$warnings[] = sprintf(
					/* translators: 1: number of real URLs, 2: the entries and statuses dropped. */
					_n( 'Dropped a status as confirmed: %1$d real URL checked now gets a 404 (%2$s).', 'Dropped a status as confirmed: %1$d real URLs checked now get a 404 (%2$s).', count( $dropped ), 'post-404-shield' ),
					count( $dropped ),
					implode( ', ', $which )
				);
			}
		}
		if ( [] !== $unclaimed && empty( $flags['force_preflight'] ) ) {
			$unclaimed_errors = [];
			foreach ( $unclaimed as $entry_key => $home ) {
				$unclaimed_errors[] = '' === (string) $home
					? sprintf(
						/* translators: %s: entry name. */
						__( 'Coverage check FAILED: none of %s\'s real URLs sit under its URL bases, so it would shield nothing — check the URL base.', 'post-404-shield' ),
						$name( $entry_key )
					)
					: sprintf(
						/* translators: 1: entry name, 2: the URL base its posts really use. */
						__( 'Coverage check FAILED: none of %1$s\'s real URLs sit under its URL bases, so it would shield nothing. Its posts live under /%2$s/ — check the URL base.', 'post-404-shield' ),
						$name( $entry_key ),
						(string) $home
					);
			}
			return [
				'ok'       => false,
				'errors'   => $unclaimed_errors,
				'warnings' => $warnings,
			];
		}
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
					$name( $entry_key ),
					(string) $home
				);
			}

			foreach ( array_slice( $breaks, 0, 10 ) as $break ) {
				$coverage_errors[] = sprintf(
					isset( $break['status'] )
					/* translators: 1: URL, 2: shield decision, 3: entry name, 4: post status. */
					? __( 'Would break: %1$s (%2$s, %3$s — status "%4$s" is not listed)', 'post-404-shield' )
					/* translators: 1: URL, 2: shield decision, 3: entry name. */
					: __( 'Would break: %1$s (%2$s, %3$s)', 'post-404-shield' ),
					(string) $break['url'],
					(string) $break['marker'],
					$name( $break['entry'] ),
					(string) ( $break['status'] ?? '' )
				);
			}
			return [
				'ok'                => false,
				'errors'            => $coverage_errors,
				'warnings'          => $warnings,
				// Refused only over dropped statuses: the screen offers to confirm
				// exactly these (entry, status) pairs.
				'status_drop'       => [] === array_filter( $breaks, static fn( $b ) => ! isset( $b['status'] ) ),
				'status_drop_pairs' => array_values( array_unique( array_merge( $this->confirmed_drop_pairs, array_map( static fn( $b ) => (string) ( $b['entry'] ?? '' ) . ':' . (string) $b['status'], array_filter( $breaks, static fn( $b ) => isset( $b['status'] ) ) ) ) ) ),
			];
		}
		$depth_hits = (array) ( $coverage['depth'] ?? [] );
		if ( [] !== $depth_hits ) {
			$warnings[] = sprintf(
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
		foreach ( (array) ( $coverage['unlisted'] ?? [] ) as $entry_key => $unlisted_statuses ) {
			foreach ( (array) $unlisted_statuses as $unlisted_status => $posts ) {
				$warnings[] = sprintf(
					/* translators: 1: entry name, 2: post status, 3: number of posts. */
					__( '%1$s: %3$d posts are in the public status "%2$s", which this entry does not list, so the shield answers them with a 404. If the site shows them to visitors, tick it under the entry\'s statuses; if it hides them (a pre-launch status), leave it unticked.', 'post-404-shield' ),
					$name( $entry_key ),
					(string) $unlisted_status,
					(int) $posts
				);
			}
		}
		foreach ( (array) ( $coverage['private'] ?? [] ) as $entry_key => $posts ) {
			$warnings[] = sprintf(
				/* translators: 1: entry name, 2: number of posts sampled. */
				__( '%1$s: private posts are not listed (%2$d sampled), so staff who can read them get a 404 at their address. Tick Private under the entry\'s statuses to pass them to WordPress.', 'post-404-shield' ),
				$name( $entry_key ),
				(int) $posts
			);
		}
		foreach ( (array) ( $coverage['homes'] ?? [] ) as $entry_key => $home ) {
			if ( ! isset( $unclaimed[ $entry_key ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: entry name, 2: the URL base most of its posts use. */
					__( '%1$s: most of its real URLs live under /%2$s/, which is not one of its URL bases — check the bases are complete.', 'post-404-shield' ),
					$name( $entry_key ),
					(string) $home
				);
			}
		}
		// Forced over a wrong base: say so, entry by entry — a "passed" would lie.
		foreach ( $unclaimed as $entry_key => $home ) {
			$warnings[] = '' === (string) $home
				/* translators: %s: entry name. */
				? sprintf( __( 'Coverage check FAILED but the save was FORCED: none of %s\'s real URLs sit under its URL bases.', 'post-404-shield' ), $name( $entry_key ) )
				/* translators: 1: entry name, 2: the URL base its posts really use. */
				: sprintf( __( 'Coverage check FAILED but the save was FORCED: none of %1$s\'s real URLs sit under its URL bases; they live under /%2$s/.', 'post-404-shield' ), $name( $entry_key ), (string) $home );
		}
		// Forced over broken URLs: said whatever else the save confirmed or
		// forced. Only a clean check is a pass.
		if ( [] !== $breaks ) {
			/* translators: %d: number of real URLs the forced save breaks. */
			$warnings[] = sprintf( __( 'Coverage check reported %d broken real URL(s) but the save was FORCED.', 'post-404-shield' ), count( $breaks ) );
		} elseif ( (int) ( $coverage['resolving'] ?? $coverage['checked'] ?? 0 ) > 0 && [] === $unclaimed && [] === $dropped ) {
			// Counted without the samples said above to get a 404 (an unlisted
			// or private status): only what still resolves is a pass.
			/* translators: %d: number of real URLs checked. */
			$warnings[]     = sprintf( __( 'Coverage check passed — %d real URLs of the changed post types still resolve.', 'post-404-shield' ), (int) ( $coverage['resolving'] ?? $coverage['checked'] ) );
			$this->passes[] = end( $warnings );
		}
		return null;
	}

	/**
	 * Whether two config documents say the same thing, apart from when and by
	 * whom they were generated.
	 *
	 * @param array<string, mixed> $a Document.
	 * @param array<string, mixed> $b Document.
	 *
	 * @return bool
	 */
	private static function same_document( array $a, array $b ): bool {
		foreach ( [ 'version', 'generated_at', 'generated_by' ] as $meta ) {
			unset( $a[ $meta ], $b[ $meta ] );
		}
		// Key order may differ; types may not — `0` and `null` are different
		// depth rules and TTLs, and a loose == would call them the same.
		return self::key_sorted( $a ) === self::key_sorted( $b );
	}

	/**
	 * Whether a document has root entries, switched on or kept off.
	 *
	 * @param array<mixed> $entries Config entries.
	 *
	 * @return bool
	 */
	private static function has_root_rows( array $entries ): bool {
		return [] !== array_filter( $entries, static fn( $entry ) => is_array( $entry ) && true === ( $entry['root'] ?? false ) );
	}

	/**
	 * A document as far as the loader reads it, for comparing an automatic
	 * write with the live artifact: with no root entry switched on the root
	 * stage never runs, so the snapshot buckets only it reads change nothing,
	 * and a new redirect source base must not archive a revision each night
	 * (pushing the operator's own out of retention, the pre-Disable one
	 * included). The endpoints stay: based matching strips them too.
	 *
	 * @param array<string, mixed> $doc Config document.
	 *
	 * @return array<string, mixed>
	 */
	private static function as_loaded( array $doc ): array {
		if ( ! is_array( $doc['excluded_bases'] ?? null ) ) {
			return $doc;
		}
		$entries = (array) ( $doc['entries'] ?? [] );
		// Root rows kept switched off: the root stage does not run either,
		// but the moved-base record (operator, post_base, posts_left_root)
		// must still land for when root matching comes back on.
		$unread = self::has_root_rows( $entries )
			? ( self::enabled_root_rows( $entries ) ? [] : [ 'floor', 'derived' ] )
			: [ 'floor', 'derived', 'operator', 'post_base', 'posts_left_root' ];
		foreach ( $unread as $bucket ) {
			unset( $doc['excluded_bases'][ $bucket ] );
		}
		return $doc;
	}

	/**
	 * An array with every map's keys sorted, lists kept in order.
	 *
	 * @param array<mixed> $value Array.
	 *
	 * @return array<mixed>
	 */
	private static function key_sorted( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::key_sorted( $item );
			}
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * Whether an entry's list is full-path: set so, or a root entry, which is
	 * full-path by definition (as AllowlistBuilder::match_for() and the reader
	 * default it).
	 *
	 * @param array<string, mixed> $entry Entry.
	 *
	 * @return bool
	 */
	private static function is_full_path( array $entry ): bool {
		return true === ( $entry['root'] ?? false ) || 'full-path' === ( $entry['match'] ?? 'slug' );
	}

	/**
	 * S6, the root preflight: refuse (return the result) when a real URL
	 * would get a pre-boot 404 that no earlier forced save accepted; else add
	 * its verdict to the warnings. Sets what stays accepted after this save.
	 *
	 * @param array<string, mixed> $config         Candidate document.
	 * @param array<string, mixed> $flags          write() flags.
	 * @param string[]             $warnings       Save warnings, added to.
	 * @param string[]|null        $accepted_after Set: the would-blocks accepted after this save.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 */
	private function root_preflight_gate( array $config, array $flags, array &$warnings, ?array &$accepted_after ): ?array {
		$result      = (array) ( $this->preflight_handler )( $config );
		$would_block = array_values( array_filter( (array) ( $result['would_block'] ?? ( isset( $result['dropped'] ) ? [] : $result ) ), 'is_string' ) );
		$dropped     = array_filter( (array) ( $result['dropped'] ?? [] ), 'is_string' );
		$confirmed   = [];
		// Each dropped would-block as its (root entry, status) pair.
		$key_of = [];
		foreach ( (array) ( $config['entries'] ?? [] ) as $entry_key => $entry ) {
			if ( is_array( $entry ) && true === ( $entry['root'] ?? false ) ) {
				$key_of[ (string) ( $entry['post_type'] ?? $entry_key ) ] = (string) $entry_key;
			}
		}
		$pair_of = static function ( string $url ) use ( $dropped, $result, $key_of ): string {
			$type = (string) ( $result['dropped_types'][ $url ] ?? '' );
			return ( $key_of[ $type ] ?? $type ) . ':' . $dropped[ $url ];
		};
		// A status dropped as confirmed (the settings screen's checkbox, a
		// restore's, CLI --force): its posts' 404s are what the save is for.
		if ( [] !== $dropped ) {
			$confirmed   = array_values( array_filter( $would_block, fn( string $url ): bool => isset( $dropped[ $url ] ) && self::drop_is_confirmed( $flags, ...explode( ':', $pair_of( $url ), 2 ) ) ) );
			$would_block = array_values( array_diff( $would_block, $confirmed ) );
			foreach ( $confirmed as $url ) {
				$this->confirmed_drop_pairs[] = $pair_of( $url );
			}
			if ( [] !== $confirmed ) {
				$warnings[] = sprintf(
					/* translators: %d: number of real URLs. */
					_n( 'Dropped a status as confirmed: %d real URL checked now gets a 404.', 'Dropped a status as confirmed: %d real URLs checked now get a 404.', count( $confirmed ), 'post-404-shield' ),
					count( $confirmed )
				);
			}
		}
		$accepted   = array_values( array_filter( (array) get_option( self::PREFLIGHT_ACCEPTED_OPTION, [] ), 'is_string' ) );
		$new_blocks = array_values( array_diff( $would_block, $accepted ) );
		// What stays accepted after this save: previously accepted URLs
		// that still would-block (the rest fixed themselves), plus, on a
		// forced save, everything it reported.
		$accepted_after = ! empty( $flags['force_preflight'] )
			? array_values( array_unique( array_merge( array_intersect( $accepted, $would_block ), $would_block ) ) )
			: array_values( array_intersect( $accepted, $would_block ) );
		if ( [] !== $new_blocks && empty( $flags['force_preflight'] ) ) {
			$block_errors = [
				/* translators: %d: number of URLs the root preflight would 404. */
				sprintf( __( 'Root preflight FAILED: %d real URL(s) would be served a pre-boot 404 — nothing was saved.', 'post-404-shield' ), count( $new_blocks ) ),
			];
			foreach ( array_slice( $new_blocks, 0, 10 ) as $blocked_url ) {
				$block_errors[] = isset( $dropped[ $blocked_url ] )
					/* translators: 1: a URL the root preflight would 404, 2: post status. */
					? sprintf( __( 'Would block: %1$s (status "%2$s" is not listed)', 'post-404-shield' ), $blocked_url, $dropped[ $blocked_url ] )
					/* translators: %s: a URL the root preflight would 404. */
					: sprintf( __( 'Would block: %s', 'post-404-shield' ), $blocked_url );
			}
			return [
				'ok'                => false,
				'root_refused'      => true,
				'errors'            => $block_errors,
				'warnings'          => $warnings,
				// Refused only over dropped statuses: the screen offers to confirm
				// exactly these (entry, status) pairs.
				'status_drop'       => [] === array_diff( $new_blocks, array_keys( $dropped ) ),
				'status_drop_pairs' => array_values( array_unique( array_merge( $this->confirmed_drop_pairs, array_map( $pair_of, array_values( array_intersect( $new_blocks, array_keys( $dropped ) ) ) ) ) ) ),
			];
		}
		if ( [] === $would_block && [] !== $confirmed ) {
			return null; // Not a pass: the confirmed drop's 404s are said above.
		}
		if ( [] === $would_block ) {
			$warnings[]     = __( 'Root preflight passed — no real URL would be blocked.', 'post-404-shield' );
			$this->passes[] = end( $warnings );
		} elseif ( [] === $new_blocks ) {
			/* translators: %d: number of previously accepted would-block URLs. */
			$warnings[]     = sprintf( __( 'Root preflight passed — %d would-block URL(s) were accepted on an earlier forced save.', 'post-404-shield' ), count( $would_block ) );
			$this->passes[] = end( $warnings );
		} else {
			/* translators: %d: number of URLs the root preflight would 404 (forced save). */
			$warnings[] = sprintf( __( 'Root preflight reported %d would-block URL(s) but the save was FORCED.', 'post-404-shield' ), count( $would_block ) );
		}
		return null;
	}

	/**
	 * Refresh everything write() derives from live WordPress into the
	 * candidate: the excluded-bases snapshot and each entry's derived
	 * reserved slugs. Returns the redirect fingerprint to record once the
	 * save lands (null to record nothing).
	 *
	 * @param array<string, mixed> $config Candidate document, updated.
	 *
	 * @return string|null
	 */
	private function take_snapshots( array &$config ): ?string {
		$locale_pattern           = self::locale_pattern_of( $config );
		$operator                 = (array) ( $config['excluded_bases']['operator'] ?? [] );
		$stored                   = $this->option();
		$left_root                = $this->posts_left_the_root( $config );
		$config['excluded_bases'] = $this->excluded_bases_snapshot(
			$operator,
			$locale_pattern,
			(array) ( $config['excluded_bases']['endpoints'] ?? $stored['excluded_bases']['endpoints'] ?? [] )
		);
		if ( $left_root ) {
			$config['excluded_bases']['posts_left_root'] = true;
		}

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

		// A reader failed (threw, or its query errored): what was read is
		// incomplete. Keep what the stored config derived — the union,
		// fail-open — and record nothing, so the nightly sync tries again.
		// Reading, correctly, that there are none is not a failure: the
		// redirects are gone, and so are the slugs they reserved.
		if ( $this->redirect_read_failed && is_array( $stored ) ) {
			$config      = self::with_stored_derivations( $config, $stored );
			$redirect_fp = null;
		}
		return $redirect_fp;
	}

	/**
	 * A config with the stored one's derived buckets folded back in — the
	 * excluded bases and each entry's derived reserved slugs, as a union.
	 *
	 * @param array<string, mixed> $config Candidate document.
	 * @param array<string, mixed> $stored Stored document.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_stored_derivations( array $config, array $stored ): array {
		$config['excluded_bases']['derived'] = array_values(
			array_unique( array_merge( (array) ( $config['excluded_bases']['derived'] ?? [] ), (array) ( $stored['excluded_bases']['derived'] ?? [] ) ) )
		);
		foreach ( (array) ( $config['entries'] ?? [] ) as $key => $entry ) {
			$kept = (array) ( $stored['entries'][ $key ]['reserved_derived'] ?? [] );
			if ( is_array( $entry ) && [] !== $kept ) {
				$merged = array_values( array_unique( array_merge( (array) ( $entry['reserved_derived'] ?? [] ), $kept ) ) );
				sort( $merged );
				$config['entries'][ $key ]['reserved_derived'] = $merged;
			}
		}
		return $config;
	}

	/**
	 * The `expect_revision` check, under the save lock: null when the save may
	 * go on, else the refusal. Reads the option fresh — this request's copy
	 * predates the lock, and a save that committed meanwhile is exactly what
	 * it must see.
	 *
	 * @param array<string, mixed> $flags write() flags.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stale_result( array $flags ): ?array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			// Only these two rows: both are stored with autoload off, so the
			// site-wide alloptions / notoptions entries are touched only when
			// they carry one of them (an install that autoloaded it once) —
			// evicting them on every save makes every web head reload them.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( self::KEEP_OPTION, 'options' );
			foreach ( [ 'alloptions', 'notoptions' ] as $bucket ) {
				$cached = wp_cache_get( $bucket, 'options' );
				if ( is_array( $cached ) && ( isset( $cached[ self::OPTION ] ) || isset( $cached[ self::KEEP_OPTION ] ) ) ) {
					wp_cache_delete( $bucket, 'options' );
				}
			}
		}
		if ( ! isset( $flags['expect_revision'] ) || (string) $flags['expect_revision'] === $this->current_revision() ) {
			return null;
		}
		return [
			'ok'       => false,
			'stale'    => true,
			'errors'   => [ __( 'The settings changed after this page was opened — another save, a restore or a CLI write. This page now shows the current settings; make your change again.', 'post-404-shield' ) ],
			'warnings' => [],
		];
	}

	/**
	 * The pre-swap rebuild: the types becoming full-path or widened, and
	 * root-extras when this save switches root mode on or posts have just
	 * left the site root (their old addresses must be listed before the
	 * artifact that drops them from root matching goes live).
	 *
	 * @param string[]             $rebuild_before     Types to rebuild before the swap.
	 * @param array<string, mixed> $config             Candidate document.
	 * @param bool                 $posts_leaving_root Whether posts just left the root.
	 *
	 * @return bool False when a list failed to write.
	 *
	 * @throws ReadFailure When a database read failed (write() refuses with a retry).
	 */
	private function rebuild_before_swap( array $rebuild_before, array $config, bool $posts_leaving_root = false ): bool {
		$live              = $this->artifact();
		$root_switching_on = $this->has_enabled_root_entries( $config['entries'] )
			&& ! ( null !== $live && $this->has_enabled_root_entries( (array) $live['entries'] ) );
		$root_extras       = $root_switching_on || ( $posts_leaving_root && $this->has_enabled_root_entries( $config['entries'] ) );
		if ( null === $this->rebuild_handler || ( [] === $rebuild_before && ! $root_extras ) ) {
			return true;
		}
		try {
			$result = ( $this->rebuild_handler )( $rebuild_before, self::with_live_statuses( (array) $config['entries'], $live ), $root_extras, self::posts_left_root_of( $config ) );
		} catch ( ReadFailure $e ) {
			throw $e; // A failed read: write() refuses with a retry.
		} catch ( \Throwable $e ) {
			// A bug in a rebuild must refuse the save, never fatal it.
			error_log( '[post-404-shield] pre-swap rebuild failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = false;
		}
		// The handler names the lists that failed; a plain false (or a bug)
		// fails every list this rebuild was for — root-extras too, when it is
		// the only one.
		$asked = array_merge( $rebuild_before, $root_extras ? [ 'root-extras' ] : [] );
		if ( is_array( $result ) ) {
			$this->rebuild_failures = array_map( 'strval', $result );
		} else {
			$this->rebuild_failures = true === $result ? [] : $asked;
		}
		return [] === $this->rebuild_failures;
	}

	/**
	 * Whether a document records that posts have left the site root.
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return bool
	 */
	private static function posts_left_root_of( array $config ): bool {
		return true === ( $config['excluded_bases']['posts_left_root'] ?? false );
	}

	/**
	 * Candidate entries with each type's statuses joined by the ones the live
	 * artifact lists for it. A pre-swap list is read by the artifact that is
	 * live until the swap — or for good, when the save is refused after it
	 * (a lost lock, a failed stage or swap) — so it must still hold every post
	 * that artifact lists, although the save also drops a status. Every entry,
	 * not only the types rebuilt: root-extras reads every root type's and the
	 * Posts entry's statuses. The rebuild after the swap narrows them.
	 *
	 * @param array<mixed>              $entries Candidate entries.
	 * @param array<string, mixed>|null $live    Live artifact.
	 *
	 * @return array<mixed>
	 */
	private static function with_live_statuses( array $entries, ?array $live ): array {
		$listed = [];
		foreach ( (array) ( $live['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' ) ) {
				$type            = (string) ( $entry['post_type'] ?? $key );
				$listed[ $type ] = array_merge( $listed[ $type ] ?? [], \Post404Shield\effective_statuses( $entry ) );
			}
		}
		foreach ( $entries as $key => $entry ) {
			$type = is_array( $entry ) ? (string) ( $entry['post_type'] ?? $key ) : '';
			if ( isset( $listed[ $type ] ) ) {
				$entries[ $key ]['post_status'] = array_values( array_unique( array_merge( \Post404Shield\effective_statuses( $entry ), $listed[ $type ] ) ) );
			}
		}
		return $entries;
	}

	/**
	 * The refusal of a save whose lock another save broke.
	 *
	 * @param string[] $warnings Save warnings.
	 *
	 * @return array{ok: false, errors: string[], warnings: string[], retry: true}
	 */
	private static function lock_lost( array $warnings ): array {
		return [
			'ok'       => false,
			'errors'   => [ __( 'Another save took over while this one was running — nothing was saved. Reload the page and check the current settings.', 'post-404-shield' ) ],
			'warnings' => $warnings,
			'retry'    => true,
		];
	}

	/**
	 * Which effective CPTs need a synchronous allowlist rebuild for a candidate
	 * config, split by WHEN (relative to the artifact swap). Compares against
	 * the CURRENT ARTIFACT — the loader's live view: an enabled full-path entry
	 * whose type is not already serving full-path, or one listing a status the
	 * live one does not, rebuilds before the swap; an enabled slug entry whose
	 * type was serving full-path, or one dropping a status, rebuilds after it.
	 *
	 * @param array<string, mixed> $candidate Candidate config document.
	 *
	 * @return array{0: string[], 1: string[]} [before, after] effective CPT lists.
	 */
	private function match_switch_rebuilds( array $candidate ): array {
		$current = $this->artifact();

		// The loader's current per-CPT format and statuses: only enabled
		// allowlist entries count.
		$current_full_path = [];
		$current_statuses  = [];
		if ( null !== $current ) {
			foreach ( $current['entries'] as $key => $entry ) {
				if ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) {
					continue;
				}
				if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
					continue;
				}
				$cpt                       = (string) ( $entry['post_type'] ?? $key );
				$current_full_path[ $cpt ] = self::is_full_path( $entry );
				$current_statuses[ $cpt ]  = array_merge( $current_statuses[ $cpt ] ?? [], \Post404Shield\effective_statuses( $entry ) );
			}
		}

		$before     = [];
		$after      = [];
		$candidates = [];
		foreach ( (array) ( $candidate['entries'] ?? [] ) as $key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) ) {
				continue;
			}
			if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$cpt                = (string) ( $entry['post_type'] ?? $key );
			$candidates[ $cpt ] = array_merge( $candidates[ $cpt ] ?? [], \Post404Shield\effective_statuses( $entry ) );
			$wants_fp           = self::is_full_path( $entry );
			$serves_fp          = $current_full_path[ $cpt ] ?? false;
			// A type the live artifact does not shield, or shields with fewer
			// statuses, has a list on disk that is stale or missing: rebuild it
			// before the swap. Safe then — the old artifact does not read that
			// list, or reads a superset of what it needs.
			$widened = ! isset( $current_full_path[ $cpt ] )
				|| [] !== array_diff( \Post404Shield\effective_statuses( $entry ), $current_statuses[ $cpt ] ?? [] );
			if ( $wants_fp && ! $serves_fp ) {
				$before[] = $cpt;
			} elseif ( ! $wants_fp && $serves_fp ) {
				$after[] = $cpt;
			} elseif ( $widened ) {
				$before[] = $cpt;
			}
		}
		// A status the live artifact lists and the candidate drops: its posts
		// leave the list only when it is rebuilt, after the swap (until then
		// the live artifact reads the wider list, a superset). Every save path
		// — the settings screen, a restore, the CLI — so a hidden status is not
		// confirmable until the nightly rebuild.
		foreach ( $candidates as $cpt => $statuses ) {
			if ( isset( $current_statuses[ $cpt ] ) && [] !== array_diff( $current_statuses[ $cpt ], $statuses ) && ! in_array( $cpt, $before, true ) ) {
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
		// The guard line also carries the loader's pre-filter, so a request
		// that is not shield business exits without decoding the document.
		$content = '<?php exit; __halt_compiler(); // post-404-shield generated config — do not edit by hand. prefilter:'
			. wp_json_encode( \Post404Shield\prefilter_for( $config ), JSON_UNESCAPED_SLASHES ) . "\n" . $json . "\n";

		// Named temp file in the SAME directory so rename() is an atomic swap.
		$tmp     = \Post404Shield\temp_path( $file );
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
				$key   = static function ( string $path ): array {
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
	 * @param string               $stamp        Revision stamp.
	 * @param string               $generated_by Display label for the restored artifact's meta.
	 * @param array<string, mixed> $flags        write() flags (`expect_revision`: the settings the
	 *                                           confirm screen showed the diff against).
	 *
	 * @return array{ok: bool, errors: string[], warnings: string[], stale?: bool}
	 */
	public function restore( string $stamp, string $generated_by, array $flags = [] ): array {
		$config = $this->read_revision( $stamp );
		if ( null === $config ) {
			return [
				'ok'       => false,
				'errors'   => [ __( 'That revision does not exist or is not a valid config.', 'post-404-shield' ) ],
				'warnings' => [],
			];
		}
		unset( $config['generated_at'], $config['generated_by'] );
		return $this->write( $config, $generated_by, $flags );
	}

	// --- Legacy import -----------------------------------------------------------

	/**
	 * Keep root mode true to the site after a change nothing else would
	 * notice — the fail-open answer to it.
	 *
	 * Root mode's rules depend on live site state: which types dwell at the
	 * root, a based post entry's base and the excluded-bases snapshot all come
	 * from the permalink settings and rewrite rules, and validate() only sees
	 * them at save time. Change Settings → Permalinks afterwards (say from
	 * /blog/%postname%/ to /%postname%/, or /blog/ to /news/) and post URLs
	 * leave what the artifact knows — each becomes a fake slug and gets a
	 * pre-boot 404. So: when root mode still fits (root_mode_errors()), re-save
	 * the option with the post base updated if the snapshot or base drifted,
	 * which re-snapshots and re-runs the root preflight; when it does not fit,
	 * or that save fails, switch every root entry off (settings kept, so the
	 * operator can re-enable once fixed) — from the option, or from the live
	 * artifact when the option also fails for unrelated reasons.
	 *
	 * Callers: the permalink settings hooks (at shutdown) and the daily
	 * health check.
	 *
	 * @param string $reason What triggered it (the permalink settings changed,
	 *                       the daily health check), for the revision label,
	 *                       the log and the notice.
	 *
	 * @return bool True when root mode was switched off.
	 */
	public function revalidate_root( string $reason ): bool {
		$option = $this->option();
		if ( null === $option ) {
			return false;
		}

		// A based `post` entry follows the permalink structure's base (the
		// settings screen derives it the same way on every save) — but not in
		// the request that changed the structure: its rewrite rules are still
		// the old ones, so the routes under the new base would reserve nothing.
		// The follow-up a minute later, with the rules flushed, moves it.
		$candidate = $this->with_current_post_base( $option );
		if ( $candidate !== $option && $this->rewrite_reset_this_request() ) {
			$candidate = $option;
		}

		if ( ! $this->has_enabled_root_entries( (array) ( $candidate['entries'] ?? [] ) ) ) {
			// Based mode: the same drift, for a `post` entry — its base moved
			// (`/blog/` → `/news/`), or the structure no longer has a fixed one
			// (`/blog/%category%/%postname%/`), under which every post would
			// read as a fake slug. Follow the base, or switch the entry off
			// (settings kept); the save's coverage gate replays its posts.
			$candidate = $this->with_unservable_post_disabled( $candidate );
			if ( $candidate === $option && ! $this->snapshot_is_stale( $option ) ) {
				return false;
			}
			// Automatic: an error the live artifact already carries does not
			// hold it back, a new one does (a moved base still meets the
			// coverage gate), and with no live artifact nothing is published.
			// Say what the write does: the Posts entry followed the structure,
			// or only the snapshot (a new redirect, a plugin's route) moved.
			$result = $this->write(
				$candidate,
				( $candidate === $option ? 'auto: snapshot refreshed — ' : 'auto: the Posts entry follows the permalink settings — ' ) . $reason,
				[
					'expect_revision' => $this->revision_of( $option ),
					'fail_open'       => true,
				]
			);
			if ( ! $result['ok'] ) {
				error_log( '[post-404-shield] permalink revalidation (' . $reason . '): could not update the Posts entry: ' . implode( ' | ', $result['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return false;
		}

		// A based Posts entry beside root Pages goes the same way as in based
		// mode, or the switch-off below would be refused over it.
		$candidate = $this->with_unservable_post_disabled( $candidate );
		$errors    = $this->root_mode_errors( $candidate );
		if ( [] === $errors ) {
			// Still valid for root mode, but the snapshot it runs on may not be:
			// a changed post, category or tag base, or a plugin's rewrite rules,
			// moves real URLs out from under the excluded bases, and root
			// matching would 404 them. Re-saving re-snapshots and re-runs the
			// root preflight; only if that fails does root matching go off.
			if ( $candidate === $option && ! $this->snapshot_is_stale( $option ) ) {
				return false;
			}
			// A moved Posts base is kept passing by write() itself.
			$label  = $candidate === $option ? 'auto: root snapshot refreshed — ' : 'auto: the Posts entry follows the permalink settings — ';
			$result = $this->write(
				$candidate,
				$label . $reason,
				[
					'expect_revision' => $this->revision_of( $option ),
					'fail_open'       => true,
				]
			);
			// A save that landed meanwhile ran every check itself.
			if ( $result['ok'] || ! empty( $result['stale'] ) ) {
				return false;
			}
			// A busy lock or a failed read says nothing about the config: the
			// follow-up or the next daily check tries again. Any other refusal
			// (the root preflight, the coverage gate, a new error) leaves the
			// live snapshot stale for good, so root matching goes off.
			if ( ! empty( $result['retry'] ) ) {
				error_log( '[post-404-shield] root revalidation (' . $reason . '): the refresh did not land, retried later: ' . implode( ' | ', $result['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return false;
			}
			$errors = $result['errors'];
		}

		// Switch root matching off: a write that only disables entries, so it
		// lands whatever else the stored config gets wrong that is already
		// live (fail_open) — and never over a save that committed after the
		// option was read here. The Posts base is left where it was: moving
		// it is a change the coverage gate may refuse, and a based entry at
		// its old base only ever passes URLs through. The next revalidation
		// moves it.
		$result = $this->write(
			self::root_disabled( $this->with_unservable_post_disabled( $option ) ),
			'auto: root matching switched off — ' . $reason,
			[
				'skip_root_preflight' => true,
				'fail_open'           => true,
				'expect_revision'     => $this->revision_of( $option ),
			]
		);
		if ( ! empty( $result['stale'] ) ) {
			return false; // A save landed meanwhile; the next check judges it.
		}
		if ( ! $result['ok'] ) {
			error_log( '[post-404-shield] root revalidation (' . $reason . '): could not switch root matching off: ' . implode( ' | ', $result['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		update_option(
			self::ROOT_OFF_OPTION,
			[
				'at'     => gmdate( 'c' ),
				'reason' => $reason,
				'errors' => array_values( array_map( 'strval', $errors ) ),
			],
			false
		);
		error_log( '[post-404-shield] root matching switched OFF (' . $reason . '): ' . implode( ' | ', $errors ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return true;
	}

	/**
	 * What makes root mode itself invalid on this site now — separate from any
	 * other error the config may carry, so an unrelated problem (a status a
	 * plugin stopped registering) never switches root matching off, and never
	 * keeps it on either.
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return string[] Errors; empty when root mode still fits the site.
	 */
	private function root_mode_errors( array $config ): array {
		$entries      = (array) ( $config['entries'] ?? [] );
		$enabled_root = [];
		foreach ( $entries as $key => $entry ) {
			if ( is_array( $entry ) && true === ( $entry['root'] ?? false )
				&& ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] )
				&& 'allowlist' === ( $entry['mode'] ?? 'allowlist' )
			) {
				$enabled_root[] = (string) ( $entry['post_type'] ?? $key );
			}
		}
		if ( [] === $enabled_root ) {
			return [];
		}

		$errors = [];
		if ( ! $this->post_base_info()['supported'] ) {
			$errors[] = __( 'The permalink structure no longer gives posts a fixed shape (only static segments before %postname% are supported).', 'post-404-shield' );
		}
		$dwellers = $this->root_dweller_types();
		foreach ( array_diff( $enabled_root, $dwellers ) as $type ) {
			/* translators: %s: post type name and slug. */
			$errors[] = sprintf( __( '%s no longer lives at the site root.', 'post-404-shield' ), $this->entry_label( $type, [ 'post_type' => $type ] ) );
		}
		foreach ( array_diff( $dwellers, $enabled_root ) as $type ) {
			/* translators: %s: post type name and slug. */
			$errors[] = sprintf( __( '%s now lives at the site root but is not shielded there.', 'post-404-shield' ), $this->entry_label( $type, [ 'post_type' => $type ] ) );
		}
		// Only the part about root mode: a based entry sharing a root type. Two
		// based entries disagreeing on their format is a problem of theirs.
		return array_merge( $errors, $this->shared_type_errors( $entries, true ) );
	}

	/**
	 * Whether the stored snapshot — the floor, the WordPress-derived bases,
	 * the rewrite endpoints and the routes reserved under each base — differs
	 * from what would be taken now (a permalink, category or tag base, a
	 * plugin's rewrite rules, or this plugin's own floor changed since the
	 * last save).
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return bool
	 */
	public function snapshot_is_stale( array $config ): bool {
		$stored = (array) ( $config['excluded_bases'] ?? [] );
		$live   = $this->excluded_bases_snapshot( (array) ( $stored['operator'] ?? [] ), self::locale_pattern_of( $config ), (array) ( $stored['endpoints'] ?? [] ) );
		// A failed redirect read keeps the stored derivations (take_snapshots()):
		// judge staleness by the same rule, or the snapshot never matches.
		if ( $this->redirect_read_failed ) {
			$live['derived'] = array_values( array_unique( array_merge( (array) $live['derived'], array_filter( (array) ( $stored['derived'] ?? [] ), 'is_string' ) ) ) );
		}
		// With no root entry switched on the root stage never runs: only the
		// endpoints (based matching strips them too) and the reserved slugs
		// are read. Kept-off root rows still record a moved Posts base.
		$root_rows = self::has_root_rows( (array) ( $config['entries'] ?? [] ) );
		if ( $root_rows && array_key_exists( 'post_base', $stored ) && $stored['post_base'] !== $live['post_base'] ) {
			return true; // The permalink post base moved.
		}
		foreach ( self::enabled_root_rows( (array) ( $config['entries'] ?? [] ) ) ? [ 'floor', 'derived', 'endpoints' ] : [ 'endpoints' ] as $bucket ) {
			$a = array_values( array_filter( (array) ( $stored[ $bucket ] ?? [] ), 'is_string' ) );
			$b = array_values( (array) $live[ $bucket ] );
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				return true;
			}
		}
		// Reserved slugs derived now — from redirects and routes — that the
		// entries do not carry, or that they carry and nothing derives any
		// more: a new rewrite rule, a redirect added or gone, or a change in
		// how they are derived (which a fingerprint of the sources cannot see).
		// After a failed redirect read the stored ones are kept (the union),
		// so only a missing slug counts.
		$entries = (array) ( $config['entries'] ?? [] );
		foreach ( $this->apply_derived_reserved( $entries, self::locale_pattern_of( $config ) ) as $key => $entry ) {
			$fresh = array_map( 'strval', (array) ( $entry['reserved_derived'] ?? [] ) );
			$held  = array_map( 'strval', (array) ( $entries[ $key ]['reserved_derived'] ?? [] ) );
			if ( [] !== array_diff( $fresh, $held ) || ( ! $this->redirect_read_failed && [] !== array_diff( $held, $fresh ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A config with its based `post` entry's base set to the one the current
	 * permalink structure gives posts (unchanged when there is none to set).
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return array<string, mixed>
	 */
	private function with_current_post_base( array $config ): array {
		$info = $this->post_base_info();
		if ( ! $info['supported'] || '' === $info['base'] ) {
			return $config;
		}
		foreach ( (array) ( $config['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'post' === (string) ( $entry['post_type'] ?? $key )
				&& true !== ( $entry['root'] ?? false ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' )
				&& ( $entry['url_base'] ?? null ) !== [ $info['base'] ]
			) {
				$config['entries'][ $key ]['url_base'] = [ $info['base'] ];
			}
		}
		return $config;
	}

	/**
	 * The notice for a Posts base kept in the root skip-list.
	 *
	 * @param string $old_base The base the Posts entry left.
	 *
	 * @return string
	 */
	private static function vacated_base_note( string $old_base ): string {
		return sprintf(
			/* translators: %s: the old Posts base. */
			__( 'Root mode: the Posts base moved, so /%s/ was added to the root skip-list to keep its old post links reaching WordPress\'s redirect. Remove it from Pages & posts when those links no longer matter.', 'post-404-shield' ),
			$old_base
		);
	}

	/**
	 * Whether posts have left the site root since a config shielded them
	 * there, or a snapshot found them there. Root mode must keep their old
	 * `/{slug}/` links reaching WordPress, which 301s each to the post's new
	 * address. A vacated base can be kept passing whole
	 * (with_vacated_post_base_kept()); the root cannot, so root-extras lists
	 * the posts' slugs instead. Carried from write to write until posts live
	 * at the root again.
	 *
	 * @param array<string, mixed> $config Candidate document.
	 *
	 * @return bool
	 */
	private function posts_left_the_root( array $config ): bool {
		$info = $this->post_base_info();
		if ( $info['supported'] && '' === $info['base'] ) {
			return false; // At the root again: a root Posts entry lists them.
		}
		$live = $this->artifact() ?? $this->option();
		foreach ( [ $config, $live ] as $doc ) {
			if ( is_array( $doc ) && true === ( $doc['excluded_bases']['posts_left_root'] ?? false ) ) {
				return true;
			}
		}
		if ( ! is_array( $live ) ) {
			return false;
		}
		if ( '' === ( $live['excluded_bases']['post_base'] ?? null ) ) {
			return true; // The last snapshot found posts at the root.
		}
		foreach ( (array) ( $live['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'post' === (string) ( $entry['post_type'] ?? $key ) && true === ( $entry['root'] ?? false )
				&& ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A candidate whose based `post` entry moved off bases the stored one had,
	 * with those old bases added to the operator skip-list (root mode passes
	 * them to WordPress, which 301s old post links), and the bases added.
	 *
	 * @param array<string, mixed> $option    Stored document.
	 * @param array<string, mixed> $candidate Candidate document.
	 *
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	private function with_vacated_post_base_kept( array $option, array $candidate ): array {
		// What the live document had posts under: the permalink post base its
		// snapshot recorded (posts shielded or not), and its based Posts
		// entry's bases, switched on or off (an artifact from before the
		// snapshot recorded one, or an entry the switch-off left disabled).
		$old = [];
		if ( is_string( $option['excluded_bases']['post_base'] ?? null ) && '' !== $option['excluded_bases']['post_base'] ) {
			$old[] = $option['excluded_bases']['post_base'];
		}
		foreach ( (array) ( $option['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'post' === (string) ( $entry['post_type'] ?? $key ) && true !== ( $entry['root'] ?? false )
				&& 'allowlist' === ( $entry['mode'] ?? 'allowlist' )
			) {
				$old = array_merge( $old, array_filter( (array) ( $entry['url_base'] ?? [] ), 'is_string' ) );
			}
		}
		// Where posts live now: the current permalink base, and the candidate's
		// enabled based Posts entry's bases.
		$info = $this->post_base_info();
		$now  = $info['supported'] && '' !== $info['base'] ? [ $info['base'] ] : [];
		foreach ( (array) ( $candidate['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'post' === (string) ( $entry['post_type'] ?? $key ) && true !== ( $entry['root'] ?? false )
				&& ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] )
			) {
				$now = array_merge( $now, array_filter( (array) ( $entry['url_base'] ?? [] ), 'is_string' ) );
			}
		}
		$vacated  = array_diff( array_unique( $old ), $now );
		$operator = array_values( array_filter( (array) ( $candidate['excluded_bases']['operator'] ?? [] ), 'is_string' ) );
		$added    = array_values( array_diff( $vacated, $operator ) );
		if ( [] !== $added ) {
			$candidate['excluded_bases']['operator'] = array_merge( $operator, $added );
		}
		return [ $candidate, $added ];
	}

	/**
	 * A config with its enabled based `post` entry switched off (settings
	 * kept) when the permalink structure gives posts no fixed shape, or no
	 * base at all (`/%postname%/`): posts then live at the root, and an entry
	 * still claiming the old base would 404 the pages WordPress now serves
	 * under it.
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return array<string, mixed>
	 */
	private function with_unservable_post_disabled( array $config ): array {
		$info = $this->post_base_info();
		if ( $info['supported'] && '' !== $info['base'] ) {
			return $config;
		}
		foreach ( (array) ( $config['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'post' === (string) ( $entry['post_type'] ?? $key )
				&& true !== ( $entry['root'] ?? false ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' )
				&& ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] )
			) {
				$config['entries'][ $key ]['enabled'] = false;
			}
		}
		return $config;
	}

	/**
	 * A config with every root entry switched off, settings kept.
	 *
	 * @param array<string, mixed> $config Config document.
	 *
	 * @return array<string, mixed>
	 */
	private static function root_disabled( array $config ): array {
		foreach ( (array) ( $config['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && true === ( $entry['root'] ?? false ) ) {
				$config['entries'][ $key ]['enabled'] = false;
			}
		}
		return $config;
	}

	/**
	 * Repair a missing or broken artifact from the option, or the option from
	 * the artifact.
	 *
	 * - Artifact valid, option missing (a database restored from backup):
	 *   rehydrate the option from the artifact, never let them fight.
	 * - Artifact missing or invalid, option valid: regenerate the artifact
	 *   through the full save pipeline (lock, validation, snapshots) minus root
	 *   mode's URL-walking preflight, with a one-minute backoff so concurrent
	 *   requests don't all queue on the lock. Failures are logged.
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

			// The full save pipeline, not a bare file write, for root mode too:
			// the lock and the revision check (so a heal can never publish the
			// option it read over a save that committed meanwhile), validate() (reserved namespaces, overlaps, and root
			// mode's S1 together-rule and permalink checks), the excluded-bases
			// and derived-reserved snapshots, and the atomic swap. The one step
			// skipped is root mode's S6 preflight: a heal republishes the stored
			// option — vetted by the save that wrote it, or by the operator who
			// staged it — and must not stay off over it. The coverage gate does
			// not run either: nothing changes relative to the stored option.
			$result = $this->write(
				$option,
				'self-heal (artifact was missing or invalid)',
				[
					'skip_root_preflight' => true,
					'expect_revision'     => $this->revision_of( $option ),
					'keep_warnings'       => true,
				]
			);
			// Stale: a save committed meanwhile, and wrote a valid artifact.
			if ( $result['ok'] || ! empty( $result['stale'] ) ) {
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
		// writes the artifact on the next admin request or the daily health
		// check — until then every request fails open to WordPress), or
		// afterwards with `wp post-shield config import-legacy`. Both are
		// explicit operator actions. Nothing reconfigures a site as a side
		// effect of deploying.
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
			$statuses  = \Post404Shield\effective_statuses( $settings );

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
			// A re-key must never land on another entry's key (a blocked
			// section named like the merged prefix): the later one would
			// replace it, and a type would go unshielded without a word.
			if ( $new_key !== $key && ( isset( $final[ $new_key ] ) || isset( $entries[ $new_key ] ) ) ) {
				$new_key = $key;
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
	 * Remove temp files left by a killed write — the config, every allowlist
	 * and the probe token — once they are an hour old (no write takes that
	 * long, so nothing in flight is touched). Called by the daily cron sweep.
	 *
	 * @return void
	 */
	public function sweep_tmp(): void {
		$files = array_merge(
			(array) glob( $this->artifact_dir() . '/*.tmp*' ),
			(array) glob( $this->artifact_dir() . '/*/*.tmp*' )
		);
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! \Post404Shield\is_temp_file_name( basename( $file ) ) ) {
				continue;
			}
			if ( is_file( $file ) && ( time() - (int) filemtime( $file ) ) > HOUR_IN_SECONDS ) {
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
		// The lock row is only ever read with a direct query; this drops any
		// copy another caller's get_option() made of it, nothing site-wide.
		wp_cache_delete( self::LOCK_OPTION, 'options' );
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
