<?php
/**
 * Free-Elementor header/footer integration.
 *
 * Generated pages use the "Elementor Canvas" page template. Elementor fires
 * documented actions before/after Canvas content; this class prints the
 * project's header template, a <main> landmark and the footer template
 * there. Nothing is changed on pages that do not belong to a project.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

use AIThemeStudio\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Canvas layout.
 */
class CanvasLayout {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Per-request cache.
	 *
	 * @var array<int, array<string, int|bool>|null>
	 */
	private static array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'elementor/page_templates/canvas/before_content', array( $this, 'before' ) );
		add_action( 'elementor/page_templates/canvas/after_content', array( $this, 'after' ) );
		add_action( 'elementor/page_templates/header-footer/before_content', array( $this, 'main_open' ) );
		add_action( 'elementor/page_templates/header-footer/after_content', array( $this, 'main_close' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_head', array( $this, 'js_flag' ), 1 );
	}

	/**
	 * Mark JS support before first paint so the mobile menu is collapsed from the
	 * start instead of collapsing when nav.js runs (layout shift). Without JS the
	 * menu stays expanded and usable.
	 */
	public function js_flag(): void {
		$not_found = is_404() && \AIThemeStudio\WordPress\NotFoundHandler::page_id();
		if ( $not_found || $this->current() ) {
			wp_print_inline_script_tag( "document.documentElement.classList.add('ats-js');" );
		}
	}

	/**
	 * Layout context for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, int|bool>|null
	 */
	public static function context_for( int $post_id ): ?array {
		if ( array_key_exists( $post_id, self::$cache ) ) {
			return self::$cache[ $post_id ];
		}
		$project_id = (int) get_post_meta( $post_id, '_ats_project_id', true );
		if ( ! $project_id || 'ats_project' !== get_post_type( $project_id ) ) {
			self::$cache[ $post_id ] = null;
			return null;
		}
		self::$cache[ $post_id ] = array(
			'project_id'    => $project_id,
			'header_id'     => (int) get_post_meta( $project_id, '_ats_header_id', true ),
			'footer_id'     => (int) get_post_meta( $project_id, '_ats_footer_id', true ),
			'theme_builder' => (bool) get_post_meta( $project_id, '_ats_theme_builder', true ),
		);
		return self::$cache[ $post_id ];
	}

	/**
	 * Context for the current request.
	 *
	 * @return array<string, int|bool>|null
	 */
	private function current(): ?array {
		$id = (int) get_queried_object_id();
		return $id && 'page' === get_post_type( $id ) ? self::context_for( $id ) : null;
	}

	/**
	 * Before Canvas content.
	 */
	public function before(): void {
		$ctx = $this->current();
		if ( ! $ctx ) {
			return;
		}
		self::open( $ctx );
	}

	/**
	 * After Canvas content.
	 */
	public function after(): void {
		$ctx = $this->current();
		if ( ! $ctx ) {
			return;
		}
		self::close( $ctx );
	}

	/**
	 * Header + skip link + <main>.
	 *
	 * @param array<string, int|bool> $ctx Context.
	 */
	public static function open( array $ctx ): void {
		echo '<a class="ats-skip-link" href="#ats-main">' . esc_html__( 'رفتن به محتوای اصلی', 'ai-theme-studio' ) . '</a>';
		if ( empty( $ctx['theme_builder'] ) && $ctx['header_id'] ) {
			self::render( (int) $ctx['header_id'] );
		}
		echo '<main id="ats-main" class="ats-main" tabindex="-1">';
	}

	/**
	 * </main> + footer.
	 *
	 * @param array<string, int|bool> $ctx Context.
	 */
	public static function close( array $ctx ): void {
		echo '</main>';
		if ( empty( $ctx['theme_builder'] ) && $ctx['footer_id'] ) {
			self::render( (int) $ctx['footer_id'] );
		}
	}

	/**
	 * <main> landmark for the header-footer template (Pro theme builder mode).
	 */
	public function main_open(): void {
		if ( $this->current() ) {
			echo '<main id="ats-main" class="ats-main" tabindex="-1">';
		}
	}

	/**
	 * Close <main>.
	 */
	public function main_close(): void {
		if ( $this->current() ) {
			echo '</main>';
		}
	}

	/**
	 * Render an Elementor template.
	 *
	 * @param int $template_id Template id.
	 */
	public static function render( int $template_id ): void {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! get_post( $template_id ) ) {
			return;
		}
		// Elementor returns already-escaped builder markup.
		echo \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue header/footer CSS in <head> to avoid layout shifts.
	 */
	public function enqueue(): void {
		$ctx = $this->current();
		if ( ! $ctx ) {
			return;
		}
		self::enqueue_for( $ctx );
	}

	/**
	 * Enqueue assets for a context.
	 *
	 * @param array<string, int|bool> $ctx Context.
	 */
	public static function enqueue_for( array $ctx ): void {
		wp_enqueue_style( 'ats-frontend' );
		wp_enqueue_script( 'ats-nav' );
		if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) || ! empty( $ctx['theme_builder'] ) ) {
			return;
		}
		foreach ( array( 'header_id', 'footer_id' ) as $key ) {
			if ( ! empty( $ctx[ $key ] ) ) {
				\Elementor\Core\Files\CSS\Post::create( (int) $ctx[ $key ] )->enqueue();
			}
		}
	}
}
