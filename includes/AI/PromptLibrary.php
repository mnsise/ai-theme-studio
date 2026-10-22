<?php
/**
 * Loads prompt templates from /prompts and renders {{placeholders}}.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt library.
 */
class PromptLibrary {

	/**
	 * Directory.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Cache.
	 *
	 * @var array<string, string>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param string $dir Prompt directory.
	 */
	public function __construct( string $dir ) {
		$this->dir = rtrim( $dir, '/' );
	}

	/**
	 * Load a raw template. Only names from a strict charset are accepted.
	 *
	 * @param string $name Template name.
	 * @throws \InvalidArgumentException Unknown template.
	 */
	public function raw( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9\-]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid prompt name' );
		}
		if ( ! isset( $this->cache[ $name ] ) ) {
			$file = $this->dir . '/' . $name . '.md';
			if ( ! is_readable( $file ) ) {
				throw new \InvalidArgumentException( 'Missing prompt template ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$this->cache[ $name ] = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		/**
		 * Filter a prompt template before rendering.
		 *
		 * @param string $template Template text.
		 * @param string $name     Template name.
		 */
		return (string) apply_filters( 'ats_prompt_template', $this->cache[ $name ], $name );
	}

	/**
	 * Render a template.
	 *
	 * Values are inserted verbatim; untrusted user text must be passed through
	 * self::untrusted() by the caller so it is fenced as data.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables.
	 */
	public function render( string $name, array $vars = array() ): string {
		$template = $this->raw( $name );
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z0-9_]+)\s*\}\}/',
			static function ( $m ) use ( $vars ) {
				if ( ! array_key_exists( $m[1], $vars ) ) {
					return '';
				}
				$v = $vars[ $m[1] ];
				if ( is_array( $v ) ) {
					return (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
				}
				if ( is_bool( $v ) ) {
					return $v ? 'true' : 'false';
				}
				return (string) $v;
			},
			$template
		);
	}

	/**
	 * Fence untrusted text so the model treats it as data, not instructions.
	 *
	 * @param string $text Untrusted text.
	 * @param string $tag  Tag name.
	 */
	public static function untrusted( string $text, string $tag = 'user_input' ): string {
		$text = str_replace( array( '<' . $tag . '>', '</' . $tag . '>' ), '', $text );
		return '<' . $tag . ">\n" . $text . "\n</" . $tag . '>';
	}
}
