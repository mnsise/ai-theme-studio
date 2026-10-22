<?php
/**
 * Renders the generated, Elementor-built 404 page for not-found requests
 * (opt-in at publish time). The HTTP status stays 404.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\WordPress;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\CanvasLayout;

defined( 'ABSPATH' ) || exit;

/**
 * 404 handler.
 */
class NotFoundHandler {

	public const OPTION = 'ats_404_page_id';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'template_include', array( $this, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Configured page id if usable.
	 */
	public static function page_id(): int {
		$id = (int) get_option( self::OPTION, 0 );
		if ( ! $id || 'publish' !== get_post_status( $id ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Swap the template on 404.
	 *
	 * @param string $template Template.
	 */
	public function template( $template ) {
		if ( is_404() && self::page_id() ) {
			return ATS_DIR . 'templates/not-found.php';
		}
		return $template;
	}

	/**
	 * Assets for the 404 page.
	 */
	public function enqueue(): void {
		$id = is_404() ? self::page_id() : 0;
		if ( ! $id ) {
			return;
		}
		$ctx = CanvasLayout::context_for( $id );
		if ( $ctx ) {
			CanvasLayout::enqueue_for( $ctx );
		}
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $id )->enqueue();
		}
		$frontend = \Elementor\Plugin::$instance->frontend;
		if ( method_exists( $frontend, 'enqueue_styles' ) ) {
			$frontend->enqueue_styles();
		}
	}
}
