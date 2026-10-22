<?php
/**
 * Everything the document builder needs, decoupled from WordPress lookups
 * so the conversion is deterministic and unit-testable.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Build context.
 */
final class BuildContext {

	/**
	 * Design tokens.
	 *
	 * @var DesignTokens
	 */
	public DesignTokens $tokens;

	/**
	 * Text direction (rtl|ltr).
	 *
	 * @var string
	 */
	public string $direction = 'rtl';

	/**
	 * Site language code.
	 *
	 * @var string
	 */
	public string $language = 'fa';

	/**
	 * Reference Elementor globals (requires the kit to be applied).
	 *
	 * @var bool
	 */
	public bool $use_globals = true;

	/**
	 * Use Flexbox Containers (else legacy sections/columns).
	 *
	 * @var bool
	 */
	public bool $containers = true;

	/**
	 * Resolves "page:key", "#anchor", URLs to final hrefs.
	 *
	 * @var callable(string): string
	 */
	public $link_resolver;

	/**
	 * Resolves an image ref (img_N) to attachment data or null.
	 *
	 * @var callable(string): (array{id: int, url: string, alt: string, width: int, height: int}|null)
	 */
	public $image_resolver;

	/**
	 * Widget availability check.
	 *
	 * @var callable(string): bool
	 */
	public $widget_available;

	/**
	 * Contact form shortcode if a supported form plugin exists.
	 *
	 * @var string|null
	 */
	public ?string $contact_form_shortcode = null;

	/**
	 * Business details from the specification (email, phone, address).
	 *
	 * @var array<string, string|null>
	 */
	public array $business = array();

	/**
	 * Project id (for custom widgets).
	 *
	 * @var int
	 */
	public int $project_id = 0;

	/**
	 * Constructor with safe defaults.
	 *
	 * @param DesignTokens $tokens Tokens.
	 */
	public function __construct( DesignTokens $tokens ) {
		$this->tokens           = $tokens;
		$this->link_resolver    = static fn( string $link ): string => $link;
		$this->image_resolver   = static fn( string $ref ) => null;
		$this->widget_available = static fn( string $name ): bool => true;
	}

	/**
	 * Whether the layout is right-to-left.
	 */
	public function rtl(): bool {
		return 'rtl' === $this->direction;
	}
}
