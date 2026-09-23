<?php
/**
 * Keeps the shield allowlists current as posts change — via instant append only.
 *
 * Responsibilities are split in two:
 *   1. Instant protection (this controller) — when a managed post goes live, its
 *      slug is APPENDED to the allowlist file synchronously, in the same request:
 *      on publish, scheduled auto-publish, slug rename, or a PublishPress revision
 *      that renames the live post. The append skips lines already listed and
 *      writes under the list's lock, so concurrent publishes — including many
 *      translations sharing a slug — and a rebuild in flight cannot lose it.
 *   2. Authoritative cleanup (PostShieldCronController) — a DAILY full rebuild
 *      reads the DB and rewrites each file deduped, dropping stale slugs
 *      (unpublish / trash / delete / the old slug after a rename).
 *
 * There is deliberately NO per-change rebuild. The append handles the only
 * time-sensitive case (a new or renamed post being reachable). Duplicates and
 * lingering stale slugs are harmless — the loader still matches a duplicate, and
 * a stale slug merely lets WordPress load and 404 it — so cleaning them is not
 * urgent and does not justify a scheduled action per publish. The single daily
 * rebuild is both the cleanup and the backstop that heals any missed or
 * duplicated append.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Controller/PostShieldSyncController.php
 *
 * @package Post404Shield\Controller
 */

declare(strict_types=1);

namespace Post404Shield\Controller;

use Post404Shield\Library\AllowlistBuilder;

/**
 * Appends a managed post's slug the instant it goes live (publish / rename /
 * revision); the daily cron does the authoritative dedupe + removals.
 */
class PostShieldSyncController {

	/**
	 * Shared allowlist builder.
	 *
	 * @var AllowlistBuilder
	 */
	private AllowlistBuilder $builder;

	/**
	 * Managed, enabled post-type slugs.
	 *
	 * @var string[]
	 */
	private array $post_types;

	/**
	 * Posts whose URL changed in this request (slug or parent), recorded on
	 * post_updated — which fires before save_post — so the save's full-path
	 * append walks the subtree only when descendants' URLs actually moved.
	 *
	 * @var array<int, true>
	 */
	private array $moved = [];

	/**
	 * Same-type children of a post being deleted, keyed by the deleted post's
	 * ID: core then re-parents them with a direct query that fires no post
	 * hook, so their URLs change unseen (before_delete_post → after_delete_post).
	 *
	 * @var array<int, array{0: string, 1: int[]}>
	 */
	private array $orphans = [];

	/**
	 * Media of a post being deleted, keyed by its ID (root mode only).
	 *
	 * @var array<int, int[]>
	 */
	private array $orphan_media = [];

	/**
	 * Construct the sync controller for a set of managed post types.
	 *
	 * @param AllowlistBuilder $builder    Shared builder.
	 * @param string[]         $post_types Managed, enabled post-type slugs.
	 */
	public function __construct( AllowlistBuilder $builder, array $post_types ) {
		$this->builder    = $builder;
		$this->post_types = $post_types;
	}

	/**
	 * Register the post-change hooks that drive the instant append.
	 *
	 * @return void
	 */
	public function set_up(): void {
		foreach ( $this->post_types as $post_type ) {
			// Fires on any update to a managed post, including a slug rename.
			add_action( 'save_post_' . $post_type, [ $this, 'handle_saved_post' ], 10, 1 );
		}
		add_action( 'transition_post_status', [ $this, 'handle_transition_post_status' ], 10, 3 );

		// PublishPress Revisions renames the live post via a direct $wpdb->update()
		// that bypasses save_post, then fires these — with the live post ID first,
		// after cleaning the post cache. Harmless no-ops if the plugin is absent.
		add_action( 'revision_applied', [ $this, 'handle_revision_applied' ], 10, 1 );
		add_action( 'revision_published', [ $this, 'handle_revision_applied' ], 10, 1 );

		// Root mode only (root-pages v2): attachments join the root union the
		// instant they upload (S2 — their URLs are real, and status `inherit`
		// never fires transition_post_status), and a slug/parent change appends
		// the OLD address to root-extras in the same request (S3 — WordPress
		// 301s old slugs via _wp_old_slug; a pre-boot 404 there breaks real
		// redirects). Priority 20 on post_updated: after core's
		// wp_check_for_changed_slugs (12) has stored the meta.
		if ( $this->builder->has_root_entries() ) {
			add_action( 'add_attachment', [ $this, 'handle_attachment' ], 10, 1 );
			add_action( 'edit_attachment', [ $this, 'handle_attachment' ], 10, 1 );
			// Attach / Detach in the Media Library re-parents with a direct query.
			add_action( 'wp_media_attach_action', [ $this, 'handle_media_attach' ], 10, 2 );
			// Media on a draft is left out of the union (no public page yet);
			// it joins the instant its post goes live.
			add_action( 'transition_post_status', [ $this, 'handle_parent_live' ], 10, 3 );
		}

		// Children whose parent is deleted move up a level without a hook of
		// their own, and WPML moves every translation when the original moves
		// (after save_post priority 100) — both change real URLs unseen.
		add_action( 'before_delete_post', [ $this, 'handle_before_delete' ], 10, 1 );
		add_action( 'after_delete_post', [ $this, 'handle_after_delete' ], 10, 1 );
		add_action( 'save_post', [ $this, 'handle_translations_moved' ], 200, 1 );

		// Every managed type, root or based: core stores the outgoing slug in
		// `_wp_old_slug` on a rename and 301s it. This was root-only, so on a
		// based type the old address went straight to a pre-boot 404 until the
		// nightly rebuild picked the meta up. Priority 20: after core's
		// wp_check_for_changed_slugs (12) has written it.
		add_action( 'post_updated', [ $this, 'handle_post_updated' ], 20, 3 );
	}

	/**
	 * A media item was uploaded or edited — append its resolved URI and bare
	 * slug to the root-extras union so its URL never pre-boot-404s.
	 *
	 * @param int $post_id Attachment ID.
	 *
	 * @return void
	 */
	public function handle_attachment( int $post_id ): void {
		// Exactly the rebuild's lines for it (AllowlistBuilder::attachment_lines()),
		// which leave out media on a post that is not live yet: it has no page,
		// and listing it would reveal the unreleased post.
		$this->builder->append_root_extras( $this->builder->attachment_lines( null, [ $post_id ] ) );
	}

	/**
	 * Attach or Detach in the Media Library: the attachment's URL moved.
	 *
	 * @param string $action        `attach` or `detach`.
	 * @param int    $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	public function handle_media_attach( $action, $attachment_id ): void {
		$this->handle_attachment( (int) $attachment_id );
	}

	/**
	 * A post went live: append its media to the root-extras union, which leaves
	 * out media whose post is not live yet.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post being transitioned.
	 *
	 * @return void
	 */
	public function handle_parent_live( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'attachment' === $post->post_type ) {
			return;
		}
		$live = $this->builder->attachment_parent_statuses();
		if ( ! in_array( $new_status, $live, true ) || in_array( $old_status, $live, true ) ) {
			return;
		}
		$this->builder->append_root_extras( $this->builder->attachment_lines( [ (int) $post->ID ] ) );
	}

	/**
	 * A managed post is about to be deleted: note its same-type children, whose
	 * URLs change when core moves them up to its parent.
	 *
	 * @param int $post_id Post being deleted.
	 *
	 * @return void
	 */
	public function handle_before_delete( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || 'attachment' === $post_type ) {
			return;
		}
		// Root mode: core also moves the post's media to its parent — their
		// URLs change the same unseen way.
		if ( $this->builder->has_root_entries() ) {
			$media = get_children(
				[
					'post_parent' => $post_id,
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'fields'      => 'ids',
				]
			);
			if ( [] !== $media ) {
				$this->orphan_media[ $post_id ] = array_map( 'intval', (array) $media );
			}
		}
		if ( ! in_array( $post_type, $this->post_types, true ) || ! is_post_type_hierarchical( $post_type ) ) {
			return;
		}
		$children = get_children(
			[
				'post_parent' => $post_id,
				'post_type'   => $post_type,
				'post_status' => 'any',
				'fields'      => 'ids',
			]
		);
		if ( [] !== $children ) {
			$this->orphans[ $post_id ] = [ $post_type, array_map( 'intval', (array) $children ) ];
		}
	}

	/**
	 * A managed post was deleted and core moved its children up a level:
	 * append their new addresses — each child and its subtree, as for a move.
	 *
	 * @param int $post_id Deleted post ID.
	 *
	 * @return void
	 */
	public function handle_after_delete( int $post_id ): void {
		if ( isset( $this->orphan_media[ $post_id ] ) ) {
			$this->builder->append_root_extras( $this->builder->attachment_lines( null, $this->orphan_media[ $post_id ] ) );
			unset( $this->orphan_media[ $post_id ] );
		}
		if ( ! isset( $this->orphans[ $post_id ] ) ) {
			return;
		}
		[ $post_type, $children ] = $this->orphans[ $post_id ];
		unset( $this->orphans[ $post_id ] );
		foreach ( $children as $child ) {
			$this->fast_append( $child, $post_type, true );
		}
	}

	/**
	 * WPML keeps translations' parents in sync: when an original moves, it
	 * re-parents every translation with a direct query after save_post
	 * priority 100. Append each translation's new address (and subtree) too.
	 *
	 * @param int $post_id Saved post ID.
	 *
	 * @return void
	 */
	public function handle_translations_moved( int $post_id ): void {
		if ( ! isset( $this->moved[ $post_id ] ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! in_array( $post_type, $this->post_types, true ) || ! is_post_type_hierarchical( $post_type ) ) {
			return;
		}
		$element_type = 'post_' . $post_type;
		$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		if ( empty( $trid ) ) {
			return;
		}
		foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) as $translation ) {
			$translation_id = (int) ( $translation->element_id ?? 0 );
			if ( 0 !== $translation_id && $translation_id !== $post_id ) {
				$this->fast_append( $translation_id, $post_type, true );
			}
		}
	}

	/**
	 * A post was updated — when a ROOT-shielded post's slug or parent changed,
	 * its old address must keep reaching WordPress (which serves the 301 / the
	 * 404-guess redirect). Appends the old bare slug and, for nested URIs, the
	 * old address in both its old and current parent context. The nightly
	 * rebuild re-derives the authoritative set from _wp_old_slug.
	 *
	 * @param int      $post_id     Post ID.
	 * @param \WP_Post $post_after  Post after the update.
	 * @param \WP_Post $post_before Post before the update.
	 *
	 * @return void
	 */
	public function handle_post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( $post_before->post_name !== $post_after->post_name || $post_before->post_parent !== $post_after->post_parent ) {
			$this->moved[ $post_id ] = true;
			$this->remember_old_uris( $post_id, $post_after, $post_before );
		}
		if ( ! $this->builder->is_root_type( $post_after->post_type ) ) {
			$this->append_based_old_slug( $post_after, $post_before );
			return;
		}
		$slug_changed   = $post_before->post_name !== $post_after->post_name && '' !== $post_before->post_name;
		$parent_changed = $post_before->post_parent !== $post_after->post_parent;
		if ( ! $slug_changed && ! $parent_changed ) {
			return;
		}
		if ( ! $this->builder->is_shielding_status( $post_after->post_type, (string) $post_after->post_status ) ) {
			return;
		}

		$old_slug = $slug_changed ? $post_before->post_name : $post_after->post_name;
		$lines    = [ $old_slug ];

		// Old parent context: the address the post lived at before this save.
		if ( $post_before->post_parent > 0 && is_post_type_hierarchical( $post_after->post_type ) ) {
			$old_parent_uri = get_page_uri( $post_before->post_parent );
			if ( is_string( $old_parent_uri ) && '' !== $old_parent_uri ) {
				$lines[] = $old_parent_uri . '/' . $old_slug;
			}
		}
		// Current parent context (renamed in place under the same parent).
		$uri = $this->builder->uris_for( $post_after->post_type, [ $post_id ] )[ $post_id ] ?? '';
		if ( false !== strpos( $uri, '/' ) ) {
			$lines[] = substr( $uri, 0, (int) strrpos( $uri, '/' ) ) . '/' . $old_slug;
		}
		$this->builder->append_root_extras( $lines );
	}

	/**
	 * A hierarchical post moved (renamed, or re-parented): the addresses it and
	 * its live descendants just left still get WordPress's 301 — its 404 guess
	 * finds a page by name — but core records no old slug for them. Remember
	 * them (AllowlistBuilder::record_old_uris(), which the nightly rebuild
	 * reads) and append them now, in the format the type's list uses.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 *
	 * @return void
	 */
	private function remember_old_uris( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		$type = $after->post_type;
		if ( '' === $before->post_name || ! in_array( $type, $this->post_types, true ) || ! is_post_type_hierarchical( $type ) ) {
			return;
		}
		$new_uri = $this->builder->uris_for( $type, [ $post_id ] )[ $post_id ] ?? '';
		$parent  = (int) $before->post_parent;
		$old_uri = ( $parent > 0 ? ( $this->builder->uris_for( $type, [ $parent ] )[ $parent ] ?? '' ) . '/' : '' ) . $before->post_name;
		if ( '' === $new_uri || $old_uri === $new_uri || 0 === strpos( $old_uri, '/' ) ) {
			return;
		}

		$old = [];
		if ( $this->builder->is_shielding_status( $type, (string) $after->post_status ) ) {
			$old[ $post_id ] = $old_uri;
		}
		$live = [];
		foreach ( $this->descendant_statuses( $post_id, $type ) as $child_id => $child_status ) {
			if ( $this->builder->is_shielding_status( $type, $child_status ) ) {
				$live[] = $child_id;
			}
		}
		foreach ( $this->builder->uris_for( $type, $live ) as $child_id => $uri ) {
			if ( 0 === strpos( $uri, $new_uri . '/' ) ) {
				$old[ $child_id ] = $old_uri . substr( $uri, strlen( $new_uri ) );
			}
		}
		if ( [] === $old ) {
			return;
		}
		$this->builder->record_old_uris( $old );

		$lines = array_values( $old );
		if ( $this->builder->is_root_type( $type ) ) {
			$this->builder->append_root_extras( $lines );
			return;
		}
		if ( 'full-path' !== $this->builder->match_for( $type ) ) {
			$lines = array_values(
				array_filter(
					$lines,
					static function ( string $line ): bool {
						return false === strpos( $line, '/' );
					}
				)
			);
		}
		$this->builder->append_slugs( $type, $lines );
	}

	/**
	 * A BASED-type post was renamed: append its old slug to that type's own
	 * allowlist in the same request, so the 301 WordPress now serves from the
	 * old address is not pre-empted by a shield 404 while the nightly rebuild is
	 * still hours away.
	 *
	 * Mirrors what core records rather than guessing: it only stores (and
	 * redirects) an old slug for a published, non-hierarchical post, so
	 * anything else is skipped here too.
	 *
	 * @param \WP_Post $post_after  Post after the update.
	 * @param \WP_Post $post_before Post before the update.
	 *
	 * @return void
	 */
	private function append_based_old_slug( \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( ! in_array( $post_after->post_type, $this->post_types, true ) ) {
			return;
		}
		if ( '' === $post_before->post_name || $post_before->post_name === $post_after->post_name ) {
			return;
		}
		// Core records old slugs only for non-hierarchical posts, whatever
		// stray post_parent they carry (WordPress ignores it for them).
		if ( is_post_type_hierarchical( $post_after->post_type ) ) {
			return;
		}
		if ( ! $this->builder->is_shielding_status( $post_after->post_type, (string) $post_after->post_status ) ) {
			return;
		}
		$this->builder->append_slug( $post_after->post_type, $post_before->post_name );
	}

	/**
	 * A managed post was saved — a content edit or a slug rename. If it is live,
	 * append its current slug instantly so a rename is protected at once. (A
	 * content edit re-appends the same slug — a harmless duplicate the daily
	 * rebuild compacts.)
	 *
	 * @param int $post_id Saved post ID.
	 *
	 * @return void
	 */
	public function handle_saved_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$this->fast_append( $post_id, $post_type );
	}

	/**
	 * A managed post changed publish state. Appends on the transition INTO a
	 * shielded status — a publish, including a scheduled auto-publish, which fires
	 * in cron and never calls save_post. An unpublish/trash appends nothing;
	 * removing the now-stale slug is the daily rebuild's job (safe while stale).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post being transitioned.
	 *
	 * @return void
	 */
	public function handle_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, $this->post_types, true ) || $new_status === $old_status ) {
			return;
		}
		$this->fast_append( $post->ID, $post->post_type );
	}

	/**
	 * PublishPress Revisions applied/published a revision to a managed post. It can
	 * rename the live post via a direct DB write that never fires save_post or
	 * post_updated — but it cleans the post cache before firing these actions, so
	 * the current (possibly renamed) slug reads back correctly here. Append it
	 * instantly, treating it as moved since no before/after is available. Both
	 * actions pass the live post ID first.
	 *
	 * @param int $post_id Published/updated live post ID.
	 *
	 * @return void
	 */
	public function handle_revision_applied( $post_id ): void {
		$post_id   = (int) $post_id;
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		$this->fast_append( $post_id, $post_type, true );
	}

	/**
	 * Append the post's current slug if it is in a shielding status, so a
	 * just-live or just-renamed post is protected in the same request. Lines
	 * already listed are skipped, so a content-only edit writes nothing; the
	 * daily rebuild compacts anything else. A non-shielding status (draft/
	 * trash) appends nothing for the post itself; removal is the daily
	 * rebuild's job. Every caller fires after the post cache is refreshed, so
	 * the slug reads back correctly.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Managed post type.
	 * @param bool   $moved     The post's URL may have changed without a
	 *                          post_updated (a PublishPress revision).
	 *
	 * @return void
	 */
	private function fast_append( int $post_id, string $post_type, bool $moved = false ): void {
		$status = get_post_status( $post_id );
		$live   = is_string( $status ) && $this->builder->is_shielding_status( $post_type, $status );

		// full-path entries store the whole hierarchical sub-path, not the slug —
		// and a slug/parent change on a page changes EVERY descendant's URI, so
		// the affected subtree is re-appended in the same request (guard a of
		// the full-path fail-closed set; children must not 404 until the
		// nightly rebuild). Only on a move: a content edit changes no URL. The
		// walk runs even when the moved post itself is a draft or private —
		// WordPress still serves its published children at the new path.
		if ( 'full-path' === $this->builder->match_for( $post_type ) ) {
			// Root mode lists every PRIVATE root-type address in root-extras,
			// whatever the entry's statuses (staff open them at their pretty
			// URL): append those at once too.
			$root_type = $this->builder->is_root_type( $post_type );
			$ids       = $live ? [ $post_id ] : [];
			$private   = $root_type && 'private' === $status ? [ $post_id ] : [];
			$was_moved = $moved || isset( $this->moved[ $post_id ] );
			if ( $was_moved && is_post_type_hierarchical( $post_type ) ) {
				foreach ( $this->descendant_statuses( $post_id, $post_type ) as $child_id => $child_status ) {
					if ( $this->builder->is_shielding_status( $post_type, $child_status ) ) {
						$ids[] = $child_id;
					} elseif ( $root_type && 'private' === $child_status ) {
						$private[] = $child_id;
					}
				}
			}
			if ( [] === $ids && [] === $private ) {
				return;
			}
			if ( [] !== $ids ) {
				$this->builder->append_slugs( $post_type, array_values( $this->builder->uris_for( $post_type, $ids ) ) );
			}
			if ( [] !== $private ) {
				$this->builder->append_root_extras( array_values( $this->builder->uris_for( $post_type, $private ) ) );
			}
			// Root mode: the media of every moved post moves with it — its URL
			// nests under the post's.
			if ( $this->builder->has_root_entries() && $was_moved ) {
				$this->builder->append_root_extras( $this->builder->attachment_lines( array_merge( $ids, $private ) ) );
			}
			if ( $live ) {
				$this->purge_page_cache( $post_id );
			}
			return;
		}

		if ( ! $live ) {
			return;
		}
		$slug = get_post_field( 'post_name', $post_id );
		if ( is_string( $slug ) && '' !== $slug ) {
			$this->builder->append_slug( $post_type, $slug );
			$this->purge_page_cache( $post_id );
		}
	}

	/**
	 * Every descendant of a post, with its status. Raw, unfiltered reads so
	 * WPML/queries cannot hide translations from the append.
	 *
	 * Runs on save_post, so the query count is bounded rather than growing with
	 * the subtree: one query answers the common case (a leaf — no children);
	 * otherwise one read of the type's parent/status map, walked in memory.
	 * Traversal ignores status on purpose: a draft's published child still has
	 * its URI changed by a move, so the walk must pass through the draft.
	 *
	 * @param int    $post_id   Root post ID.
	 * @param string $post_type Managed post type.
	 *
	 * @return array<int, string> Descendant ID => post status.
	 */
	private function descendant_statuses( int $post_id, string $post_type ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_child = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = %s LIMIT 1",
				$post_id,
				$post_type
			)
		);
		if ( null === $has_child ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_parent <> 0",
				$post_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$children = [];
		$status   = [];
		foreach ( (array) $rows as $row ) {
			$children[ (int) $row->post_parent ][] = (int) $row->ID;
			$status[ (int) $row->ID ]              = (string) $row->post_status;
		}

		$found = [];
		$queue = [ $post_id ];
		while ( [] !== $queue ) {
			$parent = array_shift( $queue );
			foreach ( $children[ $parent ] ?? [] as $child ) {
				if ( ! isset( $found[ $child ] ) ) {
					$found[ $child ] = $status[ $child ];
					$queue[]         = $child;
				}
			}
		}

		return $found;
	}

	/**
	 * Purge the WP Engine ORIGIN page cache for a just-shielded URL.
	 *
	 * A visitor who hit the URL *before* publish got a shielded 404 that WP Engine's
	 * origin page cache (Varnish) — and the Advanced Network CDN edge — may have
	 * cached. This purges the ORIGIN for that exact path, so that when the CDN edge
	 * next revalidates the URL it gets the now-real page instead of the origin
	 * re-serving a cached 404.
	 *
	 * It deliberately does NOT purge the CDN edge itself. The drop-ins expose only a
	 * *full* CDN purge (`WpeCommon::clear_cdn_cache()` / `clear_maxcdn_cache()`, as
	 * every other plugin here calls it — no per-URL form), which is far too
	 * destructive to run on every publish. So the CDN edge is bounded instead by the
	 * short `cache_ttl` (60s): after it lapses the edge revalidates and, because we
	 * purged the origin here, self-heals to the live page. That short TTL — not a
	 * purge — is the real guarantee against a premature visit poisoning an edge PoP.
	 *
	 * Platform-native, no API keys: drives `WpeCommon::purge_varnish_cache()` through
	 * the `wpe_purge_varnish_cache_paths` filter (a targeted single-URL purge, never
	 * a site-wide flush — the by-URL purge the bundled "WP Engine Advanced Cache
	 * Options" plugin uses). No-op off WP Engine (local, CI). WP Engine also
	 * auto-purges a post's permalink on publish; this adds the non-trailing-slash
	 * form the shield 404s, and covers the PublishPress direct-DB rename that skips
	 * save_post. The `post_shield_purge_paths` action carries the same paths for a
	 * CDN that *does* offer a per-URL API.
	 *
	 * @param int $post_id Post whose permalink path should be purged.
	 *
	 * @return void
	 */
	private function purge_page_cache( int $post_id ): void {
		// get_permalink resolves the correct localised (WPML) URL for THIS post's
		// language, so each translation purges its own locale on publish.
		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return;
		}
		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}
		// Both slash forms — the shield 404s whichever exact path was requested.
		$paths = array_values( array_unique( [ trailingslashit( $path ), untrailingslashit( $path ) ] ) );

		// Extension seam: a CDN with its own purge API (e.g. a future Cloudflare
		// token) can hook this to purge the same paths. Core stays WPE-native.
		do_action( 'post_shield_purge_paths', $paths, $post_id );

		// WP Engine only — WpeCommon is platform-injected; absent locally / on CI.
		if ( [] === $paths || ! class_exists( '\WpeCommon' ) || ! method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
			return;
		}
		// REPLACE the purge list with EXACTLY our two paths — this is a targeted
		// purge of just the shielded 404 URL, NOT a site-wide or listing purge. WP
		// Engine already auto-purges the post's permalink + home + archives on this
		// same publish; we only add the non-trailing-slash form it would miss. The
		// filter output is the complete purge set (see WP Engine Advanced Cache
		// Options' purge_cache_by_path), and $paths is guaranteed non-empty above so
		// this can never degrade into a full-cache flush.
		$only_paths = static function () use ( $paths ) {
			return $paths;
		};
		add_filter( 'wpe_purge_varnish_cache_paths', $only_paths );
		try {
			\WpeCommon::purge_varnish_cache( $post_id );
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] WPE page-cache purge failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		remove_filter( 'wpe_purge_varnish_cache_paths', $only_paths );
	}
}
