<?php
/**
 * AI-layer exception carrying a user-facing (Persian) message.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * AI exception.
 */
class AIException extends \RuntimeException {

	/**
	 * Whether retrying later may succeed.
	 *
	 * @var bool
	 */
	private bool $retryable;

	/**
	 * Technical details (never shown with secrets).
	 *
	 * @var array<string, mixed>
	 */
	private array $details;

	/**
	 * Constructor.
	 *
	 * @param string               $message   User-facing message.
	 * @param bool                 $retryable Retryable.
	 * @param array<string, mixed> $details   Details.
	 */
	public function __construct( string $message, bool $retryable = false, array $details = array() ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
		$this->details   = $details;
	}

	/**
	 * Retryable flag.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Details.
	 *
	 * @return array<string, mixed>
	 */
	public function details(): array {
		return $this->details;
	}
}
