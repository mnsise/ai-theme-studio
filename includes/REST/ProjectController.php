<?php
/**
 * Projects, images, generation jobs and sitemap review endpoints.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\REST;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Generation\Pipeline;
use AIThemeStudio\Media\ImageUploader;
use AIThemeStudio\Security\RateLimiter;
use AIThemeStudio\Validation\SchemaValidator;
use AIThemeStudio\Validation\Schemas;
use AIThemeStudio\Validation\Vocabulary;
use AIThemeStudio\WordPress\MenuManager;

defined( 'ABSPATH' ) || exit;

/**
 * Project controller.
 */
class ProjectController {

	public const PROMPT_MAX = 8000;

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $p;

	/**
	 * Constructor.
	 *
	 * @param Plugin $p Plugin.
	 */
	public function __construct( Plugin $p ) {
		$this->p = $p;
	}

	/**
	 * List projects.
	 */
	public function index(): \WP_REST_Response {
		return rest_ensure_response( $this->p->get( 'projects' )->list_for_current_user() );
	}

	/**
	 * Create a project.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $r ) {
		$input = self::sanitize_input( (array) $r->get_param( 'input' ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = wp_trim_words( $input['prompt'], 6, '…' );
		}
		$id = $this->p->get( 'projects' )->create( mb_substr( $name, 0, 120 ), $input );
		if ( ! $id ) {
			return new \WP_Error( 'ats_create_failed', __( 'ایجاد پروژه ناموفق بود.', 'ai-theme-studio' ), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( $this->project_payload( $id ), 201 );
	}

	/**
	 * Show a project.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function show( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( $this->project_payload( (int) $r['id'] ) );
	}

	/**
	 * Update project name/input (only while no generation has started).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $r ) {
		$id       = (int) $r['id'];
		$projects = $this->p->get( 'projects' );
		$project  = $projects->get( $id );
		if ( null !== $r->get_param( 'name' ) ) {
			$name = sanitize_text_field( (string) $r->get_param( 'name' ) );
			if ( '' !== $name ) {
				wp_update_post(
					array(
						'ID'         => $id,
						'post_title' => mb_substr( $name, 0, 120 ),
					)
				);
			}
		}
		if ( null !== $r->get_param( 'input' ) ) {
			if ( $this->p->get( 'jobs' )->active_for_project( $id ) ) {
				return new \WP_Error( 'ats_job_active', __( 'تا پایان عملیات جاری نمی‌توان ورودی را تغییر داد.', 'ai-theme-studio' ), array( 'status' => 409 ) );
			}
			$input = self::sanitize_input( (array) $r->get_param( 'input' ) );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
			$projects->set_input( $id, $input );
			if ( $project && in_array( $project['status'], array( 'failed', 'cancelled' ), true ) ) {
				$projects->set_status( $id, 'draft' );
			}
		}
		return rest_ensure_response( $this->project_payload( $id ) );
	}

	/**
	 * Delete a project record. Generated pages are user content and are never deleted here.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! $r->get_param( 'confirm' ) ) {
			return new \WP_Error( 'ats_confirm', __( 'حذف پروژه نیاز به تأیید دارد.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$active = $this->p->get( 'jobs' )->active_for_project( $id );
		if ( $active ) {
			$this->p->get( 'runner' )->cancel( (int) $active['id'] );
		}
		$this->p->get( 'artifacts' )->delete_project( $id );
		$this->p->get( 'jobs' )->delete_project( $id );
		$this->p->get( 'projects' )->delete( $id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Upload a reference image.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_image( \WP_REST_Request $r ) {
		$id       = (int) $r['id'];
		$projects = $this->p->get( 'projects' );
		$settings = $this->p->get( 'settings' );
		if ( count( $projects->images( $id ) ) >= (int) $settings->get( 'max_images' ) ) {
			/* translators: %d: max images */
			return new \WP_Error( 'ats_too_many_images', sprintf( __( 'حداکثر %d تصویر برای هر پروژه مجاز است.', 'ai-theme-studio' ), (int) $settings->get( 'max_images' ) ), array( 'status' => 400 ) );
		}
		if ( ! RateLimiter::hit( 'upload', 60 ) ) {
			return new \WP_Error( 'ats_rate_limited', __( 'تعداد آپلودها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', 'ai-theme-studio' ), array( 'status' => 429 ) );
		}
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'ats_no_file', __( 'فایلی ارسال نشده است.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$attachment = $this->p->get( 'uploader' )->store( $files['file'], $id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		$ref = $projects->add_image( $id, $attachment, sanitize_key( (string) $r->get_param( 'role' ) ) );
		return new \WP_REST_Response(
			array_merge(
				ImageUploader::describe( $attachment ),
				array(
					'ref'  => $ref,
					'role' => $this->image_role( $id, $ref ),
				)
			),
			201
		);
	}

	/**
	 * Change an image role.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_image( \WP_REST_Request $r ) {
		$ok = $this->p->get( 'projects' )->set_image_role( (int) $r['id'], (string) $r['ref'], sanitize_key( (string) $r->get_param( 'role' ) ) );
		if ( ! $ok ) {
			return new \WP_Error( 'ats_not_found', __( 'تصویر پیدا نشد.', 'ai-theme-studio' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'role' => $this->image_role( (int) $r['id'], (string) $r['ref'] ) ) );
	}

	/**
	 * Remove an image from the project (the media item is kept).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_image( \WP_REST_Request $r ) {
		if ( ! $this->p->get( 'projects' )->remove_image( (int) $r['id'], (string) $r['ref'] ) ) {
			return new \WP_Error( 'ats_not_found', __( 'تصویر پیدا نشد.', 'ai-theme-studio' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'removed' => true ) );
	}

	/**
	 * Start (or restart) full generation.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( \WP_REST_Request $r ) {
		$id      = (int) $r['id'];
		$project = $this->p->get( 'projects' )->get( $id );
		if ( ! $project || '' === trim( (string) ( $project['input']['prompt'] ?? '' ) ) ) {
			return new \WP_Error( 'ats_no_prompt', __( 'توضیحات سایت خالی است.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$error = $this->preflight_ai();
		if ( $error ) {
			return $error;
		}
		$compat = $this->p->get( 'compat' );
		if ( ! $compat->meets_minimum() ) {
			$report = $compat->report();
			return new \WP_Error( 'ats_elementor_missing', implode( ' ', $report['issues'] ), array( 'status' => 409 ) );
		}
		if ( ! RateLimiter::hit( 'generate', max( 1, (int) ( $this->p->get( 'settings' )->get( 'rate_limit_per_hour' ) / 5 ) ) ) ) {
			return new \WP_Error( 'ats_rate_limited', __( 'تعداد درخواست‌های تولید در این ساعت بیش از حد مجاز است.', 'ai-theme-studio' ), array( 'status' => 429 ) );
		}

		$cursor = array( 'force' => (bool) $r->get_param( 'force' ) );
		$job_id = $this->p->get( 'runner' )->start( $id, 'analyze_requirements', $cursor );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		$this->p->get( 'projects' )->set_status( $id, 'generating' );
		$this->p->get( 'logger' )->info( 'Generation started', $id, (int) $job_id );
		return new \WP_REST_Response( $this->job_payload( (int) $job_id ), 202 );
	}

	/**
	 * Latest job + recent log for a project.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function job( \WP_REST_Request $r ): \WP_REST_Response {
		$job = $this->p->get( 'jobs' )->latest_for_project( (int) $r['id'] );
		return rest_ensure_response(
			array(
				'job'     => $job ? $this->job_payload( (int) $job['id'] ) : null,
				'status'  => (string) $this->p->get( 'projects' )->get( (int) $r['id'] )['status'],
				'log'     => $this->p->get( 'logger' )->for_project( (int) $r['id'], 30 ),
				'notices' => array_values( array_filter( (array) $this->p->get( 'projects' )->meta( (int) $r['id'], 'notices' ) ) ),
			)
		);
	}

	/**
	 * Advance a job by one unit (driven by the polling UI; complements WP-Cron).
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function tick( \WP_REST_Request $r ): \WP_REST_Response {
		$job_id = (int) $r['job_id'];
		$job    = $this->p->get( 'jobs' )->find( $job_id );
		// A "running" job whose lock expired (e.g. PHP timeout) is recovered here; a live lock makes this a no-op.
		if ( $job && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			$this->p->get( 'runner' )->tick( $job_id );
		}
		return rest_ensure_response( $this->job_payload( $job_id ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function cancel( \WP_REST_Request $r ): \WP_REST_Response {
		$this->p->get( 'runner' )->cancel( (int) $r['job_id'] );
		return rest_ensure_response( $this->job_payload( (int) $r['job_id'] ) );
	}

	/**
	 * Retry/resume a job from its current step.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume( \WP_REST_Request $r ) {
		$error = $this->preflight_ai();
		if ( $error ) {
			return $error;
		}
		$extra = array();
		if ( null !== $r->get_param( 'confirm' ) ) {
			$extra['confirm'] = (bool) $r->get_param( 'confirm' );
		}
		if ( ! $this->p->get( 'runner' )->resume( (int) $r['job_id'], $extra ) ) {
			return new \WP_Error( 'ats_cannot_resume', __( 'این عملیات قابل ازسرگیری نیست.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		return rest_ensure_response( $this->job_payload( (int) $r['job_id'] ) );
	}

	/**
	 * Sitemap.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function get_sitemap( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		return rest_ensure_response(
			array(
				'sitemap'    => $this->p->get( 'artifacts' )->latest( $id, 'sitemap' ),
				'editable'   => 'review_sitemap' === $this->p->get( 'projects' )->get( $id )['status'],
				'page_types' => Vocabulary::PAGE_TYPES,
				'sections'   => Vocabulary::SECTION_TYPES,
			)
		);
	}

	/**
	 * Save an edited sitemap (only while the job waits for review).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_sitemap( \WP_REST_Request $r ) {
		$id      = (int) $r['id'];
		$project = $this->p->get( 'projects' )->get( $id );
		if ( 'review_sitemap' !== $project['status'] ) {
			return new \WP_Error( 'ats_sitemap_locked', __( 'نقشه سایت فقط در مرحله بررسی قابل ویرایش است. برای تغییر صفحات بعد از تولید، از بازتولید صفحه استفاده کنید.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		$raw = $r->get_param( 'sitemap' );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'ats_invalid', __( 'داده نقشه سایت نامعتبر است.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$schema = ( new SchemaValidator( Schemas::definitions() ) )->validate( $raw, Schemas::get( 'sitemap' ) );
		if ( $schema['errors'] ) {
			return new \WP_Error( 'ats_invalid', __( 'نقشه سایت معتبر نیست.', 'ai-theme-studio' ), array( 'status' => 400, 'errors' => array_slice( $schema['errors'], 0, 20 ) ) );
		}
		$result = Pipeline::validate_sitemap( $schema['data'], array() );
		if ( $result['errors'] ) {
			return new \WP_Error( 'ats_invalid', implode( ' ', $result['errors'] ), array( 'status' => 400, 'errors' => $result['errors'] ) );
		}
		$this->p->get( 'artifacts' )->save( $id, 'sitemap', '', $result['data'] );
		$this->p->get( 'projects' )->set( $id, 'nav_proposal', MenuManager::proposal_from_sitemap( $result['data'] ) );
		$this->p->get( 'logger' )->info( 'Sitemap edited by user', $id );
		return rest_ensure_response(
			array(
				'sitemap'  => $result['data'],
				'warnings' => $result['warnings'],
			)
		);
	}

	/**
	 * Approve the sitemap and continue generation.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve_sitemap( \WP_REST_Request $r ) {
		$job = $this->p->get( 'jobs' )->latest_for_project( (int) $r['id'] );
		if ( ! $job || 'waiting' !== $job['status'] ) {
			return new \WP_Error( 'ats_not_waiting', __( 'عملیاتی در انتظار تأیید نقشه سایت نیست.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		$this->p->get( 'runner' )->resume( (int) $job['id'] );
		return rest_ensure_response( $this->job_payload( (int) $job['id'] ) );
	}

	/**
	 * Validate the AI provider configuration before queuing work.
	 */
	private function preflight_ai(): ?\WP_Error {
		$settings = $this->p->get( 'settings' );
		if ( '' === $settings->api_key() && 'openai_compatible' !== $settings->get( 'provider' ) && ! has_filter( 'ats_ai_provider' ) ) {
			return new \WP_Error(
				'ats_no_api_key',
				__( 'کلید API سرویس هوش مصنوعی تنظیم نشده است. از بخش «تنظیمات سرویس هوش مصنوعی» آن را وارد کنید.', 'ai-theme-studio' ),
				array( 'status' => 409 )
			);
		}
		return null;
	}

	/**
	 * Current role of an image.
	 *
	 * @param int    $project_id Project.
	 * @param string $ref        Ref.
	 */
	private function image_role( int $project_id, string $ref ): string {
		foreach ( $this->p->get( 'projects' )->images( $project_id ) as $img ) {
			if ( $img['ref'] === $ref ) {
				return $img['role'];
			}
		}
		return 'auto';
	}

	/**
	 * Project payload for the UI.
	 *
	 * @param int $id Project.
	 * @return array<string, mixed>
	 */
	private function project_payload( int $id ): array {
		$project = $this->p->get( 'projects' )->get( $id );
		if ( ! $project ) {
			return array();
		}
		$analysis = (array) $this->p->get( 'artifacts' )->latest( $id, 'image_analysis' );
		$by_ref   = array_column( (array) ( $analysis['images'] ?? array() ), null, 'ref' );
		$images   = array();
		foreach ( $project['images'] as $img ) {
			$images[] = array_merge(
				ImageUploader::describe( (int) $img['id'] ),
				array(
					'ref'      => $img['ref'],
					'role'     => $img['role'],
					'analysis' => $by_ref[ $img['ref'] ] ?? null,
				)
			);
		}
		$project['images']          = $images;
		$project['image_status']    = $analysis['status'] ?? null;
		$project['notices']         = array_values( array_filter( (array) $project['notices'] ) );
		$project['has_sitemap']     = null !== $this->p->get( 'artifacts' )->latest( $id, 'sitemap' );
		$project['has_design']      = null !== $this->p->get( 'artifacts' )->latest( $id, 'design' );
		$job                        = $this->p->get( 'jobs' )->latest_for_project( $id );
		$project['job']             = $job ? $this->job_payload( (int) $job['id'] ) : null;
		$project['header_edit_url'] = $project['header_id'] ? admin_url( 'post.php?post=' . $project['header_id'] . '&action=elementor' ) : null;
		$project['footer_edit_url'] = $project['footer_id'] ? admin_url( 'post.php?post=' . $project['footer_id'] . '&action=elementor' ) : null;
		return $project;
	}

	/**
	 * Job payload with progress information.
	 *
	 * @param int $job_id Job.
	 * @return array<string, mixed>|null
	 */
	private function job_payload( int $job_id ): ?array {
		$job = $this->p->get( 'jobs' )->find( $job_id );
		if ( ! $job ) {
			return null;
		}
		$labels = Pipeline::labels();
		$steps  = Pipeline::STEPS;
		$index  = array_search( $job['step'], $steps, true );
		$total  = count( $steps );
		$done   = false === $index ? ( 'done' === $job['step'] ? $total : 0 ) : (int) $index;

		$sitemap_pages = 0;
		$pages_done    = count( (array) ( $job['cursor']['pages_done'] ?? array() ) );
		if ( 'generate_pages' === $job['step'] ) {
			$sitemap       = (array) $this->p->get( 'artifacts' )->latest( (int) $job['project_id'], 'sitemap' );
			$sitemap_pages = count( (array) ( $sitemap['pages'] ?? array() ) );
		}
		$progress = $total ? ( $done / $total ) : 0;
		if ( $sitemap_pages ) {
			$progress += min( 1, $pages_done / $sitemap_pages ) / $total;
		}
		if ( 'completed' === $job['status'] ) {
			$progress = 1;
		}

		return array(
			'id'          => $job['id'],
			'project_id'  => $job['project_id'],
			'status'      => $job['status'],
			'step'        => $job['step'],
			'step_label'  => $labels[ $job['step'] ] ?? $job['step'],
			'steps'       => array_map(
				static fn( $s ) => array(
					'id'    => $s,
					'label' => $labels[ $s ],
				),
				$steps
			),
			'progress'    => round( $progress * 100 ),
			'pages_done'  => $pages_done,
			'pages_total' => $sitemap_pages,
			'attempts'    => $job['attempts'],
			'error'       => $job['last_error'],
			'updated_at'  => $job['updated_at'],
		);
	}

	/**
	 * Sanitize and validate project input.
	 *
	 * @param array<string, mixed> $raw Raw input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sanitize_input( array $raw ) {
		$prompt = trim( sanitize_textarea_field( (string) ( $raw['prompt'] ?? '' ) ) );
		if ( mb_strlen( $prompt ) < 20 ) {
			return new \WP_Error( 'ats_prompt_short', __( 'لطفاً سایت موردنظر را دست‌کم در یک یا دو جمله توضیح دهید.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $prompt ) > self::PROMPT_MAX ) {
			/* translators: %d: max characters */
			return new \WP_Error( 'ats_prompt_long', sprintf( __( 'توضیحات حداکثر می‌تواند %d نویسه باشد.', 'ai-theme-studio' ), self::PROMPT_MAX ), array( 'status' => 400 ) );
		}

		$language = (string) ( $raw['language'] ?? 'fa' );
		$language = preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $language ) ? $language : 'fa';
		$dir      = (string) ( $raw['direction'] ?? 'auto' );
		if ( ! in_array( $dir, array( 'rtl', 'ltr' ), true ) ) {
			$dir = Vocabulary::is_rtl_language( $language ) ? 'rtl' : 'ltr';
		}

		$category = (string) ( $raw['category'] ?? 'auto' );
		$category = in_array( $category, Vocabulary::SITE_TYPES, true ) ? $category : 'auto';

		$colors = array( 'mode' => 'custom' === ( $raw['colors']['mode'] ?? '' ) ? 'custom' : 'ai' );
		foreach ( array( 'primary', 'secondary', 'background' ) as $c ) {
			$hex = sanitize_hex_color( (string) ( $raw['colors'][ $c ] ?? '' ) );
			if ( $hex && 7 === strlen( $hex ) ) {
				$colors[ $c ] = strtoupper( $hex );
			}
		}

		$fonts   = Vocabulary::fonts_for( $language );
		$heading = (string) ( $raw['fonts']['heading'] ?? 'auto' );
		$body    = (string) ( $raw['fonts']['body'] ?? 'auto' );

		$style = array_slice(
			array_values( array_filter( array_map( static fn( $s ) => mb_substr( sanitize_text_field( (string) $s ), 0, 40 ), (array) ( $raw['style'] ?? array() ) ) ) ),
			0,
			8
		);

		$pages = array_values( array_intersect( array_map( 'strval', (array) ( $raw['pages'] ?? array() ) ), Vocabulary::PAGE_TYPES ) );

		$business = array(
			'name'    => mb_substr( sanitize_text_field( (string) ( $raw['business']['name'] ?? '' ) ), 0, 120 ),
			'email'   => sanitize_email( (string) ( $raw['business']['email'] ?? '' ) ),
			'phone'   => mb_substr( preg_replace( '/[^0-9+\-\s()۰-۹]/u', '', (string) ( $raw['business']['phone'] ?? '' ) ), 0, 40 ),
			'address' => mb_substr( sanitize_textarea_field( (string) ( $raw['business']['address'] ?? '' ) ), 0, 300 ),
			'hours'   => mb_substr( sanitize_text_field( (string) ( $raw['business']['hours'] ?? '' ) ), 0, 120 ),
		);

		$opt = (array) ( $raw['options'] ?? array() );
		$tc  = (int) ( $raw['responsive']['tablet_columns'] ?? 2 );

		return array(
			'prompt'     => $prompt,
			'category'   => $category,
			'language'   => $language,
			'direction'  => $dir,
			'colors'     => $colors,
			'fonts'      => array(
				'heading' => in_array( $heading, $fonts, true ) ? $heading : 'auto',
				'body'    => in_array( $body, $fonts, true ) ? $body : 'auto',
			),
			'style'      => $style,
			'density'    => in_array( $raw['density'] ?? '', array( 'compact', 'comfortable', 'spacious' ), true ) ? $raw['density'] : 'comfortable',
			'corners'    => in_array( $raw['corners'] ?? '', array( 'sharp', 'rounded', 'pill' ), true ) ? $raw['corners'] : 'rounded',
			'responsive' => array(
				'tablet_columns' => max( 1, min( 3, $tc ) ),
				'mobile_columns' => 1,
				'mobile_menu'    => 'collapse',
			),
			'pages'      => $pages,
			'business'   => $business,
			'options'    => array(
				'review_sitemap' => rest_sanitize_boolean( $opt['review_sitemap'] ?? true ),
				'apply_globals'  => rest_sanitize_boolean( $opt['apply_globals'] ?? true ),
				'set_front_page' => rest_sanitize_boolean( $opt['set_front_page'] ?? true ),
			),
		);
	}
}
