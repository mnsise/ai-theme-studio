<?php
/**
 * Structured output schemas for every AI generation stage.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Schema registry.
 */
final class Schemas {

	private const HEX     = '^#[0-9A-Fa-f]{6}$';
	private const KEY     = '^[a-z][a-z0-9_]{1,30}$';
	private const SECTION = '^[a-z][a-z0-9-]{1,40}$';
	private const SLUG    = '^[\p{L}\p{N}]+(-[\p{L}\p{N}]+)*$';

	/**
	 * Get a schema by name.
	 *
	 * @param string $name Schema name.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException Unknown schema.
	 */
	public static function get( string $name ): array {
		switch ( $name ) {
			case 'site_spec':
				return self::site_spec();
			case 'image_analysis':
				return self::image_analysis();
			case 'sitemap':
				return self::sitemap();
			case 'design_system':
				return self::design_system();
			case 'page':
				return self::page();
			case 'section':
				return array( '$ref' => 'section' );
			case 'seo_fix':
				return self::seo_fix();
		}
		throw new \InvalidArgumentException( 'Unknown schema ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Named definitions for $ref resolution.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			'section' => self::section(),
			'element' => self::element(),
		);
	}

	/**
	 * Shorthand string schema.
	 *
	 * @param int  $max      Max length.
	 * @param int  $min      Min length.
	 * @param bool $nullable Nullable.
	 * @return array<string, mixed>
	 */
	private static function str( int $max, int $min = 0, bool $nullable = false ): array {
		$s = array(
			'type'      => $nullable ? array( 'string', 'null' ) : 'string',
			'maxLength' => $max,
		);
		if ( $min ) {
			$s['minLength'] = $min;
		}
		return $s;
	}

	/**
	 * List of strings.
	 *
	 * @param int $max_items Max items.
	 * @param int $max_len   Max string length.
	 * @return array<string, mixed>
	 */
	private static function str_list( int $max_items, int $max_len = 200 ): array {
		return array(
			'type'     => 'array',
			'items'    => self::str( $max_len ),
			'maxItems' => $max_items,
			'default'  => array(),
		);
	}

	/**
	 * Website specification.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_spec(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'site_name', 'site_type', 'language', 'direction', 'required_pages' ),
			'properties' => array(
				'site_name'      => self::str( 80, 1 ),
				'tagline'        => self::str( 160 ),
				'site_type'      => array(
					'type' => 'string',
					'enum' => Vocabulary::SITE_TYPES,
				),
				'language'       => array(
					'type'    => 'string',
					'pattern' => '^[a-z]{2}(_[A-Z]{2})?$',
				),
				'direction'      => array(
					'type' => 'string',
					'enum' => array( 'rtl', 'ltr' ),
				),
				'audience'       => self::str( 400 ),
				'tone'           => self::str( 160 ),
				'goals'          => self::str_list( 8 ),
				'primary_cta'    => array(
					'type'       => 'object',
					'properties' => array(
						'text'            => self::str( 40 ),
						'target_page_key' => array( 'type' => array( 'string', 'null' ) ),
					),
					'default'    => array(),
				),
				'design_intent'  => array(
					'type'       => 'object',
					'properties' => array(
						'style_keywords'   => self::str_list( 10, 40 ),
						'color_notes'      => self::str( 400 ),
						'typography_notes' => self::str( 300 ),
						'layout_notes'     => self::str( 600 ),
					),
					'default'    => array(),
				),
				'required_pages' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => Vocabulary::PAGE_TYPES,
					),
					'minItems' => 1,
					'maxItems' => 20,
				),
				'business'       => array(
					'type'       => 'object',
					'properties' => array(
						'email'   => self::str( 120, 0, true ),
						'phone'   => self::str( 40, 0, true ),
						'address' => self::str( 300, 0, true ),
						'hours'   => self::str( 200, 0, true ),
					),
					'default'    => array(),
				),
				'warnings'       => self::str_list( 10, 400 ),
			),
		);
	}

	/**
	 * Image analysis.
	 *
	 * @return array<string, mixed>
	 */
	public static function image_analysis(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'images' ),
			'properties' => array(
				'images'  => array(
					'type'     => 'array',
					'maxItems' => 30,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'ref', 'suggested_role', 'alt_text', 'decorative' ),
						'properties' => array(
							'ref'              => array(
								'type'    => 'string',
								'pattern' => '^img_[0-9]+$',
							),
							'suggested_role'   => array(
								'type' => 'string',
								'enum' => Vocabulary::IMAGE_ROLES,
							),
							'description'      => self::str( 500 ),
							'alt_text'         => self::str( 200 ),
							'decorative'       => array( 'type' => 'boolean' ),
							'use_as_asset'     => array(
								'type'    => 'boolean',
								'default' => false,
							),
							'target_section'   => self::str( 40, 0, true ),
							'dominant_colors'  => array(
								'type'     => 'array',
								'maxItems' => 6,
								'items'    => array(
									'type'    => 'string',
									'pattern' => self::HEX,
								),
								'default'  => array(),
							),
							'layout_notes'     => self::str( 600 ),
							'typography_notes' => self::str( 300 ),
							'contains_third_party_branding' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
					),
				),
				'overall' => array(
					'type'       => 'object',
					'properties' => array(
						'palette'         => array(
							'type'     => 'array',
							'maxItems' => 8,
							'items'    => array(
								'type'    => 'string',
								'pattern' => self::HEX,
							),
							'default'  => array(),
						),
						'style_keywords'  => self::str_list( 10, 40 ),
						'layout_patterns' => self::str_list( 10, 200 ),
						'spacing'         => array(
							'type'    => 'string',
							'enum'    => array( 'compact', 'comfortable', 'spacious' ),
							'default' => 'comfortable',
						),
						'corner_style'    => array(
							'type'    => 'string',
							'enum'    => array( 'sharp', 'rounded', 'pill' ),
							'default' => 'rounded',
						),
					),
					'default'    => array(),
				),
			),
		);
	}

	/**
	 * Sitemap and navigation proposal.
	 *
	 * @return array<string, mixed>
	 */
	public static function sitemap(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'pages' ),
			'properties' => array(
				'pages'      => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 20,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'key', 'type', 'title', 'slug', 'sections', 'seo' ),
						'properties' => array(
							'key'        => array(
								'type'    => 'string',
								'pattern' => self::KEY,
							),
							'type'       => array(
								'type' => 'string',
								'enum' => Vocabulary::PAGE_TYPES,
							),
							'title'      => self::str( 90, 1 ),
							'slug'       => array(
								'type'      => 'string',
								'pattern'   => self::SLUG,
								'maxLength' => 80,
							),
							'parent'     => array(
								'type'    => array( 'string', 'null' ),
								'default' => null,
							),
							'menu_label' => self::str( 40 ),
							'in_menu'    => array(
								'type'    => 'boolean',
								'default' => true,
							),
							'in_footer'  => array(
								'type'    => 'boolean',
								'default' => false,
							),
							'order'      => array(
								'type'    => 'integer',
								'minimum' => 0,
								'maximum' => 100,
								'default' => 0,
							),
							'purpose'    => self::str( 400 ),
							'noindex'    => array(
								'type'    => 'boolean',
								'default' => false,
							),
							'sections'   => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => 14,
								'items'    => array(
									'type'       => 'object',
									'required'   => array( 'id', 'type' ),
									'properties' => array(
										'id'      => array(
											'type'    => 'string',
											'pattern' => self::SECTION,
										),
										'type'    => array(
											'type' => 'string',
											'enum' => Vocabulary::SECTION_TYPES,
										),
										'summary' => self::str( 400 ),
									),
								),
							),
							'seo'        => array(
								'type'       => 'object',
								'required'   => array( 'title', 'description' ),
								'properties' => array(
									'title'       => self::str( 70, 5 ),
									'description' => self::str( 170, 20 ),
									'focus_topic' => self::str( 80 ),
								),
							),
						),
					),
				),
				'header_cta' => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'text'     => self::str( 40 ),
						'page_key' => array( 'type' => array( 'string', 'null' ) ),
					),
					'default'    => null,
				),
				'footer'     => array(
					'type'       => 'object',
					'properties' => array(
						'about_text' => self::str( 300 ),
					),
					'default'    => array(),
				),
			),
		);
	}

	/**
	 * Design tokens.
	 *
	 * @return array<string, mixed>
	 */
	public static function design_system(): array {
		$color  = array(
			'type'    => 'string',
			'pattern' => self::HEX,
		);
		$colors = array();
		foreach ( array( 'primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'heading', 'dark', 'on_dark', 'on_primary', 'border' ) as $c ) {
			$colors[ $c ] = $color;
		}
		$weight = array(
			'type' => 'string',
			'enum' => array( '300', '400', '500', '600', '700', '800', '900' ),
		);
		return array(
			'type'       => 'object',
			'required'   => array( 'colors', 'typography' ),
			'properties' => array(
				'colors'          => array(
					'type'       => 'object',
					'required'   => array_keys( $colors ),
					'properties' => $colors,
				),
				'typography'      => array(
					'type'       => 'object',
					'required'   => array( 'heading_font', 'body_font' ),
					'properties' => array(
						'heading_font'   => self::str( 60, 1 ),
						'body_font'      => self::str( 60, 1 ),
						'base_size'      => array(
							'type'    => 'integer',
							'minimum' => 14,
							'maximum' => 20,
							'default' => 16,
						),
						'scale'          => array(
							'type'    => 'number',
							'minimum' => 1.1,
							'maximum' => 1.6,
							'default' => 1.25,
						),
						'heading_weight' => array_merge( $weight, array( 'default' => '700' ) ),
						'body_weight'    => array_merge( $weight, array( 'default' => '400' ) ),
						'line_height'    => array(
							'type'    => 'number',
							'minimum' => 1.2,
							'maximum' => 2.2,
							'default' => 1.7,
						),
					),
				),
				'radius'          => array(
					'type'       => 'object',
					'properties' => array(
						'small'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 24,
							'default' => 6,
						),
						'medium' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 32,
							'default' => 12,
						),
						'button' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 999,
							'default' => 8,
						),
					),
					'default'    => array(),
				),
				'spacing'         => array(
					'type'       => 'object',
					'properties' => array(
						'section_padding' => array(
							'type'    => 'string',
							'enum'    => array( 'sm', 'md', 'lg', 'xl' ),
							'default' => 'lg',
						),
						'gap'             => array(
							'type'    => 'string',
							'enum'    => array( 'sm', 'md', 'lg' ),
							'default' => 'md',
						),
					),
					'default'    => array(),
				),
				'container_width' => array(
					'type'    => 'integer',
					'minimum' => 960,
					'maximum' => 1600,
					'default' => 1200,
				),
				'shadow'          => array(
					'type'    => 'string',
					'enum'    => array( 'none', 'soft', 'medium' ),
					'default' => 'soft',
				),
				'button_style'    => array(
					'type'    => 'string',
					'enum'    => array( 'solid', 'outline' ),
					'default' => 'solid',
				),
				'rationale'       => self::str( 800 ),
			),
		);
	}

	/**
	 * Page definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function page(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'key', 'sections' ),
			'properties' => array(
				'key'      => array(
					'type'    => 'string',
					'pattern' => self::KEY,
				),
				'sections' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 14,
					'items'    => array( '$ref' => 'section' ),
				),
			),
		);
	}

	/**
	 * Section definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function section(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'id', 'type', 'elements' ),
			'properties' => array(
				'id'               => array(
					'type'    => 'string',
					'pattern' => self::SECTION,
				),
				'type'             => array(
					'type' => 'string',
					'enum' => Vocabulary::SECTION_TYPES,
				),
				'label'            => self::str( 60 ),
				'tag'              => array(
					'type'    => 'string',
					'enum'    => array( 'section', 'div', 'article', 'aside' ),
					'default' => 'section',
				),
				'background'       => array(
					'type'    => 'string',
					'enum'    => array( 'default', 'surface', 'primary', 'dark', 'accent', 'image' ),
					'default' => 'default',
				),
				'background_image' => array(
					'type'    => array( 'string', 'null' ),
					'default' => null,
				),
				'layout'           => array(
					'type'       => 'object',
					'properties' => array(
						'direction'  => array(
							'type'    => 'string',
							'enum'    => array( 'column', 'row' ),
							'default' => 'column',
						),
						'align'      => array(
							'type'    => 'string',
							'enum'    => array( 'start', 'center', 'end' ),
							'default' => 'start',
						),
						'padding'    => array(
							'type' => array( 'string', 'null' ),
							'enum' => array( 'sm', 'md', 'lg', 'xl', null ),
						),
						'gap'        => array(
							'type' => array( 'string', 'null' ),
							'enum' => array( 'sm', 'md', 'lg', null ),
						),
						'width'      => array(
							'type'    => 'string',
							'enum'    => array( 'boxed', 'full' ),
							'default' => 'boxed',
						),
						'min_height' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
							'default' => 0,
						),
					),
					'default'    => array(),
				),
				'elements'         => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 30,
					'items'    => array( '$ref' => 'element' ),
				),
			),
		);
	}

	/**
	 * Element definition (recursive through "group").
	 *
	 * @return array<string, mixed>
	 */
	private static function element(): array {
		$item = array(
			'type'       => 'object',
			'properties' => array(
				'text'    => self::str( 300 ),
				'title'   => self::str( 200 ),
				'content' => self::str( 2000 ),
				'icon'    => self::str( 40 ),
				'link'    => self::str( 500 ),
			),
		);
		return array(
			'type'       => 'object',
			'required'   => array( 'type' ),
			'properties' => array(
				'type'        => array(
					'type' => 'string',
					'enum' => Vocabulary::ELEMENT_TYPES,
				),
				'text'        => self::str( 300 ),
				'level'       => array(
					'type' => 'string',
					'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ),
				),
				'html'        => self::str( 6000 ),
				'link'        => self::str( 500 ),
				'style'       => array(
					'type' => 'string',
					'enum' => array( 'primary', 'secondary', 'link', 'card', 'plain' ),
				),
				'align'       => array(
					'type' => 'string',
					'enum' => array( 'start', 'center', 'end' ),
				),
				'image_ref'   => array( 'type' => array( 'string', 'null' ) ),
				'alt'         => self::str( 200 ),
				'decorative'  => array( 'type' => 'boolean' ),
				'icon'        => self::str( 40 ),
				'title'       => self::str( 200 ),
				'description' => self::str( 1000 ),
				'items'       => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => $item,
				),
				'name'        => self::str( 80 ),
				'role'        => self::str( 120 ),
				'placeholder' => array( 'type' => 'boolean' ),
				'count'       => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 12,
				),
				'size'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 200,
				),
				'direction'   => array(
					'type' => 'string',
					'enum' => array( 'row', 'column' ),
				),
				'width'       => array(
					'type'    => 'integer',
					'minimum' => 10,
					'maximum' => 100,
				),
				'elements'    => array(
					'type'     => 'array',
					'maxItems' => 12,
					'items'    => array( '$ref' => 'element' ),
				),
			),
		);
	}

	/**
	 * SEO metadata fix-up.
	 *
	 * @return array<string, mixed>
	 */
	public static function seo_fix(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'pages' ),
			'properties' => array(
				'pages' => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'key', 'title', 'description' ),
						'properties' => array(
							'key'         => array(
								'type'    => 'string',
								'pattern' => self::KEY,
							),
							'title'       => self::str( 70, 5 ),
							'description' => self::str( 170, 20 ),
						),
					),
				),
			),
		);
	}
}
