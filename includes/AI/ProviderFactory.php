<?php
/**
 * Builds the configured AI provider.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

use AIThemeStudio\AI\Providers\AnthropicProvider;
use AIThemeStudio\AI\Providers\GeminiProvider;
use AIThemeStudio\AI\Providers\OpenAICompatibleProvider;
use AIThemeStudio\AI\Providers\OpenAIProvider;
use AIThemeStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provider factory.
 */
class ProviderFactory {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create the configured provider. Extensions may supply their own via the
	 * `ats_ai_provider` filter (must implement ProviderInterface).
	 *
	 * @throws AIException When misconfigured.
	 */
	public function make(): ProviderInterface {
		$s       = $this->settings->all();
		$options = array(
			'timeout'     => (int) $s['timeout'],
			'max_retries' => (int) $s['max_retries'],
			'vision'      => (string) $s['vision'],
			'base_url'    => (string) $s['base_url'],
			'json_mode'   => (bool) $s['json_mode'],
		);
		$key   = $this->settings->api_key();
		$model = (string) $s['model'];

		$provider = apply_filters( 'ats_ai_provider', null, $s['provider'], $model, $options );
		if ( $provider instanceof ProviderInterface ) {
			return $provider;
		}

		if ( '' === $model ) {
			throw new AIException( __( 'نام مدل هوش مصنوعی در تنظیمات مشخص نشده است.', 'ai-theme-studio' ) );
		}

		switch ( $s['provider'] ) {
			case 'anthropic':
				return new AnthropicProvider( $key, $model, $options );
			case 'gemini':
				return new GeminiProvider( $key, $model, $options );
			case 'openai_compatible':
				return new OpenAICompatibleProvider( $key, $model, $options );
			case 'openai':
			default:
				return new OpenAIProvider( $key, $model, $options );
		}
	}

	/**
	 * Max output tokens setting.
	 */
	public function max_tokens(): int {
		return (int) $this->settings->get( 'max_output_tokens' );
	}
}
