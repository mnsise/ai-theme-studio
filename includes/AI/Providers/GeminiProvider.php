<?php
/**
 * Google Gemini generateContent provider.
 *
 * Endpoint: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI\Providers;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\AIRequest;
use AIThemeStudio\AI\AIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Gemini provider.
 */
class GeminiProvider extends AbstractProvider {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'gemini';
	}

	/**
	 * Gemini 1.5+ models are multimodal.
	 */
	protected function model_supports_vision(): bool {
		return (bool) preg_match( '/^gemini-(1\.5|[2-9])/i', $this->model );
	}

	/**
	 * Complete.
	 *
	 * @param AIRequest $request Request.
	 * @throws AIException On failure.
	 */
	public function complete( AIRequest $request ): AIResponse {
		$parts = array( array( 'text' => $request->user ) );
		foreach ( $request->images as $img ) {
			$parts[] = array( 'text' => 'Image reference: ' . $img['label'] );
			$parts[] = array(
				'inline_data' => array(
					'mime_type' => $img['mime'],
					'data'      => $img['data'],
				),
			);
		}

		$config = array(
			'maxOutputTokens' => $request->max_tokens,
			'temperature'     => $request->temperature,
		);
		if ( $request->json ) {
			$config['responseMimeType'] = 'application/json';
		}

		$data = $this->post_json(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':generateContent',
			array( 'x-goog-api-key' => $this->api_key ),
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $request->system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => $parts,
					),
				),
				'generationConfig'  => $config,
			)
		);

		if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
			/* translators: %s: block reason */
			throw new AIException( sprintf( __( 'درخواست توسط فیلتر ایمنی Gemini مسدود شد (%s).', 'ai-theme-studio' ), $data['promptFeedback']['blockReason'] ) );
		}
		$candidate = $data['candidates'][0] ?? array();
		$text      = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}
		if ( '' === $text ) {
			throw new AIException( __( 'پاسخ مدل خالی یا نامعتبر بود.', 'ai-theme-studio' ) );
		}
		return new AIResponse(
			$text,
			'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ),
			array(
				'input'  => (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
				'output' => (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			)
		);
	}
}
