<?php
/**
 * Persistent logger backed by a custom table.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Core;

use AIThemeStudio\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {

	/**
	 * Write a log entry. Secrets must never be passed in $context.
	 *
	 * @param string               $level      info|warning|error.
	 * @param string               $message    Human readable message.
	 * @param int                  $project_id Project id.
	 * @param int                  $job_id     Job id.
	 * @param array<string, mixed> $context    Extra data.
	 */
	public function log( string $level, string $message, int $project_id = 0, int $job_id = 0, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'logs' ),
			array(
				'project_id' => $project_id,
				'job_id'     => $job_id,
				'level'      => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message'    => mb_substr( $message, 0, 2000 ),
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Info.
	 *
	 * @param string               $message Message.
	 * @param int                  $project_id Project.
	 * @param int                  $job_id Job.
	 * @param array<string, mixed> $context Context.
	 */
	public function info( string $message, int $project_id = 0, int $job_id = 0, array $context = array() ): void {
		$this->log( 'info', $message, $project_id, $job_id, $context );
	}

	/**
	 * Warning.
	 *
	 * @param string               $message Message.
	 * @param int                  $project_id Project.
	 * @param int                  $job_id Job.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, int $project_id = 0, int $job_id = 0, array $context = array() ): void {
		$this->log( 'warning', $message, $project_id, $job_id, $context );
	}

	/**
	 * Error.
	 *
	 * @param string               $message Message.
	 * @param int                  $project_id Project.
	 * @param int                  $job_id Job.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, int $project_id = 0, int $job_id = 0, array $context = array() ): void {
		$this->log( 'error', $message, $project_id, $job_id, $context );
	}

	/**
	 * Recent entries for a project.
	 *
	 * @param int $project_id Project.
	 * @param int $limit      Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_project( int $project_id, int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::table( 'logs' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, job_id, level, message, created_at FROM {$table} WHERE project_id = %d ORDER BY id DESC LIMIT %d", $project_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Delete entries older than N days.
	 *
	 * @param int $days Retention.
	 */
	public function purge( int $days ): void {
		global $wpdb;
		$table = Schema::table( 'logs' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
