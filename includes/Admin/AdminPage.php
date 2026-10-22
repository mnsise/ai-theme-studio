<?php
/**
 * Admin menu and single-page app bootstrap.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Admin;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\REST\Routes;
use AIThemeStudio\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
class AdminPage {

	public const SLUG = 'ai-theme-studio';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $p;

	/**
	 * Screen hook suffixes owned by the plugin.
	 *
	 * @var array<int, string>
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $p Plugin.
	 */
	public function __construct( Plugin $p ) {
		$this->p = $p;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ATS_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'environment_notice' ) );
	}

	/**
	 * Menu entries. Sub-pages map to routes of the same app.
	 */
	public function menu(): void {
		$cap           = Capabilities::cap_for( 'use_studio' );
		$this->hooks[] = add_menu_page(
			__( 'استودیو قالب هوشمند', 'ai-theme-studio' ),
			__( 'استودیو قالب', 'ai-theme-studio' ),
			$cap,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-art',
			59
		);
		$subs = array(
			''         => array( __( 'داشبورد', 'ai-theme-studio' ), $cap ),
			'new'      => array( __( 'پروژه جدید', 'ai-theme-studio' ), $cap ),
			'settings' => array( __( 'تنظیمات', 'ai-theme-studio' ), Capabilities::cap_for( 'manage_settings' ) ),
			'system'   => array( __( 'وضعیت سیستم', 'ai-theme-studio' ), $cap ),
		);
		foreach ( $subs as $route => $sub ) {
			$this->hooks[] = add_submenu_page(
				self::SLUG,
				$sub[0],
				$sub[0],
				$sub[1],
				'' === $route ? self::SLUG : self::SLUG . '-' . $route,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Render the app container.
	 */
	public function render(): void {
		if ( ! Capabilities::can( 'use_studio' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'ai-theme-studio' ) );
		}
		require ATS_DIR . 'admin/views/app.php';
	}

	/**
	 * Enqueue assets only on plugin screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = self::SLUG === $page ? '' : substr( $page, strlen( self::SLUG ) + 1 );

		wp_enqueue_style( 'ats-admin', ATS_URL . 'admin/css/app.css', array(), ATS_VERSION );
		wp_enqueue_script( 'ats-admin', ATS_URL . 'admin/js/app.js', array( 'wp-api-fetch', 'wp-i18n' ), ATS_VERSION, true );
		wp_set_script_translations( 'ats-admin', 'ai-theme-studio', ATS_DIR . 'languages' );
		wp_localize_script(
			'ats-admin',
			'ATS',
			array(
				'ns'       => Routes::NS,
				'route'    => $route,
				'adminUrl' => admin_url(),
				'userId'   => get_current_user_id(),
				'rtl'      => is_rtl(),
				'docsUrl'  => ATS_URL . 'docs/',
			)
		);
	}

	/**
	 * Plugin list links.
	 *
	 * @param array<int, string> $links Links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'شروع', 'ai-theme-studio' ) . '</a>' );
		return $links;
	}

	/**
	 * Show a notice on the plugin screens when Elementor is unusable.
	 */
	public function environment_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, $this->hooks, true ) ) {
			return;
		}
		$report = $this->p->get( 'compat' )->report();
		foreach ( $report['issues'] as $issue ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $issue ) );
		}
	}
}
