<?php
/**
 * Shared HTTP handling: timeouts, retries with backoff, error mapping.
 *
 * External requests: the only outbound calls made by the AI layer go to the
 * endpoint of the provider configured by the administrator (see docs).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI\Providers;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Base provider.
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected string $api_key;

	/**
	 * Model.
	 *
	 * @var string
	 */
	protected string $model;

	/**
	 * Options (timeout, max_retries, vision, base_url, json_mode).
	 *
	 * @var array<string, mixed>
	 */
	protected array $options;

	/**
	 * Constructor.
	 *
	 * @param string               $api_key API key.
	 * @param string               $model   Model.
	 * @param array<string, mixed> $options Options.
	 */
	public function __construct( string $api_key, string $model, array $options = array() ) {
		$this->api_key = $api_key;
		$this->model   = $model;
		$this->options = array_merge(
			array(
				'timeout'     => 120,
				'max_retries' => 2,
				'vision'      => 'auto',
				'base_url'    => '',
				'json_mode'   => true,
			),
			$options
		);
	}

	/**
	 * Model.
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Vision support honouring the admin override.
	 */
	public function supports_vision(): bool {
		if ( 'on' === $this->options['vision'] ) {
			return true;
		}
		if ( 'off' === $this->options['vision'] ) {
			return false;
		}
		return $this->model_supports_vision();
	}

	/**
	 * Heuristic vision detection by model name.
	 */
	abstract protected function model_supports_vision(): bool;

	/**
	 * POST JSON with retries.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param array<string, mixed>  $body    Body.
	 * @return array<string, mixed> Decoded response.
	 * @throws AIException On failure.
	 */
	protected function post_json( string $url, array $headers, array $body ): array {
		if ( '' === $this->api_key ) {
			throw new AIException( __( 'کلید API هوش مصنوعی تنظیم نشده است. از بخش تنظیمات آن را وارد کنید.', 'ai-theme-studio' ) );
		}

		$attempts = (int) $this->options['max_retries'] + 1;
		$last     = null;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout'     => (int) $this->options['timeout'],
					'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
					'body'        => wp_json_encode( $body ),
					'data_format' => 'body',
					'sslverify'   => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last = new AIException(
					sprintf(
						/* translators: %s: error message */
						__( 'ارتباط با سرویس هوش مصنوعی برقرار نشد: %s', 'ai-theme-studio' ),
						$response->get_error_message()
					),
					true
				);
				$this->backoff( $i, 0 );
				continue;
			}

			$code    = (int) wp_remote_retrieve_response_code( $response );
			$raw     = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );

			if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
				return $decoded;
			}

			$last = $this->map_error( $code, is_array( $decoded ) ? $decoded : array(), $raw );
			if ( ! $last->is_retryable() ) {
				throw $last;
			}
			$this->backoff( $i, (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
		}

		throw $last ?? new AIException( __( 'پاسخی از سرویس هوش مصنوعی دریافت نشد.', 'ai-theme-studio' ), true );
	}

	/**
	 * Sleep before retrying (bounded so a single worker tick stays short).
	 *
	 * @param int $attempt     Attempt index.
	 * @param int $retry_after Server hint in seconds.
	 */
	private function backoff( int $attempt, int $retry_after ): void {
		$wait = $retry_after > 0 ? min( $retry_after, 20 ) : min( 2 ** $attempt, 8 );
		sleep( $wait );
	}

	/**
	 * Map an HTTP error to a user-facing exception.
	 *
	 * @param int                  $code    HTTP status.
	 * @param array<string, mixed> $decoded Decoded body.
	 * @param string               $raw     Raw body.
	 */
	protected function map_error( int $code, array $decoded, string $raw ): AIException {
		$provider_msg = $this->extract_error_message( $decoded );
		if ( '' === $provider_msg ) {
			$provider_msg = mb_substr( wp_strip_all_tags( $raw ), 0, 300 );
		}
		$details = array(
			'status'   => $code,
			'provider' => $this->id(),
			'message'  => $provider_msg,
		);

		switch ( true ) {
			case 401 === $code || 403 === $code:
				return new AIException( __( 'کلید API نامعتبر است یا به این مدل دسترسی ندارد.', 'ai-theme-studio' ), false, $details );
			case 404 === $code:
				/* translators: %s: model name */
				return new AIException( sprintf( __( 'مدل «%s» در این سرویس پیدا نشد. نام مدل را در تنظیمات بررسی کنید.', 'ai-theme-studio' ), $this->model ), false, $details );
			case 413 === $code:
				return new AIException( __( 'حجم درخواست بیش از حد مجاز است. تعداد یا اندازه تصاویر را کاهش دهید.', 'ai-theme-studio' ), false, $details );
			case 429 === $code:
				return new AIException( __( 'محدودیت نرخ یا سهمیه سرویس هوش مصنوعی تمام شده است. کمی بعد دوباره تلاش کنید.', 'ai-theme-studio' ), true, $details );
			case $code >= 500:
				return new AIException( __( 'سرویس هوش مصنوعی موقتاً در دسترس نیست.', 'ai-theme-studio' ), true, $details );
			default:
				/* translators: 1: HTTP status, 2: provider message */
				return new AIException( sprintf( __( 'خطای سرویس هوش مصنوعی (%1$d): %2$s', 'ai-theme-studio' ), $code, $provider_msg ), false, $details );
		}
	}

	/**
	 * Provider-specific error message extraction.
	 *
	 * @param array<string, mixed> $decoded Decoded body.
	 */
	protected function extract_error_message( array $decoded ): string {
		if ( isset( $decoded['error']['message'] ) ) {
			return (string) $decoded['error']['message'];
		}
		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return $decoded['error'];
		}
		return '';
	}
}
