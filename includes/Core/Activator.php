<?php
/**
 * Activation and deactivation routines.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Core;

use AIThemeStudio\Database\Schema;
use AIThemeStudio\Jobs\Runner;
use AIThemeStudio\WordPress\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation/deactivation.
 */
final class Activator {

	/**
	 * Activation: verify requirements, create tables, schedule cleanup.
	 */
	public static function activate(): void {
		global $wp_version;

		if ( version_compare( $wp_version, ATS_MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( ATS_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: WordPress version */
						__( 'AI Theme Studio به وردپرس نسخه %s یا بالاتر نیاز دارد.', 'ai-theme-studio' ),
						ATS_MIN_WP
					)
				),
				'',
				array( 'back_link' => true )
			);
		}

		Schema::install();
		PostTypes::register();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		if ( ! wp_next_scheduled( 'ats_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ats_daily_cleanup' );
		}
	}

	/**
	 * Deactivation: stop scheduled events. Data is kept (see uninstall.php).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ats_daily_cleanup' );
		wp_unschedule_hook( Runner::CRON_HOOK );
	}
}
