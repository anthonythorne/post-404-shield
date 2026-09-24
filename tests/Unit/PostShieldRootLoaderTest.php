<?php
/**
 * Unit tests for the pre-boot loader's ROOT stage and its guard-line
 * prefilter, run for real: each request is a child PHP process against a
 * temp site tree holding an artifact written the way ConfigStore writes it
 * (prefilter on the guard line), root page and post lists, root-extras and a
 * based entry.
 *
 * What only the loader assembles is pinned here — the exclusions (every
 * snapshot bucket, every entry's bases, root reserved slugs), the inert
 * states (an empty root list, a missing root-extras file, a missing bucket)
 * and the prefilter's early exit — since match_root() alone is tested with
 * inputs the loader would have built.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';

/**
 * Test class for the loader's root stage.
 */
class PostShieldRootLoaderTest extends TestCase {

	/**
	 * The temp site root, removed after each test.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * The shield's data directory in it.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * Build the site tree: loader, reader, matcher, a root-mode artifact and
	 * its lists.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/postshield-rootloader-' . getmypid() . '-' . uniqid();
		$plugin     = dirname( __DIR__, 2 ) . '/post-404-shield';
		$dest       = $this->root . '/wp-content/mu-plugins/post-404-shield';
		mkdir( $dest . '/src/php/Function', 0755, true );
		copy( $plugin . '/bootstrap-front-end-post-404-shield.php', $dest . '/bootstrap-front-end-post-404-shield.php' );
		foreach ( [ 'ConfigReader.php', 'Matcher.php' ] as $file ) {
			copy( $plugin . '/src/php/Function/' . $file, $dest . '/src/php/Function/' . $file );
		}

		$this->uploads = $this->root . '/wp-content/uploads/post-404-shield';
		foreach ( [ 'page', 'post', 'root-extras', 'accommodation' ] as $list ) {
			mkdir( $this->uploads . '/' . $list, 0755, true );
		}
		$this->write_config( $this->config() );
		$this->write_list( 'page', [ 'about', 'about/team' ] );
		$this->write_list( 'post', [ 'hello-world' ] );
		$this->write_list( 'root-extras', [ 'unattached-photo', 'about/team-photo' ] );
		$this->write_list( 'accommodation', [ 'real-stay' ] );

		file_put_contents(
			$this->root . '/request.php',
			'<?php
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["REQUEST_URI"]    = $argv[1];
$_SERVER["HTTP_HOST"]      = "example.test";
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
	 * A root-mode config: page and post at the root, one based entry, every
	 * excluded-bases bucket.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$root = static fn( string $type, array $reserved = [] ): array => [
			'enabled'            => true,
			'mode'               => 'allowlist',
			'post_type'          => $type,
			'root'               => true,
			'url_base'           => [],
			'reserved_allowlist' => $reserved,
			'post_status'        => [ 'publish' ],
			'match'              => 'full-path',
			'allow_pagination'   => true,
			'cache_ttl'          => null,
			'edge_ttl'           => null,
		];
		return [
			'version'        => 1,
			'generated_at'   => '2026-09-24T00:00:00+00:00',
			'generated_by'   => 'test',
			'locale'         => [
				'mode'    => 'none',
				'pattern' => '',
			],
			'excluded_bases' => [
				'floor'     => [ 'wp-admin', 'login' ],
				'derived'   => [ 'category', 'feed' ],
				'operator'  => [ 'shop' ],
				'endpoints' => [],
			],
			'entries'        => [
				'page'          => $root( 'page' ),
				'post'          => $root( 'post', [ 'partner-route' ] ),
				'accommodation' => [
					'enabled'            => true,
					'mode'               => 'allowlist',
					'post_type'          => 'accommodation',
					'url_base'           => [ 'accommodation' ],
					'reserved_allowlist' => [],
					'post_status'        => [ 'publish' ],
					'match'              => 'slug',
					'allow_pagination'   => true,
					'depth_allowed'      => 0,
					'depth_action'       => 'redirect',
					'cache_ttl'          => null,
					'edge_ttl'           => null,
				],
			],
		];
	}

	/**
	 * Write the artifact as ConfigStore::stage_artifact() does, prefilter included.
	 *
	 * @param array<string, mixed> $config Document.
	 *
	 * @return void
	 */
	private function write_config( array $config ): void {
		file_put_contents(
			$this->uploads . '/config.php',
			'<?php exit; __halt_compiler(); // post-404-shield generated config — do not edit by hand. prefilter:'
				. json_encode( \Post404Shield\prefilter_for( $config ), JSON_UNESCAPED_SLASHES ) . "\n"
				. json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
	}

	/**
	 * Write one list (guard line + lines; no lines = the empty list the
	 * builder writes).
	 *
	 * @param string   $list  List directory.
	 * @param string[] $lines Lines.
	 *
	 * @return void
	 */
	private function write_list( string $list, array $lines ): void {
		file_put_contents( $this->uploads . '/' . $list . '/allowlist.php', "<?php exit; // guard\n" . ( [] === $lines ? "\n" : implode( "\n", $lines ) . "\n" ) );
	}

	/**
	 * Whether a request reached WordPress (the loader returned).
	 *
	 * @param string $uri Request URI.
	 *
	 * @return bool
	 */
	private function passes( string $uri ): bool {
		$process = proc_open(
			[ PHP_BINARY, $this->root . '/request.php', $uri ],
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		proc_close( $process );
		$through = false !== strpos( $out, 'FELL-THROUGH' );
		if ( ! $through ) {
			$this->assertStringContainsString( 'CODE:404', $err, $uri . ' was stopped, but not with a 404' );
		}
		return $through;
	}

	/**
	 * The response headers a request gets from the loader, served by PHP's
	 * built-in web server (the CLI records no headers).
	 *
	 * @param string $uri Request URI.
	 *
	 * @return array<string, string> Lower-cased header name => value.
	 */
	private function headers_for( string $uri ): array {
		file_put_contents(
			$this->root . '/server.php',
			'<?php
require __DIR__ . "/wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php";
echo "FELL-THROUGH";
'
		);
		$port   = random_int( 20000, 40000 );
		$server = proc_open(
			[ PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->root . '/server.php' ],
			[
				1 => [ 'file', '/dev/null', 'w' ],
				2 => [ 'file', '/dev/null', 'w' ],
			],
			$pipes
		);
		$headers = [];
		try {
			for ( $try = 0; $try < 50; $try++ ) {
				$socket = @fsockopen( '127.0.0.1', $port ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- polling for the server to listen.
				if ( false !== $socket ) {
					fclose( $socket );
					break;
				}
				usleep( 100000 );
			}
			$context = stream_context_create(
				[
					'http' => [
						'ignore_errors' => true,
						'header'        => "Host: example.test\r\n",
					],
				]
			);
			file_get_contents( 'http://127.0.0.1:' . $port . $uri, false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( (array) ( $http_response_header ?? [] ) as $line ) {
				if ( false !== strpos( $line, ':' ) ) {
					[ $name, $value ]                    = explode( ':', $line, 2 );
					$headers[ strtolower( trim( $name ) ) ] = trim( $value );
				}
			}
		} finally {
			proc_terminate( $server );
			proc_close( $server );
		}
		return $headers;
	}

	/**
	 * A blocked root URL is sent with the Pages entry's cache times, even
	 * when Posts comes first in the document (as a first save stores them).
	 *
	 * @return void
	 */
	public function test_a_blocked_root_url_gets_the_pages_entrys_cache_times(): void {
		$config            = $this->config();
		$page              = $config['entries']['page'];
		$post              = $config['entries']['post'];
		$page['cache_ttl'] = 120;
		$page['edge_ttl']  = 30;
		$post['cache_ttl'] = 7;
		$post['edge_ttl']  = 7;
		unset( $config['entries']['page'], $config['entries']['post'] );
		$config['entries'] = [
			'post' => $post,
			'page' => $page,
		] + $config['entries'];
		$this->write_config( $config );

		$headers = $this->headers_for( '/definitely-fake/' );
		$this->assertSame( 'blocked-unknown-slug', $headers['x-post-shield'] ?? '' );
		$this->assertSame( 'public, max-age=30, s-maxage=120', $headers['cache-control'] ?? '' );
		$this->assertSame( 'max-age=30', $headers['cdn-cache-control'] ?? '' );
	}

	/**
	 * Real root addresses pass, an unknown one gets the pre-boot 404.
	 *
	 * @return void
	 */
	public function test_real_root_urls_pass_and_a_fake_one_is_blocked(): void {
		foreach ( [ '/about/', '/about/team/', '/hello-world/', '/unattached-photo/', '/about/team-photo/', '/about/page/2/' ] as $uri ) {
			$this->assertTrue( $this->passes( $uri ), $uri );
		}
		$this->assertFalse( $this->passes( '/definitely-fake/' ) );
	}

	/**
	 * Every exclusion the loader assembles passes: each snapshot bucket, a
	 * based entry's bare base, and a root entry's reserved slug.
	 *
	 * @return void
	 */
	public function test_every_exclusion_passes(): void {
		foreach ( [ '/login/', '/category/news/', '/shop/cart/', '/accommodation/', '/partner-route/x/' ] as $uri ) {
			$this->assertTrue( $this->passes( $uri ), $uri );
		}
		// The based entry still judges its own slugs.
		$this->assertFalse( $this->passes( '/accommodation/fake-stay/' ) );
		$this->assertTrue( $this->passes( '/accommodation/real-stay/' ) );
	}

	/**
	 * Root mode is inert — every URL passes — while a root list is empty,
	 * root-extras is missing, or a snapshot bucket is.
	 *
	 * @return void
	 */
	public function test_root_mode_is_inert_until_every_part_is_in_place(): void {
		$this->write_list( 'post', [] );
		$this->assertTrue( $this->passes( '/definitely-fake/' ), 'an empty post list' );
		$this->write_list( 'post', [ 'hello-world' ] );

		unlink( $this->uploads . '/root-extras/allowlist.php' );
		$this->assertTrue( $this->passes( '/definitely-fake/' ), 'no root-extras list' );
		$this->write_list( 'root-extras', [] );
		$this->assertFalse( $this->passes( '/definitely-fake/' ), 'an EMPTY root-extras list is a real state' );

		$config = $this->config();
		unset( $config['excluded_bases']['derived'] );
		$this->write_config( $config );
		$this->assertTrue( $this->passes( '/definitely-fake/' ), 'a missing bucket' );
	}

	/**
	 * The prefilter: a based-only site's request outside every base exits
	 * before the document is decoded; one under a base is judged.
	 *
	 * @return void
	 */
	public function test_the_prefilter_lets_non_shield_requests_go_and_judges_the_rest(): void {
		$config = $this->config();
		unset( $config['entries']['page'], $config['entries']['post'] );
		$this->write_config( $config );
		$this->assertFalse( $this->passes( '/accommodation/fake-stay/' ), 'under a needle: judged' );
		$this->assertTrue( $this->passes( '/definitely-fake/' ), 'outside every needle: WordPress' );

		// Proof the early exit ran: with the document broken, a request outside
		// the needles still exits cleanly, and one under a needle fails open.
		file_put_contents( $this->uploads . '/config.php', strstr( (string) file_get_contents( $this->uploads . '/config.php' ), "\n", true ) . "\n{broken" );
		$this->assertTrue( $this->passes( '/accommodation/fake-stay/' ), 'an unreadable document: fail-open' );
	}

	/**
	 * Root rows switched off (as root_disabled() and Disable shield write
	 * them) leave root matching off, lists on disk or not; so does a config
	 * with no root entries at all beside a leftover root-extras file.
	 *
	 * @return void
	 */
	public function test_switched_off_or_absent_root_rows_leave_root_matching_off(): void {
		$config                                     = $this->config();
		$config['entries']['page']['enabled']       = false;
		$config['entries']['post']['enabled']       = false;
		$this->write_config( $config );
		// Past the prefilter (a base needle in the path), not under the base.
		$this->assertTrue( $this->passes( '/about/accommodation/x/' ), 'switched off' );

		unset( $config['entries']['page'], $config['entries']['post'] );
		$this->write_config( $config );
		$this->assertTrue( $this->passes( '/contact/?from=/accommodation/x' ), 'no root entries, leftover root-extras' );
		$this->assertFalse( $this->passes( '/accommodation/fake-stay/' ), 'the based entry still judges' );
	}

	/**
	 * A document that is shaped right but not valid (a block on a reserved
	 * base) is not acted on at all.
	 *
	 * @return void
	 */
	public function test_an_invalid_document_is_not_acted_on(): void {
		$config                     = $this->config();
		$config['entries']['feeds'] = [
			'enabled'   => true,
			'mode'      => 'block',
			'url_base'  => [ 'wp-json' ],
			'cache_ttl' => null,
		];
		$this->write_config( $config );
		$this->assertTrue( \Post404Shield\config_shape_is_valid( $config ), 'shaped right' );
		$this->assertFalse( \Post404Shield\config_is_valid( $config ), 'but invalid' );
		$this->assertTrue( $this->passes( '/wp-json/x/' ) );
		$this->assertTrue( $this->passes( '/definitely-fake/' ), 'the whole document is ignored' );
	}

	/**
	 * Rewrite endpoints in the snapshot are stripped like sub-routes: a real
	 * page's endpoint passes, at the root and under a full-path base.
	 *
	 * @return void
	 */
	public function test_endpoints_are_stripped_like_sub_routes(): void {
		$config                                   = $this->config();
		$config['excluded_bases']['endpoints']    = [ 'amp' ];
		$config['entries']['guide']               = [
			'enabled'            => true,
			'mode'               => 'allowlist',
			'post_type'          => 'guide',
			'url_base'           => [ 'guides' ],
			'reserved_allowlist' => [],
			'post_status'        => [ 'publish' ],
			'match'              => 'full-path',
			'allow_pagination'   => true,
			'cache_ttl'          => null,
			'edge_ttl'           => null,
		];
		mkdir( $this->uploads . '/guide', 0755, true );
		$this->write_list( 'guide', [ 'parent', 'parent/child' ] );
		$this->write_config( $config );
		$this->assertTrue( $this->passes( '/about/amp/' ), 'root: a page endpoint' );
		$this->assertTrue( $this->passes( '/guides/parent/child/amp/' ), 'full-path: an endpoint' );
		$this->assertFalse( $this->passes( '/about/not-real/' ), 'root still judges' );
		$this->assertFalse( $this->passes( '/guides/parent/fake/' ), 'full-path still judges' );
	}
}
