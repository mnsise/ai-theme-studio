<?php
/**
 * Plugin settings storage with encrypted secrets.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Core;

use AIThemeStudio\Security\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor.
 */
class Settings {

	public const OPTION = 'ats_settings';

	public const PROVIDERS = array( 'openai', 'anthropic', 'gemini', 'openai_compatible' );

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'            => 'openai',
			'model'               => 'gpt-4o',
			'api_key'             => '',
			'base_url'            => '',
			'vision'              => 'auto',
			'json_mode'           => true,
			'timeout'             => 120,
			'max_retries'         => 2,
			'max_output_tokens'   => 8000,
			'rate_limit_per_hour' => 30,
			'max_upload_mb'       => 8,
			'max_images'          => 10,
			'seo_output'          => true,
			'pagespeed_api_key'   => '',
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * Cached settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * All settings (secrets remain encrypted).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Get a single value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Decrypted AI API key. A wp-config.php constant takes precedence.
	 */
	public function api_key(): string {
		if ( defined( 'ATS_API_KEY' ) && ATS_API_KEY ) {
			return (string) ATS_API_KEY;
		}
		return Crypto::decrypt( (string) $this->get( 'api_key' ) );
	}

	/**
	 * Decrypted PageSpeed Insights key.
	 */
	public function pagespeed_key(): string {
		if ( defined( 'ATS_PAGESPEED_API_KEY' ) && ATS_PAGESPEED_API_KEY ) {
			return (string) ATS_PAGESPEED_API_KEY;
		}
		return Crypto::decrypt( (string) $this->get( 'pagespeed_api_key' ) );
	}

	/**
	 * Settings safe to expose to the admin UI (secrets masked).
	 *
	 * @return array<string, mixed>
	 */
	public function public_view(): array {
		$all                      = $this->all();
		$all['api_key']           = self::mask( $this->api_key() );
		$all['pagespeed_api_key'] = self::mask( $this->pagespeed_key() );
		$all['api_key_from_constant'] = defined( 'ATS_API_KEY' ) && ATS_API_KEY;
		return $all;
	}

	/**
	 * Validate, sanitize and persist an update.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Saved settings (public view).
	 */
	public function update( array $input ): array {
		$current = $this->all();
		$clean   = $current;

		if ( isset( $input['provider'] ) && in_array( $input['provider'], self::PROVIDERS, true ) ) {
			$clean['provider'] = $input['provider'];
		}
		if ( isset( $input['model'] ) ) {
			$clean['model'] = substr( preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', (string) $input['model'] ), 0, 120 );
		}
		if ( isset( $input['base_url'] ) ) {
			$url               = esc_url_raw( trim( (string) $input['base_url'] ), array( 'https' ) );
			$clean['base_url'] = $url ? untrailingslashit( $url ) : '';
		}
		if ( isset( $input['vision'] ) && in_array( $input['vision'], array( 'auto', 'on', 'off' ), true ) ) {
			$clean['vision'] = $input['vision'];
		}
		foreach ( array( 'json_mode', 'seo_output', 'delete_on_uninstall' ) as $bool ) {
			if ( isset( $input[ $bool ] ) ) {
				$clean[ $bool ] = rest_sanitize_boolean( $input[ $bool ] );
			}
		}
		$ranges = array(
			'timeout'             => array( 15, 600 ),
			'max_retries'         => array( 0, 5 ),
			'max_output_tokens'   => array( 1000, 64000 ),
			'rate_limit_per_hour' => array( 1, 500 ),
			'max_upload_mb'       => array( 1, 50 ),
			'max_images'          => array( 1, 30 ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		// Secrets: an empty value or the masked placeholder keeps the stored key; "__clear__" removes it.
		foreach ( array( 'api_key', 'pagespeed_api_key' ) as $secret ) {
			if ( ! isset( $input[ $secret ] ) ) {
				continue;
			}
			$value = trim( (string) $input[ $secret ] );
			if ( '__clear__' === $value ) {
				$clean[ $secret ] = '';
			} elseif ( '' !== $value && false === strpos( $value, '•' ) ) {
				$clean[ $secret ] = Crypto::encrypt( sanitize_text_field( $value ) );
			}
		}

		update_option( self::OPTION, $clean, false );
		$this->cache = null;
		return $this->public_view();
	}

	/**
	 * Mask a secret for display.
	 *
	 * @param string $secret Secret.
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
