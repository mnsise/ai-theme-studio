<?php
/**
 * WCAG 2.x contrast ratio utilities.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Contrast helper.
 */
final class Contrast {

	/**
	 * Pairs that must reach AA for normal text: [foreground, background, label].
	 */
	public const PAIRS = array(
		array( 'text', 'background' ),
		array( 'heading', 'background' ),
		array( 'text', 'surface' ),
		array( 'heading', 'surface' ),
		array( 'muted', 'background' ),
		array( 'on_dark', 'dark' ),
		array( 'on_primary', 'primary' ),
		array( 'primary', 'background' ),
	);

	/**
	 * Relative luminance.
	 *
	 * @param string $hex Color.
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$rgb = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$c     = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$rgb[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	/**
	 * Contrast ratio.
	 *
	 * @param string $a Color.
	 * @param string $b Color.
	 */
	public static function ratio( string $a, string $b ): float {
		$l1 = self::luminance( $a );
		$l2 = self::luminance( $b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * Failing pairs.
	 *
	 * @param array<string, string> $colors Token colors.
	 * @param float                 $min    Minimum ratio.
	 * @return array<int, array{fg: string, bg: string, ratio: float}>
	 */
	public static function failures( array $colors, float $min = 4.5 ): array {
		$out = array();
		foreach ( self::PAIRS as $pair ) {
			[ $fg, $bg ] = $pair;
			if ( empty( $colors[ $fg ] ) || empty( $colors[ $bg ] ) ) {
				continue;
			}
			// Brand primary on background only needs 3:1 (used for large text, icons, borders).
			$required = 'primary' === $fg ? 3.0 : $min;
			$ratio    = self::ratio( $colors[ $fg ], $colors[ $bg ] );
			if ( $ratio < $required ) {
				$out[] = array(
					'fg'    => $fg,
					'bg'    => $bg,
					'ratio' => round( $ratio, 2 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Adjust a foreground color (keeping hue) until it reaches the target ratio.
	 *
	 * @param string $fg     Foreground.
	 * @param string $bg     Background.
	 * @param float  $target Target ratio.
	 */
	public static function fix( string $fg, string $bg, float $target = 4.6 ): string {
		$darken = self::luminance( $bg ) > 0.4;
		$color  = strtoupper( $fg );
		for ( $i = 0; $i < 40 && self::ratio( $color, $bg ) < $target; $i++ ) {
			$color = \AIThemeStudio\Elementor\DesignTokens::shade( $color, $darken ? -8 : 8 );
		}
		if ( self::ratio( $color, $bg ) < $target ) {
			$color = $darken ? '#000000' : '#FFFFFF';
		}
		return $color;
	}

	/**
	 * Auto-fix all failing token pairs.
	 *
	 * @param array<string, string> $colors Colors.
	 * @return array{colors: array<string, string>, changes: array<int, string>}
	 */
	public static function fix_all( array $colors ): array {
		$changes = array();
		for ( $pass = 0; $pass < 3; $pass++ ) {
			$fails = self::failures( $colors );
			if ( ! $fails ) {
				break;
			}
			foreach ( $fails as $f ) {
				$target            = 'primary' === $f['fg'] ? 3.1 : 4.6;
				$new               = self::fix( $colors[ $f['fg'] ], $colors[ $f['bg'] ], $target );
				$changes[]         = sprintf( '%s %s → %s (contrast on %s was %.2f:1)', $f['fg'], $colors[ $f['fg'] ], $new, $f['bg'], $f['ratio'] );
				$colors[ $f['fg'] ] = $new;
			}
		}
		return array(
			'colors'  => $colors,
			'changes' => $changes,
		);
	}
}
