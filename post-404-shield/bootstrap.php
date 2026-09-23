<?php
/**
 * Post 404 shield generator bootstrap (WRITE path).
 *
 * Loads the config + write-side classes and wires each controller directly. The read path (bootstrap-front-end-post-404-shield.php) is
 * loaded separately and earlier — by wp-config.php (Tier 1) or the failsafe in
 * 05-post-404-shield-bootstrap.php (Tier 2).
 *
 * Config comes from the GENERATED artifact (`uploads/post-404-shield/config.php`)
 * — the same runtime truth the loader reads — never from a committed file. The
 * artifact is produced by Settings → Post 404 Shield (or the CLI) from the
 * `post_shield_config` option, and SELF-HEALS here on init:
 *   1. artifact present and valid                     → nothing to do;
 *   2. artifact missing/corrupt, option valid         → regenerate from option;
 *   3. neither → the shield stays inert until an operator configures it;
 *      deploying never reconfigures a site as a side effect;
 *   4. artifact valid, option missing (DB restore)    → rehydrate option;
 *   5. nothing anywhere                               → shield inert, admin page
 *      shows the INACTIVE banner and is where it gets configured.
 *
 * The Admin + 404-bake controllers wire ALWAYS (the settings page must exist
 * when the config is empty — that's where you configure it); sync + cron wire
 * only when enabled types exist, and clear their scheduled events when none do.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/bootstrap.php
 *
 * @package Post404Shield\Bootstrap
 */

declare(strict_types=1);

if ( ! defined( 'POST_SHIELD_PLUGIN_DIR' ) ) {
	define( 'POST_SHIELD_PLUGIN_DIR', __DIR__ );
}

// All shielded posts live on blog 1; never wire the write side on a subsite.
if ( ! is_main_site() ) {
	return;
}

// Reader + matcher (pure) + store (WP-side): the config plumbing everything
// below shares. The matcher is write-side business too now — the root
// preflight (S6) replays the exact loader decision through its functions.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Function/ConfigReader.php';
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Function/Matcher.php';
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Library/ConfigStore.php';

$post_shield_store  = new \Post404Shield\Library\ConfigStore();
$post_shield_config = $post_shield_store->artifact();

// Self-heal / rehydrate — deferred to wp_loaded because validation
// needs registered CPTs and custom post STATUSES, which do not
// exist at mu-plugin load and can register as late as the END of init (a
// custom status has been seen at init priority 100050) — wp_loaded is the first hook
// guaranteed to run after every init registration. The health check is
// read_config() !== null, NOT is_readable(): a corrupt-but-present artifact
// (truncated write, bad manual edit, attacker garbage) must heal too, or the
// shield stays silently off forever. The loader already ran fail-open this
// request; the heal makes the NEXT request shielded again.
add_action(
	'wp_loaded',
	static function () use ( $post_shield_store, $post_shield_config ): void {
		$option = $post_shield_store->option();

		if ( null !== $post_shield_config ) {
			if ( null === $option ) {
				// 4. Artifact valid, option missing (e.g. DB restored from backup):
				// rehydrate the option FROM the artifact — never let them fight.
				update_option( \Post404Shield\Library\ConfigStore::OPTION, $post_shield_config, false );
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
			if ( $post_shield_store->has_enabled_root_entries( (array) ( $option['entries'] ?? [] ) ) ) {
				$healed                   = $option;
				$heal_pattern             = \Post404Shield\Library\ConfigStore::locale_pattern_of( $healed );
				$healed['excluded_bases'] = $post_shield_store->excluded_bases_snapshot( (array) ( $healed['excluded_bases']['operator'] ?? [] ), $heal_pattern );
				$healed['entries']        = $post_shield_store->apply_derived_reserved( (array) ( $healed['entries'] ?? [] ), $heal_pattern );
				if ( ! \Post404Shield\config_is_valid( $healed ) ) {
					$healed = $option;
				}
				if ( $post_shield_store->write_artifact( $healed ) ) {
					// Keep the option in step with what was published, so the two
					// never disagree about the snapshots.
					if ( $healed !== $option ) {
						update_option( \Post404Shield\Library\ConfigStore::OPTION, $healed, false );
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
			$result = $post_shield_store->write( $option, 'self-heal (artifact was missing or invalid)' );
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
	},
	20
);

$post_shield_entries = null !== $post_shield_config ? $post_shield_config['entries'] : [];

// Enabled, allowlist-mode post types only (entries can be left in place and
// switched off). These are EFFECTIVE CPTs: an entry's `post_type` when set,
// else its key — so several bases sharing one CPT yield ONE save-hook, ONE
// allowlist, ONE admin row. mode=block entries are loader-only: no allowlist,
// no save hooks, no rebuilds.
$post_shield_enabled_types = [];
foreach ( $post_shield_entries as $post_shield_entry_key => $post_shield_entry ) {
	if ( isset( $post_shield_entry['enabled'] ) && false === $post_shield_entry['enabled'] ) {
		continue;
	}
	if ( 'allowlist' !== ( $post_shield_entry['mode'] ?? 'allowlist' ) ) {
		continue;
	}
	$post_shield_effective_type = (string) ( $post_shield_entry['post_type'] ?? $post_shield_entry_key );
	if ( ! in_array( $post_shield_effective_type, $post_shield_enabled_types, true ) ) {
		$post_shield_enabled_types[] = $post_shield_effective_type;
	}
}

// Enabled blocked bases (label => url_base[]), for the settings page display.
$post_shield_blocked_bases = [];
foreach ( $post_shield_entries as $post_shield_entry_key => $post_shield_entry ) {
	if ( ( ! isset( $post_shield_entry['enabled'] ) || false !== $post_shield_entry['enabled'] )
		&& 'block' === ( $post_shield_entry['mode'] ?? 'allowlist' )
		&& [] !== ( $post_shield_entry['url_base'] ?? [] )
	) {
		$post_shield_blocked_bases[ (string) $post_shield_entry_key ] = array_map( 'strval', (array) $post_shield_entry['url_base'] );
	}
}

// Shared allowlist builder (no autoloader — this is a standalone mu-plugin).
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Library/AllowlistBuilder.php';
$post_shield_builder = new \Post404Shield\Library\AllowlistBuilder( $post_shield_entries );

// Mode-switch rebuild seam: ConfigStore::write() rebuilds a type's allowlist
// synchronously (against the CANDIDATE entries) when its match mode changes,
// ordered around the artifact swap so a full-path artifact never reads a
// slug-format list. Every save path — admin, restore, CLI — inherits this.
$post_shield_store->set_rebuild_handler(
	static function ( array $post_types, array $entries ): void {
		$candidate_builder = new \Post404Shield\Library\AllowlistBuilder( $entries );
		foreach ( $post_types as $rebuild_type ) {
			try {
				$candidate_builder->rebuild_type( (string) $rebuild_type );
			} catch ( \Throwable $e ) {
				error_log( '[post-404-shield] mode-switch rebuild for ' . $rebuild_type . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		// Root mode (root-pages v2): the extras union member (attachments + old
		// slugs) must exist BEFORE a root-enabling artifact swaps in — the
		// loader refuses to run root matching without it (fail-open).
		if ( $candidate_builder->has_root_entries() ) {
			try {
				$candidate_builder->rebuild_root_extras();
			} catch ( \Throwable $e ) {
				error_log( '[post-404-shield] root-extras rebuild failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
);

// S6 preflight seam: every save that leaves root mode active walks real URLs
// through the would-be loader decision; ConfigStore::write() aborts on a
// non-empty report. The result is persisted for the status card either way.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Library/RootPreflight.php';
$post_shield_store->set_preflight_handler(
	static function ( array $candidate ): array {
		$preflight = new \Post404Shield\Library\RootPreflight();
		$result    = $preflight->run( $candidate );
		update_option(
			\Post404Shield\Library\ConfigStore::PREFLIGHT_OPTION,
			[
				'at'          => gmdate( 'c' ),
				'checked'     => $result['checked'],
				'would_block' => count( $result['would_block'] ),
				'warn_block'  => count( $result['warn_block'] ),
				'sample'      => array_slice( array_merge( $result['would_block'], $result['warn_block'] ), 0, 10 ),
			],
			false
		);
		// Based-entry blocks are pre-existing v1 behaviour (e.g. a redirect
		// source under a shielded base) — logged for the operator, never a
		// root-save blocker. Remedy: that entry's reserved slugs.
		foreach ( $result['warn_block'] as $post_shield_warn_url ) {
			error_log( '[post-404-shield] preflight warning: ' . $post_shield_warn_url . ' is blocked by a BASED entry (pre-existing behaviour) — if it is a real/redirecting URL, add its slug to that entry\'s reserved slugs.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $result['would_block'];
	}
);

// Static 404 baker — captures the themed 404 for the pre-boot loader to serve.
// Locale-mode-driven probe shape; null = auto-detect while unconfigured.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Library/Static404Baker.php';
$post_shield_locale_mode = null !== $post_shield_config && isset( $post_shield_config['locale']['mode'] )
	? (string) $post_shield_config['locale']['mode']
	: null;
$post_shield_baker       = new \Post404Shield\Library\Static404Baker(
	$post_shield_locale_mode,
	\Post404Shield\Library\ConfigStore::locale_pattern_of( $post_shield_config )
);

// 404 controller — weekly re-bake schedule + the on-demand bake worker. ALWAYS
// wired: the settings page queues bakes even while types are being set up.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Controller/PostShield404Controller.php';
$post_shield_404_controller = new \Post404Shield\Controller\PostShield404Controller( $post_shield_baker );
$post_shield_404_controller->set_up();

// Admin controller — Settings → Post 404 Shield. ALWAYS wired: the page is
// where an empty/missing config gets configured, so it must exist then most
// of all.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Controller/PostShieldAdminController.php';
$post_shield_admin_controller = new \Post404Shield\Controller\PostShieldAdminController(
	$post_shield_builder,
	$post_shield_store,
	$post_shield_enabled_types,
	$post_shield_blocked_bases
);
$post_shield_admin_controller->set_up();

// Sync controller + the daily rebuild run only when at least one type is
// enabled: clear their scheduled events (the daily rebuild, per-type rebuilds)
// when the shield is fully off. Note: disabling every type also stops uploads
// self-cleaning — re-enable a type and run `wp post-shield rebuild`, or remove
// the uploads dir by hand.
if ( [] === $post_shield_enabled_types ) {
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'post_shield_rebuild_allowlist' );
		wp_unschedule_hook( 'post_shield_rebuild_type' ); // admin-button per-type rebuild.
	}
} else {
	// Sync controller — appends slugs to allowlists on post changes (real-time).
	require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Controller/PostShieldSyncController.php';
	$post_shield_sync_controller = new \Post404Shield\Controller\PostShieldSyncController( $post_shield_builder, $post_shield_enabled_types );
	$post_shield_sync_controller->set_up();
}

// Cron controller — ALWAYS wired, for the daily config health check: its job
// is to report a shield that is off (no artifact, nothing enabled), which is
// exactly when the rebuild above is unscheduled. The daily rebuild + redirect
// sync still only run while a type is enabled.
require_once POST_SHIELD_PLUGIN_DIR . '/src/php/Controller/PostShieldCronController.php';
$post_shield_cron_controller = new \Post404Shield\Controller\PostShieldCronController( $post_shield_builder, $post_shield_store, [] !== $post_shield_enabled_types );
$post_shield_cron_controller->set_up();

// WP-CLI: allowlist rebuilds, 404 bakes, and the generated-config lifecycle.
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'post-shield rebuild',
		static function ( $args, $assoc_args ) use ( $post_shield_builder, $post_shield_enabled_types ) {
			$only = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : '';

			if ( '' !== $only ) {
				if ( ! in_array( $only, $post_shield_enabled_types, true ) ) {
					\WP_CLI::error( sprintf( '"%s" is not a managed, enabled shield type.', $only ) );
				}
				$count = $post_shield_builder->rebuild_type( $only );
				\WP_CLI::success( sprintf( 'Post shield allowlist rebuilt for %s: %d slug(s).', $only, $count ) );
				return;
			}

			if ( [] === $post_shield_enabled_types ) {
				\WP_CLI::error( 'No enabled shield types — configure the shield on Settings → Post 404 Shield first.' );
			}
			$count = $post_shield_builder->rebuild_all();
			\WP_CLI::success( sprintf( 'Post shield allowlist rebuilt: %d slug(s) across %d type(s); stale type dirs reconciled.', $count, count( $post_shield_enabled_types ) ) );
		},
		[
			'shortdesc' => 'Rebuild post-404-shield allowlists (all enabled types, or one via --type).',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'type',
					'description' => 'Rebuild only this post type instead of all enabled types.',
					'optional'    => true,
				],
			],
		]
	);

	\WP_CLI::add_command(
		'post-shield bake-404',
		static function ( $args, $assoc_args ) use ( $post_shield_baker ) {
			$locale = isset( $assoc_args['locale'] ) ? (string) $assoc_args['locale'] : '';

			if ( '' !== $locale ) {
				if ( $post_shield_baker->bake( $locale ) ) {
					\WP_CLI::success( sprintf( 'Baked themed 404 for "%s".', $locale ) );
					return;
				}
				\WP_CLI::error( sprintf( 'Could not bake "%s": %s', $locale, $post_shield_baker->last_reason() ) );
			}

			$count = $post_shield_baker->bake_all();
			if ( 0 === $count ) {
				\WP_CLI::error( 'Baked 0 pages. Last reason: ' . $post_shield_baker->last_reason() );
			}
			\WP_CLI::success( sprintf( 'Baked themed 404 for %d locale(s) — every language plus the default fallback.', $count ) );
		},
		[
			'shortdesc' => 'Capture the themed 404 per language to static files the pre-boot loader serves.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'locale',
					'description' => 'Bake only this URL locale (xx-xx); omit to bake every language + default.',
					'optional'    => true,
				],
			],
		]
	);

	\WP_CLI::add_command(
		'post-shield root-preflight',
		static function ( $args, $assoc_args ) use ( $post_shield_store ) {
			$candidate = $post_shield_store->option() ?? $post_shield_store->artifact();
			if ( null === $candidate ) {
				\WP_CLI::error( 'No shield config anywhere — configure Settings → Post 404 Shield first.' );
			}

			// With root mode not yet enabled, SIMULATE it: this is the prod gate
			// run BEFORE the enabling save, so it measures the exact config that
			// save would produce (every root-dweller enabled, pagination on,
			// publish status, fresh excluded-bases snapshot).
			$simulated = false;
			if ( ! $post_shield_store->has_enabled_root_entries( (array) ( $candidate['entries'] ?? [] ) ) ) {
				$simulated = true;
				foreach ( $post_shield_store->root_dweller_types() as $root_dweller ) {
					$candidate['entries'][ $root_dweller ] = [
						'enabled'          => true,
						'mode'             => 'allowlist',
						'post_type'        => $root_dweller,
						'root'             => true,
						'url_base'         => [],
						'match'            => 'full-path',
						'allow_pagination' => true,
						'post_status'      => [ 'publish' ],
					];
				}
				\WP_CLI::log( 'Root mode is not enabled — simulating root entries for: ' . implode( ', ', $post_shield_store->root_dweller_types() ) . '.' );
			}
			$operator                    = (array) ( $candidate['excluded_bases']['operator'] ?? [] );
			$candidate['excluded_bases'] = $post_shield_store->excluded_bases_snapshot( $operator, \Post404Shield\Library\ConfigStore::locale_pattern_of( $candidate ) );

			$preflight = new \Post404Shield\Library\RootPreflight();
			$result    = $preflight->run( $candidate );
			update_option(
				\Post404Shield\Library\ConfigStore::PREFLIGHT_OPTION,
				[
					'at'          => gmdate( 'c' ),
					'checked'     => $result['checked'],
					'would_block' => count( $result['would_block'] ),
					'warn_block'  => count( $result['warn_block'] ),
					'sample'      => array_slice( array_merge( $result['would_block'], $result['warn_block'] ), 0, 10 ),
				],
				false
			);

			foreach ( $result['warn_block'] as $post_shield_warn_url ) {
				\WP_CLI::warning( 'Blocked by a BASED entry (pre-existing v1 behaviour, not root mode): ' . $post_shield_warn_url . ' — if this is a real/redirecting URL, add its slug to that entry\'s reserved slugs.' );
			}
			if ( [] !== $result['would_block'] ) {
				foreach ( $result['would_block'] as $post_shield_blocked_url ) {
					\WP_CLI::log( 'WOULD BLOCK: ' . $post_shield_blocked_url );
				}
				\WP_CLI::error( sprintf( 'Root preflight FAILED: %d of %d real URL(s) would be served a pre-boot 404 by root matching. Investigate before enabling root mode.', count( $result['would_block'] ), $result['checked'] ) );
			}
			\WP_CLI::success( sprintf( 'Root preflight passed: %d URL(s) checked, zero root would-blocks%s.', $result['checked'], $simulated ? ' (simulated root config)' : '' ) );
		},
		[
			'shortdesc' => 'Walk real site URLs through the would-be loader decision and report any that root mode would 404 (S6 coverage gate).',
		]
	);

	\WP_CLI::add_command(
		'post-shield config',
		static function ( $args, $assoc_args ) use ( $post_shield_store ) {
			$action = $args[0] ?? '';

			switch ( $action ) {
				case 'export':
					$config = $post_shield_store->artifact();
					if ( null === $config ) {
						\WP_CLI::error( 'No valid config artifact — the shield is not in place.' );
					}
					\WP_CLI::print_value( $config, [ 'format' => 'json' ] );
					return;

				case 'write':
					$option = $post_shield_store->option();
					if ( null === $option ) {
						\WP_CLI::error( 'No config option to write from — save Settings → Post 404 Shield first.' );
					}
					// --force: the knowing-operator escape hatch for the S6 root
					// preflight — an ACCEPTED would-block must not brick every
					// future root-active save.
					$result = $post_shield_store->write(
						$option,
						'wp-cli (config write)',
						[ 'force_preflight' => ! empty( $assoc_args['force'] ) ]
					);
					if ( ! $result['ok'] ) {
						\WP_CLI::error( implode( ' | ', $result['errors'] ) );
					}
					foreach ( $result['warnings'] as $post_shield_warning ) {
						\WP_CLI::warning( $post_shield_warning );
					}
					\WP_CLI::success( 'Config artifact regenerated from the option (previous artifact rotated to a revision).' );
					return;

				case 'import-legacy':
					if ( null !== $post_shield_store->option() ) {
						\WP_CLI::error( 'A config option already exists — refusing to overwrite it. Use the admin UI, or delete the option first.' );
					}
					// The legacy array is site-owned, so it is passed in rather
					// than looked for inside the plugin: a vendored copy is
					// replaced wholesale on every sync, and a file dropped into it
					// would not survive one. The in-plugin location is still read
					// when no path is given, for sites following the old docs.
					$legacy_file = isset( $args[1] ) && '' !== (string) $args[1]
						? (string) $args[1]
						: POST_SHIELD_PLUGIN_DIR . '/config/allowed-post-types.php';
					if ( ! is_readable( $legacy_file ) ) {
						\WP_CLI::error( sprintf( 'No legacy config at %s. Pass the path to the old committed array: wp post-shield config import-legacy <file>', $legacy_file ) );
					}
					$legacy = require $legacy_file;
					if ( ! is_array( $legacy ) || [] === $legacy ) {
						\WP_CLI::error( 'The legacy committed config is empty.' );
					}
					$locale_mode    = defined( 'ICL_SITEPRESS_VERSION' ) ? 'wpml-directory' : 'none';
					$locale_pattern = '';
					if ( 'wpml-directory' === $locale_mode ) {
						$locale_pattern = '[a-z]{2}-[a-z]{2}';
						$default_lang   = apply_filters( 'wpml_default_language', null );
						if ( is_string( $default_lang ) && 1 === preg_match( '/^[a-z0-9-]+$/', $default_lang ) && 1 !== preg_match( '/^[a-z]{2}-[a-z]{2}$/', $default_lang ) ) {
							$locale_pattern .= '|' . $default_lang;
						}
					}
					$result = $post_shield_store->write( $post_shield_store->import_legacy( $legacy, $locale_mode, $locale_pattern ), 'wp-cli (import-legacy)' );
					if ( ! $result['ok'] ) {
						\WP_CLI::error( implode( ' | ', $result['errors'] ) );
					}
					\WP_CLI::success( 'Legacy config imported: option + artifact written (locale mode ' . $locale_mode . ').' );
					return;

				case 'revisions':
					$rows = [];
					foreach ( $post_shield_store->revisions() as $revision ) {
						$rows[] = [
							'stamp'        => $revision['stamp'],
							'generated_at' => $revision['generated_at'],
							'generated_by' => $revision['generated_by'],
							'valid'        => $revision['valid'] ? 'yes' : 'NO',
						];
					}
					if ( [] === $rows ) {
						\WP_CLI::log( 'No revisions.' );
						return;
					}
					\WP_CLI\Utils\format_items( 'table', $rows, [ 'stamp', 'generated_at', 'generated_by', 'valid' ] );
					return;

				case 'restore':
					$stamp = $args[1] ?? '';
					if ( '' === $stamp ) {
						\WP_CLI::error( 'Usage: wp post-shield config restore <stamp>' );
					}
					$result = $post_shield_store->restore( $stamp, 'wp-cli (restore ' . $stamp . ')' );
					if ( ! $result['ok'] ) {
						\WP_CLI::error( implode( ' | ', $result['errors'] ) );
					}
					\WP_CLI::success( 'Revision ' . $stamp . ' restored (the outgoing config was rotated to a new revision).' );
					return;

				default:
					\WP_CLI::error( 'Usage: wp post-shield config <export|write|import-legacy <file>|revisions|restore <stamp>>' );
			}
		},
		[
			'shortdesc' => 'Manage the generated shield config artifact: export, write (from option), import-legacy, revisions, restore.',
			'synopsis'  => [
				[
					'type'        => 'positional',
					'name'        => 'action',
					'description' => 'export | write | import-legacy | revisions | restore',
				],
				[
					'type'        => 'positional',
					'name'        => 'stamp',
					'description' => 'restore: the revision stamp. import-legacy: path to the old committed config array.',
					'optional'    => true,
				],
				[
					'type'        => 'flag',
					'name'        => 'force',
					'description' => 'write only: land the save despite root-preflight would-blocks (knowing operator).',
					'optional'    => true,
				],
			],
		]
	);
}
