<?php
/**
 * REST route registration. Every route has a permission callback; cookie
 * authentication requires the wp_rest nonce (X-WP-Nonce), which protects all
 * state-changing requests against CSRF.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\REST;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
class Routes {

	public const NS = 'ai-theme-studio/v1';

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
	 * Register routes.
	 */
	public function register(): void {
		$projects = new ProjectController( $this->p );
		$site     = new SiteController( $this->p );

		$studio  = static fn() => Capabilities::can( 'use_studio' );
		$admin   = static fn() => Capabilities::can( 'manage_settings' );
		$project = static fn( \WP_REST_Request $r ) => Capabilities::can_access_project( (int) $r['id'] );
		$upload  = static fn( \WP_REST_Request $r ) => Capabilities::can_access_project( (int) $r['id'] ) && Capabilities::can( 'upload' );
		$publish = static fn( \WP_REST_Request $r ) => Capabilities::can_access_project( (int) $r['id'] ) && Capabilities::can( 'publish' );
		$menus   = static fn( \WP_REST_Request $r ) => Capabilities::can_access_project( (int) $r['id'] ) && Capabilities::can( 'menus' );
		$job     = function ( \WP_REST_Request $r ) {
			$job = $this->p->get( 'jobs' )->find( (int) $r['job_id'] );
			return $job && Capabilities::can_access_project( (int) $job['project_id'] );
		};

		$id  = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		$key = '(?P<key>[a-z][a-z0-9_]{1,30})';

		$routes = array(
			array( '/system', 'GET', array( $site, 'system' ), $studio ),
			array( '/settings', 'GET', array( $site, 'get_settings' ), $admin ),
			array( '/settings', 'POST', array( $site, 'update_settings' ), $admin ),
			array( '/settings/test', 'POST', array( $site, 'test_provider' ), $admin ),

			array( '/projects', 'GET', array( $projects, 'index' ), $studio ),
			array( '/projects', 'POST', array( $projects, 'create' ), $studio ),
			array( '/projects/(?P<id>\d+)', 'GET', array( $projects, 'show' ), $project ),
			array( '/projects/(?P<id>\d+)', 'PATCH', array( $projects, 'update' ), $project ),
			array( '/projects/(?P<id>\d+)', 'DELETE', array( $projects, 'delete' ), $project ),
			array( '/projects/(?P<id>\d+)/images', 'POST', array( $projects, 'upload_image' ), $upload ),
			array( '/projects/(?P<id>\d+)/images/(?P<ref>img_\d+)', 'PATCH', array( $projects, 'update_image' ), $project ),
			array( '/projects/(?P<id>\d+)/images/(?P<ref>img_\d+)', 'DELETE', array( $projects, 'remove_image' ), $project ),
			array( '/projects/(?P<id>\d+)/generate', 'POST', array( $projects, 'generate' ), $project ),
			array( '/projects/(?P<id>\d+)/job', 'GET', array( $projects, 'job' ), $project ),
			array( '/projects/(?P<id>\d+)/sitemap', 'GET', array( $projects, 'get_sitemap' ), $project ),
			array( '/projects/(?P<id>\d+)/sitemap', 'PUT', array( $projects, 'save_sitemap' ), $project ),
			array( '/projects/(?P<id>\d+)/sitemap/approve', 'POST', array( $projects, 'approve_sitemap' ), $project ),
			array( '/jobs/(?P<job_id>\d+)/tick', 'POST', array( $projects, 'tick' ), $job ),
			array( '/jobs/(?P<job_id>\d+)/cancel', 'POST', array( $projects, 'cancel' ), $job ),
			array( '/jobs/(?P<job_id>\d+)/resume', 'POST', array( $projects, 'resume' ), $job ),

			array( '/projects/(?P<id>\d+)/pages', 'GET', array( $site, 'pages' ), $project ),
			array( '/projects/(?P<id>\d+)/pages/' . $key . '/regenerate', 'POST', array( $site, 'regenerate_page' ), $project ),
			array( '/projects/(?P<id>\d+)/pages/' . $key . '/sections/(?P<section>[a-z][a-z0-9-]{1,50})/regenerate', 'POST', array( $site, 'regenerate_section' ), $project ),
			array( '/projects/(?P<id>\d+)/pages/' . $key . '/restore', 'POST', array( $site, 'restore_page' ), $project ),
			array( '/projects/(?P<id>\d+)/design', 'GET', array( $site, 'design' ), $project ),
			array( '/projects/(?P<id>\d+)/navigation', 'GET', array( $site, 'navigation' ), $project ),
			array( '/projects/(?P<id>\d+)/navigation', 'POST', array( $site, 'apply_navigation' ), $menus ),
			array( '/projects/(?P<id>\d+)/audit', 'GET', array( $site, 'get_audit' ), $project ),
			array( '/projects/(?P<id>\d+)/audit', 'POST', array( $site, 'run_audit' ), $project ),
			array( '/projects/(?P<id>\d+)/pagespeed', 'POST', array( $site, 'pagespeed' ), $publish ),
			array( '/projects/(?P<id>\d+)/publish', 'POST', array( $site, 'publish' ), $publish ),
			array( '/projects/(?P<id>\d+)/unpublish', 'POST', array( $site, 'unpublish' ), $publish ),
			array( '/projects/(?P<id>\d+)/history', 'GET', array( $site, 'history' ), $project ),
		);

		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => $r[2],
					'permission_callback' => $r[3],
					'args'                => false !== strpos( $r[0], '(?P<id>' ) ? $id : array(),
				)
			);
		}
	}
}
