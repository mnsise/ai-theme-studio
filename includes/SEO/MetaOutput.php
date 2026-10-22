<?php
/**
 * Optional, lightweight metadata output for generated pages when no SEO
 * plugin is active: document title, meta description, Open Graph basics,
 * robots (via the core wp_robots API) and JSON-LD (Organization, WebSite,
 * BreadcrumbList). Canonical URLs are left to WordPress core.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

use AIThemeStudio\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Meta output.
 */
class MetaOutput {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks (registered late so SEO plugin constants are known).
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'maybe_hook' ) );
	}

	/**
	 * Hook output only for generated pages, only without an SEO plugin.
	 */
	public function maybe_hook(): void {
		if ( is_admin() || ! $this->plugin->get( 'settings' )->get( 'seo_output' ) ) {
			return;
		}
		if ( '' !== $this->plugin->get( 'seo_detector' )->active() ) {
			return;
		}
		$id = $this->page_id();
		if ( ! $id ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'wp_head', array( $this, 'head' ), 5 );
	}

	/**
	 * Current generated page id (including the 404 page on not-found requests).
	 */
	private function page_id(): int {
		if ( is_404() ) {
			return \AIThemeStudio\WordPress\NotFoundHandler::page_id();
		}
		if ( ! is_singular( 'page' ) ) {
			return 0;
		}
		$id = (int) get_queried_object_id();
		return get_post_meta( $id, '_ats_project_id', true ) ? $id : 0;
	}

	/**
	 * Document title.
	 *
	 * @param string $title Title.
	 */
	public function title( $title ) {
		$custom = (string) get_post_meta( $this->page_id(), MetaManager::TITLE, true );
		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Robots.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public function robots( $robots ) {
		if ( '1' === get_post_meta( $this->page_id(), MetaManager::NOINDEX, true ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * Head tags.
	 */
	public function head(): void {
		$id   = $this->page_id();
		$desc = (string) get_post_meta( $id, MetaManager::DESC, true );
		if ( '' !== $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		if ( is_404() ) {
			return;
		}
		$title = (string) get_post_meta( $id, MetaManager::TITLE, true );
		$url   = is_front_page() ? home_url( '/' ) : (string) get_permalink( $id );
		printf( '<meta property="og:type" content="%s">' . "\n", is_front_page() ? 'website' : 'article' );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( '' !== $title ? $title : get_the_title( $id ) ) );
		if ( '' !== $desc ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
		}
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( get_locale() ) );

		$graph = SchemaGenerator::graph( $id, (int) get_post_meta( $id, '_ats_project_id', true ) );
		if ( $graph ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
