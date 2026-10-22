<?php
/**
 * Schema, page, heading, contrast and JSON extraction tests.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Tests\Unit;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\JsonExtractor;
use AIThemeStudio\SEO\HeadingValidator;
use AIThemeStudio\Validation\Contrast;
use AIThemeStudio\Validation\PageValidator;
use AIThemeStudio\Validation\Schemas;
use AIThemeStudio\Validation\SchemaValidator;

final class ValidationTest extends TestCase {

	private function validator(): SchemaValidator {
		return new SchemaValidator( Schemas::definitions() );
	}

	public function test_valid_fixture_documents_pass_their_schemas(): void {
		foreach ( array( 'sitemap' => 'sitemap', 'design' => 'design_system', 'home' => 'page' ) as $fixture => $schema ) {
			$r = $this->validator()->validate( $this->fixture( $fixture ), Schemas::get( $schema ) );
			$this->assertSame( array(), $r['errors'], $fixture );
			$this->assertTrue( $r['valid'] );
		}
	}

	public function test_schema_reports_missing_required_and_bad_enum(): void {
		$page                        = $this->fixture( 'home' );
		$page['sections'][0]['type'] = 'carousel_of_doom';
		unset( $page['sections'][1]['elements'] );
		$r = $this->validator()->validate( $page, Schemas::get( 'page' ) );
		$this->assertFalse( $r['valid'] );
		$joined = implode( "\n", $r['errors'] );
		$this->assertStringContainsString( 'sections[0].type', $joined );
		$this->assertStringContainsString( 'elements', $joined );
	}

	public function test_schema_strips_unknown_keys_and_applies_defaults(): void {
		$page                                       = $this->fixture( 'home' );
		$page['sections'][0]['onclick']             = 'alert(1)';
		$page['sections'][0]['elements'][1]['php'] = '<?php system("id"); ?>';
		unset( $page['sections'][2]['background'] );
		$r = $this->validator()->validate( $page, Schemas::get( 'page' ) );
		$this->assertArrayNotHasKey( 'onclick', $r['data']['sections'][0] );
		$this->assertArrayNotHasKey( 'php', $r['data']['sections'][0]['elements'][1] );
		$this->assertSame( 'default', $r['data']['sections'][2]['background'] );
	}

	public function test_schema_rejects_wrong_types_and_bad_patterns(): void {
		$sitemap                       = $this->fixture( 'sitemap' );
		$sitemap['pages'][0]['key']    = 'Home Page!';
		$sitemap['pages'][1]['in_menu'] = 'yes please';
		$r                             = $this->validator()->validate( $sitemap, Schemas::get( 'sitemap' ) );
		$this->assertFalse( $r['valid'] );
		$this->assertGreaterThanOrEqual( 2, count( $r['errors'] ) );
	}

	public function test_json_extractor_handles_fences_and_chatter(): void {
		$this->assertSame( array( 'a' => 1 ), JsonExtractor::extract( '{"a":1}' ) );
		$this->assertSame( array( 'a' => 2 ), JsonExtractor::extract( "Sure!\n```json\n{\"a\":2}\n```\nDone." ) );
		$this->assertSame( array( 'a' => '}{' ), JsonExtractor::extract( 'Result: {"a":"}{"} trailing' ) );
	}

	public function test_json_extractor_throws_on_garbage(): void {
		$this->expectException( AIException::class );
		JsonExtractor::extract( 'I cannot do that.' );
	}

	public function test_heading_fix_enforces_single_h1_and_no_skipped_levels(): void {
		$page  = $this->fixture( 'home' );
		$fixes = HeadingValidator::fix_page( $page );
		$this->assertNotEmpty( $fixes );

		$levels = array();
		array_walk_recursive(
			$page,
			static function ( $v, $k ) use ( &$levels ) {
				if ( 'level' === $k ) {
					$levels[] = $v;
				}
			}
		);
		$this->assertSame( 1, count( array_keys( $levels, 'h1', true ) ), 'exactly one H1' );
		$this->assertSame( 'h2', $page['sections'][1]['elements'][0]['level'], 'H4 after H1 becomes H2' );
		$this->assertSame( 'h2', $page['sections'][3]['elements'][0]['level'], 'second H1 demoted' );
	}

	public function test_heading_fix_promotes_when_no_h1(): void {
		$page = array(
			'sections' => array(
				array(
					'id'       => 'a',
					'elements' => array(
						array(
							'type'  => 'heading',
							'level' => 'h3',
							'text'  => 'Title',
						),
					),
				),
			),
		);
		HeadingValidator::fix_page( $page );
		$this->assertSame( 'h1', $page['sections'][0]['elements'][0]['level'] );
	}

	public function test_heading_outline_audit(): void {
		$issues = HeadingValidator::audit_outline(
			array(
				array( 'level' => 'h2', 'text' => 'a', 'id' => 'a1b2c3d' ),
				array( 'level' => 'h4', 'text' => 'b', 'id' => 'a1b2c3e' ),
			),
			true
		);
		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'missing_h1', $codes );
		$this->assertContains( 'skipped_level', $codes );
	}

	public function test_page_validator_repairs_links_images_icons_and_placeholders(): void {
		$v = new PageValidator( array( 'home', 'services', 'implant', 'contact' ), array( 'img_1' ) );
		$r = $v->validate( $this->fixture( 'home' ) );
		$this->assertSame( array(), $r['errors'] );
		$page = $r['page'];

		// Unsupported scheme neutralised; invented image dropped.
		$cta = $page['sections'][3]['elements'];
		$this->assertSame( '#', $cta[1]['link'] );
		$this->assertCount( 2, $cta );

		// Uploaded image kept.
		$this->assertSame( 'img_1', $page['sections'][0]['elements'][1]['image_ref'] );

		// Icons mapped to allowlist.
		$boxes = $page['sections'][1]['elements'][1]['elements'];
		$this->assertSame( 'tooth', $boxes[0]['icon'] );
		$this->assertSame( 'check', $boxes[1]['icon'] );

		// Empty accordion items removed, testimonials always flagged.
		$this->assertCount( 1, $page['sections'][2]['elements'][1]['items'] );
		$this->assertTrue( $page['sections'][2]['elements'][2]['placeholder'] );

		$this->assertNotEmpty( $r['warnings'] );
	}

	public function test_page_validator_link_rules(): void {
		$v = new PageValidator( array( 'home', 'contact' ), array() );
		$this->assertSame( 'page:contact', $v->link( 'page:contact', 'p' ) );
		$this->assertSame( 'page:home', $v->link( 'page:ghost', 'p' ) );
		$this->assertSame( 'page:contact', $v->link( '', 'p' ) );
		$this->assertSame( '#faqscript', $v->link( '#FAQ"><script>', 'p' ) );
		$this->assertSame( 'mailto:a@b.co', $v->link( 'mailto:a@b.co', 'p' ) );
		$this->assertSame( '#', $v->link( 'data:text/html,<b>x</b>', 'p' ) );
		$this->assertSame( '#', $v->link( 'JavaScript:alert(1)', 'p' ) );
	}

	public function test_page_validator_rejects_oversized_and_too_deep_pages(): void {
		$deep = array( 'type' => 'heading', 'level' => 'h2', 'text' => 'x' );
		for ( $i = 0; $i < 4; $i++ ) {
			$deep = array(
				'type'     => 'group',
				'elements' => array( $deep ),
			);
		}
		$many = array_fill( 0, 170, array( 'type' => 'heading', 'level' => 'h3', 'text' => 'x' ) );
		$v    = new PageValidator( array( 'home' ), array() );
		$r    = $v->validate(
			array(
				'key'      => 'home',
				'sections' => array(
					array( 'id' => 'a', 'type' => 'content', 'elements' => array( $deep ) ),
					array( 'id' => 'b', 'type' => 'content', 'elements' => $many ),
				),
			)
		);
		$joined = implode( "\n", $r['errors'] );
		$this->assertStringContainsString( 'nested deeper', $joined );
		$this->assertStringContainsString( 'elements; the maximum', $joined );
	}

	public function test_contrast_detection_and_fix(): void {
		$colors = $this->fixture( 'design' )['colors'];
		$this->assertSame( array(), Contrast::failures( $colors ) );

		$bad          = $colors;
		$bad['text']  = '#BBBBBB';
		$bad['muted'] = '#DDDDDD';
		$fails        = Contrast::failures( $bad );
		$this->assertCount( 3, $fails );

		$fixed = Contrast::fix_all( $bad );
		$this->assertSame( array(), Contrast::failures( $fixed['colors'] ) );
		$this->assertNotEmpty( $fixed['changes'] );
		$this->assertEqualsWithDelta( 21.0, Contrast::ratio( '#000000', '#FFFFFF' ), 0.01 );
	}
}
