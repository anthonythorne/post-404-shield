<?php
/**
 * Unit tests for what the gates say:
 *
 * - about a FORCED save (CLI --force): every broken real URL the based-entry
 *   coverage gate lets through is reported, whatever else the save confirmed
 *   (a status drop) or forced (a wrong base) — a forced restore must never
 *   read as if only the confirmed part broke anything;
 * - about a status drop, in both gates: every (entry, status) pair the
 *   confirmation covers is named, not only those among the sampled URLs.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';

/**
 * Test class for the coverage gate's forced verdicts.
 */
class PostShieldCoverageGateTest extends TestCase {

	/**
	 * Run the gate with a stub coverage report.
	 *
	 * @param array<string, mixed> $coverage The handler's report.
	 * @param array<string, mixed> $flags    write() flags.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string} The refusal, and the warnings joined.
	 */
	private function gate( array $coverage, array $flags ): array {
		$store = new ConfigStore();
		$store->set_coverage_handler( static fn(): array => $coverage );
		$method = new \ReflectionMethod( ConfigStore::class, 'coverage_gate' );
		$method->setAccessible( true );
		$warnings = [];
		$config   = [
			'entries' => [
				'firmware' => [
					'mode'        => 'allowlist',
					'post_type'   => 'firmware',
					'url_base'    => [ 'support/firmware' ],
					'post_status' => [ 'publish' ],
				],
			],
		];
		$refusal = $method->invokeArgs( $store, [ $config, $flags, &$warnings ] );
		return [ $refusal, implode( "\n", $warnings ) ];
	}

	/**
	 * Breaks, some of them a dropped status.
	 *
	 * @param int $status_breaks Breaks from the dropped status.
	 * @param int $other_breaks  Other breaks (a wrong base).
	 *
	 * @return array<int, array<string, string>>
	 */
	private function breaks( int $status_breaks, int $other_breaks ): array {
		$out = [];
		for ( $i = 0; $i < $status_breaks; $i++ ) {
			$out[] = [
				'url'    => '/global/support/firmware/old-' . $i . '/',
				'marker' => 'blocked-unknown-slug',
				'entry'  => 'firmware',
				'status' => 'discontinued',
			];
		}
		for ( $i = 0; $i < $other_breaks; $i++ ) {
			$out[] = [
				'url'    => '/global/support/manual/x-' . $i . '/',
				'marker' => 'blocked-depth-redirect',
				'entry'  => 'firmware',
			];
		}
		return $out;
	}

	/**
	 * A forced save that also confirms a status drop reports the other breaks.
	 *
	 * @return void
	 */
	public function test_a_forced_drop_still_reports_the_other_breaks(): void {
		[ $refusal, $warnings ] = $this->gate(
			[
				'checked' => 456,
				'breaks'  => $this->breaks( 12, 444 ),
			],
			[
				'force_preflight'   => true,
				'allow_status_drop' => true,
			]
		);
		$this->assertNull( $refusal );
		$this->assertStringContainsString( 'Dropped a status as confirmed: 12 real URLs', $warnings );
		$this->assertStringContainsString( 'Coverage check reported 444 broken real URL(s) but the save was FORCED.', $warnings );
		$this->assertStringNotContainsString( 'Coverage check passed', $warnings );
	}

	/**
	 * A forced save over an unclaimed entry reports the breaks elsewhere too.
	 *
	 * @return void
	 */
	public function test_a_forced_wrong_base_still_reports_the_breaks(): void {
		[ $refusal, $warnings ] = $this->gate(
			[
				'checked'   => 50,
				'breaks'    => $this->breaks( 0, 3 ),
				'unclaimed' => [ 'firmware' => 'support/firmware-updates' ],
			],
			[ 'force_preflight' => true ]
		);
		$this->assertNull( $refusal );
		$this->assertStringContainsString( 'Coverage check FAILED but the save was FORCED', $warnings );
		$this->assertStringContainsString( 'Coverage check reported 3 broken real URL(s) but the save was FORCED.', $warnings );
	}

	/**
	 * Only a clean check is a pass; a confirmed drop alone is not one.
	 *
	 * @return void
	 */
	public function test_only_a_clean_check_passes(): void {
		[ , $warnings ] = $this->gate(
			[
				'checked' => 20,
				'breaks'  => [],
			],
			[]
		);
		$this->assertStringContainsString( 'Coverage check passed — 20 real URLs', $warnings );

		[ , $warnings ] = $this->gate(
			[
				'checked' => 20,
				'breaks'  => $this->breaks( 2, 0 ),
			],
			[ 'allow_status_drop' => true ]
		);
		$this->assertStringNotContainsString( 'Coverage check passed', $warnings );
	}

	/**
	 * A confirmation covers the (entry, status) pairs it names, no others: a
	 * drop nobody was shown refuses, and the refusal offers both — the one
	 * confirmed too, so one more confirmation covers everything.
	 *
	 * @return void
	 */
	public function test_a_confirmation_covers_only_the_pairs_it_names(): void {
		$breaks = $this->breaks( 2, 0 );
		foreach ( [ 1, 2 ] as $i ) {
			$breaks[] = [
				'url'    => '/global/support/firmware/archived-' . $i . '/',
				'marker' => 'blocked-unknown-slug',
				'entry'  => 'firmware',
				'status' => 'archived',
			];
		}
		[ $refusal, $warnings ] = $this->gate(
			[
				'checked' => 10,
				'breaks'  => $breaks,
			],
			[ 'allow_status_drop' => [ 'firmware:discontinued' ] ]
		);
		$this->assertNotNull( $refusal, 'The archived drop was never confirmed.' );
		$this->assertTrue( $refusal['status_drop'] );
		$this->assertEqualsCanonicalizing( [ 'firmware:discontinued', 'firmware:archived' ], $refusal['status_drop_pairs'] );
		$this->assertStringContainsString( 'status "archived" is not listed', implode( "\n", $refusal['errors'] ) );
		$this->assertStringNotContainsString( 'status "discontinued" is not listed', implode( "\n", $refusal['errors'] ) );
		$this->assertStringContainsString( 'Dropped a status as confirmed: 2 real URLs', $warnings );

		[ $refusal ] = $this->gate(
			[
				'checked' => 10,
				'breaks'  => $breaks,
			],
			[ 'allow_status_drop' => $refusal['status_drop_pairs'] ]
		);
		$this->assertNull( $refusal, 'Both confirmed: the save goes on.' );
	}

	/**
	 * Every (entry, status) pair a confirmation would cover is named in the
	 * refusal, with its count, even when its URLs fall past the ten sampled.
	 *
	 * @return void
	 */
	public function test_every_pair_is_named_past_the_sampled_urls(): void {
		$breaks = $this->breaks( 12, 0 );
		foreach ( [ 1, 2, 3 ] as $i ) {
			$breaks[] = [
				'url'    => '/global/support/firmware/archived-' . $i . '/',
				'marker' => 'blocked-unknown-slug',
				'entry'  => 'firmware',
				'status' => 'archived',
			];
		}
		[ $refusal ] = $this->gate(
			[
				'checked' => 20,
				'breaks'  => $breaks,
			],
			[]
		);
		$said = implode( "\n", $refusal['errors'] );
		$this->assertStringNotContainsString( 'archived-1', $said, 'Past the ten sampled URLs.' );
		$this->assertStringContainsString( 'firmware — status "discontinued" is not listed: 12 real URLs checked would 404.', $said );
		$this->assertStringContainsString( 'firmware — status "archived" is not listed: 3 real URLs checked would 404.', $said );
		$this->assertEqualsCanonicalizing( [ 'firmware:discontinued', 'firmware:archived' ], $refusal['status_drop_pairs'] );
	}

	/**
	 * The root gate names every dropped (entry, status) pair too.
	 *
	 * @return void
	 */
	public function test_the_root_gate_names_every_pair(): void {
		$store   = new ConfigStore();
		$blocked = [];
		$dropped = [];
		$types   = [];
		foreach ( range( 1, 11 ) as $i ) {
			$blocked[]                        = '/news-' . $i . '/';
			$dropped[ '/news-' . $i . '/' ]   = 'private';
			$types[ '/news-' . $i . '/' ]     = 'post';
		}
		$blocked[]              = '/about-old/';
		$dropped['/about-old/'] = 'archived';
		$types['/about-old/']   = 'page';
		$store->set_preflight_handler(
			static fn(): array => [
				'would_block'   => $blocked,
				'dropped'       => $dropped,
				'dropped_types' => $types,
			]
		);
		$method = new \ReflectionMethod( ConfigStore::class, 'root_preflight_gate' );
		$method->setAccessible( true );
		$warnings = [];
		$accepted = null;
		$config   = [
			'entries' => [
				'post' => [ 'post_type' => 'post', 'root' => true ],
				'page' => [ 'post_type' => 'page', 'root' => true ],
			],
		];
		$refusal  = $method->invokeArgs( $store, [ $config, [], &$warnings, &$accepted ] );
		$said     = implode( "\n", $refusal['errors'] );
		$this->assertStringContainsString( 'post — status "private" is not listed: 11 real URLs checked would 404.', $said );
		$this->assertStringContainsString( 'page — status "archived" is not listed: 1 real URL checked would 404.', $said, 'Past the ten sampled URLs.' );
		$this->assertEqualsCanonicalizing( [ 'post:private', 'page:archived' ], $refusal['status_drop_pairs'] );
	}
}
