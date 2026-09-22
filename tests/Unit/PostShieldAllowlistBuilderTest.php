<?php
/**
 * Unit tests for the post 404 shield AllowlistBuilder.
 *
 * Covers the two pure/isolated seams: build_allowlist() (defensive filtering,
 * flat vs hierarchical) and write_allowlist_atomically() (guarded flat file +
 * atomic swap). The $wpdb-backed query and hook wiring are exercised by the
 * ddev e2e, not here.
 *
 * File Path: build-tools/tests/unit-testing/unit/PostShieldAllowlistBuilderTest.php
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\AllowlistBuilder;

// Require ONLY the class file. It has no top-level side effects, and the methods
// under test touch only preg_match + the filesystem. __DIR__ is tests/unit-testing/unit.
require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';

/**
 * Test class for AllowlistBuilder.
 */
class PostShieldAllowlistBuilderTest extends TestCase {

	/**
	 * Absolute path to a per-test scratch directory.
	 *
	 * @var string
	 */
	private string $work_dir = '';

	/**
	 * Create an isolated scratch directory for filesystem assertions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp(); // phpcs:ignore
		$this->work_dir = sys_get_temp_dir() . '/postshield-test-' . getmypid() . '-' . uniqid();
	}

	/**
	 * Remove the scratch directory after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->work_dir && is_dir( $this->work_dir ) ) {
			$this->delete_tree( $this->work_dir );
		}
		parent::tearDown(); // phpcs:ignore
	}

	/**
	 * Non-hierarchical: only ASCII [a-z0-9_-] slugs survive; slashes are dropped.
	 *
	 * Underscores are IN the list charset (root mode needs them — attachment
	 * slugs routinely carry underscores), while `match_entry()` still captures
	 * a based-entry slug as `[a-z0-9-]+`. An underscore slug therefore lands in
	 * the list but is never looked up by a based entry: the URL falls through
	 * to WordPress, which is the fail-open direction.
	 *
	 * @return void
	 */
	public function test_build_allowlist_flat_keeps_only_ascii_slugs() {
		$builder = new AllowlistBuilder( [] );
		$allow   = $builder->build_allowlist(
			[ 'jane-doe', 'BERT-STEPHANI', '%e9%8b%a4', 'eric-bouvet', '', 'jane_doe', 'a1-b2', 'parent/child' ]
		);

		$this->assertSame(
			[
				'jane-doe' => true,
				'eric-bouvet'   => true,
				'jane_doe' => true,
				'a1-b2'         => true,
			],
			$allow
		);
	}

	/**
	 * The written file is a `<?php exit;`-guarded flat list, and both directory
	 * levels are hardened with an index.php.
	 *
	 * @return void
	 */
	public function test_write_allowlist_atomically_produces_guarded_flat_file() {
		$builder = new AllowlistBuilder( [] );
		$file    = $this->work_dir . '/post-404-shield/member/allowlist.php';
		$allow   = [
			'jane-doe' => true,
			'eric-bouvet'   => true,
		];

		$result = $builder->write_allowlist_atomically( $allow, $file );

		$this->assertTrue( $result, 'Atomic write should report success.' );
		$this->assertFileExists( $file );

		$raw = file_get_contents( $file );
		$this->assertStringStartsWith( '<?php exit;', $raw, 'First line must be the HTTP guard.' );
		$this->assertSame( [ 'jane-doe', 'eric-bouvet' ], $this->parse_slugs( $raw ) );
		$this->assertFileExists( dirname( $file ) . '/index.php', 'Per-type dir hardened.' );
		$this->assertFileExists( dirname( $file, 2 ) . '/index.php', 'Root dir hardened.' );
		$this->assertSame( [], $this->list_temp_files( dirname( $file ) ), 'No temp files remain.' );
	}

	/**
	 * A second write fully replaces the previous contents (full rebuild).
	 *
	 * @return void
	 */
	public function test_write_allowlist_atomically_replaces_existing_file() {
		$builder = new AllowlistBuilder( [] );
		$file    = $this->work_dir . '/post-404-shield/member/allowlist.php';

		$builder->write_allowlist_atomically( [ 'old-slug' => true ], $file );
		$builder->write_allowlist_atomically( [ 'new-slug' => true ], $file );

		$this->assertSame( [ 'new-slug' ], $this->parse_slugs( (string) file_get_contents( $file ) ) );
	}

	/**
	 * reconcile_dir() self-cleans uploads: it removes the directory for a type
	 * switched off (`enabled => false`) or dropped from config entirely, keeps
	 * enabled types, and preserves the hardened root index.php.
	 *
	 * @return void
	 */
	public function test_reconcile_dir_removes_disabled_and_orphaned_type_dirs() {
		$builder = new AllowlistBuilder(
			[
				'member' => [ 'enabled' => true ],
				'product'       => [ 'enabled' => false ],
			]
		);
		$root = $this->work_dir . '/post-404-shield';

		// Enabled, disabled, and orphaned (absent from config) type dirs.
		$builder->write_allowlist_atomically( [ 'jane-doe' => true ], $root . '/member/allowlist.php' );
		$builder->write_allowlist_atomically( [ 'trail-5' => true ], $root . '/product/allowlist.php' );
		$builder->write_allowlist_atomically( [ 'legacy-slug' => true ], $root . '/accessory/allowlist.php' );

		$builder->reconcile_dir( $root );

		$this->assertTrue( is_dir( $root . '/member' ), 'Enabled type dir kept.' );
		$this->assertFalse( is_dir( $root . '/product' ), 'Disabled type dir removed.' );
		$this->assertFalse( is_dir( $root . '/accessory' ), 'Orphaned type dir removed.' );
		$this->assertFileExists( $root . '/index.php', 'Root hardening index preserved.' );
	}

	/**
	 * append_slug_to_file() is the optimistic fast-path: it appends a well-formed
	 * slug to an existing file, rejects a malformed slug, and no-ops when the file
	 * does not exist yet (the rebuild will create it, guarded).
	 *
	 * @return void
	 */
	public function test_append_slug_appends_guards_and_skips_missing() {
		$builder = new AllowlistBuilder( [] );
		$file    = $this->work_dir . '/post-404-shield/member/allowlist.php';

		// No guarded file yet → leave it to the rebuild.
		$this->assertFalse( $builder->append_slug_to_file( $file, 'jane-doe' ) );

		$builder->write_allowlist_atomically( [ 'jane-doe' => true ], $file );

		$this->assertTrue( $builder->append_slug_to_file( $file, 'eric-bouvet' ) );
		$this->assertSame(
			[ 'jane-doe', 'eric-bouvet' ],
			$this->parse_slugs( (string) file_get_contents( $file ) ),
			'Slug appended on a new line; existing lines intact.'
		);

		// Malformed slug rejected; file unchanged.
		$this->assertFalse( $builder->append_slug_to_file( $file, 'Bad_Slug' ) );
		$this->assertSame( [ 'jane-doe', 'eric-bouvet' ], $this->parse_slugs( (string) file_get_contents( $file ) ) );

		// The appended slug is newline-wrapped, so the loader's "\n{slug}\n" matches.
		$this->assertStringContainsString( "\neric-bouvet\n", (string) file_get_contents( $file ) );
	}

	/**
	 * is_shielding_status() reflects the type's post_status config (default
	 * `publish`), driving when the sync controller fast-appends a slug.
	 *
	 * @return void
	 */
	public function test_is_shielding_status_reflects_config() {
		$builder = new AllowlistBuilder(
			[
				'member' => [],
				'product'       => [ 'post_status' => [ 'publish', 'discontinued' ] ],
			]
		);

		$this->assertTrue( $builder->is_shielding_status( 'member', 'publish' ), 'Default status is publish.' );
		$this->assertFalse( $builder->is_shielding_status( 'member', 'draft' ) );
		$this->assertTrue( $builder->is_shielding_status( 'product', 'discontinued' ), 'Configured extra status shields.' );
		$this->assertFalse( $builder->is_shielding_status( 'product', 'pending' ) );
	}

	/**
	 * allowlist_type_map() resolves the effective CPT (`post_type` ?? key),
	 * collapses entries sharing a CPT to one map row with UNIONED statuses,
	 * defaults to publish, and excludes disabled and mode=block entries.
	 *
	 * @return void
	 */
	public function test_allowlist_type_map_resolves_shared_post_types() {
		$builder = new AllowlistBuilder(
			[
				'member'           => [ 'enabled' => true ],
				'product'                 => [
					'enabled'     => true,
					'post_status' => [ 'publish', 'discontinued' ],
				],
				'support-compat-shoes' => [
					'enabled'     => true,
					'post_type'   => 'compatibility',
					'post_status' => [ 'publish' ],
				],
				'support-compat-boots'  => [
					'enabled'     => true,
					'post_type'   => 'compatibility',
					'post_status' => [ 'publish', 'discontinued' ],
				],
				'switched-off'           => [ 'enabled' => false ],
				'old-section'              => [
					'enabled'  => true,
					'mode'     => 'block',
					'url_base' => 'old-section',
				],
			]
		);

		$map = $builder->allowlist_type_map();

		$this->assertSame( [ 'member', 'product', 'compatibility' ], array_keys( $map ), 'Shared CPT collapses; disabled + block excluded.' );
		$this->assertSame( [ 'publish' ], $map['member'], 'Missing post_status defaults to publish.' );
		$this->assertSame( [ 'publish', 'discontinued' ], $map['product'] );
		$this->assertSame( [ 'publish', 'discontinued' ], $map['compatibility'], 'Statuses unioned across entries sharing the CPT.' );
	}

	/**
	 * Parse an allowlist file body the way the front-end bootstrap does.
	 *
	 * @param string $raw Raw file contents.
	 *
	 * @return string[]
	 */
	private function parse_slugs( string $raw ): array {
		$body = trim( substr( $raw, (int) strpos( $raw, "\n" ) + 1 ) );
		return '' === $body ? [] : explode( "\n", $body );
	}

	/**
	 * List leftover `*.tmp` temp files in a directory.
	 *
	 * @param string $dir Directory to scan.
	 *
	 * @return string[]
	 */
	private function list_temp_files( string $dir ): array {
		return array_values( (array) glob( $dir . '/*.tmp' ) );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $dir Directory to remove.
	 *
	 * @return void
	 */
	private function delete_tree( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( is_dir( $path ) ) {
				$this->delete_tree( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
