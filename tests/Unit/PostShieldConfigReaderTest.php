<?php
/**
 * Unit tests for the post 404 shield config reader.
 *
 * Pure PHP, no WordPress: ConfigReader.php only declares namespaced functions.
 * These tests are the security contract of the runtime artifact — the reader
 * must return null (shield off, fail-open) for EVERY malformed or hostile
 * document, and must re-enforce the save-time invariants, not just JSON shape.
 *
 * File Path: build-tools/tests/unit-testing/unit/PostShieldConfigReaderTest.php
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

use function Post404Shield\read_config;
use function Post404Shield\config_is_valid;
use function Post404Shield\locale_pattern_is_valid;
use function Post404Shield\url_base_is_valid;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';

/**
 * Test class for the post 404 shield config reader.
 */
class PostShieldConfigReaderTest extends TestCase {

	/**
	 * Temp artifact path for read_config tests.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Create a temp file per test.
	 */
	protected function setUp(): void {
		$this->file = tempnam( sys_get_temp_dir(), 'ps-config-' );
	}

	/**
	 * Remove the temp file.
	 */
	protected function tearDown(): void {
		if ( file_exists( $this->file ) ) {
			unlink( $this->file );
		}
	}

	/**
	 * A minimal valid schema-v1 document.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_config(): array {
		return [
			'version'      => 1,
			'generated_at' => '2026-07-10T00:00:00+00:00',
			'generated_by' => 'test (#1)',
			'locale'       => [
				'mode'    => 'wpml-directory',
				'pattern' => '[a-z]{2}-[a-z]{2}|global',
			],
			'entries'      => [
				'story'     => [
					'enabled'            => true,
					'mode'               => 'allowlist',
					'post_type'          => 'story',
					'url_base'           => [ 'stories' ],
					'reserved_allowlist' => [ 'b2b-solutions' ],
					'post_status'        => [ 'publish' ],
					'match'              => 'slug',
					'depth_allowed'      => 0,
					'depth_action'       => 'redirect',
					'cache_ttl'          => null,
					'edge_ttl'           => null,
				],
				'old-section' => [
					'enabled'   => true,
					'mode'      => 'block',
					'post_type' => null,
					'url_base'  => [ 'old-section' ],
					'cache_ttl' => null,
				],
			],
		];
	}

	/**
	 * Write a document to the temp artifact (guard line + JSON).
	 *
	 * @param mixed  $config Document to encode.
	 * @param string $guard  First line.
	 */
	private function write( $config, string $guard = '<?php exit; // guard' ): void {
		file_put_contents( $this->file, $guard . "\n" . json_encode( $config ) . "\n" );
	}

	/**
	 * A well-formed artifact round-trips with every field intact.
	 */
	public function test_valid_artifact_round_trips() {
		$this->write( $this->valid_config() );
		$config = read_config( $this->file );

		$this->assertNotNull( $config );
		$this->assertSame( [ 'stories' ], $config['entries']['story']['url_base'] );
		$this->assertSame( 'wpml-directory', $config['locale']['mode'] );
	}

	/**
	 * Missing file, missing guard, bad JSON — all null (fail-open).
	 */
	public function test_structural_failures_return_null() {
		$this->assertNull( read_config( '/nonexistent/config.php' ) );

		file_put_contents( $this->file, "no guard line\n{}" );
		$this->assertNull( read_config( $this->file ) );

		file_put_contents( $this->file, "<?php exit;\nnot json at all" );
		$this->assertNull( read_config( $this->file ) );

		file_put_contents( $this->file, '<?php exit; no newline at all' );
		$this->assertNull( read_config( $this->file ) );
	}

	/**
	 * Invariant violations — hostile-but-valid JSON — all null.
	 *
	 * @dataProvider provide_invariant_violations
	 *
	 * @param callable $mutate Mutation applied to a valid document.
	 */
	public function test_invariant_violations_return_null( callable $mutate ) {
		$config = $this->valid_config();
		$mutate( $config );
		$this->write( $config );
		$this->assertNull( read_config( $this->file ) );
	}

	/**
	 * Hostile mutations the reader must reject.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function provide_invariant_violations(): array {
		return [
			'wrong version'            => [
				function ( &$c ) {
					$c['version'] = 2;
				},
			],
			'missing locale'           => [
				function ( &$c ) {
					unset( $c['locale'] );
				},
			],
			'bad locale mode'          => [
				function ( &$c ) {
					$c['locale']['mode'] = 'everything';
				},
			],
			'paren locale pattern'     => [
				function ( &$c ) {
					$c['locale']['pattern'] = '(en)|(fr-fr)';
				},
			],
			'leading-slash base'       => [
				function ( &$c ) {
					$c['entries']['story']['url_base'] = [ '/stories' ];
				},
			],
			'empty base list'          => [
				function ( &$c ) {
					$c['entries']['story']['url_base'] = [];
				},
			],
			'string base (legacy)'     => [
				function ( &$c ) {
					$c['entries']['story']['url_base'] = 'stories';
				},
			],
			'traversal post_type'      => [
				function ( &$c ) {
					$c['entries']['story']['post_type'] = '../../evil';
				},
			],
			'traversal entry key'      => [
				function ( &$c ) {
					$c['entries']['../evil'] = $c['entries']['story'];
				},
			],
			'bad mode'                 => [
				function ( &$c ) {
					$c['entries']['story']['mode'] = 'destroy';
				},
			],
			'bad match'                => [
				function ( &$c ) {
					$c['entries']['story']['match'] = 'regex';
				},
			],
			'string depth'             => [
				function ( &$c ) {
					$c['entries']['story']['depth_allowed'] = '0';
				},
			],
			'negative ttl'             => [
				function ( &$c ) {
					$c['entries']['story']['cache_ttl'] = -1;
				},
			],
			'bad depth action'         => [
				function ( &$c ) {
					$c['entries']['story']['depth_action'] = 'explode';
				},
			],
			'malformed reserved slug'  => [
				function ( &$c ) {
					$c['entries']['story']['reserved_allowlist'] = [ '../x' ];
				},
			],
			'non-bool enabled'         => [
				function ( &$c ) {
					$c['entries']['story']['enabled'] = 1;
				},
			],
		];
	}

	/**
	 * The locale-pattern validator: presets pass; delimiters, escapes, parens,
	 * oversized and empty bodies are rejected.
	 */
	public function test_locale_pattern_validation() {
		$this->assertTrue( locale_pattern_is_valid( '[a-z]{2}-[a-z]{2}|global' ) );
		$this->assertTrue( locale_pattern_is_valid( 'en|fr-fr,de' ) );

		$this->assertFalse( locale_pattern_is_valid( '' ) );
		$this->assertFalse( locale_pattern_is_valid( '(en)|(fr-fr)' ), 'Parens would shift the matcher captures.' );
		$this->assertFalse( locale_pattern_is_valid( 'a#b' ), 'The delimiter must be rejected.' );
		$this->assertFalse( locale_pattern_is_valid( 'a\\d' ), 'Escapes must be rejected.' );
		$this->assertFalse( locale_pattern_is_valid( str_repeat( 'a', 201 ) ) );
	}

	/**
	 * The url_base validator: internal slashes fine, edges and traversal not.
	 */
	public function test_url_base_validation() {
		$this->assertTrue( url_base_is_valid( 'stories' ) );
		$this->assertTrue( url_base_is_valid( 'support/compatibility/shoes' ) );

		$this->assertFalse( url_base_is_valid( '/stories' ) );
		$this->assertFalse( url_base_is_valid( 'stories/' ) );
		$this->assertFalse( url_base_is_valid( 'a//b' ) );
		$this->assertFalse( url_base_is_valid( 'a/../b' ) );
		$this->assertFalse( url_base_is_valid( 'UPPER' ) );
		$this->assertFalse( url_base_is_valid( '' ) );
		$this->assertFalse( url_base_is_valid( 42 ) );
	}

	/**
	 * config_is_valid accepts an entries-empty document (a fully disabled
	 * shield is still a valid artifact — the INACTIVE state).
	 */
	public function test_empty_entries_document_is_valid() {
		$config            = $this->valid_config();
		$config['entries'] = [];
		$this->assertTrue( config_is_valid( $config ) );
	}
}
