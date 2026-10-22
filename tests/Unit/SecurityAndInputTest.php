<?php
/**
 * Secrets, settings, rate limiting, input sanitisation, sitemap and menu rules.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Tests\Unit;

use AIThemeStudio\Core\Settings;
use AIThemeStudio\Generation\Pipeline;
use AIThemeStudio\REST\ProjectController;
use AIThemeStudio\Security\Crypto;
use AIThemeStudio\Security\RateLimiter;
use AIThemeStudio\WordPress\MenuManager;

final class SecurityAndInputTest extends TestCase {

	public function test_crypto_round_trip_and_tamper_detection(): void {
		$enc = Crypto::encrypt( 'sk-secret-123' );
		$this->assertStringStartsWith( 'v1:', $enc );
		$this->assertStringNotContainsString( 'sk-secret', $enc );
		$this->assertSame( 'sk-secret-123', Crypto::decrypt( $enc ) );

		$tampered = substr( $enc, 0, -2 ) . ( 'A' === substr( $enc, -2, 1 ) ? 'B' : 'A' ) . substr( $enc, -1 );
		$this->assertSame( '', Crypto::decrypt( $tampered ) );
		$this->assertSame( '', Crypto::decrypt( 'garbage' ) );
	}

	public function test_settings_store_keys_encrypted_and_expose_them_masked(): void {
		$s    = new Settings();
		$view = $s->update(
			array(
				'provider' => 'anthropic',
				'model'    => 'claude-sonnet-4-5; DROP TABLE',
				'api_key'  => 'sk-ant-abcdef1234',
				'timeout'  => 99999,
				'base_url' => 'http://insecure.example/v1',
			)
		);
		$this->assertSame( 'anthropic', $view['provider'] );
		$this->assertSame( 'claude-sonnet-4-5DROPTABLE', $view['model'] );
		$this->assertSame( 600, $view['timeout'] );
		$this->assertSame( '', $view['base_url'], 'non-HTTPS base URL rejected' );
		$this->assertStringEndsWith( '1234', $view['api_key'] );
		$this->assertStringNotContainsString( 'sk-ant', $view['api_key'] );

		$stored = get_option( Settings::OPTION );
		$this->assertStringStartsWith( 'v1:', $stored['api_key'] );
		$this->assertSame( 'sk-ant-abcdef1234', ( new Settings() )->api_key() );

		// Re-saving the masked value (what the UI sends back) keeps the key.
		( new Settings() )->update( array( 'api_key' => $view['api_key'] ) );
		$this->assertSame( 'sk-ant-abcdef1234', ( new Settings() )->api_key() );

		( new Settings() )->update( array( 'api_key' => '__clear__' ) );
		$this->assertSame( '', ( new Settings() )->api_key() );
	}

	public function test_unknown_provider_is_rejected(): void {
		$view = ( new Settings() )->update( array( 'provider' => 'evil' ) );
		$this->assertSame( 'openai', $view['provider'] );
	}

	public function test_rate_limiter_blocks_after_limit(): void {
		$this->assertTrue( RateLimiter::hit( 'generate', 2 ) );
		$this->assertTrue( RateLimiter::hit( 'generate', 2 ) );
		$this->assertFalse( RateLimiter::hit( 'generate', 2 ) );
		$this->assertTrue( RateLimiter::hit( 'other', 2 ) );
	}

	public function test_project_input_is_sanitized(): void {
		$in = ProjectController::sanitize_input(
			array(
				'prompt'     => '<script>alert(1)</script>یک سایت برای کلینیک دندانپزشکی در شیراز با رزرو آنلاین',
				'category'   => 'hacker',
				'language'   => 'fa',
				'direction'  => 'auto',
				'colors'     => array(
					'mode'    => 'custom',
					'primary' => '#12ab34',
					'secondary' => 'red; background:url(x)',
				),
				'fonts'      => array( 'heading' => 'Comic Sans', 'body' => 'Vazirmatn' ),
				'pages'      => array( 'about', 'contact', 'wp-admin' ),
				'business'   => array( 'email' => 'not-an-email', 'phone' => '+98 (71) 1234<b>' ),
				'responsive' => array( 'tablet_columns' => 9 ),
				'options'    => array( 'review_sitemap' => 'false' ),
			)
		);
		$this->assertIsArray( $in );
		$this->assertStringNotContainsString( '<script', $in['prompt'] );
		$this->assertSame( 'auto', $in['category'] );
		$this->assertSame( 'rtl', $in['direction'] );
		$this->assertSame( '#12AB34', $in['colors']['primary'] );
		$this->assertArrayNotHasKey( 'secondary', $in['colors'] );
		$this->assertSame( 'auto', $in['fonts']['heading'] );
		$this->assertSame( 'Vazirmatn', $in['fonts']['body'] );
		$this->assertSame( array( 'about', 'contact' ), $in['pages'] );
		$this->assertSame( '', $in['business']['email'] );
		$this->assertSame( '+98 (71) 1234', $in['business']['phone'] );
		$this->assertSame( 3, $in['responsive']['tablet_columns'] );
		$this->assertFalse( $in['options']['review_sitemap'] );
		$this->assertTrue( $in['options']['set_front_page'] );
	}

	public function test_short_and_huge_prompts_are_rejected(): void {
		$this->assertInstanceOf( \WP_Error::class, ProjectController::sanitize_input( array( 'prompt' => 'کوتاه' ) ) );
		$this->assertInstanceOf( \WP_Error::class, ProjectController::sanitize_input( array( 'prompt' => str_repeat( 'a ', 5000 ) ) ) );
	}

	public function test_ltr_language_gets_ltr_direction(): void {
		$in = ProjectController::sanitize_input( array( 'prompt' => 'A website for a bakery in Berlin with online orders.', 'language' => 'de' ) );
		$this->assertSame( 'ltr', $in['direction'] );
	}

	public function test_sitemap_validation_rules(): void {
		$sitemap                        = $this->fixture( 'sitemap' );
		$sitemap['pages'][]             = $sitemap['pages'][1];
		$sitemap['pages'][2]['slug']    = 'services';
		$sitemap['header_cta']['page_key'] = 'ghost';
		$r                              = Pipeline::validate_sitemap( $sitemap, array() );
		$this->assertSame( array(), $r['errors'] );

		$keys = array_column( $r['data']['pages'], 'key' );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'duplicate keys removed' );
		$slugs = array_column( $r['data']['pages'], 'slug' );
		$this->assertSame( count( $slugs ), count( array_unique( $slugs ) ), 'slugs unique' );

		$nf = array_values( array_filter( $r['data']['pages'], static fn( $p ) => 'not_found' === $p['type'] ) )[0];
		$this->assertFalse( $nf['in_menu'] );
		$this->assertTrue( $nf['noindex'] );
		$this->assertSame( 'contact', $r['data']['header_cta']['page_key'] );
	}

	public function test_sitemap_without_home_is_an_error(): void {
		$sitemap          = $this->fixture( 'sitemap' );
		$sitemap['pages'] = array_slice( $sitemap['pages'], 1 );
		$this->assertNotEmpty( Pipeline::validate_sitemap( $sitemap, array() )['errors'] );
	}

	public function test_sitemap_drops_unrequested_page_types(): void {
		$r = Pipeline::validate_sitemap( $this->fixture( 'sitemap' ), array( 'home', 'contact', 'not_found' ) );
		$this->assertSame( array( 'home', 'contact', 'not_found' ), array_column( $r['data']['pages'], 'key' ) );
		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_navigation_proposal_respects_menu_flags_and_hierarchy(): void {
		$sitemap = Pipeline::validate_sitemap( $this->fixture( 'sitemap' ), array() )['data'];
		$nav     = MenuManager::proposal_from_sitemap( $sitemap );
		$keys    = array_column( $nav, 'key' );
		$this->assertSame( array( 'home', 'services', 'implant', 'contact' ), $keys );
		$this->assertNotContains( 'not_found', $keys );
		$this->assertSame( 'services', $nav[2]['parent'] );
		$this->assertSame( 'ایمپلنت', $nav[2]['label'], 'falls back to title without menu label' );
	}
}
