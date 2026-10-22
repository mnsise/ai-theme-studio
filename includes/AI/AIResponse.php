<?php
/**
 * Provider-neutral AI response.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Response value object.
 */
final class AIResponse {

	/**
	 * Text output.
	 *
	 * @var string
	 */
	public string $text;

	/**
	 * Whether output was truncated by the token limit.
	 *
	 * @var bool
	 */
	public bool $truncated;

	/**
	 * Token usage (input/output) when reported.
	 *
	 * @var array<string, int>
	 */
	public array $usage;

	/**
	 * Constructor.
	 *
	 * @param string             $text      Text.
	 * @param bool               $truncated Truncated.
	 * @param array<string, int> $usage     Usage.
	 */
	public function __construct( string $text, bool $truncated = false, array $usage = array() ) {
		$this->text      = $text;
		$this->truncated = $truncated;
		$this->usage     = $usage;
	}
}
