<?php
/**
 * Builds and writes the post-404-shield allowlists.
 *
 * Shared by every write trigger — the sync controller (post changes), the cron
 * controller (safety net), and the CLI command — so the query + atomic write
 * live in one place. Config-driven: one allowlist of top-level slugs per managed,
 * enabled post type. (Child paths are handled by the loader's allowed_depth
 * policy, not by listing them, so only top-level slugs are stored.)
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Library/AllowlistBuilder.php
 *
 * @package Post404Shield\Library
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * Queries shielded posts and writes their slug allowlists to uploads.
 */
class AllowlistBuilder {

	/**
	 * Uploads-relative directory that holds the generated allowlists.
	 */
	private const ALLOWLIST_SUBDIR = 'post-404-shield';

	/**
	 * Managed post types, keyed by CPT name (from config/allowed-post-types.php).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $config;

	/**
	 * Construct the builder for a set of managed post types.
	 *
	 * @param array<string, array<string, mixed>> $config Managed post-type config.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Number of slugs in an allowlist file's raw contents.
	 *
	 * Counts the non-blank lines after the guard line. Counting newlines is
	 * wrong at exactly the case that matters: an empty list is written as
	 * `guard\n\n` (the guard, an empty implode, the trailing newline), which a
	 * newline count reports as one slug — hiding the empty allowlist an admin
	 * checks for after a rebuild, since an empty list makes the type fail open.
	 *
	 * @param string $raw File contents, guard line included.
	 *
	 * @return int
	 */
	public static function count_entries( string $raw ): int {
		$lines = explode( "\n", $raw );
		array_shift( $lines );

		$count = 0;
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Directory name of the root-extras union member (attachment URIs + old
	 * slugs) — NOT a post type; built only while root mode is active.
	 */
	public const ROOT_EXTRAS_DIR = 'root-extras';

	/**
	 * Rebuild the allowlist for every enabled managed post type, then reconcile
	 * uploads so a type switched off (or removed from config) self-cleans.
	 * Multiple entries sharing one CPT (via `post_type`, e.g. the category-
	 * segmented support types) collapse to ONE query and ONE file. With root
	 * mode active, the root-extras union member is rebuilt too.
	 *
	 * @return int Total slugs written across all types.
	 */
	public function rebuild_all(): int {
		$total = 0;
		foreach ( array_keys( $this->allowlist_type_map() ) as $post_type ) {
			$total += $this->rebuild_type( $post_type );
		}
		if ( $this->has_root_entries() ) {
			$total += $this->rebuild_root_extras();
		}
		$this->reconcile();
		return $total;
	}

	/**
	 * Rebuild the allowlist for a single post type (the EFFECTIVE CPT — an
	 * entry's `post_type` when set, else its key).
	 *
	 * @param string $post_type CPT name.
	 *
	 * @return int Slugs written for this type.
	 */
	public function rebuild_type( string $post_type ): int {
		// Guard: an unregistered CPT (a config `post_type`/key that no plugin
		// registers) queries nothing → empty allowlist → the base silently fails
		// open. Make it loud instead. See type_is_registered().
		if ( ! $this->type_is_registered( $post_type ) ) {
			error_log( '[post-404-shield] rebuild: post type "' . $post_type . '" is NOT registered — its allowlist will be empty and that base fails open. Fix the entry\'s post type on Settings → Post 404 Shield.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$match    = $this->match_for( $post_type );
		$statuses = $this->post_statuses_for( $post_type );

		$allow = $this->build_allowlist( $this->raw_lines( $post_type, $match, $statuses ), $match );
		$this->write_allowlist_atomically( $allow, $this->get_allowlist_file( $post_type ) );
		return count( $allow );
	}

	/**
	 * Whether an effective CPT is actually registered. The failure this catches:
	 * a config `post_type` (or a key used as the default) that no plugin
	 * registers — the query then returns nothing and the base fails open with no
	 * error. Returns true when post_type_exists() is unavailable (pre-boot / unit
	 * context) so it never false-flags outside a booted request.
	 *
	 * @param string $post_type Effective CPT name.
	 *
	 * @return bool
	 */
	public function type_is_registered( string $post_type ): bool {
		return ! function_exists( 'post_type_exists' ) || post_type_exists( $post_type );
	}

	// --- Data ---------------------------------------------------------------

	/**
	 * The enabled allowlist surface: effective CPT => queryable post statuses.
	 *
	 * The entry KEY is the CPT by default; an entry may set `post_type` to share
	 * a CPT across several entries (category-segmented URL bases like
	 * `support/compatibility/{shoes|boots|...}` — one allowlist, many bases).
	 * Statuses are the UNION across all enabled entries sharing the CPT,
	 * defaulting to `['publish']`. Disabled and mode=block entries are excluded.
	 * Pure (config-only): unit-testable in isolation.
	 *
	 * @return array<string, string[]> Effective CPT => post statuses.
	 */
	public function allowlist_type_map(): array {
		$map = [];
		foreach ( $this->config as $key => $settings ) {
			if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
				continue;
			}
			// mode=block entries have no post type and no allowlist — loader-only.
			if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$post_type = (string) ( $settings['post_type'] ?? $key );
			$statuses  = array_values(
				array_filter( (array) ( $settings['post_status'] ?? [] ), 'is_string' )
			);

			$map[ $post_type ] = array_values( array_unique( array_merge( $map[ $post_type ] ?? [], $statuses ) ) );
		}

		foreach ( $map as $post_type => $statuses ) {
			if ( [] === $statuses ) {
				$map[ $post_type ] = [ 'publish' ];
			}
		}

		return $map;
	}

	/**
	 * Resolve the queryable post statuses for an effective CPT, defaulting to
	 * `['publish']`. Non-string entries are dropped defensively (they are bound
	 * into the query).
	 *
	 * @param string $post_type Effective CPT name.
	 *
	 * @return string[]
	 */
	private function post_statuses_for( string $post_type ): array {
		return $this->allowlist_type_map()[ $post_type ] ?? [ 'publish' ];
	}

	/**
	 * The allowlist FORMAT for an effective CPT: `slug` (flat top-level slugs,
	 * the default) or `full-path` (full hierarchical paths relative to the
	 * base). Read from the first enabled entry naming the CPT.
	 *
	 * @param string $post_type Effective CPT name.
	 *
	 * @return string `slug` or `full-path`.
	 */
	public function match_for( string $post_type ): string {
		foreach ( $this->config as $key => $settings ) {
			if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
				continue;
			}
			if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			if ( (string) ( $settings['post_type'] ?? $key ) === $post_type ) {
				// A root entry is full-path BY DEFINITION — its `match` key
				// defaults full-path in the reader, so the builder must agree
				// even when the key is absent (a hand-written option), or the
				// list format and the loader's expectation diverge.
				if ( true === ( $settings['root'] ?? false ) ) {
					return 'full-path';
				}
				return 'full-path' === ( $settings['match'] ?? 'slug' ) ? 'full-path' : 'slug';
			}
		}
		return 'slug';
	}

	/**
	 * Whether the config holds at least one ENABLED root entry (root mode
	 * active). Pure (config-only).
	 *
	 * @return bool
	 */
	public function has_root_entries(): bool {
		return [] !== $this->root_types();
	}

	/**
	 * Effective CPTs of every enabled root entry (root-pages v2). Pure.
	 *
	 * @return string[]
	 */
	public function root_types(): array {
		$types = [];
		foreach ( $this->config as $key => $settings ) {
			if ( true !== ( $settings['root'] ?? false ) ) {
				continue;
			}
			if ( isset( $settings['enabled'] ) && false === $settings['enabled'] ) {
				continue;
			}
			if ( 'allowlist' !== ( $settings['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$post_type = (string) ( $settings['post_type'] ?? $key );
			if ( ! in_array( $post_type, $types, true ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}

	/**
	 * Whether an effective CPT is shielded by an enabled ROOT entry. Pure.
	 *
	 * @param string $post_type Effective CPT name.
	 *
	 * @return bool
	 */
	public function is_root_type( string $post_type ): bool {
		return in_array( $post_type, $this->root_types(), true );
	}

	/**
	 * The lines a type's allowlist would hold, straight from the database —
	 * exposed for the root preflight (S6), which measures the would-be loader
	 * decision against candidate lists WITHOUT writing anything. $statuses
	 * overrides the config-derived statuses: the preflight's PROBE corpus is
	 * always built from published content, independently of the candidate
	 * config (a misconfigured status list must shrink the ALLOWLIST it
	 * measures, never the corpus it measures WITH).
	 *
	 * @param string        $post_type Effective CPT name.
	 * @param string[]|null $statuses  Status override; null = the config's.
	 *
	 * @return string[] Validated allowlist lines.
	 */
	public function lines_for( string $post_type, ?array $statuses = null ): array {
		$match    = $this->match_for( $post_type );
		$statuses = $statuses ?? $this->post_statuses_for( $post_type );
		return array_keys( $this->build_allowlist( $this->raw_lines( $post_type, $match, $statuses ), $match ) );
	}

	/**
	 * Unvalidated allowlist lines for one type: its live slugs (or paths), plus
	 * the `_wp_old_slug` values WordPress 301s from.
	 *
	 * The single source for both rebuild_type() (what the loader reads) and
	 * lines_for() (what the root preflight measures), so the two can never
	 * disagree about what a type's allowlist contains.
	 *
	 * Root types are excluded from the old-slug merge: theirs already live in
	 * the root-extras union, and root mode is a separate, deferred track.
	 *
	 * @param string   $post_type Effective CPT name.
	 * @param string   $match     Match mode: `slug` or `full-path`.
	 * @param string[] $statuses  Post statuses to include (already validated).
	 *
	 * @return string[] Raw lines, validated later by build_allowlist().
	 */
	private function raw_lines( string $post_type, string $match, array $statuses ): array {
		$lines = 'full-path' === $match
			? $this->fetch_paths( $post_type, $statuses )
			: $this->fetch_slugs( $post_type, $statuses );

		if ( $this->is_root_type( $post_type ) ) {
			return $lines;
		}
		return array_merge( $lines, $this->fetch_old_slugs( $post_type, $statuses ) );
	}

	/**
	 * Every `_wp_old_slug` value for one based type's live posts.
	 *
	 * WordPress stores the outgoing slug on a rename and 301s it to the current
	 * one. The allowlist is built from current slugs only, so without this the
	 * shield answered every old slug with a pre-boot 404 and the redirect never
	 * fired — a rename silently broke every inbound link to the old address.
	 *
	 * Core only records old slugs for published, NON-hierarchical posts
	 * (`wp_check_for_changed_slugs()`), which are always top level. So a bare
	 * value is the right line in both slug and full-path mode, and no URI has
	 * to be resolved — one query, no `get_page_uri()` per row.
	 *
	 * Filtered on the post's CURRENT status: a trashed or drafted post's old
	 * slug is not redirected by WordPress, so it must not be allowed through.
	 *
	 * @param string   $post_type CPT name.
	 * @param string[] $statuses  Post statuses to include (already validated).
	 *
	 * @return string[] Old slugs.
	 */
	private function fetch_old_slugs( string $post_type, array $statuses ): array {
		global $wpdb;

		if ( [] === $statuses ) {
			return [];
		}
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Same shape as fetch_slugs(): bound type + statuses, constant REGEXP.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wp_old_slug'
				   AND p.post_type = %s
				   AND p.post_status IN ($status_placeholders)
				   AND p.post_parent = 0
				   AND pm.meta_value <> ''
				   AND pm.meta_value REGEXP '^[a-z0-9_-]+$'",
				array_merge( [ $post_type ], $statuses )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $slugs;
	}

	/**
	 * Whether a status is one the type shields — i.e. a post in this status belongs
	 * in the allowlist. Used by the sync controller to decide when a post has just
	 * become a live, shieldable slug.
	 *
	 * @param string $post_type Effective CPT name.
	 * @param string $status    Post status to test.
	 *
	 * @return bool
	 */
	public function is_shielding_status( string $post_type, string $status ): bool {
		return in_array( $status, $this->post_statuses_for( $post_type ), true );
	}

	/**
	 * Optimistic fast-path: append one slug to a type's EXISTING allowlist so a
	 * just-published post is protected in the very same request, before the async
	 * rebuild runs.
	 *
	 * Deliberately a pure append (`FILE_APPEND | LOCK_EX`), NOT a read-modify-write:
	 * there is no lost-update race across concurrent publishes (nothing is read
	 * first). The only cost is a possible duplicate line — harmless, since the
	 * loader still matches it, and the next daily rebuild rewrites the file
	 * deduped and compacted. No-op if the guarded file does not exist yet (the
	 * rebuild creates it) or the slug is malformed. Used for publishes and renames;
	 * removals (unpublish/trash/delete) are left to the rebuild (safe while stale:
	 * a lingering slug just lets WordPress 404 it).
	 *
	 * @param string $post_type CPT name.
	 * @param string $slug      Slug to append.
	 *
	 * @return bool True when a line was appended.
	 */
	public function append_slug( string $post_type, string $slug ): bool {
		return 1 === $this->append_slugs( $post_type, [ $slug ] );
	}

	/**
	 * Append several lines to a type's EXISTING allowlist in one write — a
	 * subtree re-append after a page move is one locked append, not one per
	 * descendant.
	 *
	 * @param string   $post_type Effective CPT name.
	 * @param string[] $slugs     Slugs (or, in full-path mode, hierarchical paths).
	 *
	 * @return int Lines appended.
	 */
	public function append_slugs( string $post_type, array $slugs ): int {
		return $this->append_slugs_to_file( $this->get_allowlist_file( $post_type ), $slugs, $this->match_for( $post_type ) );
	}

	/**
	 * Append core for one line, split out so it is unit-testable without
	 * wp_upload_dir().
	 *
	 * @param string $file       Absolute allowlist path.
	 * @param string $slug       Slug (or, in full-path mode, hierarchical path) to append.
	 * @param string $match_mode Allowlist format: `slug` or `full-path`.
	 *
	 * @return bool True when a line was appended.
	 */
	public function append_slug_to_file( string $file, string $slug, string $match_mode = 'slug' ): bool {
		return 1 === $this->append_slugs_to_file( $file, [ $slug ], $match_mode );
	}

	/**
	 * Append core: validate every line against the format's charset, then write
	 * the valid ones in a single locked append. A line that fails the charset
	 * is skipped, never written. A missing file is left to the rebuild, which
	 * creates it guarded.
	 *
	 * @param string   $file       Absolute allowlist path.
	 * @param string[] $slugs      Lines to append.
	 * @param string   $match_mode Allowlist format: `slug` or `full-path`.
	 *
	 * @return int Lines appended.
	 */
	public function append_slugs_to_file( string $file, array $slugs, string $match_mode = 'slug' ): int {
		$line_pattern = 'full-path' === $match_mode ? '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#' : '/^[a-z0-9_-]+$/';
		$lines        = [];
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( $line_pattern, $slug ) ) {
				$lines[] = $slug;
			}
		}
		if ( [] === $lines || ! is_file( $file ) ) {
			return 0;
		}
		// The file always ends in "\n", so appending "a\nb\n" keeps every slug
		// newline-wrapped for the loader's "\n{slug}\n" match.
		$written = file_put_contents( $file, implode( "\n", $lines ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		return false === $written ? 0 : count( $lines );
	}

	/**
	 * Fetch every top-level slug for a post type in the given statuses on blog 1.
	 *
	 * @param string   $post_type CPT name.
	 * @param string[] $statuses  Post statuses to include (already validated).
	 *
	 * @return string[] Raw post_name values.
	 */
	private function fetch_slugs( string $post_type, array $statuses ): array {
		global $wpdb;

		// Placeholders built from a controlled count (all values are bound).
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// Single indexed read; only the bound post type + statuses vary ($status_placeholders
		// is a controlled `%s, %s` list, all values bound), the REGEXP is a constant literal.
		// Result is written to a static file, so it is not separately object-cached.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_name FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ($status_placeholders)
				   AND post_parent = 0
				   AND post_name <> ''
				   AND post_name REGEXP '^[a-z0-9_-]+$'",
				array_merge( [ $post_type ], $statuses )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $slugs;
	}

	/**
	 * Fetch every FULL hierarchical sub-path for a post type in the given
	 * statuses — the `match: full-path` data source. IDs come from a direct SQL
	 * read (ANY parent, unlike the top-level slug query); each ID's path is then
	 * resolved via get_page_uri(), which walks post_parent on raw post data —
	 * unfiltered by WPML, so every translation's real path lands in the shared
	 * allowlist regardless of the current admin/cron language.
	 *
	 * @param string   $post_type CPT name.
	 * @param string[] $statuses  Post statuses to include (already validated).
	 *
	 * @return string[] Hierarchical paths, e.g. `parent/child`.
	 */
	private function fetch_paths( string $post_type, array $statuses ): array {
		global $wpdb;

		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status IN ($status_placeholders)
				   AND post_name <> ''",
				array_merge( [ $post_type ], $statuses )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$paths = [];
		foreach ( $ids as $id ) {
			$uri = get_page_uri( (int) $id );
			if ( is_string( $uri ) && '' !== $uri ) {
				$paths[] = $uri;
			}
		}

		return $paths;
	}

	/**
	 * Every attachment's URL line(s) for the root union (S2). Attachments are
	 * folded in automatically when root mode is on — never an operator row —
	 * because their slugs share the root namespace and their URLs are real
	 * (with an attachment-redirect SEO plugin, WordPress must receive the
	 * request to serve the 301). A parented attachment's URL is NESTED
	 * (`{parent-uri}/{slug}`), so both the resolved URI and the bare slug are
	 * written; lines outside the charset are dropped by build_allowlist() —
	 * symmetric with the root matcher, whose charset guard passes those
	 * requests to WordPress anyway (fail-open both sides).
	 *
	 * @return string[] Attachment URI + slug lines (unvalidated).
	 */
	private function fetch_attachment_lines(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT ID, post_name FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_status = 'inherit'
			   AND post_name <> ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$lines = [];
		foreach ( (array) $rows as $row ) {
			$uri = get_page_uri( (int) $row->ID );
			if ( is_string( $uri ) && '' !== $uri ) {
				$lines[] = $uri;
			}
			if ( is_string( $row->post_name ) && $row->post_name !== $uri ) {
				$lines[] = $row->post_name;
			}
		}
		return $lines;
	}

	/**
	 * Every `_wp_old_slug` line for the root union (S3): WordPress 301s renamed
	 * content off this meta, and a pre-boot 404 on an old slug would break real
	 * redirects. Each value is written bare (covers renamed posts and top-level
	 * pages) AND — for posts living at a nested URI — prefixed with the current
	 * parent path (`{parent-path}/{old-slug}`, the renamed-in-place case).
	 *
	 * @param string[] $root_types Effective CPTs of the enabled root entries.
	 *
	 * @return string[] Old-slug lines (unvalidated).
	 */
	private function fetch_old_slug_lines( array $root_types ): array {
		global $wpdb;

		if ( [] === $root_types ) {
			return [];
		}
		$type_placeholders = implode( ', ', array_fill( 0, count( $root_types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS old_slug, p.ID, p.post_type, p.post_status
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wp_old_slug'
				   AND p.post_type IN ($type_placeholders)",
				$root_types
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$lines = [];
		foreach ( (array) $rows as $row ) {
			if ( ! is_string( $row->old_slug ) || '' === $row->old_slug ) {
				continue;
			}
			if ( ! $this->is_shielding_status( (string) $row->post_type, (string) $row->post_status ) ) {
				continue;
			}
			$lines[] = $row->old_slug;
			$uri     = get_page_uri( (int) $row->ID );
			if ( is_string( $uri ) && false !== strpos( $uri, '/' ) ) {
				$lines[] = substr( $uri, 0, (int) strrpos( $uri, '/' ) ) . '/' . $row->old_slug;
			}
		}
		return $lines;
	}

	/**
	 * URIs of root-type posts in NON-shielded, human-managed statuses (drafts,
	 * pending, private, future). These are not public content, but their URLs
	 * belong to WordPress, not the shield: a logged-in editor viewing a
	 * private page (or a pretty-permalink preview) must reach WordPress, which
	 * enforces access itself — an anonymous visitor still gets WordPress's own
	 * 404. Including them also closes the publish-before-visit hazard: a
	 * scheduled/draft slug can never be edge-cached as a shield 404.
	 *
	 * @param string[] $root_types Effective CPTs of the enabled root entries.
	 *
	 * @return string[] URI + slug lines (unvalidated).
	 */
	private function fetch_unpublished_lines( array $root_types ): array {
		$lines = [];
		foreach ( $root_types as $root_type ) {
			foreach ( $this->fetch_paths( $root_type, [ 'draft', 'pending', 'private', 'future' ] ) as $uri ) {
				$lines[] = $uri;
			}
		}
		return $lines;
	}

	/**
	 * The root-extras union lines (attachments + old slugs + unpublished
	 * root-type URIs), validated — shared by the rebuild and the root
	 * preflight.
	 *
	 * @return string[]
	 */
	public function root_extras_lines(): array {
		$root_types = $this->root_types();
		$lines      = array_merge(
			$this->fetch_attachment_lines(),
			$this->fetch_old_slug_lines( $root_types ),
			$this->fetch_unpublished_lines( $root_types )
		);
		return array_keys( $this->build_allowlist( $lines, 'full-path' ) );
	}

	/**
	 * Rebuild the root-extras union member. Always writes the file — even
	 * empty — because the loader requires its PRESENCE before root matching may
	 * block anything (a missing build means an incomplete union: fail open).
	 *
	 * @return int Lines written.
	 */
	public function rebuild_root_extras(): int {
		$allow = array_fill_keys( $this->root_extras_lines(), true );
		$this->write_allowlist_atomically( $allow, $this->get_root_extras_file() );
		return count( $allow );
	}

	/**
	 * Instant-append one line to the root-extras union (a just-uploaded
	 * attachment or a just-created old slug), so the URL keeps working in the
	 * same request. Same pure-append semantics as append_slug().
	 *
	 * @param string $line URI or slug line to append.
	 *
	 * @return bool True when a line was appended.
	 */
	public function append_root_extra( string $line ): bool {
		return $this->append_slug_to_file( $this->get_root_extras_file(), $line, 'full-path' );
	}

	/**
	 * Absolute path to the root-extras allowlist file.
	 *
	 * @return string
	 */
	public function get_root_extras_file(): string {
		return $this->get_allowlist_root() . '/' . self::ROOT_EXTRAS_DIR . '/allowlist.php';
	}

	/**
	 * Build the membership map, re-filtering each line defensively.
	 *
	 * Pure: no WordPress, unit-testable in isolation. Lines are `[a-z0-9_-]`
	 * (the SQL restricts slug-mode lines the same way); the filter is repeated
	 * here so a hand-written or future data source cannot introduce a
	 * non-ASCII key into the loader map. Full-path lines allow internal
	 * slashes but never a leading/trailing slash, an empty segment or a
	 * traversal.
	 *
	 * The underscore is deliberate and asymmetric with the BASED matcher,
	 * which only recognises `[a-z0-9-]` slugs: an underscore slug under a
	 * base never matches, so it falls through to WordPress unshielded
	 * (fail-open) and its line is simply unused. The ROOT matcher does
	 * evaluate underscore segments, so for root and full-path types the line
	 * must be present or root mode would 404 a real URL.
	 *
	 * @param string[] $slugs      Raw slugs (or hierarchical paths in full-path mode).
	 * @param string   $match_mode Allowlist format: `slug` or `full-path`.
	 *
	 * @return array<string, true> Map of line => true.
	 */
	public function build_allowlist( array $slugs, string $match_mode = 'slug' ): array {
		$line_pattern = 'full-path' === $match_mode ? '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#' : '/^[a-z0-9_-]+$/';

		$clean = [];
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( $line_pattern, $slug ) ) {
				$clean[ $slug ] = true;
			}
		}
		return $clean;
	}

	// --- Filesystem ----------------------------------------------------------

	/**
	 * Absolute path to the uploads root that holds every type's allowlist dir.
	 *
	 * @return string
	 */
	public function get_allowlist_root(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::ALLOWLIST_SUBDIR;
	}

	/**
	 * Absolute path to a post type's generated allowlist file. Each type gets its
	 * own sub-directory (`post-404-shield/<type>/allowlist.php`).
	 *
	 * @param string $post_type CPT name.
	 *
	 * @return string
	 */
	public function get_allowlist_file( string $post_type ): string {
		return $this->get_allowlist_root() . '/' . $post_type . '/allowlist.php';
	}

	// --- Reconcile (self-cleaning) ------------------------------------------

	/**
	 * Remove uploads allowlist dirs for types that are no longer enabled — either
	 * switched off (`enabled => false`) or removed from config entirely — so the
	 * uploads tree self-cleans when the config changes on deploy.
	 *
	 * @return void
	 */
	public function reconcile(): void {
		$this->reconcile_dir( $this->get_allowlist_root() );
	}

	/**
	 * Reconcile a specific allowlist root against the enabled config. Split from
	 * reconcile() so it is unit-testable without wp_upload_dir().
	 *
	 * @param string $root Absolute allowlist root directory.
	 *
	 * @return void
	 */
	public function reconcile_dir( string $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$enabled = $this->enabled_types();
		$entries = scandir( $root );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			// Skip dot dirs, the root index, and the shared static-404 dir (not a
			// post type — it holds baked themed 404 pages, see Static404Baker).
			// The generated config artifact + its revisions are named explicitly as
			// documentation of intent — like probe-token.php they are root-level
			// FILES, and only directories are ever reconciled below, so this is
			// belt-and-braces rather than load-bearing.
			if ( '.' === $entry || '..' === $entry || 'index.php' === $entry || '404' === $entry
				|| 'config.php' === $entry || 1 === preg_match( '/^config-.*\.php$/', $entry )
			) {
				continue;
			}
			// The root-extras union member is not a post type: kept while root
			// mode is active, reconciled away like any other dir once it is off.
			if ( self::ROOT_EXTRAS_DIR === $entry && $this->has_root_entries() ) {
				continue;
			}
			$dir = trailingslashit( $root ) . $entry;
			if ( ! is_dir( $dir ) || isset( $enabled[ $entry ] ) ) {
				continue;
			}
			$this->remove_type_dir( $dir );
		}
	}

	/**
	 * Map of enabled managed post types (type => true).
	 *
	 * @return array<string, true>
	 */
	private function enabled_types(): array {
		return array_fill_keys( array_keys( $this->allowlist_type_map() ), true );
	}

	/**
	 * Delete a single type's allowlist directory. Conservative: only ever removes
	 * files this plugin writes (the allowlist, the hardening index, and any crash
	 * temp files), then rmdir — which no-ops if anything unexpected remains, so an
	 * unrelated file is never destroyed.
	 *
	 * @param string $dir Absolute per-type directory to remove.
	 *
	 * @return void
	 */
	private function remove_type_dir( string $dir ): void {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( 'allowlist.php' === $entry || 'index.php' === $entry || str_ends_with( $entry, '.tmp' ) ) {
				$path = trailingslashit( $dir ) . $entry;
				if ( is_file( $path ) ) {
					unlink( $path ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
				}
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
	}

	/**
	 * Write the allowlist as a flat, one-slug-per-line file behind a `<?php exit;`
	 * guard, atomically (temp file + rename) so the loader never reads a partial
	 * file. Deliberately NOT a `<?php return [...]` opcache file: opcache_invalidate
	 * is forbidden by the ruleset and unreliable across WPE's per-container FPM, so
	 * a byte-read flat file is always fresh. The guard line means a direct HTTP
	 * request to the file reveals nothing.
	 *
	 * @param array<string, true> $allow Membership map.
	 * @param string              $file  Absolute destination path.
	 *
	 * @return bool True on success.
	 */
	public function write_allowlist_atomically( array $allow, string $file ): bool {
		$dir = dirname( $file );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Harden both the per-type dir and the post-404-shield root above it.
		$this->harden_directory( $dir );
		$this->harden_directory( dirname( $dir ) );

		$content = "<?php exit; __halt_compiler(); // post-404-shield allowlist — do not edit by hand.\n"
			. implode( "\n", array_keys( $allow ) ) . "\n";

		// Named temp file in the SAME directory so rename() is an atomic,
		// same-filesystem swap. Written via file_put_contents (not tempnam, which
		// forces 0600) so umask gives the reader-readable perms the loader needs.
		$tmp = $file . '.' . getmypid() . '.tmp';
		$written = file_put_contents( $tmp, $content ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		// Short writes are not errors (see ConfigStore::write_artifact) — a
		// truncated allowlist silently 404s every slug past the cut.
		if ( false === $written || strlen( $content ) !== $written ) {
			unlink( $tmp ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
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
