<?php
/**
 * Anthropic Messages API provider.
 *
 * Endpoint: https://api.anthropic.com/v1/messages
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI\Providers;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\AIRequest;
use AIThemeStudio\AI\AIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic provider.
 */
class AnthropicProvider extends AbstractProvider {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * All Claude 3+ models accept images.
	 */
	protected function model_supports_vision(): bool {
		return (bool) preg_match( '/^claude-(3|sonnet|opus|haiku|[4-9])/i', $this->model );
	}

	/**
	 * Complete.
	 *
	 * @param AIRequest $request Request.
	 * @throws AIException On failure.
	 */
	public function complete( AIRequest $request ): AIResponse {
		$content = array();
		foreach ( $request->images as $img ) {
			$content[] = array(
				'type' => 'text',
				'text' => 'Image reference: ' . $img['label'],
			);
			$content[] = array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $img['mime'],
					'data'       => $img['data'],
				),
			);
		}
		$user = $request->user;
		if ( $request->json ) {
			$user .= "\n\nRespond with a single JSON object only, without markdown fences or commentary.";
		}
		$content[] = array(
			'type' => 'text',
			'text' => $user,
		);

		$data = $this->post_json(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			),
			array(
				'model'       => $this->model,
				'max_tokens'  => $request->max_tokens,
				'temperature' => $request->temperature,
				'system'      => $request->system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $content,
					),
				),
			)
		);

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		if ( '' === $text ) {
			throw new AIException( __( 'پاسخ مدل خالی یا نامعتبر بود.', 'ai-theme-studio' ) );
		}
		return new AIResponse(
			$text,
			'max_tokens' === ( $data['stop_reason'] ?? '' ),
			array(
				'input'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
				'output' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
			)
		);
	}
}
