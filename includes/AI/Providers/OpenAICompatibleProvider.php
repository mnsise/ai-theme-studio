<?php
/**
 * Any OpenAI-compatible Chat Completions endpoint (OpenRouter, self-hosted gateways...).
 *
 * Endpoint: {base_url}/chat/completions — base URL must be HTTPS and is set by an administrator.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI\Providers;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\AIRequest;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI-compatible provider.
 */
class OpenAICompatibleProvider extends OpenAIProvider {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'openai_compatible';
	}

	/**
	 * Endpoint URL.
	 *
	 * @throws AIException When the base URL is missing or not HTTPS.
	 */
	protected function endpoint(): string {
		$base = (string) $this->options['base_url'];
		if ( '' === $base || 0 !== strpos( $base, 'https://' ) || ! wp_http_validate_url( $base ) ) {
			throw new AIException( __( 'آدرس پایه (Base URL) سرویس سازگار با OpenAI معتبر نیست؛ باید با https:// شروع شود.', 'ai-theme-studio' ) );
		}
		return untrailingslashit( $base ) . '/chat/completions';
	}

	/**
	 * Unknown models: vision must be enabled explicitly by the admin.
	 */
	protected function model_supports_vision(): bool {
		return false;
	}

	/**
	 * Use the widely supported max_tokens parameter.
	 *
	 * @param AIRequest $request Request.
	 * @return array<string, mixed>
	 */
	protected function token_params( AIRequest $request ): array {
		return array(
			'max_tokens'  => $request->max_tokens,
			'temperature' => $request->temperature,
		);
	}
}
