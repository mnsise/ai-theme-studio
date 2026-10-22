<?php
/**
 * Extract a JSON object from model output (tolerates fences and chatter).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * JSON extractor.
 */
final class JsonExtractor {

	/**
	 * Extract the first top-level JSON object.
	 *
	 * @param string $text Model output.
	 * @return array<string, mixed>
	 * @throws AIException When no valid object is found.
	 */
	public static function extract( string $text ): array {
		$text = trim( $text );
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $text, $m ) ) {
			$decoded = json_decode( trim( $m[1] ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$candidate = self::balanced_object( $text );
		if ( null !== $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		throw new AIException(
			__( 'خروجی مدل JSON معتبر نبود.', 'ai-theme-studio' ),
			true,
			array( 'json_error' => json_last_error_msg() )
		);
	}

	/**
	 * Find the first balanced {...} block, respecting strings and escapes.
	 *
	 * @param string $text Text.
	 */
	private static function balanced_object( string $text ): ?string {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}
		$depth  = 0;
		$in_str = false;
		$escape = false;
		$len    = strlen( $text );
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $in_str ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $ch ) {
					$escape = true;
				} elseif ( '"' === $ch ) {
					$in_str = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in_str = true;
			} elseif ( '{' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}
		return null;
	}
}
