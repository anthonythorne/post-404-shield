<?php
/**
 * Settings → Post 404 Shield admin page.
 *
 * The shield's edit surface. Auto-detects every public post type, lets an admin
 * enable/configure each (URL bases pre-filled from the bases its real posts'
 * permalinks use, falling back to the rewrite slug — never invented from the
 * type name, the misconfiguration class this design rules out), and on
 * Save validates everything server-side, stores the document in the
 * `post_shield_config` option and GENERATES the runtime artifact
 * (`uploads/post-404-shield/config.php`) via ConfigStore. Old artifacts rotate
 * to timestamped revisions with one-click restore behind a diff/confirm screen.
 *
 * Also keeps the original maintenance surface: a "Rebuild allowlist" button per
 * enabled type and the "Regenerate 404 pages" button, both queueing background
 * jobs over AJAX (the admin-post handlers remain as a fallback).
 *
 * The screen itself is built from WordPress components (src/js/admin.js,
 * no build step) and styled by its own src/css/admin.css. It
 * still submits an ordinary form to admin-post.php with the same field names,
 * so validation, nonces, capability checks and the root-mode acknowledgement
 * all stay server-side, exactly as before.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Controller/PostShieldAdminController.php
 *
 * @package Post404Shield\Controller
 */

declare(strict_types=1);

namespace Post404Shield\Controller;

use Post404Shield\Library\AllowlistBuilder;
use Post404Shield\Library\ConfigStore;

/**
 * Registers the settings page, the config save/restore/disable handlers, and
 * the queue-a-job button handlers.
 */
class PostShieldAdminController {

	/**
	 * Per-type rebuild event queued by the page's buttons and by config saves.
	 * Scheduled with the post type as its sole argument, so WP-Cron dedups per
	 * type.
	 */
	public const REBUILD_EVENT = 'post_shield_rebuild_type';

	/**
	 * Settings page slug.
	 */
	private const PAGE_SLUG = 'post-404-shield';

	/**
	 * The capability every screen, handler and endpoint of this page requires.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Errors raised while assembling the candidate from the posted form, before
	 * the document is well-formed enough for validate() to speak about them.
	 *
	 * @var string[]
	 */
	private $request_errors = [];

	/**
	 * Transient (per user) carrying save/restore feedback across the redirect.
	 */
	private const NOTICE_TRANSIENT = 'post_shield_admin_notice_';

	/**
	 * Shared allowlist builder.
	 *
	 * @var AllowlistBuilder
	 */
	private AllowlistBuilder $builder;

	/**
	 * Shared config store.
	 *
	 * @var ConfigStore
	 */
	private ConfigStore $store;

	/**
	 * Managed, enabled post-type slugs (effective CPTs, from the artifact).
	 *
	 * @var string[]
	 */
	private array $post_types;

	/**
	 * Enabled mode=block bases (label => url_base[]), from the artifact.
	 *
	 * @var array<string, string[]>
	 */
	private array $blocked_bases;

	/**
	 * Construct the admin controller.
	 *
	 * @param AllowlistBuilder        $builder       Shared builder.
	 * @param ConfigStore             $store         Shared config store.
	 * @param string[]                $post_types    Managed, enabled post-type slugs.
	 * @param array<string, string[]> $blocked_bases Enabled block-mode bases (label => url_base[]).
	 */
	public function __construct( AllowlistBuilder $builder, ConfigStore $store, array $post_types, array $blocked_bases = [] ) {
		$this->builder       = $builder;
		$this->store         = $store;
		$this->post_types    = $post_types;
		$this->blocked_bases = $blocked_bases;
	}

	/**
	 * Register the page, every handler, and the rebuild worker. EVERY mutating
	 * action has its own nonce + capability check.
	 *
	 * @return void
	 */
	public function set_up(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX (primary): queue + live status polling for the page's buttons.
		add_action( 'wp_ajax_post_shield_rebuild', [ $this, 'ajax_rebuild' ] );
		add_action( 'wp_ajax_post_shield_bake_404', [ $this, 'ajax_bake' ] );
		add_action( 'wp_ajax_post_shield_status', [ $this, 'ajax_status' ] );

		// admin-post (no-JS fallback): same actions via full form POST + redirect.
		add_action( 'admin_post_post_shield_rebuild', [ $this, 'handle_rebuild_request' ] );
		add_action( 'admin_post_post_shield_bake_404', [ $this, 'handle_bake_request' ] );

		// Config lifecycle actions (admin-post, one nonce each).
		add_action( 'admin_post_post_shield_save_config', [ $this, 'handle_save_config' ] );
		add_action( 'admin_post_post_shield_restore', [ $this, 'handle_restore' ] );
		add_action( 'admin_post_post_shield_disable', [ $this, 'handle_disable' ] );

		// Worker for the button-queued per-type rebuild (runs in WP-Cron).
		add_action( self::REBUILD_EVENT, [ $this, 'run_type_rebuild' ], 10, 1 );
	}

	/**
	 * Stop an admin-post handler unless the user may manage the shield.
	 *
	 * Each handler still calls check_admin_referer() itself, with its own
	 * action, on the line before: WPCS's nonce sniff only recognises a direct
	 * call in the function that reads $_POST, and the sites that vendor this
	 * plugin lint it with their own rulesets.
	 *
	 * @return void
	 */
	private function require_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'post-404-shield' ) );
		}
	}

	/**
	 * Stop an AJAX endpoint with a 403 unless the user may manage the shield.
	 * The nonce is checked inline by each endpoint, for the same reason as
	 * require_capability().
	 *
	 * @return void
	 */
	private function require_capability_json(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'post-404-shield' ) ], 403 );
		}
	}

	/**
	 * Add the page under Settings.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_options_page(
			__( 'Post 404 Shield', 'post-404-shield' ),
			__( 'Post 404 Shield', 'post-404-shield' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// --- Config save / restore / disable -------------------------------------

	/**
	 * Save the config: assemble the candidate document from the posted form,
	 * persist retention, run the ConfigStore pipeline (validate → option →
	 * rotate → artifact → prune, with the synchronous mode-switch rebuilds),
	 * queue background rebuilds for changed types and a 404 bake when none are
	 * baked yet. Validation failure = an error banner listing every failure and
	 * NOTHING persisted.
	 *
	 * @return void
	 */
	public function handle_save_config(): void {
		check_admin_referer( 'post_shield_save_config' );
		$this->require_capability();

		$previous = $this->current_document();

		// A form opened before another save (a second tab, another admin, a
		// restore or CLI write) must not silently replace it: the form carries
		// every row, so its stale copy would delete what was added since and
		// revert what was changed. Its edits are not kept — they were made
		// against settings that no longer exist.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		// An early check keeps the stale form's input from being re-shown as a
		// draft; write() repeats it under its lock, where it is authoritative.
		$revision = isset( $_POST['ps_revision'] ) ? sanitize_key( wp_unslash( $_POST['ps_revision'] ) ) : '';
		if ( $revision !== $this->store->current_revision() ) {
			$this->set_notice(
				[ __( 'The settings changed after this page was opened — another save, a restore or a CLI write. This page now shows the current settings; make your change again.', 'post-404-shield' ) ],
				[],
				false
			);
			$this->redirect_to_page();
		}

		$this->request_errors = [];
		$candidate            = $this->config_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		$draft = [
			'document'    => $candidate,
			// The blocked-section rows as posted, rejected ones included: the
			// candidate drops a row it refuses, and a draft without it would
			// delete the stored entry on the corrected resubmit.
			'blocks'      => $this->posted_block_rows(),
			'keep'        => isset( $_POST['ps_keep'] ) ? (string) (int) $_POST['ps_keep'] : (string) ConfigStore::DEFAULT_KEEP,
			'rootConfirm' => ! empty( $_POST['ps_root_confirm'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
			'revision'    => $revision,
		];
		if ( [] !== $this->request_errors ) {
			$this->set_notice( $this->request_errors, [], false, '', $draft );
			$this->redirect_to_page();
		}

		// Retention is range-checked up front so an illegal value blocks the save,
		// but it is NOT persisted until write() has succeeded — otherwise a save
		// rejected by validation or the root preflight would still have changed
		// retention behind a "nothing was saved" banner.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		$keep = isset( $_POST['ps_keep'] ) ? (int) $_POST['ps_keep'] : ConfigStore::DEFAULT_KEEP;
		if ( ! $this->store->keep_is_valid( $keep ) ) {
			$this->set_notice( [ __( 'Retention must be between 10 and 100 in steps of 10.', 'post-404-shield' ) ], [], false, '', $draft );
			$this->redirect_to_page();
		}

		// S5 — the blocking confirm: the save that turns root mode OFF → ON must
		// carry the explicit acknowledgement (a named checkbox, enforced
		// server-side — a client-side confirm alone would be bypassable).
		// Re-required every time root mode goes off → on.
		$was_root   = null !== $previous && $this->store->has_enabled_root_entries( (array) ( $previous['entries'] ?? [] ) );
		$wants_root = $this->store->has_enabled_root_entries( (array) ( $candidate['entries'] ?? [] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		if ( $wants_root && ! $was_root && empty( $_POST['ps_root_confirm'] ) ) {
			$this->set_notice(
				[
					__( 'Enabling root matching needs the acknowledgement ticked (on the Pages & posts tab): root matching makes the shield decide EVERY URL not owned by a base or exclusion. All root content types must be enabled together; attachments and old slugs are included automatically; core and plugin routes pass via the excluded-bases list. A URL not covered by any of these will be served a 404 without WordPress loading. Nothing was saved.', 'post-404-shield' ),
				],
				[],
				false,
				'',
				$draft
			);
			$this->redirect_to_page();
		}

		$result = $this->store->write(
			$candidate,
			$this->current_user_label(),
			[
				'keep'            => $keep,
				'expect_revision' => $revision,
			]
		);
		if ( ! $result['ok'] ) {
			$this->set_notice( $result['errors'], $result['warnings'], false, '', empty( $result['stale'] ) ? $draft : null );
			$this->redirect_to_page();
		}

		// Only now that the config actually landed does retention change.
		$this->store->update_keep( $keep );

		$this->queue_follow_up_jobs( $previous, $candidate );
		// The "switched off automatically" notice stays until root matching is
		// back on: its root settings are kept by every save until then.
		if ( $wants_root ) {
			delete_option( \Post404Shield\Library\ConfigStore::ROOT_OFF_OPTION );
		}
		$this->set_notice( [], $result['warnings'], true, __( 'Config saved — the artifact was regenerated and the shield now runs this configuration.', 'post-404-shield' ) );
		$this->redirect_to_page();
	}

	/**
	 * Restore a revision (POST from the confirm screen). The stamp is strictly
	 * validated inside ConfigStore (format + realpath containment) and the
	 * payload re-runs the FULL save pipeline — fresh meta, outgoing config
	 * rotated, mode-switch rebuild ordering included.
	 *
	 * @return void
	 */
	public function handle_restore(): void {
		check_admin_referer( 'post_shield_restore' );
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		$stamp    = isset( $_POST['ps_stamp'] ) ? sanitize_text_field( wp_unslash( $_POST['ps_stamp'] ) ) : '';
		$previous = $this->current_document();

		// S5 applies to restores too: a revision that flips root mode OFF → ON
		// needs the same explicit acknowledgement (the confirm screen renders
		// the checkbox whenever the incoming revision would do that).
		$incoming   = $this->store->read_revision( $stamp );
		$was_root   = null !== $previous && $this->store->has_enabled_root_entries( (array) ( $previous['entries'] ?? [] ) );
		$wants_root = null !== $incoming && $this->store->has_enabled_root_entries( (array) ( $incoming['entries'] ?? [] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer above.
		if ( $wants_root && ! $was_root && empty( $_POST['ps_root_confirm'] ) ) {
			$this->set_notice( [ __( 'This revision turns root matching ON — restoring it needs the acknowledgement ticked on the confirm screen. Nothing was restored.', 'post-404-shield' ) ], [], false );
			$this->redirect_to_page();
		}

		$result = $this->store->restore( $stamp, $this->current_user_label() );
		if ( ! $result['ok'] ) {
			$this->set_notice( $result['errors'], $result['warnings'], false );
			$this->redirect_to_page();
		}

		$restored = $this->store->artifact();
		if ( null !== $restored ) {
			$this->queue_follow_up_jobs( $previous, $restored );
		}
		/* translators: %s: revision stamp. */
		$this->set_notice( [], $result['warnings'], true, sprintf( __( 'Revision %s restored — the outgoing config was rotated to a new revision.', 'post-404-shield' ), $stamp ) );
		$this->redirect_to_page();
	}

	/**
	 * Disable the shield: write an artifact with EVERY entry disabled — still a
	 * validated save that rotates the outgoing config, so it stays auditable
	 * and restorable.
	 *
	 * @return void
	 */
	public function handle_disable(): void {
		check_admin_referer( 'post_shield_disable' );
		$this->require_capability();

		$current = $this->current_document();
		if ( null === $current || [] === ( $current['entries'] ?? [] ) ) {
			$this->set_notice( [ __( 'Nothing to disable — no config exists yet.', 'post-404-shield' ) ], [], false );
			$this->redirect_to_page();
		}

		foreach ( $current['entries'] as $key => $entry ) {
			$current['entries'][ $key ]['enabled'] = false;
		}

		$result = $this->store->write( $current, $this->current_user_label() . ' (disable shield)' );
		if ( ! $result['ok'] ) {
			$this->set_notice( $result['errors'], $result['warnings'], false );
			$this->redirect_to_page();
		}
		$this->set_notice( [], $result['warnings'], true, __( 'Shield disabled — every entry switched off (the previous config is available as a revision).', 'post-404-shield' ) );
		$this->redirect_to_page();
	}

	/**
	 * The current editable document: the option, falling back to the artifact
	 * (they only diverge mid-heal).
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_document(): ?array {
		return $this->store->option() ?? $this->store->artifact();
	}

	/**
	 * The blocked-section repeater rows as posted, in the screen's own shape,
	 * for re-showing a rejected save — including rows the save refused.
	 *
	 * @return array<int, array{label: string, urlBase: string, enabled: bool, cacheTtl: string}>
	 */
	private function posted_block_rows(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		$posted = isset( $_POST['ps_blocks'] ) && is_array( $_POST['ps_blocks'] ) ? wp_unslash( $_POST['ps_blocks'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$rows = [];
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = [
				'label'    => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'urlBase'  => sanitize_textarea_field( (string) ( $row['url_base'] ?? '' ) ),
				'enabled'  => ! empty( $row['enabled'] ),
				'cacheTtl' => sanitize_text_field( (string) ( $row['cache_ttl'] ?? '' ) ),
			];
		}
		return $rows;
	}

	/**
	 * Merge the blocked-base repeater rows into the assembled entries.
	 *
	 * Split out of config_from_request() to keep that method under the
	 * complexity ceiling, and because the collision rule below deserves to be
	 * readable on its own.
	 *
	 * @param array<string, mixed> $new_entries Entries assembled from the type rows.
	 *
	 * @return array<string, mixed> Entries with the block rows merged in.
	 */
	private function apply_block_rows( array $new_entries ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		$posted_blocks = isset( $_POST['ps_blocks'] ) && is_array( $_POST['ps_blocks'] ) ? wp_unslash( $_POST['ps_blocks'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		foreach ( $posted_blocks as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw_label = trim( (string) ( $row['label'] ?? '' ) );
			$label     = sanitize_key( $raw_label );
			$bases     = $this->lines_from_textarea( (string) ( $row['url_base'] ?? '' ) );
			// A row with bases but no usable name is NOT a cleared row: dropping
			// it would discard what the operator typed — or delete an existing
			// block entry whose name they edited — behind a "Config saved"
			// banner. sanitize_key() keeps only a–z, 0–9, `-` and `_`, so a
			// name such as "!!!" or one in another script reduces to nothing.
			if ( '' === $label && [] !== $bases ) {
				$this->request_errors[] = '' === $raw_label
					? __( 'A blocked base has addresses but no name. Give it a name, or clear its addresses to remove it.', 'post-404-shield' )
					: sprintf(
						/* translators: %s: the name as typed. */
						__( 'Blocked base name "%s" has no usable characters. Use lowercase letters, numbers, hyphens or underscores.', 'post-404-shield' ),
						$raw_label
					);
				continue;
			}
			if ( '' === $label || [] === $bases ) {
				continue; // A cleared/blank repeater row deletes the entry.
			}
			// A blocked-base label is an ENTRY KEY. Writing it blindly would
			// silently replace a same-named post-type entry — its bases,
			// reserved slugs, statuses, depth policy and TTLs — with a blanket
			// block, turning every real URL at that base into a 404. validate()
			// cannot see it: by then only the block entry remains. Refuse here.
			if ( isset( $new_entries[ $label ] ) ) {
				$this->request_errors[] = sprintf(
					/* translators: 1: the blocked-base label that collides, 2: the entry it collides with. */
					__( 'Blocked base "%1$s" uses the same internal name as %2$s. Rename the blocked base — it would otherwise replace that entry and 404 every real URL at its base.', 'post-404-shield' ),
					$label,
					$this->store->entry_label( $label, (array) $new_entries[ $label ] )
				);
				continue;
			}
			$new_entries[ $label ] = [
				'enabled'   => ! empty( $row['enabled'] ),
				'mode'      => 'block',
				'post_type' => null,
				'url_base'  => $bases,
				'cache_ttl' => $this->int_or_null( $row['cache_ttl'] ?? '' ),
			];
		}
		return $new_entries;
	}

	/**
	 * Assemble the candidate config document from the posted form.
	 *
	 * Ordering: entries that already exist keep their position (the loader
	 * early-returns per matched base, so order is behaviour); new entries
	 * append. Type rows are included when enabled OR when an entry for that CPT
	 * already exists (so switching a type off preserves its configuration);
	 * block rows are included when label + base are non-empty — clearing a row
	 * deletes it.
	 *
	 * @return array<string, mixed>
	 */
	private function config_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller verified the nonce.
		$existing = $this->current_document();
		$entries  = is_array( $existing['entries'] ?? null ) ? $existing['entries'] : [];

		// Existing entry key per effective CPT, so re-saves keep stable keys. The
		// FIRST entry for a type is the one its row edits (as the builder reads
		// the first); any further entry for the same type — possible in a staged
		// or CLI-written config, never from this screen — is kept as it is
		// rather than dropped, since the screen has no row to show it on.
		$key_by_cpt = [];
		$extra_keys = [];
		foreach ( $entries as $key => $entry ) {
			if ( is_array( $entry ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' ) ) {
				$cpt = (string) ( $entry['post_type'] ?? $key );
				if ( isset( $key_by_cpt[ $cpt ] ) ) {
					$extra_keys[] = (string) $key;
				} else {
					$key_by_cpt[ $cpt ] = (string) $key;
				}
			}
		}

		$new_entries = [];

		// Built-in post/page derivation (requirement 1): the operator never
		// types a base for them — page is always root; post is root or based
		// per the permalink structure, and unsupported structures cannot save
		// an enabled post entry at all (validation enforces it too).
		$post_info = $this->store->post_base_info();
		$root_off  = is_array( get_option( ConfigStore::ROOT_OFF_OPTION ) );

		$posted_types = isset( $_POST['ps_types'] ) && is_array( $_POST['ps_types'] ) ? wp_unslash( $_POST['ps_types'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.
		foreach ( $posted_types as $raw_cpt => $row ) {
			$cpt = sanitize_key( (string) $raw_cpt );
			if ( '' === $cpt || ! is_array( $row ) ) {
				continue;
			}
			$enabled = ! empty( $row['enabled'] );
			$has_old = isset( $key_by_cpt[ $cpt ] );
			if ( ! $enabled && ! $has_old ) {
				continue;
			}

			$is_root_dweller = 'page' === $cpt || ( 'post' === $cpt && $post_info['supported'] && '' === $post_info['base'] );

			$statuses = [];
			foreach ( (array) ( $row['post_status'] ?? [] ) as $status ) {
				$status = sanitize_key( (string) $status );
				if ( '' !== $status ) {
					$statuses[] = $status;
				}
			}
			$key = $has_old ? $key_by_cpt[ $cpt ] : $cpt;

			// Root entries (root-pages v2): no base, full-path matching implied,
			// no depth policy. Unticked = REMOVE (the same deletion gesture as a
			// based row with a blank base — root on/off is always a whole-set
			// decision, so residue would only confuse the S1 together-rule).
			if ( $is_root_dweller ) {
				if ( ! $enabled ) {
					// Switched off automatically (ConfigStore::revalidate_root()):
					// keep the stored root settings, as its notice promises, until
					// an operator switches root matching back on.
					if ( $root_off && $has_old && true === ( $entries[ $key ]['root'] ?? false ) ) {
						$new_entries[ $key ] = array_merge( (array) $entries[ $key ], [ 'enabled' => false ] );
					}
					continue;
				}
				$new_entries[ $key ] = [
					'enabled'            => true,
					'mode'               => 'allowlist',
					'post_type'          => $cpt,
					'root'               => true,
					'url_base'           => [],
					'reserved_allowlist' => $this->lines_from_textarea( (string) ( $row['reserved'] ?? '' ) ),
					'post_status'        => [] !== $statuses ? array_values( array_unique( $statuses ) ) : [ 'publish' ],
					'match'              => 'full-path',
					'allow_pagination'   => ! empty( $row['allow_pagination'] ),
					'cache_ttl'          => $this->int_or_null( $row['cache_ttl'] ?? '' ),
					'edge_ttl'           => $this->int_or_null( $row['edge_ttl'] ?? '' ),
				];
				continue;
			}

			// Posts under a permalink structure the shield cannot model: the row
			// has no base field and can only be switched off, so keep the stored
			// entry — its reserved slugs, statuses and cache times — as the
			// "settings kept" badge promises, until the structure is supported
			// again. Switching it on is refused by validation.
			if ( 'post' === $cpt && ! $post_info['supported'] && $has_old ) {
				$new_entries[ $key ] = array_merge(
					(array) ( $entries[ $key ] ?? [] ),
					[
						'enabled'            => $enabled,
						'reserved_allowlist' => $this->lines_from_textarea( (string) ( $row['reserved'] ?? '' ) ),
						'post_status'        => [] !== $statuses ? array_values( array_unique( $statuses ) ) : [ 'publish' ],
						'allow_pagination'   => ! empty( $row['allow_pagination'] ),
						'cache_ttl'          => $this->int_or_null( $row['cache_ttl'] ?? '' ),
						'edge_ttl'           => $this->int_or_null( $row['edge_ttl'] ?? '' ),
					]
				);
				continue;
			}

			// The built-in post type under a static base (/blog/%postname%/):
			// the base is DERIVED, never taken from the form.
			$entry_bases = 'post' === $cpt && $post_info['supported'] && '' !== $post_info['base']
				? [ $post_info['base'] ]
				: $this->lines_from_textarea( (string) ( $row['url_base'] ?? '' ) );
			// Unticked with the base field empty = REMOVE the entry (the UI has no
			// delete button; this is the deletion gesture).
			if ( ! $enabled && [] === $entry_bases ) {
				continue;
			}
			$new_entries[ $key ] = $this->based_entry_from_row( $cpt, $row, $enabled, $entry_bases, $statuses, $has_old ? (array) ( $entries[ $key ] ?? [] ) : null );
		}

		foreach ( $extra_keys as $extra_key ) {
			$new_entries[ $extra_key ] = $entries[ $extra_key ];
		}

		$new_entries = $this->apply_block_rows( $new_entries );

		// Preserve existing order for surviving keys, append genuinely new ones.
		$ordered = [];
		foreach ( $entries as $key => $entry ) {
			if ( isset( $new_entries[ $key ] ) ) {
				$ordered[ $key ] = $new_entries[ $key ];
				unset( $new_entries[ $key ] );
			}
		}
		foreach ( $new_entries as $key => $entry ) {
			$ordered[ $key ] = $entry;
		}

		// Locale: wpml-directory recomputes its pattern server-side (it is
		// auto-detected, never user input); only `custom` takes the posted body.
		$mode = isset( $_POST['ps_locale_mode'] ) ? sanitize_key( wp_unslash( $_POST['ps_locale_mode'] ) ) : 'none';
		if ( ! in_array( $mode, [ 'none', 'wpml-directory', 'custom' ], true ) ) {
			$mode = 'none';
		}
		$pattern = '';
		if ( 'wpml-directory' === $mode ) {
			$pattern = self::wpml_directory_pattern();
		} elseif ( 'custom' === $mode ) {
			$pattern = isset( $_POST['ps_locale_pattern'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ps_locale_pattern'] ) ) ) : '';
		}
		// Globally excluded bases (root-pages v2): only the OPERATOR rows come
		// from the form — the floor is hardcoded and the derived set is
		// snapshotted from live WordPress inside ConfigStore::write().
		$operator = isset( $_POST['ps_excluded_operator'] )
			? $this->lines_from_textarea( (string) wp_unslash( $_POST['ps_excluded_operator'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- line-split + validated in ConfigStore::validate().
			: [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return [
			'version'        => 1,
			'locale'         => [
				'mode'    => $mode,
				'pattern' => $pattern,
			],
			'entries'        => $ordered,
			'excluded_bases' => [
				'floor'    => [],
				'derived'  => [],
				'operator' => $operator,
			],
		];
	}

	/**
	 * A based (non-root) allowlist entry from its posted row.
	 *
	 * The screen shows matching and depth only for hierarchical types — a flat
	 * type's URLs have no levels below the post. A field that was not posted
	 * keeps the entry's stored value (new entries: slug matching, no extra
	 * levels, 301 to the post), so saving never silently turns an existing
	 * depth rule into "unlimited".
	 *
	 * @param string                    $cpt         Post type.
	 * @param array<string, mixed>      $row         Posted row (unslashed).
	 * @param bool                      $enabled     Row ticked.
	 * @param string[]                  $entry_bases URL bases.
	 * @param string[]                  $statuses    Sanitised statuses.
	 * @param array<string, mixed>|null $old_entry   Stored entry, or null for a new one.
	 *
	 * @return array<string, mixed>
	 */
	private function based_entry_from_row( string $cpt, array $row, bool $enabled, array $entry_bases, array $statuses, ?array $old_entry ): array {
		$has_old   = null !== $old_entry;
		$old_entry = $old_entry ?? [];
		$match_raw = array_key_exists( 'match', $row ) ? (string) $row['match'] : (string) ( $old_entry['match'] ?? 'slug' );
		$match     = 'full-path' === $match_raw ? 'full-path' : 'slug';

		if ( array_key_exists( 'depth_allowed', $row ) ) {
			$depth_raw = trim( (string) $row['depth_allowed'] );
		} elseif ( $has_old ) {
			$depth_raw = isset( $old_entry['depth_allowed'] ) && null !== $old_entry['depth_allowed'] ? (string) (int) $old_entry['depth_allowed'] : '';
		} else {
			$depth_raw = '0';
		}
		$depth_action = $row['depth_action'] ?? ( $has_old ? ( $old_entry['depth_action'] ?? 'passthrough' ) : 'redirect' );

		return [
			'enabled'            => $enabled,
			'mode'               => 'allowlist',
			'post_type'          => $cpt,
			'url_base'           => $entry_bases,
			'reserved_allowlist' => $this->lines_from_textarea( (string) ( $row['reserved'] ?? '' ) ),
			'post_status'        => [] !== $statuses ? array_values( array_unique( $statuses ) ) : [ 'publish' ],
			'match'              => $match,
			'allow_pagination'   => ! empty( $row['allow_pagination'] ),
			'depth_allowed'      => 'full-path' === $match || '' === $depth_raw ? null : max( 0, (int) $depth_raw ),
			'depth_action'       => in_array( $depth_action, [ 'passthrough', '404', 'redirect' ], true ) ? (string) $depth_action : 'passthrough',
			'cache_ttl'          => $this->int_or_null( $row['cache_ttl'] ?? '' ),
			'edge_ttl'           => $this->int_or_null( $row['edge_ttl'] ?? '' ),
		];
	}

	/**
	 * The auto-detected WPML directory pattern: `xx-xx` language directories
	 * plus the default-language directory when it is not xx-xx-shaped (`global`
	 * here).
	 *
	 * @return string
	 */
	public static function wpml_directory_pattern(): string {
		$pattern = '[a-z]{2}-[a-z]{2}';
		$default = apply_filters( 'wpml_default_language', null );
		if ( is_string( $default ) && 1 === preg_match( '/^[a-z0-9-]+$/', $default ) && 1 !== preg_match( '/^[a-z]{2}-[a-z]{2}$/', $default ) ) {
			$pattern .= '|' . $default;
		}
		return $pattern;
	}

	/**
	 * Non-empty trimmed lines of a textarea, slashes trimmed from each end
	 * (validation rejects anything still malformed — no silent rewriting).
	 *
	 * @param string $value Raw textarea value.
	 *
	 * @return string[]
	 */
	private function lines_from_textarea( string $value ): array {
		$lines = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $value ) ?: [] as $line ) {
			$line = trim( trim( $line ), '/' );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}

	/**
	 * '' → null, anything else → non-negative int.
	 *
	 * @param mixed $value Posted value.
	 *
	 * @return int|null
	 */
	private function int_or_null( $value ): ?int {
		$value = trim( (string) $value );
		return '' === $value ? null : max( 0, (int) $value );
	}

	/**
	 * After a successful save/restore: queue a background rebuild for every
	 * enabled type whose shielding inputs changed (or that is newly enabled),
	 * and queue the themed-404 bake when no baked pages exist yet.
	 *
	 * @param array<string, mixed>|null $previous  Document before the save.
	 * @param array<string, mixed>      $candidate Document after the save.
	 *
	 * @return void
	 */
	private function queue_follow_up_jobs( ?array $previous, array $candidate ): void {
		$fingerprint = static function ( ?array $document ): array {
			$map = [];
			foreach ( (array) ( $document['entries'] ?? [] ) as $key => $entry ) {
				if ( ! is_array( $entry ) || 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) ) {
					continue;
				}
				if ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) {
					continue;
				}
				$cpt         = (string) ( $entry['post_type'] ?? $key );
				$map[ $cpt ] = wp_json_encode( [ $entry['post_status'] ?? null, $entry['match'] ?? 'slug', $entry['root'] ?? false, $entry['allow_pagination'] ?? true ] );
			}
			return $map;
		};

		$before = $fingerprint( $previous );
		$after  = $fingerprint( $candidate );
		foreach ( $after as $cpt => $print ) {
			if ( ! isset( $before[ $cpt ] ) || $before[ $cpt ] !== $print ) {
				$this->queue_rebuild( $cpt );
			}
		}

		$baked = glob( $this->builder->get_allowlist_root() . '/404/*.html' );
		if ( ! is_array( $baked ) || [] === $baked ) {
			$this->queue_bake();
		}
	}

	/**
	 * Display label for artifact meta: the human name + user ID, never a login
	 * name (the artifact is admin-visible).
	 *
	 * @return string
	 */
	private function current_user_label(): string {
		$user = wp_get_current_user();
		if ( $user instanceof \WP_User && $user->exists() ) {
			return $user->display_name . ' (#' . $user->ID . ')';
		}
		return 'unknown';
	}

	/**
	 * Stash feedback for the post-redirect render.
	 *
	 * @param string[]                  $errors   Blocking errors (nothing persisted).
	 * @param string[]                  $warnings Non-blocking warnings.
	 * @param bool                      $ok       Whether the action succeeded.
	 * @param string                    $message  Success message.
	 * @param array<string, mixed>|null $draft Rejected save's input, re-shown on the page.
	 *
	 * @return void
	 */
	private function set_notice( array $errors, array $warnings, bool $ok, string $message = '', ?array $draft = null ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			[
				'ok'       => $ok,
				'message'  => $message,
				'errors'   => $errors,
				'warnings' => $warnings,
				'draft'    => $draft,
			],
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and clear this user's one-shot notice.
	 *
	 * @return array<string, mixed>|null
	 */
	private function pull_notice(): ?array {
		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		return $notice;
	}

	/**
	 * Redirect back to the settings page and stop.
	 *
	 * @return void
	 */
	private function redirect_to_page(): void {
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	// --- Queue-a-job buttons (unchanged behaviour) -----------------------------

	/**
	 * AJAX: queue a per-type allowlist rebuild and return the queue state.
	 *
	 * @return void
	 */
	public function ajax_rebuild(): void {
		check_ajax_referer( 'post_shield_rebuild' );
		$this->require_capability_json();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Not a managed, enabled shield type.', 'post-404-shield' ) ], 400 );
		}

		$state = $this->queue_rebuild( $post_type );
		wp_send_json_success(
			[
				'state'   => $state,
				'message' => 'queued' === $state
					? __( 'Queued — rebuilding in the background…', 'post-404-shield' )
					: __( 'Already queued — waiting…', 'post-404-shield' ),
			]
		);
	}

	/**
	 * AJAX: queue the all-languages themed-404 bake and return the queue state.
	 *
	 * @return void
	 */
	public function ajax_bake(): void {
		check_ajax_referer( 'post_shield_bake_404' );
		$this->require_capability_json();

		$state = $this->queue_bake();
		wp_send_json_success(
			[
				'state'   => $state,
				'message' => 'queued' === $state
					? __( 'Queued — baking every language in the background (takes a minute or two)…', 'post-404-shield' )
					: __( 'A bake is already queued — waiting…', 'post-404-shield' ),
			]
		);
	}

	/**
	 * AJAX: report whether a queued job is still pending/running, plus the
	 * current allowlist/bake figures, so the page can poll and update in place
	 * once the job lands.
	 *
	 * @return void
	 */
	public function ajax_status(): void {
		check_ajax_referer( 'post_shield_status' );
		$this->require_capability_json();

		$subject = isset( $_GET['subject'] ) ? sanitize_key( wp_unslash( $_GET['subject'] ) ) : '';

		if ( '404' === $subject ) {
			$progress = PostShield404Controller::progress();
			$running  = $progress['active']
				|| false !== wp_next_scheduled( PostShield404Controller::BAKE_EVENT )
				|| false !== wp_next_scheduled( PostShield404Controller::BATCH_EVENT )
				|| (bool) get_transient( PostShield404Controller::LOCK_TRANSIENT_KEY );
			wp_send_json_success(
				[
					'running' => $running,
					'info'    => $this->bake_state(),
					'failed'  => $progress['failed'],
				]
			);
		}

		if ( ! in_array( $subject, $this->post_types, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown subject.', 'post-404-shield' ) ], 400 );
		}

		wp_send_json_success(
			[
				'running' => false !== wp_next_scheduled( self::REBUILD_EVENT, [ $subject ] ),
				'info'    => $this->type_info( $subject ),
			]
		);
	}

	/**
	 * No-JS fallback: queue a per-type rebuild via form POST and bounce back.
	 *
	 * @return void
	 */
	public function handle_rebuild_request(): void {
		check_admin_referer( 'post_shield_rebuild' );
		$this->require_capability();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			$this->redirect_back( 'invalid', $post_type );
		}

		$this->redirect_back( $this->queue_rebuild( $post_type ), $post_type );
	}

	/**
	 * No-JS fallback: queue the themed-404 bake via form POST and bounce back.
	 *
	 * @return void
	 */
	public function handle_bake_request(): void {
		check_admin_referer( 'post_shield_bake_404' );
		$this->require_capability();

		$this->redirect_back( $this->queue_bake(), '404' );
	}

	/**
	 * Queue a single per-type rebuild, deduped.
	 *
	 * @param string $post_type Managed post type.
	 *
	 * @return string `queued` or `already`.
	 */
	private function queue_rebuild( string $post_type ): string {
		if ( false !== wp_next_scheduled( self::REBUILD_EVENT, [ $post_type ] ) ) {
			return 'already';
		}
		wp_schedule_single_event( time(), self::REBUILD_EVENT, [ $post_type ] );
		return 'queued';
	}

	/**
	 * Queue the all-languages bake, deduped.
	 *
	 * @return string `queued` or `already`.
	 */
	private function queue_bake(): string {
		if ( false !== wp_next_scheduled( PostShield404Controller::BAKE_EVENT ) ) {
			return 'already';
		}
		wp_schedule_single_event( time(), PostShield404Controller::BAKE_EVENT );
		return 'queued';
	}

	/**
	 * Worker for a button-queued rebuild: rebuild exactly one type's allowlist.
	 * Membership is checked against this request's artifact snapshot — the
	 * worker runs on a LATER cron request, so a type enabled by the save that
	 * queued the job is in the snapshot by then. The atomic temp-file swap
	 * makes an overlap with the daily rebuild harmless.
	 *
	 * @param string $post_type Post type to rebuild.
	 *
	 * @return void
	 */
	public function run_type_rebuild( string $post_type ): void {
		if ( ! in_array( $post_type, $this->post_types, true ) ) {
			return;
		}
		try {
			$this->builder->rebuild_type( $post_type );
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] Rebuild for ' . $post_type . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	// --- Page render -------------------------------------------------------------

	/**
	 * Enqueue the settings screen: its stylesheet (all of the screen's styles,
	 * scoped under `.post-shield-admin`) and — except on the static
	 * restore-confirm screen — the component app with its state.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style( 'post-shield-admin', $this->asset_url( 'src/css/admin.css' ), [ 'wp-components' ], $this->asset_version( 'src/css/admin.css' ) );

		if ( '' !== $this->requested_restore_stamp() ) {
			return; // The restore-confirm screen is static markup.
		}

		wp_enqueue_script(
			'post-shield-admin',
			$this->asset_url( 'src/js/admin.js' ),
			[ 'wp-element', 'wp-components', 'wp-i18n', 'wp-a11y' ],
			$this->asset_version( 'src/js/admin.js' ),
			true
		);
		wp_set_script_translations( 'post-shield-admin', 'post-404-shield' );
		wp_add_inline_script(
			'post-shield-admin',
			'window.postShieldAdmin = ' . wp_json_encode( $this->admin_state(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
			'before'
		);
	}

	/**
	 * Public URL of a file inside this plugin.
	 *
	 * @param string $relative Plugin-relative path.
	 *
	 * @return string
	 */
	private function asset_url( string $relative ): string {
		return plugins_url( $relative, POST_SHIELD_PLUGIN_DIR . '/bootstrap.php' );
	}

	/**
	 * Cache-busting version for a plugin file (its mtime).
	 *
	 * @param string $relative Plugin-relative path.
	 *
	 * @return string
	 */
	private function asset_version( string $relative ): string {
		$file = POST_SHIELD_PLUGIN_DIR . '/' . $relative;
		return is_readable( $file ) ? (string) filemtime( $file ) : '1';
	}

	/**
	 * The revision stamp of a nonce-checked restore request, or ''.
	 *
	 * @return string
	 */
	private function requested_restore_stamp(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- wp_verify_nonce below.
		$stamp = isset( $_GET['ps_restore'] ) ? sanitize_text_field( wp_unslash( $_GET['ps_restore'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return '' !== $stamp && false !== wp_verify_nonce( $nonce, 'post_shield_restore_confirm' ) ? $stamp : '';
	}

	/**
	 * Render the settings page: the component app's mount point, or — when a
	 * restore is pending confirmation — the static diff/confirm screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$restore_stamp = $this->requested_restore_stamp();
		if ( '' !== $restore_stamp ) {
			$this->render_restore_confirm( $restore_stamp );
			return;
		}
		?>
		<div class="wrap post-shield-admin">
			<h1><?php esc_html_e( 'Post 404 Shield', 'post-404-shield' ); ?></h1>
			<hr class="wp-header-end" />
			<p class="post-shield-admin__lede">
				<?php esc_html_e( 'Stops bots from loading WordPress for made-up addresses. For each post type you switch on, the shield keeps a list of the real post names and answers anything else with the site’s normal 404 page before WordPress starts. New and renamed posts are added the moment they are saved, and every list is rebuilt nightly.', 'post-404-shield' ); ?>
			</p>
			<div id="post-shield-admin-root">
				<p class="post-shield-admin__meta"><?php esc_html_e( 'Loading…', 'post-404-shield' ); ?></p>
			</div>
			<noscript>
				<div class="post-shield-admin-notice post-shield-admin-notice--warning"><?php esc_html_e( 'This screen needs JavaScript. Turn it on in your browser to configure the shield.', 'post-404-shield' ); ?></div>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Everything the settings app renders, as one JSON-safe array. Built once
	 * per page load (it also consumes the one-shot save/restore notice).
	 *
	 * @return array<string, mixed>
	 */
	private function admin_state(): array {
		$config   = $this->store->artifact();
		$document = $this->current_document() ?? $config;

		// A rejected save comes back with the operator's input, so the form
		// shows their edits next to the errors instead of the stored settings.
		// It keeps the revision it was opened at: if another save landed in
		// between, saving the draft is refused like any stale form.
		$notice   = $this->pull_notice();
		$draft    = is_array( $notice['draft'] ?? null ) && is_array( $notice['draft']['document'] ?? null ) ? $notice['draft'] : null;
		$form_doc = null !== $draft ? (array) $draft['document'] : $document;
		$site     = $this->site_state( $form_doc );
		if ( null !== $draft ) {
			$site['keep'] = (string) ( $draft['keep'] ?? $site['keep'] );
		}

		return [
			'notices'    => $this->notices_state( $notice ),
			'status'     => $this->status_state( $config ),
			'form'       => [
				'site'             => $site,
				'types'            => $this->types_state( $form_doc, $document ),
				'blocks'           => null !== $draft && is_array( $draft['blocks'] ?? null ) ? $draft['blocks'] : $this->blocks_state( $form_doc ),
				'excludedOperator' => implode( "\n", array_filter( (array) ( $form_doc['excluded_bases']['operator'] ?? [] ), 'is_string' ) ),
				'revision'         => null !== $draft ? (string) ( $draft['revision'] ?? '' ) : $this->store->current_revision(),
				'draft'            => null !== $draft,
				'rootConfirm'      => null !== $draft && ! empty( $draft['rootConfirm'] ),
			],
			'statuses'   => $this->statuses_state(),
			'excluded'   => [
				'derived'    => array_values( $this->store->derived_excluded_bases( ConfigStore::locale_pattern_of( $document ) ) ),
				'floor'      => array_values( ConfigStore::FLOOR_EXCLUDED_BASES ),
				'rootActive' => null !== $document && $this->store->has_enabled_root_entries( (array) ( $document['entries'] ?? [] ) ),
			],
			'allowlists' => $this->allowlists_state(),
			'bake'       => $this->bake_state(),
			'revisions'  => $this->revisions_state(),
			'nonces'     => [
				'save'    => wp_create_nonce( 'post_shield_save_config' ),
				'disable' => wp_create_nonce( 'post_shield_disable' ),
				'rebuild' => wp_create_nonce( 'post_shield_rebuild' ),
				'bake'    => wp_create_nonce( 'post_shield_bake_404' ),
				'status'  => wp_create_nonce( 'post_shield_status' ),
			],
			'urls'       => [
				'adminPost' => admin_url( 'admin-post.php' ),
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'page'      => add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'options-general.php' ) ),
			],
		];
	}

	/**
	 * Save/restore/disable feedback (one-shot transient) plus the queue
	 * buttons' no-JS redirect flags, as a list of notices.
	 *
	 * @param array<string, mixed>|null $notice This user's one-shot notice (pull_notice()).
	 *
	 * @return array<int, array{status: string, message: string, list: string[]}>
	 */
	private function notices_state( ?array $notice ): array {
		$notices = [];

		$root_off = get_option( \Post404Shield\Library\ConfigStore::ROOT_OFF_OPTION );
		if ( is_array( $root_off ) ) {
			$notices[] = [
				'status'  => 'warning',
				'message' => sprintf(
					/* translators: %s: why root matching was switched off. */
					__( 'Root matching was switched off automatically because %s: the saved root settings no longer fit the site. They are kept — review them under Pages & posts and save to switch root matching back on.', 'post-404-shield' ),
					(string) ( $root_off['reason'] ?? '' )
				),
				'list'    => array_map( 'strval', (array) ( $root_off['errors'] ?? [] ) ),
			];
		}
		if ( is_array( $notice ) ) {
			if ( ! empty( $notice['errors'] ) ) {
				$notices[] = [
					'status'  => 'error',
					'message' => is_array( $notice['draft'] ?? null )
						? __( 'Nothing was saved — your changes are still on the page. Fix the problems below and save again:', 'post-404-shield' )
						: __( 'Nothing was saved:', 'post-404-shield' ),
					'list'    => array_map( 'strval', (array) $notice['errors'] ),
				];
			} elseif ( ! empty( $notice['ok'] ) ) {
				$notices[] = [
					'status'  => 'success',
					'message' => (string) $notice['message'],
					'list'    => [],
				];
			}
			foreach ( (array) ( $notice['warnings'] ?? [] ) as $warning ) {
				$notices[] = [
					'status'  => 'warning',
					'message' => (string) $warning,
					'list'    => [],
				];
			}
		}

		// Read-only display of redirect flags set by our own handlers; no state change.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['ps_status'] ) ? sanitize_key( wp_unslash( $_GET['ps_status'] ) ) : '';
		$subject = isset( $_GET['ps_subject'] ) ? sanitize_key( wp_unslash( $_GET['ps_subject'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $status ) {
			return $notices;
		}
		if ( '404' === $subject ) {
			$message = 'queued' === $status
				? __( 'Themed 404 regeneration queued for all languages — runs on the next cron tick.', 'post-404-shield' )
				: __( 'A 404 regeneration is already queued.', 'post-404-shield' );
		} elseif ( 'queued' === $status ) {
			/* translators: %s: post type slug. */
			$message = sprintf( __( 'Allowlist rebuild for %s queued — runs on the next cron tick.', 'post-404-shield' ), $subject );
		} elseif ( 'already' === $status ) {
			/* translators: %s: post type slug. */
			$message = sprintf( __( 'An allowlist rebuild for %s is already queued.', 'post-404-shield' ), $subject );
		} else {
			/* translators: %s: post type slug. */
			$message = sprintf( __( '%s is not a managed, enabled shield type.', 'post-404-shield' ), $subject );
		}
		$notices[] = [
			'status'  => 'invalid' === $status ? 'error' : 'success',
			'message' => $message,
			'list'    => [],
		];
		return $notices;
	}

	/**
	 * The status tiles: active or not (from the artifact, never the option),
	 * counts, generation meta, locale, root mode and the last preflight.
	 *
	 * @param array<string, mixed>|null $config Current artifact config.
	 *
	 * @return array<string, mixed>
	 */
	private function status_state( ?array $config ): array {
		$enabled    = 0;
		$types      = 0;
		$blocks     = 0;
		$root_types = [];
		foreach ( (array) ( $config['entries'] ?? [] ) as $entry_key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) ) {
				continue;
			}
			++$enabled;
			if ( 'block' === ( $entry['mode'] ?? 'allowlist' ) ) {
				++$blocks;
			} elseif ( true === ( $entry['root'] ?? false ) ) {
				$root_types[] = (string) ( $entry['post_type'] ?? $entry_key );
			} else {
				++$types;
			}
		}

		$stale = [] !== $root_types && null !== $config && $this->store->snapshot_is_stale( $config );

		$preflight = get_option( ConfigStore::PREFLIGHT_OPTION, null );

		return [
			'active'         => null !== $config && $enabled > 0,
			'enabledEntries' => $enabled,
			'shieldedTypes'  => $types,
			'blockedBases'   => $blocks,
			'generatedAt'    => (string) ( $config['generated_at'] ?? '' ),
			'generatedBy'    => (string) ( $config['generated_by'] ?? '' ),
			'artifact'       => $this->relative_artifact_path(),
			'localeMode'     => (string) ( $config['locale']['mode'] ?? 'none' ),
			'localePattern'  => (string) ( $config['locale']['pattern'] ?? '' ),
			'rootTypes'      => $root_types,
			'rootStale'      => $stale,
			'preflight'      => is_array( $preflight ) && isset( $preflight['at'] )
				? [
					'at'         => (string) $preflight['at'],
					'checked'    => (int) ( $preflight['checked'] ?? 0 ),
					'wouldBlock' => (int) ( $preflight['would_block'] ?? 0 ),
					'warnBlock'  => (int) ( $preflight['warn_block'] ?? 0 ),
				]
				: null,
		];
	}

	/**
	 * Site settings: the locale option and revision retention.
	 *
	 * @param array<string, mixed>|null $document Current editable document.
	 *
	 * @return array<string, mixed>
	 */
	private function site_state( ?array $document ): array {
		$wpml = defined( 'ICL_SITEPRESS_VERSION' );
		$mode = isset( $document['locale']['mode'] ) ? (string) $document['locale']['mode'] : ( $wpml ? 'wpml-directory' : 'none' );

		$languages = [];
		$langs     = apply_filters( 'wpml_active_languages', null );
		if ( is_array( $langs ) ) {
			foreach ( $langs as $lang_key => $lang ) {
				$languages[] = is_array( $lang ) && isset( $lang['code'] ) ? (string) $lang['code'] : (string) $lang_key;
			}
		}

		return [
			'localeMode'      => $mode,
			'localePattern'   => 'custom' === $mode ? (string) ( $document['locale']['pattern'] ?? '' ) : '',
			'detectedPattern' => self::wpml_directory_pattern(),
			'wpml'            => $wpml,
			'languages'       => $languages,
			'keep'            => (string) $this->store->keep(),
		];
	}

	/**
	 * Every selectable post status (not internal, not trash), with its label.
	 *
	 * @return array<int, array{name: string, label: string}>
	 */
	private function statuses_state(): array {
		$out = [];
		foreach ( get_post_stati( [ 'internal' => false ], 'objects' ) as $name => $status ) {
			if ( 'trash' === $name ) {
				continue;
			}
			$out[] = [
				'name'     => (string) $name,
				'label'    => isset( $status->label ) && is_string( $status->label ) && '' !== $status->label ? $status->label : (string) $name,
				// Only these are offered: the rest are never served at a
				// post's own address (see AllowlistBuilder::is_servable_status()).
				'servable' => AllowlistBuilder::is_servable_status( (string) $name ),
			];
		}
		return $out;
	}

	/**
	 * One row per public post type (plus any configured entry whose post type
	 * is no longer registered), carrying the entry's current settings.
	 *
	 * @param array<string, mixed>|null $document Document the form shows (a rejected save's draft, or the stored one).
	 * @param array<string, mixed>|null $stored   The stored document, when it differs from $document.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function types_state( ?array $document, ?array $stored = null ): array {
		$entry_by_cpt  = $this->allowlist_entries_by_cpt( $document );
		$stored_by_cpt = null === $stored ? $entry_by_cpt : $this->allowlist_entries_by_cpt( $stored );

		// S4 (root-pages v2): a type earns a row iff it has a REAL public rewrite
		// base OR core says it is viewable — never hand-rolled flag logic (core
		// `page` is publicly_queryable=false yet viewable; plenty of CPTs are
		// registered queryable=false with working bases; `wp_stream_alerts` has a rewrite
		// array but public=false and must NOT render). `attachment` is excluded
		// by name: Media is data entry here — root mode folds attachment slugs
		// into its union automatically instead (S2).
		$objects = [];
		foreach ( get_post_types( [], 'objects' ) as $name => $candidate_object ) {
			if ( 'attachment' === $name ) {
				continue;
			}
			$has_public_base = $candidate_object->public
				&& is_array( $candidate_object->rewrite ?? null )
				&& ! empty( $candidate_object->rewrite['slug'] );
			if ( $has_public_base || is_post_type_viewable( $candidate_object ) ) {
				$objects[ $name ] = $candidate_object;
			}
		}

		$seen = $this->seen_bases( array_keys( $objects ), ConfigStore::locale_pattern_of( $document ) );

		$rows = [];
		foreach ( $objects as $name => $type_object ) {
			$rows[] = $this->type_state( (string) $name, $type_object, $entry_by_cpt[ $name ] ?? null, $seen[ $name ] ?? [], $stored_by_cpt[ $name ] ?? null );
		}
		// Configured entries whose CPT is not currently registered still render
		// (with a warning) — restoring/deploy timing must not hide them.
		foreach ( array_diff_key( $entry_by_cpt, $objects ) as $name => $entry ) {
			$rows[] = $this->type_state( (string) $name, null, $entry, [], $stored_by_cpt[ $name ] ?? null );
		}
		return $rows;
	}

	/**
	 * A document's allowlist entries keyed by effective post type.
	 *
	 * @param array<string, mixed>|null $document Config document.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function allowlist_entries_by_cpt( ?array $document ): array {
		$by_cpt = [];
		foreach ( (array) ( $document['entries'] ?? [] ) as $key => $entry ) {
			if ( is_array( $entry ) && 'allowlist' === ( $entry['mode'] ?? 'allowlist' ) ) {
				// The first entry per type: the one its row edits.
				$by_cpt += [ (string) ( $entry['post_type'] ?? $key ) => $entry ];
			}
		}
		return $by_cpt;
	}

	/**
	 * One post type's row: what it is (hierarchical, root, registered) and the
	 * entry's editable settings with the same defaults the form always used.
	 *
	 * @param string                    $cpt         Post type name.
	 * @param \WP_Post_Type|null        $type_object Registered type object, or null.
	 * @param array<string, mixed>|null $entry       Existing config entry, or null.
	 * @param string[]                  $seen_bases  Bases found in this type's real URLs.
	 * @param array<string, mixed>|null $stored      The STORED entry — differs from $entry only
	 *                                               when a rejected save's draft is shown.
	 *
	 * @return array<string, mixed>
	 */
	private function type_state( string $cpt, ?\WP_Post_Type $type_object, ?array $entry, array $seen_bases, ?array $stored = null ): array {
		// A REAL rewrite base only — never invent one from the type name. Built-in
		// `page` (and `post` under a bare /%postname%/ structure) has no URL base:
		// its content lives at the ROOT, shielded by root mode (root-pages v2).
		$rewrite_slug = null !== $type_object && is_array( $type_object->rewrite ?? null ) && ! empty( $type_object->rewrite['slug'] ) ? (string) $type_object->rewrite['slug'] : '';

		// Pre-fill a new entry from the bases its real URLs actually use: plenty of
		// types build their permalinks outside the rewrite slug (a `product_docs`
		// rewrite slug whose posts link as `support/docs/…`), so the slug alone
		// would suggest a base no real URL has.
		$default_base = [] !== $seen_bases ? implode( "\n", $seen_bases ) : $rewrite_slug;

		// Built-in post/page derivation (requirement 1: bases come from
		// WordPress, automatically — the operator never types one).
		$is_root_dweller  = false;
		$derived_base     = '';
		$unsupported_post = false;
		if ( 'page' === $cpt ) {
			$is_root_dweller = true;
		} elseif ( 'post' === $cpt ) {
			$post_info = $this->store->post_base_info();
			if ( ! $post_info['supported'] ) {
				$unsupported_post = true;
			} elseif ( '' === $post_info['base'] ) {
				$is_root_dweller = true;
			} else {
				$derived_base = $post_info['base'];
			}
		}

		// What the page offers ($stored below) is fixed by what is STORED, so a
		// draft re-render shows the same controls the operator edited on (a flat
		// type switched from full-path to slug keeps its selector, and so posts
		// its choice).

		// A based entry for a type that now lives at the root (the permalink
		// structure dropped its base): it no longer shields anything, and it is
		// shown OFF rather than as an enabled root row, which would turn every
		// save into a root-mode switch-on. Saving removes it; switching it on
		// shields the type at the root, with Pages.
		$root_moved = $is_root_dweller && null !== $stored && true !== ( $stored['root'] ?? false );
		// Root settings kept while root matching is switched off automatically.
		$root_kept = $is_root_dweller && null !== $stored && true === ( $stored['root'] ?? false )
			&& isset( $stored['enabled'] ) && false === $stored['enabled']
			&& is_array( get_option( ConfigStore::ROOT_OFF_OPTION ) );

		return [
			'cpt'             => $cpt,
			'label'           => null !== $type_object ? (string) $type_object->labels->name : $cpt,
			'rootMoved'       => $root_moved,
			'rootKept'        => $root_kept,
			'registered'      => null !== $type_object,
			// Unknown (unregistered) types show every field rather than guess.
			'hierarchical'    => null === $type_object || (bool) $type_object->hierarchical,
			'root'            => $is_root_dweller,
			'unsupportedPost' => $unsupported_post,
			'derivedBase'     => $derived_base,
			'rewriteSlug'     => $rewrite_slug,
			'seenBases'       => array_values( $seen_bases ),
			'hasEntry'        => null !== $stored,
			'enabled'         => null !== $entry && ! ( $root_moved && $entry === $stored ) && ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ),
			'urlBase'         => isset( $entry['url_base'] ) && is_array( $entry['url_base'] ) ? implode( "\n", $entry['url_base'] ) : $default_base,
			'match'           => (string) ( $entry['match'] ?? 'slug' ),
			// Matching is offered for hierarchical types, and for any type already
			// stored as full-path (so it can be switched back). Fixed per page load:
			// the control must not vanish mid-edit when the select changes.
			'offerMatch'      => null === $type_object || (bool) $type_object->hierarchical || 'full-path' === ( $stored['match'] ?? 'slug' ),
			'allowPagination' => null === $entry || ! isset( $entry['allow_pagination'] ) || false !== $entry['allow_pagination'],
			'depthAllowed'    => isset( $entry['depth_allowed'] ) && null !== $entry['depth_allowed'] ? (string) (int) $entry['depth_allowed'] : ( null === $entry ? '0' : '' ),
			'depthAction'     => (string) ( $entry['depth_action'] ?? ( null === $entry ? 'redirect' : 'passthrough' ) ),
			'statuses'        => isset( $entry['post_status'] ) && is_array( $entry['post_status'] ) && [] !== $entry['post_status'] ? array_values( array_map( 'strval', $entry['post_status'] ) ) : [ 'publish' ],
			'reserved'        => isset( $entry['reserved_allowlist'] ) && is_array( $entry['reserved_allowlist'] ) ? implode( "\n", $entry['reserved_allowlist'] ) : '',
			// Derived on save, so a draft carries none yet: the stored ones.
			'reservedDerived' => isset( $stored['reserved_derived'] ) && is_array( $stored['reserved_derived'] ) ? array_values( array_map( 'strval', $stored['reserved_derived'] ) ) : [],
			'cacheTtl'        => isset( $entry['cache_ttl'] ) && null !== $entry['cache_ttl'] ? (string) (int) $entry['cache_ttl'] : '',
			'edgeTtl'         => isset( $entry['edge_ttl'] ) && null !== $entry['edge_ttl'] ? (string) (int) $entry['edge_ttl'] : '',
		];
	}

	/**
	 * The URL bases a sample of each type's real, top-level published posts
	 * use — the permalink minus its language directory and its slug. Cached for
	 * an hour: it is a pre-fill and a sanity hint, not a gate.
	 *
	 * @param string[] $post_types     Post types to sample.
	 * @param string   $locale_pattern Locale pattern body ('' = none).
	 *
	 * @return array<string, string[]> Post type => distinct bases.
	 */
	private function seen_bases( array $post_types, string $locale_pattern ): array {
		$cache_key = 'post_shield_seen_bases_' . md5( $locale_pattern . '|' . implode( ',', $post_types ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = [];
		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type, [ 'page', 'post' ], true ) ) {
				continue; // Derived from Settings → Permalinks instead.
			}
			// get_posts() suppresses filters by default, so WPML does not narrow
			// the sample to the admin's current language — every language counts.
			$ids   = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'post_parent'    => 0,
					'posts_per_page' => 30,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			$bases = [];
			foreach ( $ids as $post_id ) {
				$path     = trim( (string) wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH ), '/' );
				$segments = '' === $path ? [] : explode( '/', $path );
				if ( '' !== $locale_pattern && [] !== $segments && 1 === preg_match( '#^(?:' . $locale_pattern . ')$#', $segments[0] ) ) {
					array_shift( $segments );
				}
				array_pop( $segments ); // The slug itself.
				$base = implode( '/', $segments );
				if ( '' !== $base && \Post404Shield\url_base_is_valid( $base ) ) {
					$bases[ $base ] = true;
				}
			}
			$found = array_keys( $bases );
			sort( $found );
			$out[ $post_type ] = $found;
		}

		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Blocked-base rows.
	 *
	 * @param array<string, mixed>|null $document Current editable document.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks_state( ?array $document ): array {
		$rows = [];
		foreach ( (array) ( $document['entries'] ?? [] ) as $key => $entry ) {
			if ( ! is_array( $entry ) || 'block' !== ( $entry['mode'] ?? 'allowlist' ) ) {
				continue;
			}
			$rows[] = [
				'label'    => (string) $key,
				'urlBase'  => implode( "\n", (array) ( $entry['url_base'] ?? [] ) ),
				'enabled'  => ! isset( $entry['enabled'] ) || false !== $entry['enabled'],
				'cacheTtl' => isset( $entry['cache_ttl'] ) && null !== $entry['cache_ttl'] ? (string) (int) $entry['cache_ttl'] : '',
			];
		}
		return $rows;
	}

	/**
	 * Allowlist rows for the maintenance tab (enabled managed types).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function allowlists_state(): array {
		$rows = [];
		foreach ( $this->post_types as $post_type ) {
			$type_object = get_post_type_object( $post_type );
			$rows[]      = array_merge(
				[
					'postType' => $post_type,
					'label'    => null !== $type_object ? (string) $type_object->labels->name : $post_type,
				],
				$this->type_info( $post_type )
			);
		}
		return $rows;
	}

	/**
	 * A type's allowlist size and age, and whether its post type exists.
	 *
	 * @param string $post_type CPT name.
	 *
	 * @return array{entries: string, updated: string, registered: bool}
	 */
	private function type_info( string $post_type ): array {
		[ $entries, $updated ] = $this->allowlist_info( $post_type );
		return [
			'entries'    => $entries,
			'updated'    => $updated,
			'registered' => $this->builder->type_is_registered( $post_type ),
		];
	}

	/**
	 * Themed-404 bake state: page count, age, and batch progress.
	 *
	 * @return array{pages: string, baked: string, progress: array{done: int, total: int}|null}
	 */
	private function bake_state(): array {
		[ $pages, $baked ] = $this->baked_404_info();
		$progress          = PostShield404Controller::progress();
		return [
			'pages'    => $pages,
			'baked'    => $baked,
			'progress' => $progress['active']
				? [
					'done'  => (int) $progress['done'],
					'total' => (int) $progress['total'],
				]
				: null,
		];
	}

	/**
	 * Revisions, newest first, each with its nonce'd restore-confirm URL.
	 *
	 * @return array{keep: int, items: array<int, array<string, mixed>>}
	 */
	private function revisions_state(): array {
		$rows = [];
		foreach ( $this->store->revisions() as $revision ) {
			$rows[] = [
				'stamp'       => (string) $revision['stamp'],
				'valid'       => (bool) $revision['valid'],
				'generatedAt' => (string) $revision['generated_at'],
				'generatedBy' => (string) $revision['generated_by'],
				// Built with add_query_arg(), NOT wp_nonce_url(): core's
				// wp_nonce_url() returns esc_html()'d output (`&amp;`), which is
				// right for PHP-rendered markup but wrong here. This URL travels
				// as JSON into React, which sets href without decoding entities,
				// so PHP would receive `amp;ps_restore` and `amp;_wpnonce` and the
				// restore would silently render the normal page instead.
				'restoreUrl'  => $revision['valid']
					? add_query_arg(
						[
							'page'       => self::PAGE_SLUG,
							'ps_restore' => rawurlencode( (string) $revision['stamp'] ),
							'_wpnonce'   => wp_create_nonce( 'post_shield_restore_confirm' ),
						],
						admin_url( 'options-general.php' )
					)
					: '',
			];
		}
		return [
			'keep'  => $this->store->keep(),
			'items' => $rows,
		];
	}

	/**
	 * One line per entry for the restore diff: every field the loader acts on,
	 * defaults filled in and lists sorted, so a revision that only drops a
	 * status or changes a depth rule or cache time is marked as a change
	 * rather than looking like a no-op.
	 *
	 * @param array<string, mixed>|null $entry Entry, or null when absent.
	 *
	 * @return string
	 */
	private static function summarise_entry( ?array $entry ): string {
		if ( null === $entry ) {
			return '—';
		}
		$sorted  = static function ( $list ): array {
			$list = array_values( array_filter( (array) $list, 'is_string' ) );
			sort( $list );
			return $list;
		};
		$is_root = true === ( $entry['root'] ?? false );
		$parts   = [
			( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ) ? 'ON' : 'off',
			$is_root ? 'ROOT' : implode( ', ', (array) ( $entry['url_base'] ?? [] ) ),
		];
		if ( 'block' === ( $entry['mode'] ?? 'allowlist' ) ) {
			$parts[] = 'block';
		} else {
			$match   = (string) ( $entry['match'] ?? 'slug' );
			$parts[] = $match;
			$parts[] = 'statuses: ' . implode( ', ', $sorted( $entry['post_status'] ?? [ 'publish' ] ) );
			if ( ! $is_root && 'full-path' !== $match ) {
				$depth   = $entry['depth_allowed'] ?? null;
				$parts[] = null === $depth ? 'any depth' : 'depth ' . (int) $depth . ', then ' . (string) ( $entry['depth_action'] ?? 'passthrough' );
			}
			if ( isset( $entry['allow_pagination'] ) && false === $entry['allow_pagination'] ) {
				$parts[] = 'no-pagination';
			}
			$reserved = $sorted( $entry['reserved_allowlist'] ?? [] );
			if ( [] !== $reserved ) {
				$parts[] = 'reserved: ' . implode( ', ', $reserved );
			}
		}
		$ttl_fields = [
			'cache_ttl' => 'cache',
			'edge_ttl'  => 'edge',
		];
		foreach ( $ttl_fields as $field => $name ) {
			if ( isset( $entry[ $field ] ) ) {
				$parts[] = $name . ' ' . (int) $entry[ $field ] . 's';
			}
		}
		return implode( ' · ', $parts );
	}

	/**
	 * The restore confirm screen: a diff of the incoming revision against the
	 * current config (enabled/bases/match highlighted per entry), the
	 * registered-CPT and rewrite-slug warnings RE-RUN against the incoming
	 * payload (a stale revision is exactly when they earn their keep), and the
	 * nonce'd POST that actually restores.
	 *
	 * @param string $stamp Revision stamp.
	 *
	 * @return void
	 */
	private function render_restore_confirm( string $stamp ): void {
		$incoming = $this->store->read_revision( $stamp );
		$current  = $this->store->artifact();
		$back     = add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'options-general.php' ) );
		?>
		<div class="wrap post-shield-admin">
			<h1><?php esc_html_e( 'Restore config revision', 'post-404-shield' ); ?></h1>
			<hr class="wp-header-end" />
			<?php if ( null === $incoming ) : ?>
				<div class="post-shield-admin-notice post-shield-admin-notice--error"><?php esc_html_e( 'That revision does not exist or is not a valid config.', 'post-404-shield' ); ?></div>
				<p class="post-shield-admin__actions"><a class="button" href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to Post 404 Shield', 'post-404-shield' ); ?></a></p>
			</div>
				<?php
				return;
			endif;
			?>

			<p class="post-shield-admin__lede">
				<?php
				printf(
					/* translators: 1: revision stamp, 2: who generated it. */
					esc_html__( 'Restoring %1$s (generated by %2$s). Restoring is a normal save: the payload is re-validated, the current config rotates to a new revision, and allowlists rebuild where the matching mode changes.', 'post-404-shield' ),
					'<code>' . esc_html( $stamp ) . '</code>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					esc_html( is_string( $incoming['generated_by'] ?? null ) ? $incoming['generated_by'] : '—' )
				);
				?>
			</p>

			<?php
			// Re-run the config warnings against the INCOMING payload.
			$validated = $this->store->validate( $incoming );
			$warnings  = $validated['warnings'];
			foreach ( (array) $incoming['entries'] as $key => $entry ) {
				if ( 'allowlist' !== ( $entry['mode'] ?? 'allowlist' ) || ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) ) {
					continue;
				}
				$cpt         = (string) ( $entry['post_type'] ?? $key );
				$type_object = get_post_type_object( $cpt );
				if ( null === $type_object ) {
					continue; // Already warned by validate().
				}
				// No REAL rewrite base (built-in page; post under a bare structure) →
				// nothing to compare against; never invent one from the type name.
				$slug = is_array( $type_object->rewrite ?? null ) && ! empty( $type_object->rewrite['slug'] ) ? (string) $type_object->rewrite['slug'] : '';
				if ( '' !== $slug && ! in_array( $slug, (array) ( $entry['url_base'] ?? [] ), true ) ) {
					/* translators: 1: entry name, 2: detected rewrite slug, 3: the configured bases. */
					$warnings[] = sprintf( __( '%1$s: the type’s detected rewrite slug "%2$s" is not among the revision’s bases (%3$s) — check it predates a permalink change.', 'post-404-shield' ), $this->store->entry_label( (string) $key, (array) $entry ), $slug, implode( ', ', (array) ( $entry['url_base'] ?? [] ) ) );
				}
			}
			if ( [] !== $warnings ) {
				echo '<div class="post-shield-admin__notices">';
				foreach ( $warnings as $warning ) {
					echo '<div class="post-shield-admin-notice post-shield-admin-notice--warning">' . esc_html( $warning ) . '</div>';
				}
				echo '</div>';
			}
			?>

			<section class="post-shield-admin-card">
				<header class="post-shield-admin-card__header"><h2><?php esc_html_e( 'Changes', 'post-404-shield' ); ?></h2></header>
				<div class="post-shield-admin-card__body">
				<?php
				$locale_line = static function ( ?array $config ): string {
					if ( null === $config ) {
						return '—';
					}
					$mode = (string) ( $config['locale']['mode'] ?? 'none' );
					return 'none' === $mode ? 'none' : $mode . ' (' . (string) ( $config['locale']['pattern'] ?? '' ) . ')';
				};
				$keys        = array_unique( array_merge( array_keys( (array) ( $current['entries'] ?? [] ) ), array_keys( (array) $incoming['entries'] ) ) );

				$row = static function ( string $label, string $from, string $to ): void {
					$changed = $from !== $to;
					echo '<div class="post-shield-admin__row' . ( $changed ? ' post-shield-changed' : '' ) . '">';
					echo '<div class="post-shield-admin__row-main"><code class="post-shield-admin__chip">' . esc_html( $label ) . '</code>';
					if ( $changed ) {
						echo '<span class="post-shield-admin__badge post-shield-admin__badge--warning">' . esc_html__( 'changes', 'post-404-shield' ) . '</span>';
					}
					echo '<span class="post-shield-admin__meta">' . esc_html( $from ) . ' &rarr; <strong>' . esc_html( $to ) . '</strong></span></div></div>';
				};

				$excluded_line = static function ( ?array $config ): string {
					if ( null === $config || ! isset( $config['excluded_bases'] ) ) {
						return '—';
					}
					$operator = array_filter( (array) ( $config['excluded_bases']['operator'] ?? [] ), 'is_string' );
					return 'operator: ' . ( [] === $operator ? '(none)' : implode( ', ', $operator ) );
				};

				$row( 'locale', $locale_line( $current ), $locale_line( $incoming ) );
				$row( 'excluded', $excluded_line( $current ), $excluded_line( $incoming ) );
		foreach ( $keys as $key ) {
			$from_entry = ( $current['entries'] ?? [] )[ $key ] ?? null;
			$to_entry   = $incoming['entries'][ $key ] ?? null;
			$row(
				$this->store->entry_label( (string) $key, (array) ( $to_entry ?? $from_entry ) ),
				self::summarise_entry( $from_entry ),
				self::summarise_entry( $to_entry )
			);
		}
		?>
				</div>
			</section>

			<?php
			// S5 on restores: a revision that flips root mode OFF → ON carries
			// the same blocking acknowledgement (enforced in handle_restore()).
			$restore_needs_confirm = $this->store->has_enabled_root_entries( (array) ( $incoming['entries'] ?? [] ) )
				&& ! ( null !== $current && $this->store->has_enabled_root_entries( (array) ( $current['entries'] ?? [] ) ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="post_shield_restore" />
				<input type="hidden" name="ps_stamp" value="<?php echo esc_attr( $stamp ); ?>" />
				<?php wp_nonce_field( 'post_shield_restore' ); ?>
				<?php if ( $restore_needs_confirm ) : ?>
					<div class="post-shield-admin-notice post-shield-admin-notice--warning post-shield-confirm">
						<label>
							<input type="checkbox" name="ps_root_confirm" value="1" />
							<strong><?php esc_html_e( 'This revision turns ROOT MATCHING ON — the shield will decide every URL not owned by a base or exclusion. I understand.', 'post-404-shield' ); ?></strong>
						</label>
					</div>
				<?php endif; ?>
				<p class="post-shield-admin__actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Restore this revision', 'post-404-shield' ); ?></button>
					<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Cancel', 'post-404-shield' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Relative (wp-content-rooted) artifact path for display.
	 *
	 * @return string
	 */
	private function relative_artifact_path(): string {
		$path = $this->store->artifact_path();
		$pos  = strpos( $path, 'wp-content/' );
		return false !== $pos ? substr( $path, $pos ) : $path;
	}

	/**
	 * Entry count + last-updated label for a type's allowlist file.
	 *
	 * @param string $post_type CPT name.
	 *
	 * @return array{0:string, 1:string} [entries, updated] display values.
	 */
	private function allowlist_info( string $post_type ): array {
		$file = $this->builder->get_allowlist_file( $post_type );
		if ( ! is_readable( $file ) ) {
			return [ '—', __( 'not generated yet', 'post-404-shield' ) ];
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local file, not remote.
		if ( false === $raw ) {
			return [ '—', __( 'unreadable', 'post-404-shield' ) ];
		}
		// Duplicates count until the nightly rebuild.
		$entries = \Post404Shield\Library\AllowlistBuilder::count_entries( $raw );

		/* translators: %s: human-readable time difference. */
		return [ (string) $entries, sprintf( __( '%s ago', 'post-404-shield' ), human_time_diff( (int) filemtime( $file ) ) ) ];
	}

	/**
	 * Count + last-baked label for the generated themed-404 pages.
	 *
	 * @return array{0:string, 1:string} [pages, baked] display values.
	 */
	private function baked_404_info(): array {
		$files = glob( $this->builder->get_allowlist_root() . '/404/*.html' );
		if ( ! is_array( $files ) || [] === $files ) {
			return [ '0', __( 'never', 'post-404-shield' ) ];
		}
		$latest = max( array_map( 'filemtime', $files ) );

		/* translators: %s: human-readable time difference. */
		return [ (string) count( $files ), sprintf( __( '%s ago', 'post-404-shield' ), human_time_diff( (int) $latest ) ) ];
	}

	/**
	 * Redirect back to the settings page with a status flag (queue buttons'
	 * no-JS fallback).
	 *
	 * @param string $status  queued|already|invalid.
	 * @param string $subject Post type slug, or `404` for the bake action.
	 *
	 * @return void
	 */
	private function redirect_back( string $status, string $subject ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => self::PAGE_SLUG,
					'ps_status'  => $status,
					'ps_subject' => $subject,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
