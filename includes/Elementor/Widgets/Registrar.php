<?php
/**
 * Registers the plugin's Elementor widgets via the supported
 * `elementor/widgets/register` hook.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Widget registrar.
 */
final class Registrar {

	/**
	 * Register widgets.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Manager.
	 */
	public static function register( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$widgets_manager->register( new NavMenu() );
		$widgets_manager->register( new PostsGrid() );
	}
}
