<?php
/**
 * Unit tests for the root preflight's decision and attribution: an empty
 * root list is measured armed (the first appended post arms root matching
 * site-wide, and no preflight runs then), and a would-block URL is traced to
 * its list line the way the matcher strips sub-routes, so a dropped status
 * on a paginated probe can be confirmed.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\RootPreflight;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Function/Matcher.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/AllowlistBuilder.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/RootPreflight.php';

/**
 * Test class for the root preflight's pure parts.
 */
class PostShieldRootPreflightTest extends TestCase {

	/**
	 * Call a private method.
	 *
	 * @param string       $method Method.
	 * @param array<mixed> $args   Arguments.
	 *
	 * @return mixed
	 */
	private function call( string $method, array $args ) {
		$reflection = new \ReflectionMethod( RootPreflight::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $reflection->isStatic() ? null : new RootPreflight(), $args );
	}

	/**
	 * A root candidate for decide().
	 *
	 * @param string $type Post type.
	 * @param string $body List body.
	 *
	 * @return array<string, mixed>
	 */
	private function candidate( string $type, string $body ): array {
		return [
			'type'             => $type,
			'allow_pagination' => true,
			'body'             => $body,
			'endpoints'        => [],
		];
	}

	/**
	 * With the post list empty, the walk still measures the root stage: an
	 * unknown URL would-blocks, as it will once one post is published.
	 *
	 * @return void
	 */
	public function test_an_empty_root_list_is_measured_armed(): void {
		$this->assertFalse( \Post404Shield\list_is_empty( $this->call( 'armed_body', [ '' ] ) ), 'Armed, not empty.' );
		$this->assertSame( "<?php exit;\nabout\n", $this->call( 'armed_body', [ "<?php exit;\nabout\n" ] ) );

		$candidates = [
			$this->candidate( 'page', "<?php exit;\nabout\n" ),
			$this->candidate( 'post', $this->call( 'armed_body', [ '' ] ) ),
			$this->candidate( 'root-extras', '' ),
		];
		$decision   = $this->call( 'decide', [ '/some-archive/', [], [], [], $candidates, '' ] );
		$this->assertSame( 'blocked-unknown-slug', $decision['marker'] );
		$this->assertSame( 'root', $decision['stage'] );
		$this->assertSame( 'allowed-known-slug', $this->call( 'decide', [ '/about/', [], [], [], $candidates, '' ] )['marker'] );
	}

	/**
	 * A probed URL maps back to its line whatever sub-route the probe added.
	 *
	 * @return void
	 */
	public function test_a_probe_maps_back_to_its_line(): void {
		$pattern = '[a-z]{2}-[a-z]{2}|global';
		foreach ( [ '/global/old-page/2/', '/global/old-page/page/3/', '/ja-jp/old-page/feed/', '/old-page/' ] as $url ) {
			$this->assertSame( 'old-page', $this->call( 'line_of', [ $url, $pattern ] ), $url );
		}
		$this->assertSame( 'parent/child', $this->call( 'line_of', [ '/global/parent/child/comment-page-2/', $pattern ] ) );
	}
}
