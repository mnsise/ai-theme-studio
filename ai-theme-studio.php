<?php
/**
 * Plugin Name:       360Dialog AI Theme Studio
 * Plugin URI:        https://360dialog.ir/
 * Description:       Build a complete, SEO-aware, fully editable Elementor website from a written brief and reference images — powered by AI.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Masoud Nasrollahi far
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-theme-studio
 * Domain Path:       /languages
 *
 * @package AIThemeStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'ATS_VERSION', '1.0.0' );
define( 'ATS_DB_VERSION', '1.0.0' );
define( 'ATS_FILE', __FILE__ );
define( 'ATS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATS_URL', plugin_dir_url( __FILE__ ) );
define( 'ATS_MIN_PHP', '8.0' );
define( 'ATS_MIN_WP', '6.3' );
define( 'ATS_MIN_ELEMENTOR', '3.16.0' );

if ( version_compare( PHP_VERSION, ATS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: required PHP version */
					__( 'افزونه AI Theme Studio به PHP نسخه %s یا بالاتر نیاز دارد.', 'ai-theme-studio' ),
					ATS_MIN_PHP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once ATS_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( \AIThemeStudio\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AIThemeStudio\Core\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\AIThemeStudio\Core\Plugin::instance()->boot();
	}
);
