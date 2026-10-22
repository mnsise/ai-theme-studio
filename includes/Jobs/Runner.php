<?php
/**
 * Background job runner.
 *
 * Work advances in small units. Two independent drivers call tick():
 * WP-Cron single events (works when the browser is closed) and the admin
 * UI's polling endpoint (works when WP-Cron is disabled). An atomic DB lock
 * guarantees a unit is never executed twice concurrently.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Jobs;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\Core\Logger;
use AIThemeStudio\Database\JobRepository;
use AIThemeStudio\Generation\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Runner.
 */
class Runner {

	public const CRON_HOOK    = 'ats_run_job';
	public const LOCK_SECONDS = 330;
	public const MAX_ATTEMPTS = 3;

	/**
	 * Jobs.
	 *
	 * @var JobRepository
	 */
	private JobRepository $jobs;

	/**
	 * Pipeline.
	 *
	 * @var Pipeline
	 */
	private Pipeline $pipeline;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $jobs     Jobs.
	 * @param Pipeline      $pipeline Pipeline.
	 * @param Logger        $logger   Logger.
	 */
	public function __construct( JobRepository $jobs, Pipeline $pipeline, Logger $logger ) {
		$this->jobs     = $jobs;
		$this->pipeline = $pipeline;
		$this->logger   = $logger;
	}

	/**
	 * Start a job. Refuses when the project already has an active job (duplicate-generation guard).
	 *
	 * @param int                  $project_id Project.
	 * @param string               $step       First step.
	 * @param array<string, mixed> $cursor     Initial cursor.
	 * @return int|\WP_Error Job id.
	 */
	public function start( int $project_id, string $step, array $cursor = array() ) {
		$active = $this->jobs->active_for_project( $project_id );
		if ( $active ) {
			return new \WP_Error(
				'ats_job_active',
				__( 'برای این پروژه یک عملیات در حال اجراست. تا پایان آن صبر کنید یا آن را لغو کنید.', 'ai-theme-studio' ),
				array(
					'status' => 409,
					'job_id' => $active['id'],
				)
			);
		}
		$id = $this->jobs->create( $project_id, $step );
		if ( $cursor ) {
			$this->jobs->update( $id, array( 'cursor' => $cursor ) );
		}
		$this->schedule( $id );
		return $id;
	}

	/**
	 * Execute one unit of work.
	 *
	 * @param int $job_id Job.
	 * @return array<string, mixed>|null Job after the tick.
	 */
	public function tick( int $job_id ): ?array {
		if ( ! $this->jobs->acquire_lock( $job_id, self::LOCK_SECONDS ) ) {
			return $this->jobs->find( $job_id );
		}
		$job = $this->jobs->find( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			$this->jobs->release_lock( $job_id );
			return $job;
		}

		$this->jobs->update( $job_id, array( 'status' => 'running' ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::LOCK_SECONDS - 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		try {
			$result = $this->pipeline->run( $job );
			$fresh  = $this->jobs->find( $job_id );
			if ( $fresh && 'cancelled' === $fresh['status'] ) {
				return $fresh;
			}
			$this->jobs->update(
				$job_id,
				array(
					'step'       => $result['step'],
					'cursor'     => $result['cursor'],
					'status'     => $result['status'],
					'attempts'   => 0,
					'last_error' => null,
				)
			);
			if ( 'queued' === $result['status'] ) {
				$this->schedule( $job_id );
			}
		} catch ( AIException $e ) {
			$attempts = (int) $job['attempts'] + 1;
			if ( $e->is_retryable() && $attempts < self::MAX_ATTEMPTS ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'     => 'queued',
						'attempts'   => $attempts,
						'last_error' => $e->getMessage(),
					)
				);
				$this->logger->warning( $e->getMessage() . ' — retry ' . $attempts, (int) $job['project_id'], $job_id, $e->details() );
				$this->schedule( $job_id, 30 * $attempts );
			} else {
				$this->fail( $job, $e->getMessage(), $e->details() );
			}
		} catch ( \Throwable $e ) {
			$this->fail( $job, $e->getMessage(), array( 'type' => get_class( $e ) ) );
		} finally {
			$this->jobs->release_lock( $job_id );
		}
		return $this->jobs->find( $job_id );
	}

	/**
	 * Mark failed.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param string               $message Message.
	 * @param array<string, mixed> $details Details.
	 */
	private function fail( array $job, string $message, array $details ): void {
		$this->jobs->update(
			(int) $job['id'],
			array(
				'status'     => 'failed',
				'last_error' => $message,
			)
		);
		$this->logger->error( $message, (int) $job['project_id'], (int) $job['id'], $details );
		$projects = \AIThemeStudio\Core\Plugin::instance()->get( 'projects' );
		$restore  = (string) ( $job['cursor']['restore_status'] ?? '' );
		$projects->set_status( (int) $job['project_id'], $restore ? $restore : 'failed' );
	}

	/**
	 * WP-Cron entry point: runs as the user who started the job so capability
	 * checks (page creation, Elementor document saving) behave identically.
	 *
	 * @param int $job_id Job.
	 */
	public function run_cron( int $job_id ): void {
		$job = $this->jobs->find( $job_id );
		if ( ! $job ) {
			return;
		}
		$previous = get_current_user_id();
		if ( $job['created_by'] && user_can( $job['created_by'], 'edit_pages' ) ) {
			wp_set_current_user( $job['created_by'] );
		}
		$this->tick( $job_id );
		wp_set_current_user( $previous );
	}

	/**
	 * Cancel.
	 *
	 * @param int $job_id Job.
	 */
	public function cancel( int $job_id ): bool {
		$job = $this->jobs->find( $job_id );
		if ( ! $job || in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return false;
		}
		$this->jobs->update( $job_id, array( 'status' => 'cancelled' ) );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $job_id ) );
		$restore = (string) ( $job['cursor']['restore_status'] ?? 'cancelled' );
		\AIThemeStudio\Core\Plugin::instance()->get( 'projects' )->set_status( (int) $job['project_id'], $restore );
		return true;
	}

	/**
	 * Retry/resume a failed, cancelled or waiting job from its current step,
	 * keeping all completed work.
	 *
	 * @param int                  $job_id Job.
	 * @param array<string, mixed> $cursor Cursor overrides (e.g. confirm/force).
	 */
	public function resume( int $job_id, array $cursor = array() ): bool {
		$job = $this->jobs->find( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( 'failed', 'cancelled', 'waiting' ), true ) ) {
			return false;
		}
		$active = $this->jobs->active_for_project( (int) $job['project_id'] );
		if ( $active && $active['id'] !== $job_id ) {
			return false;
		}
		$this->jobs->update(
			$job_id,
			array(
				'status'     => 'queued',
				'attempts'   => 0,
				'last_error' => null,
				'cursor'     => array_merge( $job['cursor'], $cursor ),
			)
		);
		if ( ! in_array( $job['step'], array( 'regenerate_section', 'regenerate_page' ), true ) ) {
			\AIThemeStudio\Core\Plugin::instance()->get( 'projects' )->set_status( (int) $job['project_id'], 'generating' );
		}
		$this->schedule( $job_id );
		return true;
	}

	/**
	 * Schedule a cron continuation.
	 *
	 * @param int $job_id Job.
	 * @param int $delay  Delay in seconds.
	 */
	private function schedule( int $job_id, int $delay = 0 ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $job_id ) );
		}
	}
}
