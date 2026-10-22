<?php
/**
 * Collects the generated site's actual Elementor data and runs the SEO,
 * accessibility, Elementor-structure and performance audits.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Generation;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Elementor\DocumentValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Quality report.
 */
class QualityReport {

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
	 * Run and store.
	 *
	 * @param int $project_id Project.
	 * @return array{seo: array<string, mixed>, performance: array<string, mixed>, elementor: array<string, mixed>}
	 */
	public function run( int $project_id ): array {
		$artifacts = $this->p->get( 'artifacts' );
		$projects  = $this->p->get( 'projects' );
		$project   = $projects->get( $project_id );
		$sitemap   = (array) $artifacts->latest( $project_id, 'sitemap' );
		$design    = (array) $artifacts->latest( $project_id, 'design' );
		$writer    = $this->p->get( 'writer' );
		$pm        = $this->p->get( 'pages' );
		$compat    = $this->p->get( 'compat' );
		$map       = $project ? $project['pages'] : array();
		$front     = ! empty( $project['input']['options']['set_front_page'] );

		$pages   = array();
		$allowed = array( home_url( '/' ) );
		foreach ( (array) ( $sitemap['pages'] ?? array() ) as $page ) {
			$id  = (int) ( $map[ $page['key'] ] ?? 0 );
			$url = $id ? $pm->future_permalink( $id, 'home' === $page['key'] && $front ) : '';
			if ( $url ) {
				$allowed[] = $url;
			}
			$pages[] = array(
				'key'      => $page['key'],
				'type'     => $page['type'],
				'post_id'  => $id,
				'title'    => $page['title'],
				'seo'      => $page['seo'],
				'noindex'  => ! empty( $page['noindex'] ),
				'elements' => $id ? $writer->read( $id ) : array(),
				'url'      => $url,
			);
		}

		$header = $project && $project['header_id'] ? $writer->read( (int) $project['header_id'] ) : array();
		$footer = $project && $project['footer_id'] ? $writer->read( (int) $project['footer_id'] ) : array();

		$seo = $this->p->get( 'seo_auditor' )->audit(
			$pages,
			(array) ( ( new DesignTokens( $design ) )->to_array()['colors'] ),
			(array) $projects->meta( $project_id, 'nav_proposal' ),
			array(
				'seo_plugin'      => $this->p->get( 'seo_detector' )->active(),
				'permalinks'      => (bool) get_option( 'permalink_structure' ),
				'blog_public'     => (bool) get_option( 'blog_public' ),
				'home'            => home_url( '/' ),
				'header_elements' => $header,
				'footer_elements' => $footer,
				'allowed_urls'    => $allowed,
			)
		);

		$elementor = array();
		foreach ( $pages as $p ) {
			if ( $p['elements'] ) {
				$check       = DocumentValidator::validate( $p['elements'], $compat->is_active() ? array( $compat, 'widget_available' ) : null );
				$elementor[] = array(
					'page'     => $p['key'],
					'valid'    => $check['valid'],
					'errors'   => $check['errors'],
					'stats'    => $check['stats'],
					'modified' => $p['post_id'] ? $writer->is_modified( $p['post_id'] ) : false,
				);
			}
		}

		$perf = $this->p->get( 'perf_auditor' )->audit(
			$pages,
			( new DesignTokens( $design ) )->fonts(),
			DocumentValidator::validate( array_merge( $header, $footer ) )['stats']['elements']
		);

		$artifacts->save( $project_id, 'audit_seo', '', $seo );
		$artifacts->save( $project_id, 'audit_performance', '', $perf );
		$artifacts->save( $project_id, 'audit_elementor', '', $elementor );

		return array(
			'seo'         => $seo,
			'performance' => $perf,
			'elementor'   => $elementor,
		);
	}
}
