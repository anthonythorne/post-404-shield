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
use function Post404Shield\config_shape_is_valid;
use function Post404Shield\config_is_valid;
use function Post404Shield\locale_pattern_is_valid;
use function Post404Shield\url_base_is_valid;
use function Post404Shield\read_config_document;
use function Post404Shield\read_config_validated_in_memory;
use function Post404Shield\temp_path;
use function Post404Shield\is_temp_file_name;
use function Post404Shield\prefilter_for;
use function Post404Shield\config_prefilter;
use function Post404Shield\prefilter_matches;

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
	 * The per-request memo must never serve a stale document. An in-place
	 * rewrite of the SAME size within the same second is the case a stat()
	 * key cannot see; the memo compares bytes, so it must catch it.
	 */
	public function test_memo_sees_a_same_size_in_place_rewrite() {
		$a = $this->valid_config();
		$b = $this->valid_config();
		$b['entries']['story']['url_base'] = [ 'storiez' ]; // same length as `stories`

		$this->write( $a );
		$this->assertSame( [ 'stories' ], read_config( $this->file )['entries']['story']['url_base'] );

		$size = filesize( $this->file );
		$this->write( $b );
		clearstatcache();
		$this->assertSame( $size, filesize( $this->file ), 'Precondition: same size.' );
		$this->assertSame( [ 'storiez' ], read_config( $this->file )['entries']['story']['url_base'] );
	}

	/**
	 * The pre-boot loader validates the bytes it already decoded instead of
	 * reading the file again. The function must never read: after an in-place
	 * rewrite it still returns what was read, and only read_config(), which
	 * re-reads by design, sees the new bytes.
	 */
	public function test_validated_in_memory_never_reads_the_file() {
		$this->assertNull( read_config_validated_in_memory( $this->file ), 'Nothing read yet: null, and no read happens.' );

		$this->write( $this->valid_config() );
		$this->assertNotNull( read_config_document( $this->file ) );

		$changed = $this->valid_config();
		$changed['entries']['story']['url_base'] = [ 'storiez' ];
		$this->write( $changed );

		$this->assertSame( [ 'stories' ], read_config_validated_in_memory( $this->file )['entries']['story']['url_base'], 'Validates the bytes already in memory, without re-reading.' );
		$this->assertSame( [ 'storiez' ], read_config( $this->file )['entries']['story']['url_base'], 'read_config() still re-reads and sees the rewrite.' );
	}

	/**
	 * An invalid document read into memory validates to null, never to the
	 * document — the loader must fail open on it.
	 */
	public function test_validated_in_memory_rejects_an_invalid_document() {
		$bad = $this->valid_config();
		$bad['entries']['story']['url_base'] = [ '../escape' ];
		$this->write( $bad );
		$this->assertNotNull( read_config_document( $this->file ), 'Precondition: it decodes.' );
		$this->assertNull( read_config_validated_in_memory( $this->file ) );
	}

	/**
	 * The validity verdict is tied to the bytes it was computed for: an invalid
	 * document swapped in after a valid one reads as null, and back again.
	 */
	public function test_memo_revalidates_when_the_document_changes() {
		$this->write( $this->valid_config() );
		$this->assertNotNull( read_config( $this->file ) );

		$bad = $this->valid_config();
		$bad['entries']['story']['url_base'] = [ '../escape' ];
		$this->write( $bad );
		$this->assertNull( read_config( $this->file ), 'Invalid replacement is rejected, not served from memo.' );

		$this->write( $this->valid_config() );
		$this->assertNotNull( read_config( $this->file ) );

		unlink( $this->file );
		$this->assertNull( read_config( $this->file ), 'Deleted artifact is null, not the memoised copy.' );
	}

	/**
	 * The loader's pre-filter check is types only: it passes a document the
	 * full validator would reject (that is deferred, not skipped), and rejects
	 * anything the pre-filter could fatal on.
	 */
	public function test_shape_check_guards_the_prefilter_only() {
		$valid = $this->valid_config();
		$this->assertTrue( config_shape_is_valid( $valid ) );

		$regex_invalid = $valid;
		$regex_invalid['entries']['story']['url_base'] = [ '../escape' ];
		$this->assertTrue( config_shape_is_valid( $regex_invalid ), 'Charset is the full validator\'s job.' );
		$this->assertFalse( config_is_valid( $regex_invalid ) );

		foreach (
			[
				[ 'url_base', [ [ 'nested' ] ] ],
				[ 'url_base', 'stories' ],
				[ 'enabled', 'yes' ],
				[ 'mode', [ 'block' ] ],
				[ 'root', 1 ],
			] as [ $field, $value ]
		) {
			$broken                               = $valid;
			$broken['entries']['story'][ $field ] = $value;
			$this->assertFalse( config_shape_is_valid( $broken ), "Rejects $field of the wrong type." );
		}

		$this->assertFalse( config_shape_is_valid( [ 'entries' => 'x', 'locale' => [ 'mode' => 'none' ] ] ) );
		$this->assertFalse( config_shape_is_valid( [ 'entries' => [] ] ), 'Locale block required.' );
		$this->assertFalse( config_shape_is_valid( 'nope' ) );
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

	/**
	 * A temp file keeps a `.php` ending, so a leftover runs its guard line
	 * over HTTP; the sweep recognises both that and the older name.
	 *
	 * @return void
	 */
	public function test_temp_names_end_in_php_and_are_recognised(): void {
		$tmp = temp_path( '/x/post-404-shield/page/allowlist.php' );
		$this->assertMatchesRegularExpression( '#^/x/post-404-shield/page/allowlist\.\d+\.[0-9a-f]{12}\.tmp\.php$#', $tmp );
		$this->assertNotSame( $tmp, temp_path( '/x/post-404-shield/page/allowlist.php' ), 'Unique per call, not only per process: containers share PIDs.' );
		$this->assertTrue( is_temp_file_name( basename( $tmp ) ) );
		$this->assertTrue( is_temp_file_name( 'allowlist.4242.tmp.php' ), 'The PID-only name is swept too.' );
		$this->assertTrue( is_temp_file_name( 'config.php.4242.tmp' ), 'The older name is swept too.' );
		$this->assertFalse( is_temp_file_name( 'allowlist.php' ) );
		$this->assertFalse( is_temp_file_name( 'config-20260923-101500.php' ), 'A revision is never a temp file.' );
		$this->assertFalse( is_temp_file_name( 'index.php' ) );
	}

	/**
	 * The guard-line pre-filter round-trips, decides relevance the way the
	 * loader's own loop does, and leaves the document readable.
	 *
	 * @return void
	 */
	public function test_guard_line_prefilter(): void {
		$config    = $this->valid_config();
		$prefilter = prefilter_for( $config );
		$this->assertSame(
			[
				'root'    => false,
				'needles' => [ '/stories/', '/old-section' ],
			],
			$prefilter
		);

		$this->write( $config, '<?php exit; __halt_compiler(); // guard prefilter:' . json_encode( $prefilter, JSON_UNESCAPED_SLASHES ) );
		$raw = (string) file_get_contents( $this->file );
		$this->assertSame( $prefilter, config_prefilter( $raw ) );
		$this->assertNotNull( read_config( $this->file ), 'The document after the guard line still reads.' );

		$this->assertTrue( prefilter_matches( $prefilter, '/global/stories/x/' ) );
		$this->assertTrue( prefilter_matches( $prefilter, '/de-de/old-section' ), 'A blocked base matches bare.' );
		$this->assertTrue( prefilter_matches( $prefilter, '/en-au/post-shield-404-probe/' ), 'The probe is always shield business.' );
		$this->assertFalse( prefilter_matches( $prefilter, '/global/stories' ), 'An allowlist base needs its slash.' );
		$this->assertFalse( prefilter_matches( $prefilter, '/global/about/' ) );

		$config['entries']['page'] = [
			'enabled'   => true,
			'mode'      => 'allowlist',
			'post_type' => 'page',
			'root'      => true,
			'url_base'  => [],
		];
		$this->assertTrue( prefilter_matches( prefilter_for( $config ), '/global/about/' ), 'Root mode: everything.' );

		$this->assertNull( config_prefilter( "<?php exit; // guard\n{}" ), 'An older artifact has none.' );
		$this->assertNull( config_prefilter( '<?php exit; // guard prefilter:{"root":"yes","needles":[]}' ), 'Malformed: decode as before.' );
		$this->assertNull( config_prefilter( '<?php exit; // guard prefilter:{"root":false,"needles":[""]}' ) );
	}
}
