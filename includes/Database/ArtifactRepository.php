<?php
/**
 * Versioned storage for generation artifacts (specs, sitemaps, design tokens,
 * page definitions, validation reports, prompt history, Elementor backups).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Artifact repository. Every save creates a new version; nothing is overwritten.
 */
class ArtifactRepository {

	/**
	 * Save a new version.
	 *
	 * @param int    $project_id Project.
	 * @param string $type       Artifact type.
	 * @param string $key        Artifact key (e.g. page key).
	 * @param mixed  $data       JSON-serialisable data.
	 * @return int Version number.
	 */
	public function save( int $project_id, string $type, string $key, $data ): int {
		global $wpdb;
		$table   = Schema::table( 'artifacts' );
		$current = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE project_id = %d AND type = %s AND artifact_key = %s", $project_id, $type, $key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$version = $current + 1;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'project_id'   => $project_id,
				'type'         => $type,
				'artifact_key' => $key,
				'version'      => $version,
				'data'         => wp_json_encode( $data ),
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		return $version;
	}

	/**
	 * Latest version data.
	 *
	 * @param int    $project_id Project.
	 * @param string $type       Type.
	 * @param string $key        Key.
	 * @return mixed|null
	 */
	public function latest( int $project_id, string $type, string $key = '' ) {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		$raw   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT data FROM {$table} WHERE project_id = %d AND type = %s AND artifact_key = %s ORDER BY version DESC LIMIT 1", $project_id, $type, $key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null === $raw ? null : json_decode( $raw, true );
	}

	/**
	 * Specific version.
	 *
	 * @param int    $project_id Project.
	 * @param string $type       Type.
	 * @param string $key        Key.
	 * @param int    $version    Version.
	 * @return mixed|null
	 */
	public function version( int $project_id, string $type, string $key, int $version ) {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		$raw   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT data FROM {$table} WHERE project_id = %d AND type = %s AND artifact_key = %s AND version = %d", $project_id, $type, $key, $version ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null === $raw ? null : json_decode( $raw, true );
	}

	/**
	 * Version list (metadata only).
	 *
	 * @param int         $project_id Project.
	 * @param string|null $type       Optional type filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function history( int $project_id, ?string $type = null ): array {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		if ( $type ) {
			$sql = $wpdb->prepare( "SELECT id, type, artifact_key, version, created_by, created_at, LENGTH(data) AS size FROM {$table} WHERE project_id = %d AND type = %s ORDER BY id DESC LIMIT 200", $project_id, $type ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT id, type, artifact_key, version, created_by, created_at, LENGTH(data) AS size FROM {$table} WHERE project_id = %d ORDER BY id DESC LIMIT 200", $project_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Delete everything for a project.
	 *
	 * @param int $project_id Project.
	 */
	public function delete_project( int $project_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'artifacts' ), array( 'project_id' => $project_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Keep only the newest N versions of each type/key for a project.
	 *
	 * @param int $project_id Project.
	 * @param int $keep       Versions to keep.
	 */
	public function prune( int $project_id, int $keep = 10 ): void {
		global $wpdb;
		$table = Schema::table( 'artifacts' );
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE a FROM {$table} a JOIN (SELECT type, artifact_key, MAX(version) AS maxv FROM {$table} WHERE project_id = %d GROUP BY type, artifact_key) m ON a.type = m.type AND a.artifact_key = m.artifact_key WHERE a.project_id = %d AND a.version <= m.maxv - %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$project_id,
				$project_id,
				$keep
			)
		);
	}
}
