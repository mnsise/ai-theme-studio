<?php
/**
 * Detects active SEO plugins so metadata is written into their fields and
 * this plugin never prints duplicate titles, descriptions, canonicals,
 * robots directives or schema.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * SEO plugin detector.
 */
class SeoPluginDetector {

	/**
	 * Active SEO plugin id or '' when none.
	 */
	public function active(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return 'tsf';
		}
		return '';
	}

	/**
	 * Human label.
	 */
	public function label(): string {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO',
			'tsf'      => 'The SEO Framework',
		);
		return $labels[ $this->active() ] ?? '';
	}
}
