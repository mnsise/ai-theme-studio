<?php
/**
 * Generation job persistence with atomic locking.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Job repository.
 */
class JobRepository {

	public const ACTIVE_STATUSES = array( 'queued', 'running' );

	/**
	 * Create a job.
	 *
	 * @param int    $project_id Project.
	 * @param string $step       First step.
	 * @return int Job id.
	 */
	public function create( int $project_id, string $step ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'jobs' ),
			array(
				'project_id'  => $project_id,
				'status'      => 'queued',
				'step'        => $step,
				'cursor_data' => wp_json_encode( array() ),
				'attempts'    => 0,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a job.
	 *
	 * @param int $id Job id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Latest job of a project.
	 *
	 * @param int $project_id Project.
	 * @return array<string, mixed>|null
	 */
	public function latest_for_project( int $project_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d ORDER BY id DESC LIMIT 1", $project_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Active (queued/running) job of a project, used to prevent duplicate generations.
	 *
	 * @param int $project_id Project.
	 * @return array<string, mixed>|null
	 */
	public function active_for_project( int $project_id ): ?array {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %d AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1", $project_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Update fields.
	 *
	 * @param int                  $id     Job id.
	 * @param array<string, mixed> $fields Fields.
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['cursor'] ) ) {
			$fields['cursor_data'] = wp_json_encode( $fields['cursor'] );
			unset( $fields['cursor'] );
		}
		$fields['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Schema::table( 'jobs' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Atomically acquire the job lock. Returns false if another worker holds it.
	 *
	 * @param int $id      Job id.
	 * @param int $seconds Lock duration.
	 */
	public function acquire_lock( int $id, int $seconds ): bool {
		global $wpdb;
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table} SET locked_until = %s WHERE id = %d AND status IN ('queued','running') AND (locked_until IS NULL OR locked_until < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$until,
				$id,
				$now
			)
		);
		return 1 === (int) $rows;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $id Job id.
	 */
	public function release_lock( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'jobs' ), array( 'locked_until' => null ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete all jobs for a project.
	 *
	 * @param int $project_id Project.
	 */
	public function delete_project( int $project_id ): void {
		global $wpdb;
		$wpdb->delete( Schema::table( 'jobs' ), array( 'project_id' => $project_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Normalise a DB row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$cursor          = json_decode( (string) $row['cursor_data'], true );
		$row['cursor']   = is_array( $cursor ) ? $cursor : array();
		$row['id']       = (int) $row['id'];
		$row['attempts'] = (int) $row['attempts'];
		$row['project_id'] = (int) $row['project_id'];
		$row['created_by'] = (int) $row['created_by'];
		unset( $row['cursor_data'] );
		return $row;
	}
}
