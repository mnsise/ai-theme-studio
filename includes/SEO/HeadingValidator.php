<?php
/**
 * Heading hierarchy validation for page definitions and Elementor documents.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Heading validator.
 */
final class HeadingValidator {

	/**
	 * Enforce one H1 and no skipped levels on a page definition (in place).
	 *
	 * @param array<string, mixed> $page Page definition (modified).
	 * @return array<int, string> Human-readable list of applied fixes.
	 */
	public static function fix_page( array &$page ): array {
		$fixes = array();
		$refs  = array();
		foreach ( $page['sections'] as $si => &$section ) {
			self::collect_refs( $section['elements'], $refs );
		}
		unset( $section );

		if ( ! $refs ) {
			return $fixes;
		}

		$h1 = array_values(
			array_filter(
				array_keys( $refs ),
				static fn( $i ) => 'h1' === $refs[ $i ]['level']
			)
		);

		if ( ! $h1 ) {
			foreach ( $refs as $i => $ref ) {
				if ( 'heading' === $ref['element']['type'] ) {
					$refs[ $i ]['element']['level'] = 'h1';
					$refs[ $i ]['level']            = 'h1';
					$fixes[]                        = sprintf( 'Promoted "%s" to H1 (page had no H1).', $ref['element']['text'] ?? '' );
					break;
				}
			}
		} elseif ( count( $h1 ) > 1 ) {
			foreach ( array_slice( $h1, 1 ) as $i ) {
				$refs[ $i ]['element']['level'] = 'h2';
				$refs[ $i ]['level']            = 'h2';
				$fixes[]                        = sprintf( 'Demoted extra H1 "%s" to H2.', self::label( $refs[ $i ]['element'] ) );
			}
		}

		$prev = 0;
		foreach ( $refs as $i => $ref ) {
			$level = (int) substr( $ref['level'], 1 );
			if ( $prev > 0 && $level > $prev + 1 ) {
				$new                            = 'h' . ( $prev + 1 );
				$refs[ $i ]['element']['level'] = $new;
				$fixes[]                        = sprintf( 'Changed "%s" from %s to %s to avoid a skipped heading level.', self::label( $ref['element'] ), strtoupper( $ref['level'] ), strtoupper( $new ) );
				$level                          = $prev + 1;
			} elseif ( 0 === $prev && 1 !== $level ) {
				// Content before the H1 (e.g. an eyebrow heading) is allowed but should not be a heading level below H2.
				$level = max( 1, $level );
			}
			$prev = $level;
		}

		return $fixes;
	}

	/**
	 * Collect references to heading-bearing elements in document order.
	 *
	 * @param array<int, array<string, mixed>>  $elements Elements (by reference).
	 * @param array<int, array<string, mixed>> $refs     Output.
	 */
	private static function collect_refs( array &$elements, array &$refs ): void {
		foreach ( $elements as &$el ) {
			$type = $el['type'] ?? '';
			if ( 'heading' === $type ) {
				$el['level'] = $el['level'] ?? 'h2';
				if ( 'p' !== $el['level'] ) {
					$refs[] = array(
						'element' => &$el,
						'level'   => $el['level'],
					);
				}
			} elseif ( 'icon_box' === $type || 'accordion' === $type ) {
				$el['level'] = isset( $el['level'] ) && 'h1' !== $el['level'] ? $el['level'] : 'h3';
				if ( 'p' !== $el['level'] ) {
					$refs[] = array(
						'element' => &$el,
						'level'   => $el['level'],
					);
				}
			} elseif ( 'group' === $type && ! empty( $el['elements'] ) ) {
				self::collect_refs( $el['elements'], $refs );
			}
		}
		unset( $el );
	}

	/**
	 * Label for messages.
	 *
	 * @param array<string, mixed> $el Element.
	 */
	private static function label( array $el ): string {
		return (string) ( $el['text'] ?? $el['title'] ?? $el['type'] );
	}

	/**
	 * Extract the heading outline from Elementor element data.
	 *
	 * @param array<int, array<string, mixed>> $elements Elementor elements.
	 * @return array<int, array{level: string, text: string, id: string}>
	 */
	public static function outline_from_elementor( array $elements ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( 'widget' === ( $el['elType'] ?? '' ) ) {
				$s = $el['settings'] ?? array();
				switch ( $el['widgetType'] ?? '' ) {
					case 'heading':
						$out[] = array(
							'level' => (string) ( $s['header_size'] ?? 'h2' ),
							'text'  => wp_strip_all_tags( (string) ( $s['title'] ?? '' ) ),
							'id'    => (string) $el['id'],
						);
						break;
					case 'icon-box':
					case 'image-box':
						$out[] = array(
							'level' => (string) ( $s['title_size'] ?? 'h3' ),
							'text'  => wp_strip_all_tags( (string) ( $s['title_text'] ?? '' ) ),
							'id'    => (string) $el['id'],
						);
						break;
					case 'accordion':
					case 'toggle':
						foreach ( (array) ( $s['tabs'] ?? array() ) as $tab ) {
							$out[] = array(
								'level' => (string) ( $s['title_html_tag'] ?? 'div' ),
								'text'  => wp_strip_all_tags( (string) ( $tab['tab_title'] ?? '' ) ),
								'id'    => (string) $el['id'],
							);
						}
						break;
					case 'nested-accordion':
						foreach ( (array) ( $s['items'] ?? array() ) as $item ) {
							$out[] = array(
								'level' => (string) ( $s['title_tag'] ?? 'div' ),
								'text'  => wp_strip_all_tags( (string) ( $item['item_title'] ?? '' ) ),
								'id'    => (string) $el['id'],
							);
						}
						break;
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				$out = array_merge( $out, self::outline_from_elementor( $el['elements'] ) );
			}
		}
		return array_values( array_filter( $out, static fn( $h ) => (bool) preg_match( '/^h[1-6]$/', $h['level'] ) ) );
	}

	/**
	 * Audit an outline.
	 *
	 * @param array<int, array{level: string, text: string, id: string}> $outline Outline.
	 * @param bool                                                       $expect_h1 Whether the document is a full page (needs one H1).
	 * @return array<int, array{code: string, severity: string, message: string}>
	 */
	public static function audit_outline( array $outline, bool $expect_h1 = true ): array {
		$issues = array();
		$h1     = array_filter( $outline, static fn( $h ) => 'h1' === $h['level'] );
		if ( $expect_h1 && 0 === count( $h1 ) ) {
			$issues[] = array(
				'code'     => 'missing_h1',
				'severity' => 'error',
				'message'  => __( 'صفحه هیچ عنوان H1 ندارد.', 'ai-theme-studio' ),
			);
		}
		if ( count( $h1 ) > 1 ) {
			$issues[] = array(
				'code'     => 'multiple_h1',
				'severity' => 'warning',
				/* translators: %d: number of H1 headings */
				'message'  => sprintf( __( 'صفحه %d عنوان H1 دارد؛ فقط یک H1 اصلی توصیه می‌شود.', 'ai-theme-studio' ), count( $h1 ) ),
			);
		}
		if ( ! $expect_h1 && count( $h1 ) > 0 ) {
			$issues[] = array(
				'code'     => 'h1_in_template',
				'severity' => 'warning',
				'message'  => __( 'قالب سراسری (هدر/فوتر) نباید عنوان H1 داشته باشد.', 'ai-theme-studio' ),
			);
		}
		$prev = 0;
		foreach ( $outline as $h ) {
			$level = (int) substr( $h['level'], 1 );
			if ( $prev && $level > $prev + 1 ) {
				$issues[] = array(
					'code'     => 'skipped_level',
					'severity' => 'warning',
					/* translators: 1: heading text, 2: previous level, 3: current level */
					'message'  => sprintf( __( 'سطح عنوان «%1$s» از H%2$d به H%3$d پرش کرده است.', 'ai-theme-studio' ), $h['text'], $prev, $level ),
				);
			}
			$prev = $level;
		}
		return $issues;
	}
}
