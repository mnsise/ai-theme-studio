<?php
/**
 * Structural validation of Elementor element data before it is saved, plus
 * widget availability checks against the installed Elementor.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Document validator.
 */
final class DocumentValidator {

	private const EL_TYPES = array( 'container', 'section', 'column', 'widget' );

	/**
	 * Nested widgets: repeater setting whose items map 1:1 to child containers.
	 */
	public const NESTED_WIDGETS = array( 'nested-accordion' => 'items' );

	/**
	 * Validate.
	 *
	 * @param array<int, array<string, mixed>> $elements         Elements.
	 * @param callable(string): bool|null      $widget_available Availability check (null = skip).
	 * @return array{valid: bool, errors: array<int, string>, stats: array<string, int>}
	 */
	public static function validate( array $elements, ?callable $widget_available = null ): array {
		$errors = array();
		$ids    = array();
		$stats  = array(
			'elements'  => 0,
			'widgets'   => 0,
			'max_depth' => 0,
		);
		self::walk( $elements, null, 0, $errors, $ids, $stats, $widget_available );
		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'stats'  => $stats,
		);
	}

	/**
	 * Recursive walk.
	 *
	 * @param array<int, mixed>         $elements Elements.
	 * @param string|null               $parent   Parent elType.
	 * @param int                       $depth    Depth.
	 * @param array<int, string>        $errors   Errors.
	 * @param array<string, bool>       $ids      Seen ids.
	 * @param array<string, int>        $stats    Stats.
	 * @param callable(string): bool|null $widget_available Availability.
	 */
	private static function walk( array $elements, ?string $parent, int $depth, array &$errors, array &$ids, array &$stats, ?callable $widget_available ): void {
		$stats['max_depth'] = max( $stats['max_depth'], $depth );
		if ( array_keys( $elements ) !== range( 0, count( $elements ) - 1 ) && $elements ) {
			$errors[] = 'Elements must be a JSON list.';
			return;
		}
		foreach ( $elements as $el ) {
			++$stats['elements'];
			if ( ! is_array( $el ) ) {
				$errors[] = 'Element is not an object.';
				continue;
			}
			$id = (string) ( $el['id'] ?? '' );
			if ( ! preg_match( '/^[a-z0-9]{6,10}$/', $id ) ) {
				$errors[] = "Invalid element id \"{$id}\".";
			} elseif ( isset( $ids[ $id ] ) ) {
				$errors[] = "Duplicate element id \"{$id}\".";
			}
			$ids[ $id ] = true;

			$type = (string) ( $el['elType'] ?? '' );
			if ( ! in_array( $type, self::EL_TYPES, true ) ) {
				$errors[] = "Element {$id}: unknown elType \"{$type}\".";
				continue;
			}
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$errors[] = "Element {$id}: settings must be an object.";
			}
			if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
				$errors[] = "Element {$id}: elements must be a list.";
				continue;
			}

			switch ( $type ) {
				case 'section':
					if ( null !== $parent && 'column' !== $parent ) {
						$errors[] = "Section {$id} must be top-level or inside a column.";
					}
					foreach ( $el['elements'] as $child ) {
						if ( 'column' !== ( $child['elType'] ?? '' ) ) {
							$errors[] = "Section {$id} may only contain columns.";
							break;
						}
					}
					break;
				case 'column':
					if ( 'section' !== $parent ) {
						$errors[] = "Column {$id} must be inside a section.";
					}
					break;
				case 'container':
					if ( null !== $parent && 'container' !== $parent && 'nested' !== $parent ) {
						$errors[] = "Container {$id} must be top-level or inside a container.";
					}
					if ( null === $parent && ! empty( $el['isInner'] ) ) {
						$errors[] = "Top-level container {$id} must not be marked inner.";
					}
					break;
				case 'widget':
					++$stats['widgets'];
					$wt = (string) ( $el['widgetType'] ?? '' );
					if ( ! preg_match( '/^[a-z0-9\-_]+$/', $wt ) ) {
						$errors[] = "Widget {$id}: invalid widgetType.";
					} elseif ( $widget_available && ! $widget_available( $wt ) ) {
						$errors[] = "Widget {$id}: widget type \"{$wt}\" is not available in this Elementor installation.";
					}
					if ( null === $parent ) {
						$errors[] = "Widget {$id} cannot be top-level.";
					}
					if ( isset( self::NESTED_WIDGETS[ $wt ] ) ) {
						$repeater = (array) ( $el['settings'][ self::NESTED_WIDGETS[ $wt ] ] ?? array() );
						if ( count( $repeater ) !== count( $el['elements'] ) ) {
							$errors[] = "Widget {$id}: nested children must match its items.";
						}
						foreach ( $el['elements'] as $child ) {
							if ( 'container' !== ( $child['elType'] ?? '' ) || empty( $child['isInner'] ) ) {
								$errors[] = "Widget {$id}: nested children must be inner containers.";
								break;
							}
						}
						self::walk( $el['elements'], 'nested', $depth + 1, $errors, $ids, $stats, $widget_available );
					} elseif ( $el['elements'] ) {
						$errors[] = "Widget {$id} must not have children.";
					}
					break;
			}
			if ( 'widget' !== $type ) {
				self::walk( $el['elements'], $type, $depth + 1, $errors, $ids, $stats, $widget_available );
			}
		}
	}

	/**
	 * Find a top-level element by id.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param string                           $id       Id.
	 */
	public static function find_top_level( array $elements, string $id ): ?int {
		foreach ( $elements as $i => $el ) {
			if ( ( $el['id'] ?? '' ) === $id ) {
				return (int) $i;
			}
		}
		return null;
	}

	/**
	 * Collect all ids.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, string>
	 */
	public static function all_ids( array $elements ): array {
		$ids = array();
		foreach ( $elements as $el ) {
			$ids[] = (string) ( $el['id'] ?? '' );
			if ( ! empty( $el['elements'] ) ) {
				$ids = array_merge( $ids, self::all_ids( $el['elements'] ) );
			}
		}
		return $ids;
	}
}
