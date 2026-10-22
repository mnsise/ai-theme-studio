<?php
/**
 * Registers the tiny front-end stylesheet/script. They are only enqueued on
 * generated pages or when one of this plugin's widgets is rendered.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Performance;

use AIThemeStudio\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end assets.
 */
class FrontendAssets {

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
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue).
	 */
	public function register_assets(): void {
		wp_register_style( 'ats-frontend', ATS_URL . 'assets/css/frontend.css', array(), ATS_VERSION );
		wp_register_script(
			'ats-nav',
			ATS_URL . 'assets/js/nav.js',
			array(),
			ATS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
