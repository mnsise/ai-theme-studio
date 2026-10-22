<?php
/**
 * Base test case.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Tests\Unit;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		\WP_Stub_State::reset();
	}

	/**
	 * Fixture data.
	 *
	 * @param string $key Top-level key.
	 * @return array<string, mixed>
	 */
	protected function fixture( string $key ): array {
		static $data = null;
		if ( null === $data ) {
			$data = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/sample-site.json' ), true );
		}
		return $data[ $key ];
	}
}
