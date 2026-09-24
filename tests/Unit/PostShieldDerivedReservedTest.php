<?php
/**
 * Unit tests for the reserved slugs a save derives from the site's redirects
 * and rewrite routes (ConfigStore::apply_derived_reserved()) — what lets a
 * redirect's 301, or a route WordPress serves under a shielded base, reach
 * WordPress instead of the pre-boot 404.
 *
 * The redirect readers need a database, so the sources are put straight into
 * the request's source cache; the routes come from a stub rewrite object.
 *
 * @package Post404Shield\Tests
 */

namespace Post404Shield\Tests;

use PHPUnit\Framework\TestCase;
use Post404Shield\Library\ConfigStore;

require_once __DIR__ . '/../../post-404-shield/src/php/Function/ConfigReader.php';
require_once __DIR__ . '/../../post-404-shield/src/php/Library/ConfigStore.php';

/**
 * Test class for derived reserved slugs.
 */
class PostShieldDerivedReservedTest extends TestCase {

	/**
	 * The locale pattern the sites use.
	 */
	private const PATTERN = '[a-z]{2}-[a-z]{2}|global';

	/**
	 * Forget the stub rewrite object.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_rewrite'] );
	}

	/**
	 * A store whose redirect read found these sources (and these unmappable ones).
	 *
	 * @param array<int, array{pattern: string, regex: bool}>        $sources    Readable sources.
	 * @param array<int, array{pattern: string, comparison: string}> $unmappable Contains / ends-with ones.
	 *
	 * @return ConfigStore
	 */
	private function store( array $sources, array $unmappable = [] ): ConfigStore {
		$store = new ConfigStore();
		foreach ( [
			'redirect_sources_cache' => $sources,
			'unmappable_redirects'   => $unmappable,
		] as $property => $value ) {
			$reflection = new \ReflectionProperty( ConfigStore::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $store, $value );
		}
		return $store;
	}

	/**
	 * A stub rewrite table.
	 *
	 * @param string[] $rules Rule patterns.
	 * @param string   $front The permalink front (`blog` for /blog/%postname%/).
	 *
	 * @return void
	 */
	private function routes( array $rules, string $front = '' ): void {
		$GLOBALS['wp_rewrite'] = new class( $rules, $front ) {
			/**
			 * Permalink front.
			 *
			 * @var string
			 */
			public string $front;

			/**
			 * Rule patterns.
			 *
			 * @var string[]
			 */
			private array $rules;

			/**
			 * Build the stub.
			 *
			 * @param string[] $rules Rule patterns.
			 * @param string   $front Permalink front.
			 */
			public function __construct( array $rules, string $front ) {
				$this->rules = $rules;
				$this->front = '/' . $front . ( '' === $front ? '' : '/' );
			}

			/**
			 * The rewrite table, pattern => query.
			 *
			 * @return array<string, string>
			 */
			public function wp_rewrite_rules(): array {
				return array_fill_keys( $this->rules, 'index.php' );
			}
		};
	}

	/**
	 * Based entries under test.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function entries(): array {
		return [
			'news'         => [ 'url_base' => [ 'news' ] ],
			'photographer' => [ 'url_base' => [ 'photographers' ] ],
			'story'        => [ 'url_base' => [ 'stories' ] ],
			'post'         => [ 'url_base' => [ 'blog' ] ],
		];
	}

	/**
	 * What each entry derives.
	 *
	 * @param ConfigStore $store Store.
	 *
	 * @return array<string, string[]>
	 */
	private function derived( ConfigStore $store ): array {
		$out = [];
		foreach ( $store->apply_derived_reserved( $this->entries(), self::PATTERN ) as $key => $entry ) {
			$out[ $key ] = $entry['reserved_derived'] ?? [];
		}
		return $out;
	}

	/**
	 * Redirect sources reserve the slugs their 301s start from.
	 *
	 * @return void
	 */
	public function test_redirects_reserve_their_slugs(): void {
		$this->routes( [] );
		$derived = $this->derived(
			$this->store(
				[
					[
						'pattern' => '/global/photographers/old-name',
						'regex'   => false,
					],
					[
						'pattern' => '^/([a-z]{2}-[a-z]{2}|global)/news/(\d+)/?$',
						'regex'   => true,
					],
					[
						'pattern' => '^\/stories\/old-(tips|video)\/?$',
						'regex'   => true,
					],
				]
			)
		);

		$this->assertSame( [ 'old-name' ], $derived['photographer'] );
		$this->assertSame( array_map( static fn( $d ) => $d . '*', range( 0, 9 ) ), $derived['news'], 'A numeric part below the base reserves every digit-led slug.' );
		$this->assertSame( [ 'old-tips', 'old-video' ], $derived['story'] );
	}

	/**
	 * Routes WordPress serves under a base are reserved, including the
	 * capture-led literal groups SEO plugins emit, and the date archives at
	 * the permalink front.
	 *
	 * @return void
	 */
	public function test_routes_under_a_base_are_reserved(): void {
		$this->routes( [ 'stories/tips/?$', 'stories/(video|review)/?$', 'stories/([^/]+)/?$', 'blog/category/(.+?)/?$', 'stories\\/guides\\/?$' ], 'blog' );
		$derived = $this->derived( $this->store( [] ) );

		$this->assertSame( [ 'guides', 'review', 'tips', 'video' ], $derived['story'], 'An escaped slash is a separator.' );
		$this->assertContains( 'category', $derived['post'] );
		$this->assertContains( '2*', $derived['post'], 'The front carries the date archives.' );
		$this->assertSame( [], $derived['news'] );
	}

	/**
	 * A redirect nothing can place still says so at save.
	 *
	 * @return void
	 */
	public function test_unplaceable_redirects_warn(): void {
		$this->routes( [] );
		$store = $this->store(
			[
				[
					'pattern' => '^.*news/.*$',
					'regex'   => true,
				],
			],
			[
				[
					'pattern'    => 'photographers/old',
					'comparison' => 'contains',
				],
			]
		);
		$store->apply_derived_reserved( $this->entries(), self::PATTERN );
		$warnings = implode( "\n", (array) ( new \ReflectionProperty( ConfigStore::class, 'derivation_warnings' ) )->getValue( $store ) );

		$this->assertStringContainsString( 'looks like it covers addresses under /news/', $warnings );
		$this->assertStringContainsString( 'matches addresses that contain it', $warnings );
	}

	/**
	 * The save's warnings, after deriving for these entries.
	 *
	 * @param ConfigStore                         $store   Store.
	 * @param array<string, array<string, mixed>> $entries Entries.
	 *
	 * @return array{0: array<string, mixed>, 1: string} The entries, and the warnings joined.
	 */
	private function derive_with( ConfigStore $store, array $entries ): array {
		$entries  = $store->apply_derived_reserved( $entries, self::PATTERN );
		$warnings = implode( "\n", (array) ( new \ReflectionProperty( ConfigStore::class, 'derivation_warnings' ) )->getValue( $store ) );
		return [ $entries, $warnings ];
	}

	/**
	 * A locale group whose every branch carries its own slash is still the
	 * locale, and the group after it is expanded.
	 *
	 * @return void
	 */
	public function test_a_locale_group_with_a_slash_per_branch_is_stripped(): void {
		$this->routes( [] );
		[ $entries ] = $this->derive_with(
			$this->store(
				[
					[
						'pattern' => '^(?:[a-z]{2}-[a-z]{2}/|global/)?products/(cameras|lenses)/x-t3/?$',
						'regex'   => true,
					],
				]
			),
			[ 'camera' => [ 'url_base' => [ 'products/cameras' ] ] ]
		);
		$this->assertSame( [ 'x-t3' ], $entries['camera']['reserved_derived'] ?? [] );
	}

	/**
	 * Each reading of a source is judged on its own: a placed branch does not
	 * hide an unreadable one.
	 *
	 * @return void
	 */
	public function test_an_unreadable_branch_warns_beside_a_placed_one(): void {
		$this->routes( [] );
		[ $entries, $warnings ] = $this->derive_with(
			$this->store(
				[
					[
						'pattern' => '^global/stories/old-a/?$|^.*/stories/old-c/?$',
						'regex'   => true,
					],
				]
			),
			[ 'story' => [ 'url_base' => [ 'stories' ] ] ]
		);
		$this->assertSame( [ 'old-a' ], $entries['story']['reserved_derived'] ?? [] );
		$this->assertStringContainsString( 'looks like it covers addresses under /stories/', $warnings );
	}

	/**
	 * A redirect at or under a blocked section is answered by the block
	 * first: the save says so, for exact, regex and contains redirects alike.
	 *
	 * @return void
	 */
	public function test_a_redirect_under_a_blocked_section_warns(): void {
		$this->routes( [] );
		$block = [
			'x-photographers' => [
				'mode'     => 'block',
				'url_base' => [ 'x-photographers' ],
			],
		];
		foreach ( [
			[ '/global/x-photographers/jane-doe', false, 'is at or under /x-photographers/' ],
			[ '^(?:[a-z]{2}-[a-z]{2}|global)/x-photographers/(.+)$', true, 'is at or under /x-photographers/' ],
			[ '^.*/x-photographers/jane/?$', true, 'may cover addresses under /x-photographers/' ],
		] as [ $pattern, $regex, $expected ] ) {
			[ , $warnings ] = $this->derive_with(
				$this->store(
					[
						[
							'pattern' => $pattern,
							'regex'   => $regex,
						],
					]
				),
				$block
			);
			$this->assertStringContainsString( 'Blocked section x-photographers: the redirect "' . $pattern . '" ' . $expected, $warnings, $pattern );
		}

		[ , $warnings ] = $this->derive_with(
			$this->store(
				[],
				[
					[
						'pattern'    => 'x-photographers/jane',
						'comparison' => 'contains',
					],
				]
			),
			$block
		);
		$this->assertStringContainsString( 'matches addresses that contain it', $warnings );

		// A redirect elsewhere says nothing about the block.
		[ , $warnings ] = $this->derive_with(
			$this->store(
				[
					[
						'pattern' => '/global/photographers/jane-doe',
						'regex'   => false,
					],
				]
			),
			$block
		);
		$this->assertStringNotContainsString( 'Blocked section', $warnings );
	}

	/**
	 * Root mode: a regex redirect with no leading literal, or unanchored,
	 * cannot be let through by an excluded base, and the save says so; one
	 * that starts with its path says nothing.
	 *
	 * @return void
	 */
	public function test_root_mode_warns_about_redirects_no_base_can_cover(): void {
		$this->routes( [] );
		$root = [
			'page' => [
				'root'      => true,
				'post_type' => 'page',
			],
			'post' => [
				'root'      => true,
				'post_type' => 'post',
			],
		];
		foreach ( [ '^(.*)/amp/?$' => true, 'amp/?$' => true, '^.*/legacy/(.*)$' => true, '^old-page/?$' => false, '^/?$' => false ] as $pattern => $warns ) {
			[ , $warnings ] = $this->derive_with(
				$this->store(
					[
						[
							'pattern' => $pattern,
							'regex'   => true,
						],
					]
				),
				$root
			);
			$this->assertSame( $warns, str_contains( $warnings, 'Root mode: the redirect "' . $pattern . '"' ), $pattern );
		}
	}

	/**
	 * A regex that spells a multi-segment base with an optional character
	 * or a group inside it names the base: nothing can be reserved for it,
	 * so the save says so.
	 *
	 * @return void
	 */
	public function test_a_base_spelled_with_optional_characters_warns(): void {
		$this->routes( [] );
		foreach ( [ '^products?/cameras/old-model/?$', '^products/camera(s)?/old-model/?$' ] as $pattern ) {
			[ , $warnings ] = $this->derive_with(
				$this->store(
					[
						[
							'pattern' => $pattern,
							'regex'   => true,
						],
					]
				),
				[ 'camera' => [ 'url_base' => [ 'products/cameras' ] ] ]
			);
			$this->assertStringContainsString( 'looks like it covers addresses under /products/cameras/', $warnings, $pattern );
		}
	}
}
