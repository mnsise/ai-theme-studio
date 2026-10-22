<?php
/**
 * Runs Lighthouse remotely through the Google PageSpeed Insights API.
 *
 * External request: https://www.googleapis.com/pagespeedonline/v5/runPagespeed
 * Only URLs on this site's own host are sent, and only when an administrator
 * triggers a test. Works only for publicly reachable, published pages.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Performance;

use AIThemeStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * PageSpeed Insights client.
 */
class PageSpeedClient {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run a test.
	 *
	 * @param string $url      Page URL (must be on this site).
	 * @param string $strategy mobile|desktop.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run( string $url, string $strategy ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home_host || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'ats_psi_url', __( 'فقط نشانی‌های همین سایت قابل آزمون هستند.', 'ai-theme-studio' ) );
		}
		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';
		$query    = array(
			'url'      => $url,
			'strategy' => $strategy,
		);
		$endpoint = add_query_arg( $query, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $cat ) {
			$endpoint .= '&category=' . rawurlencode( $cat );
		}
		$key = $this->settings->pagespeed_key();
		if ( $key ) {
			$endpoint .= '&key=' . rawurlencode( $key );
		}

		$response = wp_remote_get( $endpoint, array( 'timeout' => 120 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $code;
			/* translators: %s: error */
			return new \WP_Error( 'ats_psi_failed', sprintf( __( 'آزمون PageSpeed ناموفق بود: %s', 'ai-theme-studio' ), $msg ) );
		}

		$lh     = $body['lighthouseResult'] ?? array();
		$scores = array();
		foreach ( (array) ( $lh['categories'] ?? array() ) as $id => $cat ) {
			$scores[ $id ] = isset( $cat['score'] ) ? (int) round( (float) $cat['score'] * 100 ) : null;
		}
		$audits  = (array) ( $lh['audits'] ?? array() );
		$metrics = array();
		foreach ( array( 'largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'first-contentful-paint', 'speed-index' ) as $m ) {
			if ( isset( $audits[ $m ]['displayValue'] ) ) {
				$metrics[ $m ] = (string) $audits[ $m ]['displayValue'];
			}
		}
		$failed = array();
		foreach ( $audits as $id => $audit ) {
			if ( isset( $audit['score'] ) && null !== $audit['score'] && (float) $audit['score'] < 0.9 && 'binary' === ( $audit['scoreDisplayMode'] ?? '' ) ) {
				$failed[] = array(
					'id'    => $id,
					'title' => (string) ( $audit['title'] ?? $id ),
				);
			}
		}
		$field = $body['loadingExperience']['metrics'] ?? array();

		return array(
			'url'           => $url,
			'strategy'      => $strategy,
			'fetched_at'    => gmdate( 'c' ),
			'lighthouse'    => (string) ( $lh['lighthouseVersion'] ?? '' ),
			'scores'        => $scores,
			'metrics'       => $metrics,
			'failed_audits' => array_slice( $failed, 0, 25 ),
			'field_data'    => $field ? array_map( static fn( $m ) => $m['category'] ?? '', $field ) : array(),
		);
	}
}
