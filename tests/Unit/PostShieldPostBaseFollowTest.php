<?php
/**
 * Unit tests for how a based Posts entry follows Settings → Permalinks
 * between saves (ConfigStore::revalidate_root()'s helpers): it moves to a new
 * base, and it is switched off, settings kept, when the structure gives posts
 * no fixed shape or no base at all — posts then live at the root, and an
 * entry still claiming `/blog/` would 404 the pages WordPress serves there.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';

/**
 * Test class for the Posts entry following the permalink structure.
 */
class PostShieldPostBaseFollowTest extends TestCase {

	/**
	 * Forget the permalink structure.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['post_shield_test_options'] );
	}

	/**
	 * A config with a based Posts entry under /blog/.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return [
			'entries' => [
				'post' => [
					'enabled'            => true,
					'mode'               => 'allowlist',
					'post_type'          => 'post',
					'url_base'           => [ 'blog' ],
					'reserved_allowlist' => [ 'kept-slug' ],
				],
			],
		];
	}

	/**
	 * Run one of the store's private helpers under a permalink structure.
	 *
	 * @param string $helper    Method name.
	 * @param string $structure `permalink_structure`.
	 *
	 * @return array<string, mixed> The Posts entry it returns.
	 */
	private function follow( string $helper, string $structure ): array {
		$GLOBALS['post_shield_test_options'] = [ 'permalink_structure' => $structure ];
		$method                              = new \ReflectionMethod( ConfigStore::class, $helper );
		$method->setAccessible( true );
		return $method->invoke( new ConfigStore(), $this->config() )['entries']['post'];
	}

	/**
	 * Posts at the root (`/%postname%/`): the based entry is switched off,
	 * its settings kept.
	 *
	 * @return void
	 */
	public function test_posts_at_the_root_switch_the_based_entry_off(): void {
		$entry = $this->follow( 'with_unservable_post_disabled', '/%postname%/' );
		$this->assertFalse( $entry['enabled'] );
		$this->assertSame( [ 'blog' ], $entry['url_base'] );
		$this->assertSame( [ 'kept-slug' ], $entry['reserved_allowlist'] );
	}

	/**
	 * A structure with no fixed shape switches it off; a fixed base keeps it on.
	 *
	 * @return void
	 */
	public function test_only_a_fixed_base_keeps_it_on(): void {
		$this->assertFalse( $this->follow( 'with_unservable_post_disabled', '/blog/%category%/%postname%/' )['enabled'] );
		$this->assertTrue( $this->follow( 'with_unservable_post_disabled', '/blog/%postname%/' )['enabled'] );
	}

	/**
	 * A new fixed base is followed.
	 *
	 * @return void
	 */
	public function test_a_new_base_is_followed(): void {
		$this->assertSame( [ 'news' ], $this->follow( 'with_current_post_base', '/news/%postname%/' )['url_base'] );
	}

	/**
	 * Root mode: a Posts base a save moves stays in the skip-list, once.
	 *
	 * @return void
	 */
	public function test_a_vacated_posts_base_is_kept_once(): void {
		$method = new \ReflectionMethod( ConfigStore::class, 'with_vacated_post_base_kept' );
		$method->setAccessible( true );
		$live      = $this->config();
		$candidate = $this->config();
		$candidate['entries']['post']['url_base'] = [ 'news' ];

		[ $kept, $vacated ] = $method->invoke( new ConfigStore(), $live, $candidate );
		$this->assertSame( [ 'blog' ], $vacated );
		$this->assertSame( [ 'blog' ], $kept['excluded_bases']['operator'] );

		[ $again, $vacated ] = $method->invoke( new ConfigStore(), $live, $kept );
		$this->assertSame( [], $vacated, 'Already kept: not added twice.' );
		$this->assertSame( [ 'blog' ], $again['excluded_bases']['operator'] );
	}

	/**
	 * Run with_vacated_post_base_kept() under a permalink structure.
	 *
	 * @param string               $structure `permalink_structure`.
	 * @param array<string, mixed> $live      Live document.
	 * @param array<string, mixed> $candidate Candidate.
	 *
	 * @return string[] The bases it kept.
	 */
	private function vacated( string $structure, array $live, array $candidate ): array {
		$GLOBALS['post_shield_test_options'] = [ 'permalink_structure' => $structure ];
		$method                              = new \ReflectionMethod( ConfigStore::class, 'with_vacated_post_base_kept' );
		$method->setAccessible( true );
		return $method->invoke( new ConfigStore(), $live, $candidate )[1];
	}

	/**
	 * Root Pages with posts under /blog/ and no Posts entry: the snapshot's
	 * post base alone keeps /blog/ passing once posts move to /news/.
	 *
	 * @return void
	 */
	public function test_the_snapshot_post_base_alone_keeps_a_moved_base(): void {
		$root = [
			'entries'        => [
				'page' => [
					'enabled'   => true,
					'root'      => true,
					'post_type' => 'page',
				],
			],
			'excluded_bases' => [
				'operator'  => [],
				'post_base' => 'blog',
			],
		];
		$this->assertSame( [ 'blog' ], $this->vacated( '/news/%postname%/', $root, $root ) );
		$this->assertSame( [], $this->vacated( '/blog/%postname%/', $root, $root ), 'Not moved: nothing kept.' );
	}

	/**
	 * A Posts entry switched off (the automatic switch-off, or by hand) still
	 * says where posts lived.
	 *
	 * @return void
	 */
	public function test_a_switched_off_posts_entry_keeps_its_base(): void {
		$live                                = $this->config();
		$live['entries']['post']['enabled'] = false;
		$this->assertSame( [ 'blog' ], $this->vacated( '/news/%postname%/', $live, $live ) );
	}

	/**
	 * The snapshot records the post base: '' at the root, null with no fixed
	 * base, so the two are never confused.
	 *
	 * @return void
	 */
	public function test_the_snapshot_records_the_post_base(): void {
		foreach ( [
			'/blog/%postname%/'       => 'blog',
			'/%postname%/'            => '',
			'/%category%/%postname%/' => null,
		] as $structure => $base ) {
			$GLOBALS['post_shield_test_options'] = [ 'permalink_structure' => $structure ];
			$this->assertSame( $base, ( new ConfigStore() )->excluded_bases_snapshot( [] )['post_base'], $structure );
		}
	}

	/**
	 * Posts that lived at the root (a root Posts entry, or a snapshot that
	 * found them there) and now have a base are remembered — and carried —
	 * until they live at the root again.
	 *
	 * @return void
	 */
	public function test_posts_leaving_the_root_are_remembered(): void {
		$method = new \ReflectionMethod( ConfigStore::class, 'posts_left_the_root' );
		$method->setAccessible( true );
		$left = static function ( string $structure, array $live, array $candidate = [] ) use ( $method ): bool {
			$GLOBALS['post_shield_test_options'] = [
				'permalink_structure' => $structure,
				ConfigStore::OPTION   => $live,
			];
			return $method->invoke( new ConfigStore(), $candidate );
		};
		$root_posts = [
			'entries' => [
				'post' => [
					'enabled'   => true,
					'root'      => true,
					'post_type' => 'post',
				],
			],
		];
		$this->assertTrue( $left( '/blog/%postname%/', $root_posts ) );
		$this->assertFalse( $left( '/%postname%/', $root_posts ), 'Still at the root.' );
		$this->assertTrue( $left( '/blog/%postname%/', [ 'excluded_bases' => [ 'post_base' => '' ] ] ), 'A snapshot found them at the root.' );
		$this->assertTrue( $left( '/news/%postname%/', [ 'excluded_bases' => [ 'posts_left_root' => true ] ] ), 'Carried.' );
		$this->assertFalse( $left( '/%postname%/', [ 'excluded_bases' => [ 'posts_left_root' => true ] ] ), 'Back at the root: over.' );
		$this->assertFalse( $left( '/news/%postname%/', [ 'excluded_bases' => [ 'post_base' => 'blog' ] ] ), 'Never at the root.' );
		$this->assertFalse( $left( '/news/%postname%/', [ 'excluded_bases' => [ 'post_base' => null ] ] ), 'No fixed base is not the root.' );
	}

	/**
	 * An automatic write on a site without root entries compares only what
	 * the loader reads: a new redirect source base archives no revision.
	 *
	 * @return void
	 */
	public function test_without_root_entries_only_what_the_loader_reads_is_compared(): void {
		$as_loaded = new \ReflectionMethod( ConfigStore::class, 'as_loaded' );
		$as_loaded->setAccessible( true );
		$same = new \ReflectionMethod( ConfigStore::class, 'same_document' );
		$same->setAccessible( true );
		$live                                   = $this->config();
		$live['excluded_bases']                 = [
			'floor'     => [ 'wp-admin' ],
			'derived'   => [ 'old-news' ],
			'operator'  => [],
			'endpoints' => [ 'amp' ],
			'post_base' => 'blog',
		];
		$candidate                              = $live;
		$candidate['excluded_bases']['derived'] = [ 'old-news', 'old-events' ];
		$this->assertTrue( $same->invoke( null, $as_loaded->invoke( null, $candidate ), $as_loaded->invoke( null, $live ) ) );

		$candidate['excluded_bases']['endpoints'] = [ 'amp', 'embed-card' ];
		$this->assertFalse( $same->invoke( null, $as_loaded->invoke( null, $candidate ), $as_loaded->invoke( null, $live ) ), 'Endpoints serve based matching.' );

		$live['entries']['page']               = [
			'enabled'   => false,
			'root'      => true,
			'post_type' => 'page',
		];
		$candidate                              = $live;
		$candidate['excluded_bases']['derived'] = [ 'old-news', 'old-events' ];
		$this->assertTrue( $same->invoke( null, $as_loaded->invoke( null, $candidate ), $as_loaded->invoke( null, $live ) ), 'Root rows kept off: root matching does not run, derived drift archives nothing.' );

		$candidate['excluded_bases']['operator'] = [ 'blog' ];
		$this->assertFalse( $same->invoke( null, $as_loaded->invoke( null, $candidate ), $as_loaded->invoke( null, $live ) ), 'Root rows kept off: a kept Posts base still lands.' );

		$live['entries']['page']['enabled']     = true;
		$candidate                              = $live;
		$candidate['excluded_bases']['derived'] = [ 'old-news', 'old-events' ];
		$this->assertFalse( $same->invoke( null, $as_loaded->invoke( null, $candidate ), $as_loaded->invoke( null, $live ) ), 'Root matching on: all of it counts.' );
	}

	/**
	 * A blocked root URL takes its cache times from the Pages entry, as the
	 * loader does — although Posts comes first in the document.
	 *
	 * @return void
	 */
	public function test_root_cache_times_come_from_the_pages_entry(): void {
		$method = new \ReflectionMethod( ConfigStore::class, 'root_ttl_entry_key' );
		$method->setAccessible( true );
		$root = static fn( string $type ): array => [
			'enabled'   => true,
			'root'      => true,
			'post_type' => $type,
		];
		$this->assertSame( 'page', $method->invoke( null, [ 'post' => $root( 'post' ), 'page' => $root( 'page' ) ] ) );
		$this->assertSame( 'post', $method->invoke( null, [ 'post' => $root( 'post' ) ] ) );
		$this->assertSame( '', $method->invoke( null, [ 'post' => [ 'enabled' => false ] + $root( 'post' ) ] ) );
	}
}
