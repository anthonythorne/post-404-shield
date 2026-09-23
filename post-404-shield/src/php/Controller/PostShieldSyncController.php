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
	 * Posts whose URL changed in this request (slug or parent), recorded on
	 * post_updated — which fires before save_post — so the save's full-path
	 * append walks the subtree only when descendants' URLs actually moved.
	 *
	 * @var array<int, true>
	 */
	private array $moved = [];

	/**
	 * Posts re-parented in this request (post_updated), whose WPML
	 * translations are re-parented after them.
	 *
	 * @var array<int, true>
	 */
	private array $reparented = [];

	/**
	 * Live addresses of a post and its live descendants, taken just before
	 * PublishPress Revisions applies a revision to it.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $before_revision = [];

	/**
	 * Same-type children of a post being deleted, keyed by the deleted post's
	 * ID: core then re-parents them with a direct query that fires no post
	 * hook, so their URLs change unseen (before_delete_post → after_delete_post).
	 *
	 * @var array<int, array{0: string, 1: int[]}>
	 */
	private array $orphans = [];

	/**
	 * The WPML translations of a deleted post's children, keyed by the deleted
	 * post's ID, with the addresses their subtrees had: WPML moves them to
	 * match the children with a direct query on delete_post — or, for a bulk
	 * delete, at shutdown — that fires no post hook either.
	 *
	 * @var array<int, array{0: string, 1: int[], 2: array<int, string>}>
	 */
	private array $orphan_translations = [];

	/**
	 * Media of a post being deleted, keyed by its ID (root mode only).
	 *
	 * @var array<int, int[]>
	 */
	private array $orphan_media = [];

	/**
	 * Per post type, its parent → children and child → status maps, read by
	 * descendant_statuses() and dropped when a post of the type changes parent
	 * or status.
	 *
	 * @var array<string, array{0: array<int, int[]>, 1: array<int, string>}>
	 */
	private array $family = [];

	/**
	 * Addresses a deleted post's subtree had, or a re-parented post's WPML
	 * translations' subtrees had, before core or WPML moved them with a
	 * query no hook sees — compared after the move for the old addresses.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $moving = [];

	/**
	 * Construct the sync controller.
	 *
	 * @param AllowlistBuilder $builder Shared builder, following the live artifact.
	 */
	public function __construct( AllowlistBuilder $builder ) {
		$this->builder = $builder;
	}

	/**
	 * Whether a post type is shielded right now — asked when a hook fires, not
	 * when the request started, since a save elsewhere can change it.
	 *
	 * @param string $post_type Post type.
	 *
	 * @return bool
	 */
	private function managed( string $post_type ): bool {
		return isset( $this->builder->allowlist_type_map()[ $post_type ] );
	}

	/**
	 * Register the post-change hooks that drive the instant append.
	 *
	 * @return void
	 */
	public function set_up(): void {
		// Fires on any update to a post, including a slug rename. Every type is
		// hooked and filtered when it fires: a type enabled by a save in another
		// request while this one runs (a long import) is managed from then on.
		add_action( 'save_post', $this->guarded( 'handle_saved_post' ), 10, 1 );
		add_action( 'transition_post_status', $this->guarded( 'handle_transition_post_status' ), 10, 3 );

		// PublishPress Revisions renames the live post via a direct $wpdb->update()
		// that bypasses save_post, then fires these — with the live post ID first,
		// after cleaning the post cache. Harmless no-ops if the plugin is absent.
		add_action( 'revision_applied', $this->guarded( 'handle_revision_applied' ), 10, 1 );
		add_action( 'revision_published', $this->guarded( 'handle_revision_applied' ), 10, 1 );
		add_filter( 'revisionary_apply_revision_data', $this->guarded( 'snapshot_before_revision' ), 10, 3 );

		// Root mode only (root-pages v2): attachments join the root union the
		// instant they upload (S2 — their URLs are real, and status `inherit`
		// never fires transition_post_status), and a slug/parent change appends
		// the OLD address to root-extras in the same request (S3 — WordPress
		// 301s old slugs via _wp_old_slug; a pre-boot 404 there breaks real
		// redirects). Priority 20 on post_updated: after core's
		// wp_check_for_changed_slugs (12) has stored the meta. Hooked always,
		// and each handler checks root mode is on when it fires.
		add_action( 'add_attachment', $this->guarded( 'handle_attachment' ), 10, 1 );
		add_action( 'edit_attachment', $this->guarded( 'handle_attachment' ), 10, 1 );
		// Attach / Detach in the Media Library re-parents with a direct query.
		add_action( 'wp_media_attach_action', $this->guarded( 'handle_media_attach' ), 10, 2 );
		add_action( 'attachment_updated', $this->guarded( 'handle_attachment_renamed' ), 10, 3 );
		// Media on a draft is left out of the union (no public page yet);
		// it joins the instant its post goes live.
		add_action( 'transition_post_status', $this->guarded( 'handle_parent_live' ), 10, 3 );

		// Children whose parent is deleted move up a level without a hook of
		// their own, and WPML moves every translation when the original moves
		// (after save_post priority 100) — both change real URLs unseen.
		add_action( 'before_delete_post', $this->guarded( 'handle_before_delete' ), 10, 1 );
		add_action( 'after_delete_post', $this->guarded( 'handle_after_delete' ), 10, 1 );
		add_action( 'save_post', $this->guarded( 'handle_translations_moved' ), 200, 1 );

		// Every managed type, root or based: core stores the outgoing slug in
		// `_wp_old_slug` on a rename and 301s it. This was root-only, so on a
		// based type the old address went straight to a pre-boot 404 until the
		// nightly rebuild picked the meta up. Priority 20: after core's
		// wp_check_for_changed_slugs (12) has written it.
		add_action( 'post_updated', $this->guarded( 'handle_post_updated' ), 20, 3 );
	}
	/**
	 * A hook callback that runs a handler and contains a failed database read
	 * or a mistyped argument: the builder's reads throw rather than build from
	 * "no rows", which suits a rebuild but would turn an optional append into
	 * a fatal inside an editor's save or a cron publish. The append is skipped
	 * (the nightly rebuild restores the line); a filter's value passes through
	 * unchanged.
	 *
	 * @param string $method Handler method name.
	 *
	 * @return \Closure
	 */
	private function guarded( string $method ): \Closure {
		$params = ( new \ReflectionMethod( $this, $method ) )->getParameters();
		return function ( ...$args ) use ( $method, $params ) {
			// This file is strict_types: coerce scalars to the handler's types
			// the way WordPress's own hook dispatch would, or a hook that passes
			// a database row's numeric-string ID (PublishPress does) fatals.
			foreach ( $params as $index => $param ) {
				$type = $param->getType();
				if ( ! array_key_exists( $index, $args ) || ! $type instanceof \ReflectionNamedType || ! $type->isBuiltin() || ! is_scalar( $args[ $index ] ) ) {
					continue;
				}
				if ( 'int' === $type->getName() && is_numeric( $args[ $index ] ) ) {
					$args[ $index ] = (int) $args[ $index ];
				} elseif ( 'string' === $type->getName() ) {
					$args[ $index ] = (string) $args[ $index ];
				}
			}
			try {
				return $this->$method( ...$args );
			} catch ( \RuntimeException | \TypeError $e ) {
				error_log( '[post-404-shield] ' . $method . ': ' . $e->getMessage() . ' — skipped; the nightly rebuild restores the list.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return $args[0] ?? null;
			}
		};
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
		if ( $this->builder->has_root_entries() ) {
			$this->builder->append_root_extras( $this->builder->attachment_lines( null, [ $post_id ] ) );
		}
		$this->append_based_media( null, [ $post_id ] );
	}

	/**
	 * Append media pages to the lists of based full-path types, whose lines
	 * are whole paths (AllowlistBuilder::type_media_lines()).
	 *
	 * @param int[]|null $parent_ids Only these parents' media; null for any.
	 * @param int[]|null $ids        Only these attachments; null for any.
	 * @param string     $old_name   A renamed attachment's former slug: its old page instead.
	 *
	 * @return void
	 */
	private function append_based_media( ?array $parent_ids, ?array $ids, string $old_name = '' ): void {
		foreach ( $this->builder->based_media_lines( $parent_ids, $ids ) as $type => $lines ) {
			if ( '' !== $old_name ) {
				$lines = array_map(
					static function ( string $line ) use ( $old_name ): string {
						return substr( $line, 0, (int) strrpos( $line, '/' ) + 1 ) . $old_name;
					},
					$lines
				);
			}
			$this->builder->append_slugs( $type, $lines );
		}
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
		// Root-extras: under a root type's post, its media nest when the post
		// is in a status that type serves (or private) — the same test the
		// rebuild applies; any other post's media are listed bare, in any
		// served status.
		if ( $this->builder->has_root_entries() ) {
			$root_type = $this->builder->is_root_type( $post->post_type );
			$listed    = function ( string $status ) use ( $post, $root_type ): bool {
				return $root_type
					? $this->builder->is_shielding_status( $post->post_type, $status ) || 'private' === $status
					: in_array( $status, $this->builder->attachment_parent_statuses(), true );
			};
			if ( $listed( $new_status ) && ! $listed( $old_status ) ) {
				$this->builder->append_root_extras( $this->builder->attachment_lines( [ (int) $post->ID ] ) );
			}
		}
		// A full-path type's own list, the media of posts in ITS statuses: a
		// private post published is live to it, though not to root-extras.
		$type = $post->post_type;
		if ( $this->managed( $type ) && 'full-path' === $this->builder->match_for( $type ) && ! $this->builder->is_root_type( $type )
			&& $this->builder->is_shielding_status( $type, $new_status ) && ! $this->builder->is_shielding_status( $type, $old_status )
		) {
			$this->append_based_media( [ (int) $post->ID ], null );
		}
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
		if ( ! $this->managed( $post_type ) || ! is_post_type_hierarchical( $post_type ) ) {
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
			$this->orphans[ $post_id ]            = [ $post_type, array_map( 'intval', (array) $children ) ];
			$this->moving[ 'delete-' . $post_id ] = $this->subtree_uris( $post_type, $this->orphans[ $post_id ][1] );
			$translations                         = [];
			foreach ( $this->orphans[ $post_id ][1] as $child ) {
				foreach ( $this->translation_ids( $child, $post_type ) as $translation_id ) {
					$translations[] = $translation_id;
				}
			}
			if ( [] !== $translations ) {
				$translations                          = array_values( array_unique( $translations ) );
				$this->orphan_translations[ $post_id ] = [ $post_type, $translations, $this->subtree_uris( $post_type, $translations ) ];
			}
		}
	}

	/**
	 * The addresses of some posts and their live descendants, where they were
	 * served (the statuses a move keeps an old address for).
	 *
	 * @param string $type  Post type.
	 * @param int[]  $roots Post IDs.
	 *
	 * @return array<int, string> Post ID => address.
	 */
	private function subtree_uris( string $type, array $roots ): array {
		$ids = [];
		foreach ( $roots as $root ) {
			if ( $this->was_served( $type, (string) get_post_status( $root ) ) ) {
				$ids[] = (int) $root;
			}
			foreach ( $this->descendant_statuses( (int) $root, $type ) as $child => $child_status ) {
				if ( $this->was_served( $type, $child_status ) ) {
					$ids[] = (int) $child;
				}
			}
		}
		return [] === $ids ? [] : $this->builder->uris_for( $type, array_values( array_unique( $ids ) ) );
	}

	/**
	 * Keep the addresses posts left in a move this controller only saw the
	 * ends of (see $moving).
	 *
	 * @param string $key  The $moving key.
	 * @param string $type Post type.
	 *
	 * @return void
	 */
	private function publish_moved( string $key, string $type ): void {
		$before = $this->moving[ $key ] ?? [];
		unset( $this->moving[ $key ] );
		$old = [];
		foreach ( [] === $before ? [] : $this->builder->uris_for( $type, array_keys( $before ) ) as $id => $uri ) {
			if ( isset( $before[ $id ] ) && $before[ $id ] !== $uri ) {
				$old[ $id ] = $before[ $id ];
			}
		}
		if ( [] !== $old ) {
			$this->publish_old_uris( $type, $old );
		}
	}

	/**
	 * A post's WPML translations (their IDs, the post itself left out).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 *
	 * @return int[]
	 */
	private function translation_ids( int $post_id, string $post_type ): array {
		$element_type = 'post_' . $post_type;
		$trid         = apply_filters( 'wpml_element_trid', null, $post_id, $element_type );
		if ( empty( $trid ) ) {
			return [];
		}
		$ids = [];
		foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) as $translation ) {
			$translation_id = (int) ( $translation->element_id ?? 0 );
			if ( 0 !== $translation_id && $translation_id !== $post_id ) {
				$ids[] = $translation_id;
			}
		}
		return $ids;
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
		$this->family = []; // Core just moved the children up a level.
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
		// WordPress still 301s the addresses they left (its 404 guess finds
		// them by name), so they are kept like any move's.
		$this->publish_moved( 'delete-' . $post_id, $post_type );

		// Their translations, which WPML has moved by now — unless this is a
		// bulk delete, whose sync WPML defers to shutdown: then once more there.
		if ( isset( $this->orphan_translations[ $post_id ] ) ) {
			$this->append_moved_translations( $post_id );
			if ( ! has_action( 'shutdown', [ $this, 'handle_deferred_translation_moves' ] ) ) {
				add_action( 'shutdown', [ $this, 'handle_deferred_translation_moves' ], 20 );
			}
		}
	}

	/**
	 * The translations of a deleted post's children: append each one's
	 * address now (and its subtree), and keep every address they left.
	 *
	 * @param int $post_id Deleted post ID.
	 *
	 * @return void
	 */
	private function append_moved_translations( int $post_id ): void {
		[ $post_type, $translations, $before ] = $this->orphan_translations[ $post_id ];
		$this->family                          = [];
		foreach ( $translations as $translation_id ) {
			$this->fast_append( $translation_id, $post_type, true );
		}
		$old = [];
		foreach ( [] === $before ? [] : $this->builder->uris_for( $post_type, array_keys( $before ) ) as $id => $uri ) {
			if ( isset( $before[ $id ] ) && $before[ $id ] !== $uri ) {
				$old[ $id ] = $before[ $id ];
			}
		}
		if ( [] !== $old ) {
			$this->publish_old_uris( $post_type, $old );
		}
	}

	/**
	 * After WPML's deferred shutdown sync (a bulk delete, priority 10): the
	 * translations it moved there.
	 *
	 * @return void
	 */
	public function handle_deferred_translation_moves(): void {
		foreach ( array_keys( $this->orphan_translations ) as $post_id ) {
			try {
				$this->append_moved_translations( (int) $post_id );
			} catch ( \RuntimeException | \TypeError $e ) {
				error_log( '[post-404-shield] handle_deferred_translation_moves: ' . $e->getMessage() . ' — skipped; the nightly rebuild restores the list.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			unset( $this->orphan_translations[ $post_id ] );
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
		// WPML syncs parents, not slugs: only a re-parent moves translations.
		if ( ! isset( $this->reparented[ $post_id ] ) ) {
			return;
		}
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! $this->managed( $post_type ) || ! is_post_type_hierarchical( $post_type ) ) {
			return;
		}
		unset( $this->family[ $post_type ] ); // WPML just re-parented them.
		foreach ( $this->translation_ids( $post_id, $post_type ) as $translation_id ) {
			$this->fast_append( $translation_id, $post_type, true );
		}
		$this->publish_moved( 'wpml-' . $post_id, $post_type );
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
		if ( $post_before->post_parent !== $post_after->post_parent || $post_before->post_status !== $post_after->post_status ) {
			unset( $this->family[ $post_after->post_type ] );
		}
		if ( $post_before->post_name !== $post_after->post_name || $post_before->post_parent !== $post_after->post_parent ) {
			$this->moved[ $post_id ] = true;
			$this->remember_old_uris( $post_id, $post_after, $post_before );
		}
		if ( $post_before->post_parent !== $post_after->post_parent ) {
			$this->reparented[ $post_id ] = true;
			// WPML moves the translations after save_post: note where their
			// subtrees are now, to keep the addresses they are about to leave.
			if ( $this->managed( $post_after->post_type ) && is_post_type_hierarchical( $post_after->post_type ) ) {
				$this->moving[ 'wpml-' . $post_id ] = $this->subtree_uris( $post_after->post_type, $this->translation_ids( $post_id, $post_after->post_type ) );
			}
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
		if ( ! $this->builder->is_shielding_status( $post_after->post_type, (string) $post_after->post_status )
			|| ! $this->was_served( $post_after->post_type, (string) $post_before->post_status )
		) {
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
		if ( '' === $before->post_name || ! $this->managed( $type ) || ! is_post_type_hierarchical( $type ) ) {
			return;
		}
		$new_uri = $this->builder->uris_for( $type, [ $post_id ] )[ $post_id ] ?? '';
		$parent  = (int) $before->post_parent;
		$old_uri = ( $parent > 0 ? ( $this->builder->uris_for( $type, [ $parent ] )[ $parent ] ?? '' ) . '/' : '' ) . $before->post_name;
		if ( '' === $new_uri || $old_uri === $new_uri || 0 === strpos( $old_uri, '/' ) ) {
			return;
		}

		// The post's own old address only if it was served there: a draft or
		// scheduled slug never was, and listing it would reveal it.
		$old = [];
		if ( $this->was_served( $type, (string) $before->post_status ) && $this->builder->is_shielding_status( $type, (string) $after->post_status ) ) {
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
		$this->publish_old_uris( $type, $old );
	}

	/**
	 * Keep and append addresses posts just left (see remember_old_uris()),
	 * in the format the type's list uses.
	 *
	 * @param string             $type Post type.
	 * @param array<int, string> $old  Post ID => the address it left.
	 *
	 * @return void
	 */
	private function publish_old_uris( string $type, array $old ): void {
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
		if ( ! $this->managed( $post_after->post_type ) ) {
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
		// Core stores (and 301s) an old slug only when the post is published
		// after the update. Going private or into another status it stores
		// nothing, and listing the slug would only let an anonymous request
		// confirm it exists.
		if ( 'publish' !== $post_after->post_status || ! $this->builder->is_shielding_status( $post_after->post_type, 'publish' ) ) {
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
		if ( ! is_string( $post_type ) || ! $this->managed( $post_type ) ) {
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
		if ( $new_status !== $old_status ) {
			unset( $this->family[ $post->post_type ] );
		}
		if ( ! $this->managed( $post->post_type ) || $new_status === $old_status ) {
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
		if ( ! is_string( $post_type ) || ! $this->managed( $post_type ) ) {
			return;
		}
		// The revision rewrote the post with a query no hook saw.
		unset( $this->family[ $post_type ] );
		// Addresses the revision moved (its slug applied with a direct
		// query, so post_updated never saw it): kept like any move.
		if ( isset( $this->before_revision[ $post_id ] ) ) {
			$before = $this->before_revision[ $post_id ];
			unset( $this->before_revision[ $post_id ] );
			$old = [];
			foreach ( $this->builder->uris_for( $post_type, array_keys( $before ) ) as $id => $uri ) {
				if ( isset( $before[ $id ] ) && $before[ $id ] !== $uri ) {
					$old[ $id ] = $before[ $id ];
				}
			}
			$this->publish_old_uris( $post_type, $old );
		}
		$this->fast_append( $post_id, $post_type, true );
	}

	/**
	 * PublishPress Revisions is about to apply a revision to a live post:
	 * note its and its live descendants' addresses, which the revision's
	 * slug may change. A filter used only as a hook — the data passes
	 * through untouched.
	 *
	 * @param mixed $update    Revision data about to be written.
	 * @param mixed $revision  The revision.
	 * @param mixed $published The live post: a raw `wp_posts` row (stdClass),
	 *                         not a WP_Post — PublishPress reads it with
	 *                         $wpdb->get_row(). The database still holds the
	 *                         pre-revision slug and parent at this point.
	 *
	 * @return mixed
	 */
	public function snapshot_before_revision( $update, $revision = null, $published = null ) {
		$published = is_object( $published ) && isset( $published->ID ) ? get_post( (int) $published->ID ) : null;
		if ( $published instanceof \WP_Post && $this->managed( $published->post_type ) && is_post_type_hierarchical( $published->post_type ) ) {
			$ids = $this->builder->is_shielding_status( $published->post_type, (string) $published->post_status ) ? [ (int) $published->ID ] : [];
			foreach ( $this->descendant_statuses( (int) $published->ID, $published->post_type ) as $child_id => $child_status ) {
				if ( $this->builder->is_shielding_status( $published->post_type, $child_status ) ) {
					$ids[] = $child_id;
				}
			}
			$this->before_revision[ (int) $published->ID ] = $this->builder->uris_for( $published->post_type, $ids );
		}
		return $update;
	}

	/**
	 * Whether a post in this status was served at its address before: a
	 * shielded status, or private for a root type (staff open it there).
	 *
	 * @param string $type   Post type.
	 * @param string $status Status before the update.
	 *
	 * @return bool
	 */
	private function was_served( string $type, string $status ): bool {
		return $this->builder->is_shielding_status( $type, $status ) || ( 'private' === $status && $this->builder->is_root_type( $type ) );
	}

	/**
	 * A media item's slug changed (Media → Edit): core 301s its old URL from
	 * `_wp_old_slug`; append the old lines at once (the rebuild keeps them).
	 *
	 * @param int      $post_id Attachment ID.
	 * @param \WP_Post $after   After the update.
	 * @param \WP_Post $before  Before the update.
	 *
	 * @return void
	 */
	public function handle_attachment_renamed( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( '' === $before->post_name || $before->post_name === $after->post_name ) {
			return;
		}
		$this->append_based_media( null, [ $post_id ], $before->post_name );
		if ( ! $this->builder->has_root_entries() ) {
			return;
		}
		$lines = [];
		foreach ( $this->builder->attachment_lines( null, [ $post_id ] ) as $line ) {
			$cut     = strrpos( $line, '/' );
			$lines[] = ( false === $cut ? '' : substr( $line, 0, $cut + 1 ) ) . $before->post_name;
		}
		$this->builder->append_root_extras( $lines );
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
			// The media of every moved post moves with it — its URL nests under
			// the post's: into root-extras in root mode, into the type's own
			// full-path list otherwise.
			if ( $this->builder->has_root_entries() && $was_moved ) {
				$this->builder->append_root_extras( $this->builder->attachment_lines( array_merge( $ids, $private ) ) );
			}
			if ( $was_moved && ! $root_type && [] !== $ids ) {
				$this->append_based_media( $ids, null );
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
		if ( ! isset( $this->family[ $post_type ] ) ) {
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

			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_parent, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_parent <> 0",
					$post_type
				)
			);
			$children = [];
			$status   = [];
			foreach ( (array) $rows as $row ) {
				$children[ (int) $row->post_parent ][] = (int) $row->ID;
				$status[ (int) $row->ID ]              = (string) $row->post_status;
			}
			// One read per type until a post of it changes parent or status: a
			// move walks the subtree several times (old addresses, the append,
			// every WPML translation), each against the same family.
			$this->family[ $post_type ] = [ $children, $status ];
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		[ $children, $status ] = $this->family[ $post_type ];

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
