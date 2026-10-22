<?php
/**
 * Common interface for AI providers.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Provider contract.
 */
interface ProviderInterface {

	/**
	 * Provider id.
	 */
	public function id(): string;

	/**
	 * Configured model.
	 */
	public function model(): string;

	/**
	 * Whether the configured model accepts image input.
	 */
	public function supports_vision(): bool;

	/**
	 * Run a completion.
	 *
	 * @param AIRequest $request Request.
	 * @throws AIException On failure.
	 */
	public function complete( AIRequest $request ): AIResponse;
}
