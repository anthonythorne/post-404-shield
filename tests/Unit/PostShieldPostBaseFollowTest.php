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
}
