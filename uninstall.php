<?php
/**
 * Uninstall. Plugin data is removed only when the owner enabled
 * "delete_on_uninstall". Generated pages, menus, media and Elementor
 * templates are site content and are never deleted.
 *
 * @package AIThemeStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ats_settings = get_option( 'ats_settings' );
if ( ! is_array( $ats_settings ) || empty( $ats_settings['delete_on_uninstall'] ) ) {
	return;
}

defined( 'ATS_DIR' ) || define( 'ATS_DIR', __DIR__ . '/' );
require_once __DIR__ . '/includes/autoload.php';

global $wpdb;

\AIThemeStudio\Database\Schema::drop();

$ats_projects = get_posts(
	array(
		'post_type'      => 'ats_project',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $ats_projects as $ats_project_id ) {
	wp_delete_post( (int) $ats_project_id, true );
}

delete_option( 'ats_settings' );
delete_option( 'ats_db_version' );
delete_option( 'ats_404_page_id' );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ats\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ats\\_rl\\_%'" ); // phpcs:ignore WordPress.DB

wp_clear_scheduled_hook( 'ats_daily_cleanup' );
wp_unschedule_hook( 'ats_run_job' );
