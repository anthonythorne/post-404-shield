<?php
/**
 * Cron safety net for the post 404 shield.
 *
 * Rebuilds all managed allowlists daily (WordPress's built-in `daily` schedule).
 * This is the authoritative pass: it dedupes the sync controller's appends, drops
 * stale slugs (unpublish/trash/delete/old-rename), reconciles uploads (removes
 * dirs for types switched off or dropped from config), and heals any missed
 * append. Behind a transient lock so overlapping cron fires don't run the query
 * twice. A daily interval is safe because the sync controller appends new/renamed
 * slugs instantly, so protection never waits on this — only cleanup does, and
 * duplicates + stale slugs are harmless.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Controller/PostShieldCronController.php
 *
 * @package Post404Shield\Controller
 */

declare(strict_types=1);

namespace Post404Shield\Controller;

use Post404Shield\Library\AllowlistBuilder;
use Post404Shield\Library\ConfigStore;

/**
 * Schedules and runs the daily authoritative rebuild, and the daily config
 * health check (which runs even when no type is enabled).
 */
class PostShieldCronController {

	/**
	 * Cron hook that triggers a safety-net rebuild.
	 */
	private const CRON_HOOK = 'post_shield_rebuild_allowlist';

	/**
	 * Cron hook for the daily config health check. Separate from the rebuild
	 * so it keeps running when no type is enabled — the states it exists to
	 * report (no artifact, nothing enabled) are exactly the ones in which the
	 * rebuild is unscheduled.
	 */
	private const HEALTH_HOOK = 'post_shield_config_health';

	/**
	 * Transient used to serialise concurrent rebuilds.
	 */
	private const LOCK_TRANSIENT_KEY = 'post_shield_rebuild_lock';

	/**
	 * Whether the daily rebuild + redirect sync run (at least one type enabled).
	 *
	 * @var bool
	 */
	private bool $rebuilds;

	/**
	 * Shared allowlist builder.
	 *
	 * @var AllowlistBuilder
	 */
	private AllowlistBuilder $builder;

	/**
	 * Shared config store, for the daily health check + tmp sweep.
	 *
	 * @var ConfigStore
	 */
	private ConfigStore $store;

	/**
	 * Construct the cron controller.
	 *
	 * @param AllowlistBuilder $builder  Shared builder.
	 * @param ConfigStore      $store    Shared config store.
	 * @param bool             $rebuilds Run the daily rebuild + redirect sync (false when no type
	 *                                   is enabled; the health check runs either way).
	 */
	public function __construct( AllowlistBuilder $builder, ConfigStore $store, bool $rebuilds = true ) {
		$this->builder  = $builder;
		$this->store    = $store;
		$this->rebuilds = $rebuilds;
	}

	/**
	 * Register the cron schedules + handlers: the health check always, the
	 * rebuild only while a type is enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		add_action( 'init', [ $this, 'register_health_event' ] );
		add_action( self::HEALTH_HOOK, [ $this, 'check_config_health' ] );

		if ( $this->rebuilds ) {
			add_action( 'init', [ $this, 'register_cron_event' ] );
			add_action( self::CRON_HOOK, [ $this, 'run_scheduled_rebuild' ] );
		}
	}

	/**
	 * Schedule the daily health check once.
	 *
	 * @return void
	 */
	public function register_health_event(): void {
		if ( false === wp_next_scheduled( self::HEALTH_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HEALTH_HOOK );
		}
	}

	/**
	 * Schedule the rebuild event once, using WordPress's built-in `daily`
	 * schedule (no custom interval is registered).
	 *
	 * @return void
	 */
	public function register_cron_event(): void {
		// Reschedule if a prior interval (e.g. the old `hourly`) is still registered,
		// so an existing install migrates to `daily` cleanly.
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::CRON_HOOK ) : false;
		if ( false !== $event && 'daily' !== ( $event->schedule ?? '' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$event = false;
		}
		if ( false === $event ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cron entry point: rebuild all types behind a short-lived lock.
	 *
	 * @return void
	 */
	public function run_scheduled_rebuild(): void {
		if ( (bool) get_transient( self::LOCK_TRANSIENT_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT_KEY, true, 2 * MINUTE_IN_SECONDS );
		try {
			$this->builder->rebuild_all();
			$this->store->sweep_tmp();
			$this->sync_derived_reserved();
		} catch ( \Throwable $e ) {
			error_log( '[post-404-shield] Scheduled rebuild failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} finally {
			delete_transient( self::LOCK_TRANSIENT_KEY );
		}
	}

	/**
	 * Rebuild the derived reserved slugs when the site's redirects have moved.
	 *
	 * No redirect plugin here fires an add/update hook, so this compares a
	 * fingerprint of every active redirect source against the one the current
	 * config was built from. When they differ the config is re-saved through the
	 * normal pipeline, which recomputes the derived bucket, regenerates the
	 * artifact and rotates a revision — the config genuinely changed, so a
	 * revision is warranted.
	 *
	 * Unchanged fingerprint = no write at all, so the usual night is a no-op.
	 *
	 * @return void
	 */
	private function sync_derived_reserved(): void {
		$fingerprint = $this->store->redirect_fingerprint();
		$stored      = (string) get_option( ConfigStore::REDIRECT_FP_OPTION, '' );
		if ( $stored === $fingerprint ) {
			return;
		}

		// Redirects were readable last time and now read as NONE. That is almost
		// always a failed read — Rank Math deactivated or mid-update during a
		// plugin round, its Redirections module toggled off, a DB blip — not
		// every redirect on the site being deleted at once. Re-saving now would
		// publish an EMPTY derived bucket, and the redirects would stay dead
		// until the following night even after the plugin came back. Skip,
		// leave the fingerprint alone, and let the next tick decide.
		if ( '' === $fingerprint && '' !== $stored ) {
			error_log( '[post-404-shield] redirect sync SKIPPED: no redirect sources readable (previously some were). Treating as a failed read; derived reserved slugs left in place.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$option = $this->store->option();
		if ( null === $option ) {
			// Nothing configured yet — record the fingerprint so the first real
			// save is not immediately followed by a redundant resync.
			update_option( ConfigStore::REDIRECT_FP_OPTION, $fingerprint, false );
			return;
		}

		$result = $this->store->write( $option, 'cron (redirect sync)' );
		if ( $result['ok'] ) {
			update_option( ConfigStore::REDIRECT_FP_OPTION, $fingerprint, false );
			error_log( '[post-404-shield] redirect sync: derived reserved slugs rebuilt (redirect sources changed).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		// Leave the fingerprint alone so the next tick retries.
		error_log( '[post-404-shield] redirect sync FAILED: ' . implode( ' | ', $result['errors'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Daily operator signal: silence must not be ambiguous. Compares the shield
	 * state — artifact valid? at least one enabled entry? consistent with the
	 * option? — and error_log()s + emits a New Relic custom event (no-op where
	 * the NR extension is absent) on any mismatch, so a shield that silently
	 * switched itself off is caught within a day rather than never. Runs on its
	 * own hook, so it still fires when nothing is enabled or no artifact exists
	 * (e.g. an environment deployed without a staged option). Silent under
	 * POST_SHIELD_DISABLED, which is a deliberate off.
	 *
	 * @return void
	 */
	public function check_config_health(): void {
		if ( defined( 'POST_SHIELD_DISABLED' ) && POST_SHIELD_DISABLED ) {
			return;
		}
		$artifact = $this->store->artifact();
		$option   = $this->store->option();
		$problems = [];

		if ( null === $artifact ) {
			$problems[] = 'artifact missing or invalid (shield NOT in place)';
		} else {
			$enabled = 0;
			foreach ( $artifact['entries'] as $entry ) {
				if ( ! isset( $entry['enabled'] ) || false !== $entry['enabled'] ) {
					++$enabled;
				}
			}
			if ( 0 === $enabled ) {
				$problems[] = 'artifact has zero enabled entries';
			}
		}

		if ( null === $option ) {
			$problems[] = 'config option missing';
		} elseif ( null !== $artifact ) {
			$same = wp_json_encode( [ $artifact['locale'] ?? null, $artifact['entries'] ?? null ] )
				=== wp_json_encode( [ $option['locale'] ?? null, $option['entries'] ?? null ] );
			if ( ! $same ) {
				$problems[] = 'artifact and option disagree (locale/entries)';
			}
		}

		if ( [] === $problems ) {
			return;
		}

		error_log( '[post-404-shield] config health check FAILED: ' . implode( '; ', $problems ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		if ( function_exists( 'newrelic_record_custom_event' ) ) {
			newrelic_record_custom_event(
				'PostShieldConfigHealth',
				[
					'status'   => 'mismatch',
					'problems' => implode( '; ', $problems ),
				]
			);
		}
	}
}
