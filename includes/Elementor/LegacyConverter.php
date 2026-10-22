<?php
/**
 * Converts container trees to the legacy Section > Column > Widget structure
 * for sites where the Flexbox Container feature is disabled.
 *
 * Elementor only allows one level of inner sections, so deeper rows are
 * flattened into the parent column.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy converter.
 */
final class LegacyConverter {

	/**
	 * Id generator.
	 *
	 * @var callable(): string
	 */
	private $id;

	private const STYLE_KEYS = array(
		'background_background',
		'background_color',
		'background_image',
		'background_size',
		'background_position',
		'background_overlay_background',
		'background_overlay_color',
		'background_overlay_opacity',
		'border_border',
		'border_width',
		'border_color',
		'border_radius',
		'box_shadow_box_shadow_type',
		'box_shadow_box_shadow',
		'padding',
		'padding_tablet',
		'padding_mobile',
	);

	/**
	 * Constructor.
	 *
	 * @param callable(): string $id Id generator.
	 */
	public function __construct( callable $id ) {
		$this->id = $id;
	}

	/**
	 * Convert top-level containers.
	 *
	 * @param array<int, array<string, mixed>> $elements Containers.
	 * @return array<int, array<string, mixed>>
	 */
	public function convert( array $elements ): array {
		$out = array();
		foreach ( $elements as $el ) {
			$out[] = 'container' === $el['elType'] ? $this->section( $el, false ) : $el;
		}
		return $out;
	}

	/**
	 * Container -> section.
	 *
	 * @param array<string, mixed> $c     Container.
	 * @param bool                 $inner Inner section.
	 * @return array<string, mixed>
	 */
	private function section( array $c, bool $inner ): array {
		$s        = $c['settings'];
		$settings = $this->copy_styles( $s );
		if ( ! $inner ) {
			$settings['layout']        = 'full' === ( $s['content_width'] ?? 'boxed' ) ? 'full_width' : 'boxed';
			$settings['content_width'] = $s['boxed_width'] ?? DesignTokens::px( 1200 );
			$settings['html_tag']      = $s['html_tag'] ?? 'section';
			if ( isset( $s['_element_id'] ) ) {
				$settings['_element_id'] = $s['_element_id'];
			}
			if ( isset( $s['min_height'] ) ) {
				$settings['height']        = 'min-height';
				$settings['custom_height'] = $s['min_height'];
			}
		}
		if ( isset( $s['_title'] ) ) {
			$settings['_title'] = $s['_title'];
		}
		$settings['gap']                   = 'extended';
		$settings['structure']             = '';

		$columns = array();
		if ( 'row' === ( $s['flex_direction'] ?? 'column' ) ) {
			$n = max( 1, count( $c['elements'] ) );
			foreach ( $c['elements'] as $child ) {
				if ( 'container' === $child['elType'] ) {
					$width     = (int) ( $child['settings']['width']['size'] ?? floor( 100 / $n ) );
					$columns[] = $this->column( $child['elements'], $width, $inner, $this->copy_styles( $child['settings'] ) );
				} else {
					$columns[] = $this->column( array( $child ), (int) floor( 100 / $n ), $inner, array() );
				}
			}
		} else {
			$columns[] = $this->column( $c['elements'], 100, $inner, array() );
		}

		return array(
			'id'       => $c['id'],
			'elType'   => 'section',
			'isInner'  => $inner,
			'settings' => $settings,
			'elements' => $columns,
		);
	}

	/**
	 * Build a column.
	 *
	 * @param array<int, array<string, mixed>> $children        Children.
	 * @param int                              $size            Width %.
	 * @param bool                             $in_inner_section Column of an inner section.
	 * @param array<string, mixed>             $styles          Card styles.
	 * @return array<string, mixed>
	 */
	private function column( array $children, int $size, bool $in_inner_section, array $styles ): array {
		$settings                 = $styles;
		$settings['_column_size'] = $size;
		$settings['_inline_size'] = null;
		return array(
			'id'       => ( $this->id )(),
			'elType'   => 'column',
			'isInner'  => $in_inner_section,
			'settings' => $settings,
			'elements' => $this->column_children( $children, $in_inner_section ),
		);
	}

	/**
	 * Children of a column.
	 *
	 * @param array<int, array<string, mixed>> $children        Children.
	 * @param bool                             $in_inner_section Inside an inner section already.
	 * @return array<int, array<string, mixed>>
	 */
	private function column_children( array $children, bool $in_inner_section ): array {
		$out = array();
		foreach ( $children as $child ) {
			if ( 'container' !== $child['elType'] ) {
				$out[] = $child;
				continue;
			}
			if ( 'row' === ( $child['settings']['flex_direction'] ?? 'column' ) && ! $in_inner_section ) {
				$out[] = $this->section( $child, true );
				continue;
			}
			foreach ( $this->column_children( $child['elements'], $in_inner_section ) as $grand ) {
				$out[] = $grand;
			}
		}
		return $out;
	}

	/**
	 * Copy style settings and matching global references.
	 *
	 * @param array<string, mixed> $s Settings.
	 * @return array<string, mixed>
	 */
	private function copy_styles( array $s ): array {
		$out = array();
		foreach ( self::STYLE_KEYS as $key ) {
			if ( array_key_exists( $key, $s ) ) {
				$out[ $key ] = $s[ $key ];
			}
		}
		if ( ! empty( $s['__globals__'] ) ) {
			$out['__globals__'] = array_intersect_key( $s['__globals__'], array_flip( self::STYLE_KEYS ) );
		}
		return $out;
	}
}
