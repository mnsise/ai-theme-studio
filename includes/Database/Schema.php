<?php
/**
 * Custom table schema.
 *
 * Projects are a private custom post type; large, versioned generation
 * artifacts, jobs and logs live in dedicated tables so they never bloat
 * wp_options or autoloaded data.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Schema manager.
 */
final class Schema {

	public const TABLES = array( 'jobs', 'artifacts', 'logs' );

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ats_' . $name;
	}

	/**
	 * Run dbDelta when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'ats_db_version' ) !== ATS_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = self::table( 'jobs' );
		$arts    = self::table( 'artifacts' );
		$logs    = self::table( 'logs' );

		$sql = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			step varchar(40) NOT NULL DEFAULT '',
			cursor_data longtext NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			locked_until datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_status (project_id, status)
		) {$charset};
		CREATE TABLE {$arts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			type varchar(40) NOT NULL,
			artifact_key varchar(100) NOT NULL DEFAULT '',
			version int(10) unsigned NOT NULL DEFAULT 1,
			data longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_type_key (project_id, type, artifact_key)
		) {$charset};
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			level varchar(10) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'ats_db_version', ATS_DB_VERSION, false );
	}

	/**
	 * Drop all plugin tables (uninstall only).
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'ats_db_version' );
	}
}
