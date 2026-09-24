<?php
/**
 * Keeps the baked static 404 pages current.
 *
 * The pre-boot loader serves a pre-baked copy of the theme's 404 page (one per
 * WPML language). That markup embeds the navigation menus, so it needs re-baking
 * after menu / theme / translation changes.
 *
 * Baking is asynchronous AND batched: one bake = ~50 loopback requests
 * (minutes), which would blow the ~60 s web timeout WP-Cron runs under on WPE
 * if done in a single request. So BAKE_EVENT only SEEDS a queue (all languages
 * into the progress option), and BATCH_EVENT works through it BATCH_SIZE
 * locales per cron tick, rescheduling itself until empty. Progress (done/total/
 * failed) is exposed via progress() for the settings page's live progress bar.
 * Triggers are deliberately few: a WEEKLY cron (the 404 markup changes rarely),
 * the "Regenerate 404 pages" button on Settings → Post 404 Shield, and the
 * `wp post-shield bake-404` CLI on deploy (CLI bakes synchronously — no web
 * timeout). No content-change hooks. A lock stops two batches overlapping; a
 * locked-out batch reschedules rather than dropping the queue.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Controller/PostShield404Controller.php
 *
 * @package Post404Shield\Controller
 */

declare(strict_types=1);

namespace Post404Shield\Controller;

use Post404Shield\Library\Static404Baker;

/**
 * Triggers static-404 re-bakes on menu save and on a daily schedule.
 */
class PostShield404Controller {

	/**
	 * On-demand bake event, queued by the Settings-page button. Seeds the batch
	 * queue; the actual baking happens in BATCH_EVENT chunks.
	 */
	public const BAKE_EVENT = 'post_shield_bake_404';

	/**
	 * Batch worker event: bakes BATCH_SIZE locales per WP-Cron tick, then
	 * reschedules itself until the queue is empty. Keeps every cron request
	 * well under the ~60 s web timeout WP-Cron runs under on WPE — a full
	 * 50-locale bake in one request would exceed it.
	 */
	public const BATCH_EVENT = 'post_shield_bake_404_batch';

	/**
	 * Option holding the batched bake's state: queue (locales remaining), total,
	 * done, failed[], updated, finished. Read by the settings page for its
	 * progress display. Not autoloaded.
	 */
	public const PROGRESS_OPTION = 'post_shield_bake_progress';

	/**
	 * Locales baked per batch. ~1–3 s each, so a batch stays comfortably inside
	 * a single cron request; batches chain on consecutive cron ticks (every
	 * minute on WPE, so a full bake lands in roughly 5–6 minutes worst case).
	 */
	private const BATCH_SIZE = 10;

	/**
	 * A bake whose progress has not moved for this long is considered dead
	 * (crashed mid-batch) and may be reseeded.
	 */
	private const STALE_SECONDS = 900;

	/**
	 * Recurring safety-net event (built-in `weekly` schedule).
	 */
	public const BAKE_CRON = 'post_shield_bake_404_daily';

	/**
	 * Lock so two bakes cannot run at once. Public so the admin page's status
	 * endpoint can report an in-progress bake.
	 */
	public const LOCK_TRANSIENT_KEY = 'post_shield_bake_404_lock';

	/**
	 * Debounce window (seconds) between a trigger and the bake.
	 */
	private const DEBOUNCE_SECONDS = 60;

	/**
	 * Lock TTL (seconds). Crash-safety expiry sized to one BATCH (not a full
	 * bake): BATCH_SIZE loopbacks at a few seconds each.
	 */
	private const LOCK_TTL = 120;

	/**
	 * Shared static-404 baker.
	 *
	 * @var Static404Baker
	 */
	private Static404Baker $baker;

	/**
	 * Construct the controller.
	 *
	 * @param Static404Baker $baker Shared baker.
	 */
	public function __construct( Static404Baker $baker ) {
		$this->baker = $baker;
	}

	/**
	 * Register the weekly schedule and the bake workers. (No content-change
	 * triggers — on-demand bakes are queued from Settings → Post 404 Shield.)
	 *
	 * @return void
	 */
	public function set_up(): void {
		add_action( 'init', [ $this, 'register_cron_event' ] );
		add_action( self::BAKE_EVENT, [ $this, 'run_bake' ] );
		add_action( self::BAKE_CRON, [ $this, 'run_bake' ] );
		add_action( self::BATCH_EVENT, [ $this, 'run_bake_batch' ] );
		add_filter( 'robots_txt', [ $this, 'disallow_probe_path' ] ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt -- adds the bake probe's Disallow line; robots.txt is generated per request.
	}

	/**
	 * Ask crawlers to stay away from the bake probe path. Belt only — the loader
	 * already answers tokenless probe requests with the cheap baked 404 — but it
	 * stops well-behaved bots wasting crawl budget on it at all. No-op if a
	 * physical robots.txt exists (WordPress never runs the filter then).
	 *
	 * @param string $output The generated robots.txt content.
	 *
	 * @return string
	 */
	public function disallow_probe_path( $output ): string {
		return (string) $output . "\nUser-agent: *\nDisallow: /*/" . Static404Baker::PROBE_PATH . "/\n";
	}

	/**
	 * Schedule the weekly safety-net bake once (built-in `weekly` schedule).
	 *
	 * @return void
	 */
	public function register_cron_event(): void {
		// Only where scheduling can matter: wp-admin (including a settings save), a
		// cron run or WP-CLI. A REST call or a front-end POST never runs cron here, so
		// checking the schedule on every one of those was wasted work.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// Reschedule if a prior interval is still registered (migrate to `weekly`).
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::BAKE_CRON ) : false;
		if ( false !== $event && 'weekly' !== ( $event->schedule ?? '' ) ) {
			wp_clear_scheduled_hook( self::BAKE_CRON );
			$event = false;
		}
		if ( false === $event ) {
			wp_schedule_event( time() + self::DEBOUNCE_SECONDS, 'weekly', self::BAKE_CRON );
		}
	}

	/**
	 * Seed a batched bake. Queues every language (plus the default fallback) into
	 * the progress option and schedules the first batch — it never bakes inline,
	 * so this handler returns in milliseconds regardless of language count. If a
	 * bake is already in flight it does NOT reseed (the button/cron firing twice
	 * must not restart progress); it just makes sure a batch event is scheduled,
	 * which also self-heals a bake whose next batch event was lost.
	 *
	 * @return void
	 */
	public function run_bake(): void {
		$progress = get_option( self::PROGRESS_OPTION, [] );
		$active   = is_array( $progress )
			&& ! empty( $progress['queue'] )
			&& empty( $progress['finished'] )
			&& ( time() - (int) ( $progress['updated'] ?? 0 ) ) < self::STALE_SECONDS;

		if ( ! $active ) {
			$queue = array_merge( [ 'default' ], $this->baker->get_locales() );
			update_option(
				self::PROGRESS_OPTION,
				[
					'queue'    => $queue,
					'total'    => count( $queue ),
					'done'     => 0,
					'failed'   => [],
					'updated'  => time(),
					'finished' => 0,
				],
				false
			);
		}

		if ( false === wp_next_scheduled( self::BATCH_EVENT ) ) {
			wp_schedule_single_event( time(), self::BATCH_EVENT );
		}
	}

	/**
	 * Bake the next BATCH_SIZE locales from the queue, update progress, and
	 * reschedule itself until the queue is empty. Behind the lock so two ticks
	 * can't run the same batch; a locked-out run reschedules rather than dropping
	 * the queue. A locale that fails (or throws) is recorded in `failed` and the
	 * bake moves on — one broken language never stalls the rest.
	 *
	 * @return void
	 */
	public function run_bake_batch(): void {
		if ( (bool) get_transient( self::LOCK_TRANSIENT_KEY ) ) {
			if ( false === wp_next_scheduled( self::BATCH_EVENT ) ) {
				wp_schedule_single_event( time() + 30, self::BATCH_EVENT );
			}
			return;
		}

		$progress = get_option( self::PROGRESS_OPTION, [] );
		if ( ! is_array( $progress ) || empty( $progress['queue'] ) ) {
			return; // Finished or never seeded.
		}

		set_transient( self::LOCK_TRANSIENT_KEY, true, self::LOCK_TTL );
		try {
			$batch = array_splice( $progress['queue'], 0, self::BATCH_SIZE );
			foreach ( $batch as $locale ) {
				try {
					$baked = $this->baker->bake( (string) $locale );
				} catch ( \Throwable $e ) {
					$baked = false;
					error_log( '[post-404-shield] 404 bake for ' . $locale . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				if ( ! $baked ) {
					$progress['failed'][] = (string) $locale;
				}
				++$progress['done'];
			}

			$progress['updated'] = time();
			if ( [] === $progress['queue'] ) {
				$progress['finished'] = time();
				if ( [] !== $progress['failed'] ) {
					error_log( '[post-404-shield] 404 bake finished with failures: ' . implode( ', ', $progress['failed'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			update_option( self::PROGRESS_OPTION, $progress, false );
		} finally {
			delete_transient( self::LOCK_TRANSIENT_KEY );
		}

		if ( [] !== $progress['queue'] && false === wp_next_scheduled( self::BATCH_EVENT ) ) {
			wp_schedule_single_event( time(), self::BATCH_EVENT );
		}
	}

	/**
	 * Current bake progress, for the settings page's status endpoint. `active`
	 * is false once the queue drains or if progress has gone stale (crashed
	 * bake), so a stuck option can never pin the UI at "baking" forever.
	 *
	 * @return array{active:bool, done:int, total:int, failed:string[]}
	 */
	public static function progress(): array {
		$progress = get_option( self::PROGRESS_OPTION, [] );
		if ( ! is_array( $progress ) || empty( $progress['total'] ) ) {
			return [
				'active' => false,
				'done'   => 0,
				'total'  => 0,
				'failed' => [],
			];
		}

		$active = ! empty( $progress['queue'] )
			&& empty( $progress['finished'] )
			&& ( time() - (int) ( $progress['updated'] ?? 0 ) ) < self::STALE_SECONDS;

		return [
			'active' => $active,
			'done'   => (int) ( $progress['done'] ?? 0 ),
			'total'  => (int) $progress['total'],
			'failed' => array_map( 'strval', (array) ( $progress['failed'] ?? [] ) ),
		];
	}
}
