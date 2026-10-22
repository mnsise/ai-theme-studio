<?php
/**
 * Symmetric encryption for stored API keys (encrypt-then-MAC).
 *
 * The key is derived from the site's WordPress salts, so a database dump alone
 * does not reveal API keys. Defining ATS_API_KEY in wp-config.php avoids
 * storing the key in the database entirely.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Crypto helper.
 */
final class Crypto {

	private const CIPHER = 'aes-256-cbc';

	/**
	 * Derive the encryption key.
	 */
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|ai-theme-studio', true );
	}

	/**
	 * Encrypt.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt. Returns an empty string when the payload is invalid or tampered.
	 *
	 * @param string $payload Encrypted payload.
	 */
	public static function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}
		if ( 0 === strpos( $payload, 'b64:' ) ) {
			return (string) base64_decode( substr( $payload, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $payload, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $payload, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$key    = self::key();
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}
