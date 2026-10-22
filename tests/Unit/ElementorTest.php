<?php
/**
 * Elementor document conversion and validation tests.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Tests\Unit;

use AIThemeStudio\Elementor\BuildContext;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Elementor\DocumentBuilder;
use AIThemeStudio\Elementor\DocumentValidator;
use AIThemeStudio\SEO\HeadingValidator;
use AIThemeStudio\SEO\LinkExtractor;
use AIThemeStudio\Validation\PageValidator;

final class ElementorTest extends TestCase {

	/**
	 * Build context with deterministic resolvers.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 */
	private function ctx( array $overrides = array() ): BuildContext {
		$ctx                 = new BuildContext( new DesignTokens( $this->fixture( 'design' ) ) );
		$ctx->link_resolver  = static function ( string $link ): string {
			if ( 0 === strpos( $link, 'page:' ) ) {
				return 'https://example.test/' . substr( $link, 5 ) . '/';
			}
			return $link;
		};
		$ctx->image_resolver = static fn( string $ref ) => 'img_1' === $ref ? array(
			'id'     => 42,
			'url'    => 'https://example.test/wp-content/uploads/hero.jpg',
			'alt'    => 'alt from library',
			'width'  => 1600,
			'height' => 900,
		) : null;
		foreach ( $overrides as $k => $v ) {
			$ctx->$k = $v;
		}
		return $ctx;
	}

	/**
	 * Validated home page definition.
	 *
	 * @return array<string, mixed>
	 */
	private function page(): array {
		$v = new PageValidator( array( 'home', 'services', 'implant', 'contact' ), array( 'img_1' ) );
		return $v->validate( $this->fixture( 'home' ) )['page'];
	}

	/**
	 * Collect widgets.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	private function widgets( array $elements ): array {
		$out = array();
		LinkExtractor::walk(
			$elements,
			static function ( array $el ) use ( &$out ) {
				if ( 'widget' === ( $el['elType'] ?? '' ) ) {
					$out[] = $el;
				}
			}
		);
		return $out;
	}

	public function test_container_document_is_valid_and_native(): void {
		$out   = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		$check = DocumentValidator::validate( $out['elements'] );
		$this->assertTrue( $check['valid'], implode( "\n", $check['errors'] ) );

		$this->assertCount( 4, $out['elements'] );
		foreach ( $out['elements'] as $top ) {
			$this->assertSame( 'container', $top['elType'] );
			$this->assertEmpty( $top['isInner'] ?? false );
		}
		$this->assertSame( array( 'hero', 'services', 'faq', 'cta' ), array_keys( $out['section_map'] ) );
		$this->assertSame( 'hero', $out['elements'][0]['settings']['_element_id'] );

		$types = array_unique( array_column( $this->widgets( $out['elements'] ), 'widgetType' ) );
		foreach ( $types as $t ) {
			$this->assertContains( $t, array( 'heading', 'text-editor', 'button', 'image', 'icon-box', 'nested-accordion', 'testimonial' ), "unexpected widget {$t}" );
		}
		$this->assertNotContains( 'html', $types, 'no raw HTML widgets' );
	}

	public function test_text_is_sanitized_and_links_resolved(): void {
		$out     = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		$widgets = $this->widgets( $out['elements'] );
		$editor  = array_values( array_filter( $widgets, static fn( $w ) => 'text-editor' === $w['widgetType'] ) )[0];
		$this->assertStringNotContainsString( '<script', $editor['settings']['editor'] );
		$this->assertStringContainsString( '<strong>', $editor['settings']['editor'] );

		$buttons = array_values( array_filter( $widgets, static fn( $w ) => 'button' === $w['widgetType'] ) );
		$this->assertSame( 'https://example.test/contact/', $buttons[0]['settings']['link']['url'] );
		$this->assertStringNotContainsString( 'javascript', strtolower( wp_json_encode( $out['elements'] ) ) );
		$this->assertStringNotContainsString( '<script', (string) $out['fallback_html'] );
	}

	public function test_headings_in_built_document_follow_rules(): void {
		$out     = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		$outline = HeadingValidator::outline_from_elementor( $out['elements'] );
		$this->assertSame( 'h1', $outline[0]['level'] );
		$this->assertSame( array(), HeadingValidator::audit_outline( $outline, true ) );
	}

	public function test_images_use_library_attachments_and_alt(): void {
		$out    = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		$images = LinkExtractor::images( $out['elements'] );
		$this->assertCount( 1, $images );
		$this->assertSame( 42, $images[0]['attachment'] );
		$this->assertSame( 'سالن انتظار کلینیک', $images[0]['alt'] );
	}

	public function test_global_tokens_are_referenced(): void {
		$json = wp_json_encode( ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() )['elements'] );
		$this->assertStringContainsString( 'globals\/colors?id=ats', $json );
		$this->assertStringContainsString( 'globals\/typography?id=ats', $json );

		$plain = wp_json_encode( ( new DocumentBuilder( $this->ctx( array( 'use_globals' => false ) ) ) )->build_page( $this->page() )['elements'] );
		$this->assertStringNotContainsString( 'globals\/colors', $plain );
	}

	public function test_legacy_sections_when_containers_disabled(): void {
		$out   = ( new DocumentBuilder( $this->ctx( array( 'containers' => false ) ) ) )->build_page( $this->page() );
		$check = DocumentValidator::validate( $out['elements'] );
		$this->assertTrue( $check['valid'], implode( "\n", $check['errors'] ) );
		foreach ( $out['elements'] as $top ) {
			$this->assertSame( 'section', $top['elType'] );
			$this->assertSame( 'column', $top['elements'][0]['elType'] );
		}
		$this->assertStringNotContainsString( '"container"', wp_json_encode( $out['elements'] ) );
	}

	public function test_accordion_falls_back_when_widget_missing(): void {
		$ctx   = $this->ctx( array( 'widget_available' => static fn( string $w ): bool => 'nested-accordion' !== $w ) );
		$out   = ( new DocumentBuilder( $ctx ) )->build_page( $this->page() );
		$types = array_column( $this->widgets( $out['elements'] ), 'widgetType' );
		$this->assertNotContains( 'nested-accordion', $types );
		$this->assertNotContains( 'accordion', $types, 'legacy accordion has inaccessible markup' );
		$this->assertTrue( DocumentValidator::validate( $out['elements'], $ctx->widget_available )['valid'] );
	}

	public function test_accordion_uses_nested_accordion_with_inner_containers(): void {
		$out = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		$acc = array_values( array_filter( $this->widgets( $out['elements'] ), static fn( $w ) => 'nested-accordion' === $w['widgetType'] ) );
		$this->assertCount( 1, $acc );
		$this->assertCount( count( $acc[0]['settings']['items'] ), $acc[0]['elements'] );
		foreach ( $acc[0]['elements'] as $child ) {
			$this->assertSame( 'container', $child['elType'] );
			$this->assertTrue( $child['isInner'] );
			$this->assertSame( 'text-editor', $child['elements'][0]['widgetType'] );
		}
		$this->assertMatchesRegularExpression( '/^h[2-6]$/', $acc[0]['settings']['title_tag'] );
		$this->assertTrue( DocumentValidator::validate( $out['elements'] )['valid'] );

		$acc[0]['elements'] = array();
		$wrapped            = array(
			'id'       => 'abcdef1',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => array(),
			'elements' => array( $acc[0] ),
		);
		$this->assertFalse( DocumentValidator::validate( array( $wrapped ) )['valid'], 'children must match items' );
	}

	public function test_testimonial_has_no_placeholder_photo(): void {
		$out = ( new DocumentBuilder( $this->ctx() ) )->build_page( $this->page() );
		foreach ( $this->widgets( $out['elements'] ) as $w ) {
			if ( 'testimonial' === $w['widgetType'] ) {
				$this->assertSame( '', $w['settings']['testimonial_image']['url'] );
			}
		}
	}

	public function test_contact_form_uses_shortcode_or_real_details(): void {
		$section = array(
			'id'       => 'form',
			'type'     => 'contact',
			'elements' => array( array( 'type' => 'contact_form' ) ),
		);

		$with = ( new DocumentBuilder( $this->ctx( array( 'contact_form_shortcode' => '[contact-form-7 id="5"]' ) ) ) )->build_section( $section );
		$this->assertStringContainsString( 'contact-form-7', wp_json_encode( $with['element'] ) );

		$without = ( new DocumentBuilder( $this->ctx( array( 'business' => array( 'email' => 'info@example.test' ) ) ) ) )->build_section( $section );
		$json    = wp_json_encode( $without['element'], JSON_UNESCAPED_SLASHES );
		$this->assertStringNotContainsString( 'shortcode', $json );
		$this->assertStringContainsString( 'mailto:info@example.test', $json );
	}

	public function test_section_ids_do_not_collide_with_reserved(): void {
		$builder  = new DocumentBuilder( $this->ctx() );
		$full     = $builder->build_page( $this->page() );
		$reserved = DocumentValidator::all_ids( $full['elements'] );
		$section  = $builder->build_section( $this->page()['sections'][1], $reserved );
		$new      = DocumentValidator::all_ids( array( $section['element'] ) );
		$this->assertSame( array(), array_intersect( $reserved, $new ) );
	}

	public function test_validator_catches_structural_errors(): void {
		$bad = array(
			array(
				'id'         => 'abc1234',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array(),
				'elements'   => array(),
			),
			array(
				'id'       => 'abc1234',
				'elType'   => 'column',
				'settings' => array(),
				'elements' => array(),
			),
			array(
				'id'       => 'BAD ID',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'def5678',
						'elType'     => 'widget',
						'widgetType' => 'pro-only',
						'settings'   => array(),
						'elements'   => array(),
					),
				),
			),
		);
		$r      = DocumentValidator::validate( $bad, static fn( $w ) => 'pro-only' !== $w );
		$joined = implode( "\n", $r['errors'] );
		$this->assertFalse( $r['valid'] );
		$this->assertStringContainsString( 'cannot be top-level', $joined );
		$this->assertStringContainsString( 'Duplicate element id', $joined );
		$this->assertStringContainsString( 'must be inside a section', $joined );
		$this->assertStringContainsString( 'Invalid element id', $joined );
		$this->assertStringContainsString( 'not available', $joined );
	}

	public function test_rtl_alignment_maps_to_physical_sides(): void {
		$section = array(
			'id'       => 'a',
			'type'     => 'content',
			'elements' => array(
				array(
					'type'  => 'heading',
					'level' => 'h2',
					'text'  => 'x',
					'align' => 'start',
				),
			),
		);
		$rtl = ( new DocumentBuilder( $this->ctx( array( 'direction' => 'rtl' ) ) ) )->build_section( $section )['element'];
		$ltr = ( new DocumentBuilder( $this->ctx( array( 'direction' => 'ltr' ) ) ) )->build_section( $section )['element'];
		$this->assertSame( 'right', $this->widgets( array( $rtl ) )[0]['settings']['align'] );
		$this->assertSame( 'left', $this->widgets( array( $ltr ) )[0]['settings']['align'] );
	}
}
