<?php
/**
 * Pages, regeneration, navigation, audits, publishing, history, settings and system endpoints.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\REST;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\AI\AIRequest;
use AIThemeStudio\AI\JsonExtractor;
use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Security\Capabilities;
use AIThemeStudio\Security\RateLimiter;
use AIThemeStudio\Validation\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Site controller.
 */
class SiteController {

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
	 * Environment report.
	 */
	public function system(): \WP_REST_Response {
		$settings = $this->p->get( 'settings' );
		$provider = null;
		$error    = null;
		try {
			$pr       = $this->p->get( 'providers' )->make();
			$provider = array(
				'id'     => $pr->id(),
				'model'  => $pr->model(),
				'vision' => $pr->supports_vision(),
			);
		} catch ( AIException $e ) {
			$error = $e->getMessage();
		}
		$detector = $this->p->get( 'seo_detector' );
		return rest_ensure_response(
			array(
				'version'        => ATS_VERSION,
				'php'            => PHP_VERSION,
				'wp'             => get_bloginfo( 'version' ),
				'elementor'      => $this->p->get( 'compat' )->report(),
				'seo_plugin'     => array(
					'id'    => $detector->active(),
					'label' => $detector->label(),
				),
				'provider'       => $provider,
				'provider_error' => $error,
				'api_key_set'    => '' !== $settings->api_key(),
				'permalinks'     => (bool) get_option( 'permalink_structure' ),
				'blog_public'    => (bool) get_option( 'blog_public' ),
				'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'block_theme'    => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
				'site_language'  => get_locale(),
				'can'            => array(
					'settings'      => Capabilities::can( 'manage_settings' ),
					'upload'        => Capabilities::can( 'upload' ),
					'publish'       => Capabilities::can( 'publish' ),
					'menus'         => Capabilities::can( 'menus' ),
					'global_styles' => Capabilities::can( 'global_styles' ),
				),
				'vocabulary'     => array(
					'site_types'  => Vocabulary::SITE_TYPES,
					'page_types'  => Vocabulary::PAGE_TYPES,
					'image_roles' => Vocabulary::IMAGE_ROLES,
					'fonts'       => array(
						'fa' => Vocabulary::fonts_for( 'fa' ),
						'ar' => Vocabulary::fonts_for( 'ar' ),
						'en' => Vocabulary::fonts_for( 'en' ),
					),
				),
				'limits'         => array(
					'max_upload_mb' => (int) $settings->get( 'max_upload_mb' ),
					'max_images'    => (int) $settings->get( 'max_images' ),
					'prompt_max'    => ProjectController::PROMPT_MAX,
				),
			)
		);
	}

	/**
	 * Settings (masked).
	 */
	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->p->get( 'settings' )->public_view() );
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function update_settings( \WP_REST_Request $r ): \WP_REST_Response {
		$params = (array) $r->get_json_params();
		return rest_ensure_response( $this->p->get( 'settings' )->update( $params ) );
	}

	/**
	 * Send a minimal request to verify credentials/model. Sends no site data.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_provider() {
		if ( ! RateLimiter::hit( 'provider_test', 10 ) ) {
			return new \WP_Error( 'ats_rate_limited', __( 'تعداد آزمون‌ها بیش از حد مجاز است؛ کمی بعد تلاش کنید.', 'ai-theme-studio' ), array( 'status' => 429 ) );
		}
		try {
			$provider = $this->p->get( 'providers' )->make();
			$start    = microtime( true );
			$response = $provider->complete(
				new AIRequest(
					'You are a connectivity test. Respond with JSON only.',
					'Return exactly this JSON object: {"ok": true}',
					array(),
					true,
					512,
					0.0
				)
			);
			$data = JsonExtractor::extract( $response->text );
			return rest_ensure_response(
				array(
					'ok'         => ! empty( $data['ok'] ),
					'provider'   => $provider->id(),
					'model'      => $provider->model(),
					'vision'     => $provider->supports_vision(),
					'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
					'message'    => ! empty( $data['ok'] )
						? __( 'اتصال برقرار است و مدل خروجی JSON معتبر برگرداند.', 'ai-theme-studio' )
						: __( 'اتصال برقرار است اما پاسخ مدل مطابق انتظار نبود.', 'ai-theme-studio' ),
				)
			);
		} catch ( AIException $e ) {
			return new \WP_Error( 'ats_provider_error', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * Page list.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function pages( \WP_REST_Request $r ): \WP_REST_Response {
		$project = $this->p->get( 'projects' )->get( (int) $r['id'] );
		return rest_ensure_response( $this->p->get( 'publisher' )->page_list( $project ) );
	}

	/**
	 * Regenerate a full page.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function regenerate_page( \WP_REST_Request $r ) {
		return $this->regenerate( $r, null );
	}

	/**
	 * Regenerate a section.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function regenerate_section( \WP_REST_Request $r ) {
		return $this->regenerate( $r, (string) $r['section'] );
	}

	/**
	 * Shared regeneration flow: preflight -> explicit confirmation on edited content -> job.
	 *
	 * @param \WP_REST_Request $r          Request.
	 * @param string|null      $section_id Section.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function regenerate( \WP_REST_Request $r, ?string $section_id ) {
		$id      = (int) $r['id'];
		$project = $this->p->get( 'projects' )->get( $id );
		if ( ! in_array( $project['status'], array( 'ready', 'published' ), true ) ) {
			return new \WP_Error( 'ats_not_ready', __( 'بازتولید پس از پایان تولید اولیه امکان‌پذیر است.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		$key   = sanitize_key( (string) $r['key'] );
		$check = $this->p->get( 'regenerator' )->preflight( $project, $key, $section_id );
		if ( ! $check['ok'] ) {
			return new \WP_Error( 'ats_regenerate', $check['message'], array( 'status' => 400 ) );
		}
		$confirm = (bool) $r->get_param( 'confirm' );
		if ( $check['conflict'] && ! $confirm ) {
			return new \WP_Error( 'ats_user_edits', $check['message'], array( 'status' => 409, 'needs_confirmation' => true ) );
		}
		if ( ! RateLimiter::hit( 'generate', (int) $this->p->get( 'settings' )->get( 'rate_limit_per_hour' ) ) ) {
			return new \WP_Error( 'ats_rate_limited', __( 'تعداد درخواست‌ها در این ساعت بیش از حد مجاز است.', 'ai-theme-studio' ), array( 'status' => 429 ) );
		}
		$instruction = mb_substr( sanitize_textarea_field( (string) $r->get_param( 'instruction' ) ), 0, 2000 );
		$cursor      = array(
			'page_key'       => $key,
			'instruction'    => $instruction,
			'confirm'        => $confirm,
			'restore_status' => $project['status'],
		);
		if ( null !== $section_id ) {
			$cursor['section_id'] = $section_id;
		}
		$job_id = $this->p->get( 'runner' )->start( $id, null === $section_id ? 'regenerate_page' : 'regenerate_section', $cursor );
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		return new \WP_REST_Response( array( 'job_id' => $job_id ), 202 );
	}

	/**
	 * Restore a page from an Elementor backup version.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_page( \WP_REST_Request $r ) {
		$id      = (int) $r['id'];
		$project = $this->p->get( 'projects' )->get( $id );
		$post_id = (int) ( $project['pages'][ sanitize_key( (string) $r['key'] ) ] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'ats_not_found', __( 'صفحه پیدا نشد.', 'ai-theme-studio' ), array( 'status' => 404 ) );
		}
		if ( ! $this->p->get( 'writer' )->restore( $post_id, $id, absint( $r->get_param( 'version' ) ) ) ) {
			return new \WP_Error( 'ats_restore_failed', __( 'نسخه پشتیبان پیدا نشد یا معتبر نبود.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$this->p->get( 'logger' )->info( 'Page restored from backup', $id );
		return rest_ensure_response( array( 'restored' => true ) );
	}

	/**
	 * Design system.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function design( \WP_REST_Request $r ): \WP_REST_Response {
		$design = $this->p->get( 'artifacts' )->latest( (int) $r['id'], 'design' );
		return rest_ensure_response(
			array(
				'design' => $design,
				'tokens' => is_array( $design ) ? ( new DesignTokens( $design ) )->to_array() : null,
			)
		);
	}

	/**
	 * Navigation preview.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function navigation( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( $this->p->get( 'menus' )->preview( (int) $r['id'] ) );
	}

	/**
	 * Apply navigation (explicit user action; never deletes menus).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply_navigation( \WP_REST_Request $r ) {
		$params = (array) $r->get_json_params();
		$args   = array(
			'mode'              => 'reuse' === ( $params['mode'] ?? '' ) ? 'reuse' : 'create',
			'menu_id'           => absint( $params['menu_id'] ?? 0 ),
			'menu_name'         => sanitize_text_field( (string) ( $params['menu_name'] ?? '' ) ),
			'locations'         => array_map( 'sanitize_key', (array) ( $params['locations'] ?? array() ) ),
			'replace_locations' => rest_sanitize_boolean( $params['replace_locations'] ?? false ),
			'items'             => is_array( $params['items'] ?? null ) ? $params['items'] : array(),
		);
		try {
			$result = $this->p->get( 'menus' )->apply( (int) $r['id'], $args );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ats_menu_failed', $e->getMessage(), array( 'status' => 400 ) );
		}
		$this->p->get( 'logger' )->info( 'Navigation applied', (int) $r['id'], 0, $result );
		return rest_ensure_response( $result );
	}

	/**
	 * Latest audit results.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function get_audit( \WP_REST_Request $r ): \WP_REST_Response {
		$a = $this->p->get( 'artifacts' );
		$i = (int) $r['id'];
		return rest_ensure_response(
			array(
				'seo'         => $a->latest( $i, 'audit_seo' ),
				'performance' => $a->latest( $i, 'audit_performance' ),
				'elementor'   => $a->latest( $i, 'audit_elementor' ),
				'lighthouse'  => $a->latest( $i, 'lighthouse' ),
				'config'      => $this->p->get( 'perf_auditor' )->config(),
			)
		);
	}

	/**
	 * Re-run the local audits.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function run_audit( \WP_REST_Request $r ): \WP_REST_Response {
		$this->p->get( 'quality' )->run( (int) $r['id'] );
		return $this->get_audit( $r );
	}

	/**
	 * Run a real PageSpeed Insights (Lighthouse) test for a published page.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pagespeed( \WP_REST_Request $r ) {
		$id       = (int) $r['id'];
		$project  = $this->p->get( 'projects' )->get( $id );
		$key      = sanitize_key( (string) $r->get_param( 'page' ) );
		$strategy = 'desktop' === $r->get_param( 'strategy' ) ? 'desktop' : 'mobile';
		$post_id  = (int) ( $project['pages'][ $key ] ?? 0 );
		if ( ! $post_id ) {
			return new \WP_Error( 'ats_not_found', __( 'صفحه پیدا نشد.', 'ai-theme-studio' ), array( 'status' => 404 ) );
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'ats_not_public', __( 'PageSpeed فقط صفحات منتشرشده و عمومی را می‌تواند آزمون کند. ابتدا صفحه را منتشر کنید یا از Lighthouse CI روی محیط محلی استفاده کنید.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		if ( ! RateLimiter::hit( 'pagespeed', 20 ) ) {
			return new \WP_Error( 'ats_rate_limited', __( 'تعداد آزمون‌ها بیش از حد مجاز است.', 'ai-theme-studio' ), array( 'status' => 429 ) );
		}
		$result = $this->p->get( 'pagespeed' )->run( (string) get_permalink( $post_id ), $strategy );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		$all                            = (array) $this->p->get( 'artifacts' )->latest( $id, 'lighthouse' );
		$all[ $key . '/' . $strategy ] = $result;
		$this->p->get( 'artifacts' )->save( $id, 'lighthouse', '', $all );
		return rest_ensure_response( $result );
	}

	/**
	 * Publish (explicit authorization required).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function publish( \WP_REST_Request $r ) {
		$params = (array) $r->get_json_params();
		if ( true !== ( $params['confirm'] ?? null ) ) {
			return new \WP_Error( 'ats_confirm', __( 'انتشار نیاز به تأیید صریح دارد.', 'ai-theme-studio' ), array( 'status' => 400 ) );
		}
		$opts = array(
			'set_front_page'    => rest_sanitize_boolean( $params['set_front_page'] ?? false ),
			'use_404'           => rest_sanitize_boolean( $params['use_404'] ?? false ),
			'apply_site_styles' => rest_sanitize_boolean( $params['apply_site_styles'] ?? false ),
			'theme_builder'     => rest_sanitize_boolean( $params['theme_builder'] ?? false ),
		);
		if ( isset( $params['pages'] ) && is_array( $params['pages'] ) ) {
			$opts['pages'] = array_map( 'sanitize_key', $params['pages'] );
		}
		$result = $this->p->get( 'publisher' )->publish( (int) $r['id'], $opts );
		if ( ! is_wp_error( $result ) ) {
			$this->p->get( 'logger' )->info( 'Published', (int) $r['id'], 0, $result );
		}
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Unpublish / undo site-level changes.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function unpublish( \WP_REST_Request $r ): \WP_REST_Response {
		$result = $this->p->get( 'publisher' )->unpublish( (int) $r['id'] );
		$this->p->get( 'logger' )->info( 'Unpublished', (int) $r['id'] );
		return rest_ensure_response( $result );
	}

	/**
	 * Version history, backups, prompt history and log.
	 *
	 * @param \WP_REST_Request $r Request.
	 */
	public function history( \WP_REST_Request $r ): \WP_REST_Response {
		$id       = (int) $r['id'];
		$project  = $this->p->get( 'projects' )->get( $id );
		$by_post  = array_flip( array_map( 'strval', $project['pages'] ) );
		$a        = $this->p->get( 'artifacts' );
		$backups  = array();
		foreach ( $a->history( $id, 'elementor_backup' ) as $row ) {
			$backups[] = array(
				'version'    => (int) $row['version'],
				'post_id'    => (int) $row['artifact_key'],
				'page_key'   => $by_post[ $row['artifact_key'] ] ?? null,
				'created_at' => $row['created_at'],
			);
		}
		$prompts = array();
		foreach ( $a->history( $id, 'prompt' ) as $row ) {
			$data      = $a->version( $id, 'prompt', (string) $row['artifact_key'], (int) $row['version'] );
			$prompts[] = array(
				'target'      => $row['artifact_key'],
				'instruction' => (string) ( $data['instruction'] ?? '' ),
				'created_at'  => $row['created_at'],
			);
		}
		return rest_ensure_response(
			array(
				'artifacts' => array_map(
					static fn( $row ) => array(
						'type'       => $row['type'],
						'key'        => $row['artifact_key'],
						'version'    => (int) $row['version'],
						'size'       => (int) $row['size'],
						'created_at' => $row['created_at'],
					),
					array_values( array_filter( $a->history( $id ), static fn( $row ) => ! in_array( $row['type'], array( 'elementor_backup', 'prompt' ), true ) ) )
				),
				'backups'   => $backups,
				'prompts'   => $prompts,
				'log'       => $this->p->get( 'logger' )->for_project( $id, 200 ),
			)
		);
	}
}
