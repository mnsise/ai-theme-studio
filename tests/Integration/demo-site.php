<?php
/**
 * Builds and publishes the fixture site through the real pipeline (scripted provider)
 * so Lighthouse can be run against it. Used by tests/Integration/lighthouse.sh.
 *
 * @package AIThemeStudio
 */

// phpcs:disable

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once __DIR__ . '/TestProvider.php';

$fixture  = json_decode( file_get_contents( __DIR__ . '/../fixtures/sample-site.json' ), true );
$provider = new ATS_Test_Provider( $fixture );
add_filter( 'ats_ai_provider', static fn() => $provider );
add_filter( 'ats_allow_non_uploaded_file', '__return_true' );
wp_set_current_user( 1 );
update_option( 'blog_public', 1 );
update_option( 'blogname', 'کلینیک لبخند' );

$p   = \AIThemeStudio\Core\Plugin::instance();
$res = ats_test_rest(
	'POST',
	'/projects',
	array(
		'name'  => 'Lighthouse demo',
		'input' => array(
			'prompt'   => 'سایت کلینیک دندانپزشکی لبخند در شیراز با خدمات ایمپلنت، ارتودنسی و زیبایی و امکان تماس.',
			'language' => 'fa',
			'pages'    => array( 'services', 'contact' ),
			'business' => array( 'name' => 'کلینیک لبخند', 'email' => 'info@example.test', 'phone' => '071-1234' ),
			'options'  => array( 'review_sitemap' => false, 'apply_globals' => true, 'set_front_page' => true ),
		),
	)
);
$pid = (int) $res->get_data()['id'];

if ( function_exists( 'imagecreatetruecolor' ) ) {
	$im = imagecreatetruecolor( 1200, 800 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 14, 116, 144 ) );
	$file = wp_tempnam( 'hero.jpg' );
	imagejpeg( $im, $file, 80 );
	$att = $p->get( 'uploader' )->store( array( 'tmp_name' => $file, 'name' => 'hero.jpg', 'size' => filesize( $file ), 'error' => 0 ), $pid );
	if ( is_int( $att ) ) {
		$p->get( 'projects' )->add_image( $pid, $att, 'hero' );
	}
}

$job_id = (int) ats_test_rest( 'POST', "/projects/{$pid}/generate" )->get_data()['id'];
$jobs   = $p->get( 'jobs' );
for ( $i = 0; $i < 60 && in_array( $jobs->find( $job_id )['status'], array( 'queued', 'running' ), true ); $i++ ) {
	$p->get( 'runner' )->tick( $job_id );
}
$job = $jobs->find( $job_id );
if ( 'completed' !== $job['status'] ) {
	throw new RuntimeException( 'Demo generation failed: ' . $job['last_error'] );
}

ats_test_rest( 'POST', "/projects/{$pid}/navigation", array( 'mode' => 'create', 'locations' => array() ) );
$pub = ats_test_rest( 'POST', "/projects/{$pid}/publish", array( 'confirm' => true, 'set_front_page' => true, 'use_404' => true, 'apply_site_styles' => true ) );
if ( 200 !== $pub->get_status() ) {
	throw new RuntimeException( 'Demo publish failed: ' . wp_json_encode( $pub->get_data() ) );
}
flush_rewrite_rules();

$urls = array();
foreach ( $p->get( 'projects' )->get( $pid )['pages'] as $key => $id ) {
	if ( 'not_found' !== $key ) {
		$urls[] = get_permalink( $id );
	}
}
// The plugin directory is mounted from the host, so the runner script can read this file.
file_put_contents( __DIR__ . '/.demo-urls.txt', implode( ',', $urls ) );
