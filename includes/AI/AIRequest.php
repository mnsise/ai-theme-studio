<?php
/**
 * Provider-neutral AI request.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Request value object.
 */
final class AIRequest {

	/**
	 * System instructions.
	 *
	 * @var string
	 */
	public string $system;

	/**
	 * User message.
	 *
	 * @var string
	 */
	public string $user;

	/**
	 * Images: list of [ 'mime' => string, 'data' => base64 string, 'label' => string ].
	 *
	 * @var array<int, array{mime: string, data: string, label: string}>
	 */
	public array $images;

	/**
	 * Whether JSON output is required.
	 *
	 * @var bool
	 */
	public bool $json;

	/**
	 * Max output tokens.
	 *
	 * @var int
	 */
	public int $max_tokens;

	/**
	 * Temperature.
	 *
	 * @var float
	 */
	public float $temperature;

	/**
	 * Constructor.
	 *
	 * @param string                                                     $system      System prompt.
	 * @param string                                                     $user        User prompt.
	 * @param array<int, array{mime: string, data: string, label: string}> $images     Images.
	 * @param bool                                                       $json        JSON mode.
	 * @param int                                                        $max_tokens  Max tokens.
	 * @param float                                                      $temperature Temperature.
	 */
	public function __construct( string $system, string $user, array $images = array(), bool $json = true, int $max_tokens = 8000, float $temperature = 0.4 ) {
		$this->system      = $system;
		$this->user        = $user;
		$this->images      = $images;
		$this->json        = $json;
		$this->max_tokens  = $max_tokens;
		$this->temperature = $temperature;
	}
}
