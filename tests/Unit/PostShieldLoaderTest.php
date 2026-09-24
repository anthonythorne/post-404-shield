<?php
/**
 * Unit tests for the pre-boot loader (bootstrap-front-end-post-404-shield.php),
 * run for real: each request is a child PHP process against a temp site tree
 * holding the loader, its reader and matcher, a config and one allowlist.
 *
 * What only the loader decides is pinned here: the paths it must leave to
 * WordPress whatever the lists say (a leading `//`, dot segments, percent
 * escapes, backslashes — a cache keyed on the normalised URL would store a
 * shield answer under the real one), and that a reader or matcher from
 * another release leaves the shield off instead of failing the request.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test class for the pre-boot loader.
 */
class PostShieldLoaderTest extends TestCase {

	/**
	 * The temp site root, removed after each test.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Build the site tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/postshield-loader-' . getmypid() . '-' . uniqid();
		$plugin     = dirname( __DIR__, 2 ) . '/post-404-shield';
		$dest       = $this->root . '/wp-content/mu-plugins/post-404-shield';
		mkdir( $dest . '/src/php/Function', 0755, true );
		copy( $plugin . '/bootstrap-front-end-post-404-shield.php', $dest . '/bootstrap-front-end-post-404-shield.php' );
		foreach ( [ 'ConfigReader.php', 'Matcher.php' ] as $file ) {
			copy( $plugin . '/src/php/Function/' . $file, $dest . '/src/php/Function/' . $file );
		}

		$uploads = $this->root . '/wp-content/uploads/post-404-shield';
		mkdir( $uploads . '/story', 0755, true );
		$config = [
			'version'      => 1,
			'generated_at' => '2026-09-24T00:00:00+00:00',
			'generated_by' => 'test',
			'locale'       => [
				'mode'    => 'wpml-directory',
				'pattern' => '[a-z]{2}-[a-z]{2}|global',
			],
			'entries'      => [
				'story' => [
					'enabled'            => true,
					'mode'               => 'allowlist',
					'post_type'          => 'story',
					'url_base'           => [ 'stories' ],
					'reserved_allowlist' => [],
					'post_status'        => [ 'publish' ],
					'match'              => 'slug',
					'depth_allowed'      => 0,
					'depth_action'       => '404',
					'cache_ttl'          => null,
					'edge_ttl'           => null,
				],
			],
		];
		file_put_contents( $uploads . '/config.php', "<?php exit; // guard\n" . json_encode( $config ) . "\n" );
		file_put_contents( $uploads . '/story/allowlist.php', "<?php exit; // guard\nreal-story\n" );

		file_put_contents(
			$this->root . '/request.php',
			'<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = $argv[1];
$_SERVER["HTTP_HOST"]      = "example.test";
if ( isset( $argv[2] ) ) { $_SERVER["HTTP_COOKIE"] = $argv[2]; }
register_shutdown_function( static function () { fwrite( STDERR, "CODE:" . http_response_code() . "\n" ); } );
require __DIR__ . "/wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php";
echo "FELL-THROUGH";
'
		);
	}

	/**
	 * Remove the site tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	/**
	 * One request through the loader.
	 *
	 * @param string $uri    Request URI.
	 * @param string $cookie Cookie header ('' for none).
	 *
	 * @return array{through: bool, code: int, exit: int, stderr: string}
	 */
	private function request( string $uri, string $cookie = '' ): array {
		$process = proc_open(
			array_merge( [ PHP_BINARY, $this->root . '/request.php', $uri ], '' === $cookie ? [] : [ $cookie ] ),
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		$out     = (string) stream_get_contents( $pipes[1] );
		$err     = (string) stream_get_contents( $pipes[2] );
		$exit    = proc_close( $process );
		preg_match( '/CODE:(\d+)/', $err, $code );
		return [
			'through' => false !== strpos( $out, 'FELL-THROUGH' ),
			'code'    => (int) ( $code[1] ?? 0 ),
			'exit'    => $exit,
			'stderr'  => $err,
		];
	}

	/**
	 * The control: a fake slug gets the shield's 404, a real one passes.
	 *
	 * @return void
	 */
	public function test_a_fake_slug_is_blocked_and_a_real_one_passes(): void {
		$fake = $this->request( '/global/stories/fake-one/' );
		$this->assertFalse( $fake['through'], $fake['stderr'] );
		$this->assertSame( 404, $fake['code'] );

		$real = $this->request( '/global/stories/real-story/' );
		$this->assertTrue( $real['through'], $real['stderr'] );
	}

	/**
	 * Non-canonical paths go to WordPress, whatever the lists say.
	 *
	 * @return void
	 */
	public function test_non_canonical_paths_go_to_wordpress(): void {
		// Each depends on one guard alone: without it, the path is judged and
		// the fake slug blocked — a 404 a normalising cache would file under the
		// real story. (`//x/…` parses with host `x` and the path after it.)
		foreach ( [ '/global/stories/fake/../real-story/', '/global/stories/fake/%2e%2e/real-story/', '/global/stories/fake/..\\real-story/', '//x/global/stories/fake-one/' ] as $uri ) {
			$result = $this->request( $uri );
			$this->assertTrue( $result['through'], $uri . ' ' . $result['stderr'] );
			$this->assertNotSame( 404, $result['code'], $uri );
		}
	}

	/**
	 * A matcher or reader from another release (a half-finished deploy)
	 * leaves the shield off; it never fails the request.
	 *
	 * @return void
	 */
	public function test_a_half_deployed_release_leaves_the_shield_off(): void {
		$function = $this->root . '/wp-content/mu-plugins/post-404-shield/src/php/Function';
		$matcher  = (string) file_get_contents( $function . '/Matcher.php' );
		// An older Matcher.php lacks whatever this loader added since: each
		// function it calls, missing in turn, leaves the shield off.
		foreach ( [ 'match_entry', 'decide_based', 'list_is_empty', 'match_blocked_base', 'prefers_markdown', 'shield_404_cache_headers', 'shield_redirect_cache_header', 'root_404_ttls' ] as $missing ) {
			file_put_contents( $function . '/Matcher.php', str_replace( 'function ' . $missing . '(', 'function ' . $missing . '_renamed(', $matcher ) );
			$result = $this->request( '/global/stories/fake-one/' );
			$this->assertSame( 0, $result['exit'], $missing . ': ' . $result['stderr'] );
			$this->assertTrue( $result['through'], 'An old matcher without ' . $missing . ': shield off. ' . $result['stderr'] );
		}

		file_put_contents( $function . '/Matcher.php', $matcher );
		$reader = (string) file_get_contents( $function . '/ConfigReader.php' );
		file_put_contents( $function . '/ConfigReader.php', str_replace( 'function read_config_document(', 'function read_config_document_renamed(', $reader ) );
		$result = $this->request( '/global/stories/fake-one/' );
		$this->assertSame( 0, $result['exit'], $result['stderr'] );
		$this->assertTrue( $result['through'], 'An old reader: shield off. ' . $result['stderr'] );
	}

	/**
	 * Every plugin function the loader calls is checked with function_exists()
	 * first, so a function added to Matcher.php or ConfigReader.php cannot be
	 * called unguarded (a half-finished deploy would error on every 404).
	 *
	 * @return void
	 */
	public function test_every_function_the_loader_calls_is_guarded(): void {
		$loader = (string) file_get_contents( dirname( __DIR__, 2 ) . '/post-404-shield/bootstrap-front-end-post-404-shield.php' );
		preg_match_all( '/\\\\Post404Shield\\\\([a-z0-9_]+)\\(/', $loader, $calls );
		$called = array_unique( $calls[1] );
		$this->assertNotEmpty( $called );
		foreach ( $called as $name ) {
			$this->assertStringContainsString( "function_exists( 'Post404Shield\\\\" . $name . "' )", $loader, $name . ' is called without a guard.' );
		}
	}

	/**
	 * A logged-in preview of a post's own address goes to WordPress; the
	 * same request without the login cookie is shielded.
	 *
	 * @return void
	 */
	public function test_a_logged_in_preview_goes_to_wordpress(): void {
		$logged_in = $this->request( '/global/stories/embargoed-one/?preview=true', 'wordpress_logged_in_abc=admin%7C1' );
		$this->assertTrue( $logged_in['through'], $logged_in['stderr'] );

		$anonymous = $this->request( '/global/stories/embargoed-one/?preview=true', 'other=1' );
		$this->assertFalse( $anonymous['through'] );
		$this->assertSame( 404, $anonymous['code'] );
	}
}
