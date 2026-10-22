<?php
/**
 * OpenAI Chat Completions provider.
 *
 * Endpoint: https://api.openai.com/v1/chat/completions
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI\Providers;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\AIRequest;
use AIThemeStudio\AI\AIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI provider.
 */
class OpenAIProvider extends AbstractProvider {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'openai';
	}

	/**
	 * Endpoint URL.
	 */
	protected function endpoint(): string {
		return 'https://api.openai.com/v1/chat/completions';
	}

	/**
	 * Vision heuristic.
	 */
	protected function model_supports_vision(): bool {
		return (bool) preg_match( '/^(gpt-4o|gpt-4\.1|gpt-4-turbo|gpt-5|o1|o3|o4|chatgpt-4o)/i', $this->model );
	}

	/**
	 * Reasoning models reject a custom temperature.
	 */
	protected function is_reasoning_model(): bool {
		return (bool) preg_match( '/^(o1|o3|o4|gpt-5)/i', $this->model );
	}

	/**
	 * Complete.
	 *
	 * @param AIRequest $request Request.
	 * @throws AIException On failure.
	 */
	public function complete( AIRequest $request ): AIResponse {
		$content = array(
			array(
				'type' => 'text',
				'text' => $request->user,
			),
		);
		foreach ( $request->images as $img ) {
			$content[] = array(
				'type' => 'text',
				'text' => 'Image reference: ' . $img['label'],
			);
			$content[] = array(
				'type'      => 'image_url',
				'image_url' => array(
					'url'    => 'data:' . $img['mime'] . ';base64,' . $img['data'],
					'detail' => 'high',
				),
			);
		}

		$body = array(
			'model'    => $this->model,
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $request->system,
				),
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
		);
		$body = array_merge( $body, $this->token_params( $request ) );
		if ( $request->json && $this->options['json_mode'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$data   = $this->post_json( $this->endpoint(), $this->auth_headers(), $body );
		$choice = $data['choices'][0] ?? null;
		if ( ! $choice || ! isset( $choice['message']['content'] ) ) {
			$refusal = $choice['message']['refusal'] ?? '';
			throw new AIException(
				$refusal
					/* translators: %s: refusal text */
					? sprintf( __( 'مدل درخواست را رد کرد: %s', 'ai-theme-studio' ), $refusal )
					: __( 'پاسخ مدل خالی یا نامعتبر بود.', 'ai-theme-studio' ),
				false
			);
		}

		return new AIResponse(
			(string) $choice['message']['content'],
			'length' === ( $choice['finish_reason'] ?? '' ),
			array(
				'input'  => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'output' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
			)
		);
	}

	/**
	 * Token/temperature parameters.
	 *
	 * @param AIRequest $request Request.
	 * @return array<string, mixed>
	 */
	protected function token_params( AIRequest $request ): array {
		$params = array( 'max_completion_tokens' => $request->max_tokens );
		if ( ! $this->is_reasoning_model() ) {
			$params['temperature'] = $request->temperature;
		}
		return $params;
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string, string>
	 */
	protected function auth_headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->api_key );
	}
}
