<?php
/**
 * Closed vocabularies the AI is allowed to use. Anything outside these lists
 * is rejected or replaced before reaching WordPress or Elementor.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Vocabulary constants.
 */
final class Vocabulary {

	public const SITE_TYPES = array( 'business', 'agency', 'portfolio', 'blog', 'restaurant', 'saas', 'ecommerce', 'education', 'medical', 'nonprofit', 'personal', 'custom' );

	public const PAGE_TYPES = array( 'home', 'about', 'services', 'service', 'products', 'product', 'blog', 'contact', 'faq', 'pricing', 'portfolio', 'case_study', 'team', 'privacy', 'terms', 'not_found', 'landing', 'custom' );

	public const SECTION_TYPES = array( 'hero', 'features', 'services', 'about', 'stats', 'process', 'pricing', 'testimonials', 'faq', 'cta', 'contact', 'team', 'gallery', 'portfolio', 'blog', 'content', 'logos', 'legal', 'not_found', 'custom' );

	public const ELEMENT_TYPES = array( 'heading', 'text', 'button', 'image', 'icon_box', 'icon_list', 'testimonial', 'accordion', 'spacer', 'divider', 'posts_grid', 'contact_form', 'group' );

	public const IMAGE_ROLES = array( 'logo', 'hero', 'homepage_reference', 'product', 'team', 'gallery', 'background', 'inspiration', 'unknown' );

	/**
	 * Font Awesome 5 solid icons exposed to the model (free icon set bundled with Elementor).
	 */
	public const ICONS = array(
		'check', 'check-circle', 'star', 'bolt', 'rocket', 'shield-alt', 'lock', 'chart-line', 'chart-bar', 'chart-pie',
		'cogs', 'cog', 'code', 'laptop', 'mobile-alt', 'cloud', 'server', 'database', 'robot', 'brain',
		'users', 'user', 'user-tie', 'handshake', 'heart', 'thumbs-up', 'comments', 'comment', 'envelope', 'phone',
		'map-marker-alt', 'clock', 'calendar-alt', 'globe', 'search', 'lightbulb', 'magic', 'paint-brush', 'pen', 'camera',
		'image', 'video', 'music', 'utensils', 'coffee', 'shopping-cart', 'shopping-bag', 'store', 'truck', 'box',
		'tag', 'gift', 'credit-card', 'wallet', 'percent', 'dollar-sign', 'book', 'graduation-cap', 'briefcase', 'building',
		'home', 'leaf', 'seedling', 'sun', 'tools', 'wrench', 'hammer', 'stethoscope', 'medkit', 'award',
		'trophy', 'flag', 'bullseye', 'compass', 'layer-group', 'cubes', 'puzzle-piece', 'sync', 'infinity', 'arrow-left',
		'arrow-right', 'plus', 'minus', 'question-circle', 'info-circle', 'headset', 'paper-plane', 'link', 'download', 'file-alt',
		'tooth', 'smile', 'heartbeat', 'user-md', 'hospital', 'pills', 'spa', 'dumbbell', 'car', 'paw',
		'plane', 'bed', 'key', 'balance-scale', 'gavel', 'palette', 'pizza-slice', 'birthday-cake', 'tshirt', 'recycle',
	);

	/**
	 * Fonts per script. All are on Google Fonts (Elementor can load them locally) or system stacks.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function fonts(): array {
		return array(
			'persian' => array( 'Vazirmatn', 'Noto Sans Arabic', 'Noto Naskh Arabic', 'IBM Plex Sans Arabic', 'Markazi Text', 'Lalezar', 'Tahoma' ),
			'arabic'  => array( 'Noto Sans Arabic', 'Noto Naskh Arabic', 'IBM Plex Sans Arabic', 'Cairo', 'Tajawal', 'Almarai', 'Vazirmatn' ),
			'latin'   => array( 'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins', 'DM Sans', 'Manrope', 'Playfair Display', 'Merriweather', 'Source Serif 4', 'Lora', 'Space Grotesk', 'system-ui' ),
		);
	}

	/**
	 * Script group for a language code.
	 *
	 * @param string $lang Language code (fa, fa_IR, en_US...).
	 */
	public static function script_for( string $lang ): string {
		$base = strtolower( substr( $lang, 0, 2 ) );
		if ( in_array( $base, array( 'fa', 'ps', 'ur', 'ku' ), true ) ) {
			return 'persian';
		}
		if ( 'ar' === $base ) {
			return 'arabic';
		}
		return 'latin';
	}

	/**
	 * Allowed fonts for a language.
	 *
	 * @param string $lang Language code.
	 * @return array<int, string>
	 */
	public static function fonts_for( string $lang ): array {
		return self::fonts()[ self::script_for( $lang ) ];
	}

	/**
	 * Whether a language is right-to-left.
	 *
	 * @param string $lang Language code.
	 */
	public static function is_rtl_language( string $lang ): bool {
		return in_array( strtolower( substr( $lang, 0, 2 ) ), array( 'fa', 'ar', 'he', 'ur', 'ps', 'ku', 'yi', 'dv' ), true );
	}
}
