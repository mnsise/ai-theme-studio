<?php
/**
 * JSON-LD from verified data only (site name, URL, owner-supplied logo and
 * contact details). Never emits ratings, reviews, prices or invented facts.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Schema generator.
 */
final class SchemaGenerator {

	/**
	 * Build the @graph for a page.
	 *
	 * @param int $page_id    Page.
	 * @param int $project_id Project.
	 * @return array<string, mixed>|null
	 */
	public static function graph( int $page_id, int $project_id ): ?array {
		if ( ! $project_id ) {
			return null;
		}
		$home  = home_url( '/' );
		$org   = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => $home,
		);
		$facts = get_post_meta( $project_id, '_ats_schema_facts', true );
		$facts = is_array( $facts ) ? $facts : array();
		if ( ! empty( $facts['name'] ) ) {
			$org['name'] = (string) $facts['name'];
		}
		if ( ! empty( $facts['logo_id'] ) ) {
			$logo = wp_get_attachment_image_url( (int) $facts['logo_id'], 'full' );
			if ( $logo ) {
				$org['logo'] = $logo;
			}
		}
		if ( ! empty( $facts['email'] ) && is_email( $facts['email'] ) ) {
			$org['email'] = (string) $facts['email'];
		}
		if ( ! empty( $facts['phone'] ) ) {
			$org['telephone'] = (string) $facts['phone'];
		}

		$graph = array();
		if ( is_front_page() ) {
			$graph[] = $org;
			$graph[] = array(
				'@type'      => 'WebSite',
				'@id'        => $home . '#website',
				'url'        => $home,
				'name'       => $org['name'],
				'inLanguage' => get_bloginfo( 'language' ),
				'publisher'  => array( '@id' => $home . '#organization' ),
			);
		} else {
			$crumbs = array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => $org['name'],
					'item'     => $home,
				),
			);
			$chain  = array_reverse( get_post_ancestors( $page_id ) );
			$chain[] = $page_id;
			$pos    = 2;
			foreach ( $chain as $ancestor ) {
				$crumbs[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => get_the_title( $ancestor ),
					'item'     => get_permalink( $ancestor ),
				);
			}
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $crumbs,
			);
		}
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}
}
