<?php
/**
 * Extracts links, images and text content from Elementor element data.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Link extractor.
 */
final class LinkExtractor {

	/**
	 * Links.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array{url: string, text: string, id: string, widget: string}>
	 */
	public static function links( array $elements ): array {
		$out = array();
		self::walk(
			$elements,
			static function ( array $el ) use ( &$out ) {
				$s  = (array) ( $el['settings'] ?? array() );
				$wt = (string) ( $el['widgetType'] ?? '' );
				$id = (string) ( $el['id'] ?? '' );
				if ( isset( $s['link']['url'] ) ) {
					$out[] = array(
						'url'    => (string) $s['link']['url'],
						'text'   => wp_strip_all_tags( (string) ( $s['text'] ?? $s['title'] ?? '' ) ),
						'id'     => $id,
						'widget' => $wt,
					);
				}
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
					if ( ! empty( $item['link']['url'] ) ) {
						$out[] = array(
							'url'    => (string) $item['link']['url'],
							'text'   => wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ),
							'id'     => $id,
							'widget' => $wt,
						);
					}
				}
				foreach ( array( 'editor', 'tab_content' ) as $html_key ) {
					if ( ! empty( $s[ $html_key ] ) && preg_match_all( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', (string) $s[ $html_key ], $m, PREG_SET_ORDER ) ) {
						foreach ( $m as $match ) {
							$out[] = array(
								'url'    => html_entity_decode( $match[1] ),
								'text'   => wp_strip_all_tags( $match[2] ),
								'id'     => $id,
								'widget' => $wt,
							);
						}
					}
				}
				foreach ( (array) ( $s['tabs'] ?? array() ) as $tab ) {
					if ( ! empty( $tab['tab_content'] ) && preg_match_all( '/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', (string) $tab['tab_content'], $m, PREG_SET_ORDER ) ) {
						foreach ( $m as $match ) {
							$out[] = array(
								'url'    => html_entity_decode( $match[1] ),
								'text'   => wp_strip_all_tags( $match[2] ),
								'id'     => $id,
								'widget' => $wt,
							);
						}
					}
				}
			}
		);
		return $out;
	}

	/**
	 * Image widgets.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array{id: string, attachment: int, alt: string, index: int}>
	 */
	public static function images( array $elements ): array {
		$out   = array();
		$index = 0;
		self::walk(
			$elements,
			static function ( array $el ) use ( &$out, &$index ) {
				if ( 'image' === ( $el['widgetType'] ?? '' ) ) {
					$img   = (array) ( $el['settings']['image'] ?? array() );
					$out[] = array(
						'id'         => (string) $el['id'],
						'attachment' => (int) ( $img['id'] ?? 0 ),
						'alt'        => (string) ( $img['alt'] ?? '' ),
						'index'      => $index++,
					);
				}
			}
		);
		return $out;
	}

	/**
	 * Concatenated visible text (for placeholder detection).
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 */
	public static function text( array $elements ): string {
		$parts = array();
		$keys  = array( 'title', 'editor', 'text', 'title_text', 'description_text', 'testimonial_content', 'testimonial_name' );
		self::walk(
			$elements,
			static function ( array $el ) use ( &$parts, $keys ) {
				$s = (array) ( $el['settings'] ?? array() );
				foreach ( $keys as $k ) {
					if ( isset( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
						$parts[] = wp_strip_all_tags( $s[ $k ] );
					}
				}
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
					$parts[] = (string) ( $item['text'] ?? '' );
				}
				foreach ( (array) ( $s['tabs'] ?? array() ) as $tab ) {
					$parts[] = (string) ( $tab['tab_title'] ?? '' ) . ' ' . wp_strip_all_tags( (string) ( $tab['tab_content'] ?? '' ) );
				}
				foreach ( (array) ( $s['items'] ?? array() ) as $item ) {
					$parts[] = is_array( $item ) ? (string) ( $item['item_title'] ?? '' ) : '';
				}
			}
		);
		return implode( "\n", $parts );
	}

	/**
	 * Walk widgets.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param callable                         $fn       Callback for each element.
	 */
	public static function walk( array $elements, callable $fn ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$fn( $el );
			if ( ! empty( $el['elements'] ) ) {
				self::walk( $el['elements'], $fn );
			}
		}
	}
}
