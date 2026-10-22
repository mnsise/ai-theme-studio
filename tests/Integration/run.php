<?php
/**
 * Integration tests against a real WordPress + Elementor (run inside WordPress Playground).
 *
 * The AI provider is replaced through the public `ats_ai_provider` filter by a
 * scripted test double (TestProvider.php); everything else (database,
 * pages, menus, Elementor documents, REST permissions, SEO meta, uninstall)
 * is the real implementation.
 *
 * Usage: tests/Integration/run.sh (see docs/TESTING.md).
 *
 * @package AIThemeStudio
 */

// phpcs:disable

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once __DIR__ . '/TestProvider.php';

use AIThemeStudio\AI\AIException;
use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DocumentValidator;
use AIThemeStudio\SEO\HeadingValidator;

$GLOBALS['ats_results'] = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );

function check( string $name, bool $ok, string $detail = '' ): void {
	$GLOBALS['ats_results'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? "  PASS  " : "  FAIL  " ) . $name . ( $ok || '' === $detail ? '' : "\n        → " . $detail ) . "\n";
}

function skip( string $name, string $why ): void {
	$GLOBALS['ats_results']['skip']++;
	echo "  SKIP  {$name} ({$why})\n";
}

function section( string $title ): void {
	echo "\n== {$title}\n";
}

function rest( string $method, string $path, array $params = array() ) {
	return ats_test_rest( $method, $path, $params );
}

$fixture  = json_decode( file_get_contents( __DIR__ . '/../fixtures/sample-site.json' ), true );
$provider = new ATS_Test_Provider( $fixture );
add_filter( 'ats_ai_provider', static fn() => $provider );
add_filter( 'ats_allow_non_uploaded_file', '__return_true' );
wp_set_current_user( 1 );

$elementor = is_plugin_active( 'elementor/elementor.php' );
$p         = Plugin::instance();
global $wpdb;

echo 'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', Elementor ' . ( $elementor ? ELEMENTOR_VERSION : 'not active' ) . "\n";

section( 'Activation' );
check( 'plugin is active', is_plugin_active( 'ai-theme-studio/ai-theme-studio.php' ) );
foreach ( array( 'ats_jobs', 'ats_artifacts', 'ats_logs' ) as $t ) {
	check( "table {$wpdb->prefix}{$t} exists", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) === $wpdb->prefix . $t );
}
check( 'default settings stored', is_array( get_option( 'ats_settings' ) ) );
check( 'daily cleanup scheduled', (bool) wp_next_scheduled( 'ats_daily_cleanup' ) );
check( 'project post type registered', post_type_exists( 'ats_project' ) );
$routes = rest_get_server()->get_routes();
check( 'REST routes registered', isset( $routes['/ai-theme-studio/v1/projects'] ) );
$no_perm = array();
foreach ( $routes as $route => $handlers ) {
	// The bare namespace index is registered by core, not by the plugin.
	if ( 0 !== strpos( $route, '/ai-theme-studio/v1/' ) ) {
		continue;
	}
	foreach ( $handlers as $h ) {
		if ( empty( $h['permission_callback'] ) ) {
			$no_perm[] = $route;
		}
	}
}
check( 'every REST route has a permission callback', ! $no_perm, implode( ', ', $no_perm ) );

$input = array(
	'prompt'   => 'سایت کلینیک دندانپزشکی لبخند در شیراز با خدمات ایمپلنت، ارتودنسی و زیبایی و امکان تماس.',
	'language' => 'fa',
	'pages'    => array( 'services', 'contact' ),
	'business' => array( 'email' => 'info@example.test', 'phone' => '071-1234' ),
	'options'  => array( 'review_sitemap' => false, 'apply_globals' => true, 'set_front_page' => true ),
);

if ( ! $elementor ) {
	section( 'Missing Elementor' );
	$report = $p->get( 'compat' )->report();
	check( 'compatibility report flags missing Elementor', ! empty( $report['issues'] ) && ! $report['compatible'] );
	$res = rest( 'POST', '/projects', array( 'input' => $input ) );
	check( 'project can still be created', 201 === $res->get_status() );
	$id  = (int) $res->get_data()['id'];
	$gen = rest( 'POST', "/projects/{$id}/generate" );
	check( 'generation is refused with a clear error', 409 === $gen->get_status() && 'ats_elementor_missing' === $gen->get_data()['code'], wp_json_encode( $gen->get_data() ) );
	$sys = rest( 'GET', '/system' );
	check( 'system endpoint works without Elementor', 200 === $sys->get_status() && false === $sys->get_data()['elementor']['active'] );
	ob_start();
	do_action( 'wp_head' );
	ob_end_clean();
	check( 'frontend hooks run without Elementor', true );
	summary();
}

section( 'Uploads' );
$uploader = $p->get( 'uploader' );
$bad      = wp_tempnam( 'bad.png' );
file_put_contents( $bad, '<?php echo "x"; ?>' );
$err = $uploader->validate( array( 'tmp_name' => $bad, 'name' => 'evil.png', 'size' => filesize( $bad ), 'error' => 0 ) );
check( 'PHP disguised as PNG is rejected', is_wp_error( $err ) );
$big = $uploader->validate( array( 'tmp_name' => $bad, 'name' => 'a.png', 'size' => 999 * MB_IN_BYTES, 'error' => 0 ) );
check( 'oversized upload is rejected', is_wp_error( $big ) && 'ats_upload_size' === $big->get_error_code() );

$res = rest( 'POST', '/projects', array( 'name' => 'Integration', 'input' => $input ) );
check( 'create project via REST', 201 === $res->get_status(), wp_json_encode( $res->get_data() ) );
$pid = (int) $res->get_data()['id'];

$png = null;
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$im = imagecreatetruecolor( 400, 300 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 14, 116, 144 ) );
	$png = wp_tempnam( 'hero.png' );
	imagepng( $im, $png );
	$att = $uploader->store( array( 'tmp_name' => $png, 'name' => 'hero.png', 'size' => filesize( $png ), 'error' => 0 ), $pid );
	check( 'valid PNG stored as attachment', is_int( $att ) && 'attachment' === get_post_type( $att ), is_wp_error( $att ) ? $att->get_error_message() : '' );
	if ( is_int( $att ) ) {
		$ref = $p->get( 'projects' )->add_image( $pid, $att, 'hero' );
		check( 'image registered as img_1', 'img_1' === $ref );
	}
} else {
	skip( 'valid PNG upload', 'GD not available in this PHP build' );
}

section( 'Generation pipeline' );
$runner = $p->get( 'runner' );
$res    = rest( 'POST', "/projects/{$pid}/generate" );
check( 'generation started (202)', 202 === $res->get_status(), wp_json_encode( $res->get_data() ) );
$job_id = (int) $res->get_data()['id'];
$dup    = rest( 'POST', "/projects/{$pid}/generate" );
check( 'second concurrent generation refused (409)', 409 === $dup->get_status() );

function drain( $runner, $jobs, int $job_id, int $max = 60 ): array {
	for ( $i = 0; $i < $max; $i++ ) {
		$job = $jobs->find( $job_id );
		if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			return $job;
		}
		$runner->tick( $job_id );
	}
	return $jobs->find( $job_id );
}

$job     = drain( $runner, $p->get( 'jobs' ), $job_id );
$project = $p->get( 'projects' )->get( $pid );
check( 'job completed', 'completed' === $job['status'], (string) $job['last_error'] . ' @ ' . $job['step'] );
check( 'project is ready', 'ready' === $project['status'] );
check( 'vision analysis ran for the uploaded image', ! $png || 'analyzed' === ( $p->get( 'artifacts' )->latest( $pid, 'image_analysis' )['status'] ?? '' ) );

$pages = $project['pages'];
check( 'all sitemap pages created', 5 === count( $pages ), wp_json_encode( $pages ) );
$all_drafts = true;
foreach ( $pages as $id ) {
	$all_drafts = $all_drafts && 'draft' === get_post_status( $id );
}
check( 'pages are drafts (nothing published without authorization)', $all_drafts );
check( 'child page has parent', (int) wp_get_post_parent_id( $pages['implant'] ) === (int) $pages['services'] );

$home     = (int) $pages['home'];
$doc      = \Elementor\Plugin::$instance->documents->get( $home, false );
$elements = $doc ? $doc->get_elements_data() : array();
check( 'Elementor document loads', $doc && $doc->is_built_with_elementor() );
check( '_elementor_edit_mode is builder', 'builder' === get_post_meta( $home, '_elementor_edit_mode', true ) );
check( 'canvas template set', 'elementor_canvas' === get_post_meta( $home, '_wp_page_template', true ) );
$v = DocumentValidator::validate( $elements, array( $p->get( 'compat' ), 'widget_available' ) );
check( 'stored Elementor data is structurally valid', $v['valid'], implode( '; ', array_slice( $v['errors'], 0, 5 ) ) );
check( 'uses Flexbox containers', 'container' === ( $elements[0]['elType'] ?? '' ) );

$outline = HeadingValidator::outline_from_elementor( $elements );
check( 'exactly one H1 on the homepage', 1 === count( array_filter( $outline, static fn( $h ) => 'h1' === $h['level'] ) ) );
check( 'no skipped heading levels', ! array_filter( HeadingValidator::audit_outline( $outline ), static fn( $i ) => 'skipped_level' === $i['code'] ) );

$json = wp_json_encode( $elements );
check( 'no script or javascript: in stored data', false === stripos( $json, '<script' ) && false === stripos( $json, 'javascript:' ) );
check( 'no invented image reference survives', false === strpos( $json, 'img_99' ) );

ob_start();
$html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $home );
ob_end_clean();
check( 'frontend renders page HTML', is_string( $html ) && strlen( $html ) > 500 );
check( 'rendered HTML has one <h1>', 1 === preg_match_all( '/<h1\b/i', $html ) );
check( 'FAQ renders as native <details> accordion', str_contains( $html, '<details' ) && str_contains( $html, 'e-n-accordion' ), substr( wp_strip_all_tags( $html ), 0, 200 ) );
check( 'no legacy accordion markup (role=button heading, anchor without href)', ! str_contains( $html, 'elementor-tab-title' ) && ! preg_match( '/<a(?![^>]*href)[^>]*class="elementor-accordion-title"/', $html ) );
check( 'testimonial without placeholder photo', ! str_contains( $html, 'placeholder.png' ) );
check( 'rendered links point to real page URLs', (bool) preg_match( '#href="[^"]*(contact|page_id=)#', $html ) );

$header = (int) $project['header_id'];
$footer = (int) $project['footer_id'];
check( 'header template created', $header && 'elementor_library' === get_post_type( $header ) );
check( 'footer template created', $footer && 'elementor_library' === get_post_type( $footer ) );
check( 'header has no H1', ! array_filter( HeadingValidator::outline_from_elementor( \Elementor\Plugin::$instance->documents->get( $header, false )->get_elements_data() ), static fn( $h ) => 'h1' === $h['level'] ) );

// Read the stored kit settings; the in-memory kit document caches settings from before the save.
$kit_settings = (array) get_post_meta( (int) \Elementor\Plugin::$instance->kits_manager->get_active_id(), '_elementor_page_settings', true );
$colors       = array_column( (array) ( $kit_settings['custom_colors'] ?? array() ), '_id' );
check( 'design tokens registered as Elementor globals', in_array( 'atsprimary', $colors, true ), wp_json_encode( $colors ) );
$system = (array) ( $kit_settings['system_colors'] ?? array() );
check( 'existing system colors untouched before publish', ! in_array( '#0E7490', array_column( $system, 'color' ), true ) );

check( 'SEO title stored (no SEO plugin)', '' !== (string) get_post_meta( $home, '_ats_seo_title', true ) );
check( 'not-found page is noindex', (bool) get_post_meta( $pages['not_found'], '_ats_noindex', true ) );

$audit = $p->get( 'artifacts' )->latest( $pid, 'audit_seo' );
check( 'SEO audit stored with score', is_array( $audit ) && isset( $audit['score'] ) );
check( 'no orphan pages reported', ! array_filter( (array) $audit['issues'], static fn( $i ) => 'orphan_page' === $i['code'] && 'not_found' !== $i['page'] ), wp_json_encode( array_column( (array) $audit['issues'], 'code' ) ) );

section( 'Duplicate prevention and edit preservation' );
$before = count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );

// Simulate a user edit in Elementor.
$edited                                        = $elements;
$edited[0]['elements'][0]['settings']['_title'] = 'user edit';
$doc->save( array( 'elements' => $edited ) );
check( 'user edit detected', $p->get( 'writer' )->is_modified( $home ) );

$res = rest( 'POST', "/projects/{$pid}/generate", array( 'force' => false ) );
$job = drain( $runner, $p->get( 'jobs' ), (int) $res->get_data()['id'] );
check( 'regeneration completed', 'completed' === $job['status'], (string) $job['last_error'] );
$after = count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
check( 'no duplicate pages after regeneration', $before === $after, "{$before} → {$after}" );
$now = \Elementor\Plugin::$instance->documents->get( $home, false )->get_elements_data();
check( 'user-edited page preserved without confirmation', 'user edit' === ( $now[0]['elements'][0]['settings']['_title'] ?? '' ) );
$home_def = $p->get( 'artifacts' )->latest( $pid, 'page', 'home' );
check( 'edited page definition not regenerated (stays in sync with document)', in_array( 'services', array_column( (array) ( $home_def['sections'] ?? array() ), 'id' ), true ) );

$sec = rest( 'POST', "/projects/{$pid}/pages/home/sections/hero/regenerate", array( 'instruction' => 'shorter' ) );
check( 'section regeneration on edited section needs confirmation (409)', 409 === $sec->get_status() && ! empty( $sec->get_data()['data']['needs_confirmation'] ) );

$sec = rest( 'POST', "/projects/{$pid}/pages/home/sections/services/regenerate", array( 'instruction' => 'more concise' ) );
check( 'unedited section regeneration accepted', 202 === $sec->get_status(), wp_json_encode( $sec->get_data() ) );
$job = drain( $runner, $p->get( 'jobs' ), (int) $sec->get_data()['job_id'] );
check( 'section regeneration completed', 'completed' === $job['status'], (string) $job['last_error'] );
$now = \Elementor\Plugin::$instance->documents->get( $home, false )->get_elements_data();
check( 'regenerated section replaced in place', str_contains( wp_json_encode( $now, JSON_UNESCAPED_UNICODE ), 'خدمات بازتولیدشده' ) );
check( 'user edit in other section still preserved', 'user edit' === ( $now[0]['elements'][0]['settings']['_title'] ?? '' ) );
check( 'document still valid after section replace', DocumentValidator::validate( $now )['valid'] );
check( 'backup versions recorded', count( $p->get( 'artifacts' )->history( $pid, 'elementor_backup' ) ) >= 1 );

section( 'Navigation' );
register_nav_menus( array( 'primary' => 'Primary' ) );
$existing = wp_create_nav_menu( 'Existing Menu' );
set_theme_mod( 'nav_menu_locations', array( 'primary' => $existing ) );
$res = rest( 'POST', "/projects/{$pid}/navigation", array( 'mode' => 'create', 'locations' => array( 'primary' ) ) );
check( 'menu created', 200 === $res->get_status() && $res->get_data()['created'] >= 4, wp_json_encode( $res->get_data() ) );
check( 'existing menu location not replaced without permission', in_array( 'primary', $res->get_data()['skipped_locations'], true ) && (int) get_nav_menu_locations()['primary'] === (int) $existing );
$menu_id = (int) $res->get_data()['menu_id'];
$res2    = rest( 'POST', "/projects/{$pid}/navigation", array( 'mode' => 'create' ) );
check( 'applying again creates no duplicates', 0 === $res2->get_data()['created'] && count( wp_get_nav_menu_items( $menu_id ) ) === $res->get_data()['created'] );
check( 'existing menu not deleted', (bool) wp_get_nav_menu_object( $existing ) );

section( 'Publishing permissions' );
add_role( 'ats_writer', 'ATS Writer', array( 'read' => true, 'edit_pages' => true, 'edit_others_pages' => true, 'upload_files' => true ) );
$writer_id = wp_insert_user( array( 'user_login' => 'atswriter', 'user_pass' => wp_generate_password(), 'role' => 'ats_writer' ) );
wp_set_current_user( $writer_id );
$res = rest( 'POST', "/projects/{$pid}/publish", array( 'confirm' => true ) );
check( 'user without publish_pages cannot publish (403)', 403 === $res->get_status() );
$res = rest( 'GET', "/projects/{$pid}" );
check( 'non-owner non-admin cannot read project', 403 === $res->get_status() );
$res = rest( 'GET', '/settings' );
check( 'non-admin cannot read settings', 403 === $res->get_status() );
wp_set_current_user( 0 );
$res = rest( 'GET', '/projects' );
check( 'anonymous request rejected', 401 === $res->get_status() );
wp_set_current_user( 1 );

$res = rest( 'POST', "/projects/{$pid}/publish", array( 'set_front_page' => true ) );
check( 'publish without explicit confirmation rejected', 400 === $res->get_status() );
$res = rest( 'POST', "/projects/{$pid}/publish", array( 'confirm' => true, 'set_front_page' => true, 'use_404' => true ) );
check( 'admin publish succeeds', 200 === $res->get_status() && 5 === count( $res->get_data()['published'] ), wp_json_encode( $res->get_data() ) );
check( 'front page set', (int) get_option( 'page_on_front' ) === $home && 'page' === get_option( 'show_on_front' ) );

$p->get( 'settings' )->update( array( 'seo_output' => true ) );
wp_update_post( array( 'ID' => $pages['services'], 'post_status' => 'publish' ) );
$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $pages['services'] ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['post']         = get_post( $pages['services'] );
$meta_out                = new \AIThemeStudio\SEO\MetaOutput( $p );
$meta_out->maybe_hook();
ob_start();
$meta_out->head();
$head = ob_get_clean();
check( 'meta description printed when no SEO plugin', str_contains( $head, 'name="description"' ) );
check( 'valid JSON-LD printed', (bool) preg_match( '#<script type="application/ld\+json"[^>]*>(.+?)</script>#s', $head, $m ) && is_array( json_decode( $m[1], true ) ) );

$res = rest( 'POST', "/projects/{$pid}/unpublish" );
check( 'unpublish restores drafts', 'draft' === get_post_status( $home ) && 'posts' === get_option( 'show_on_front' ) );

section( 'Provider failures and invalid output' );
$res2 = rest( 'POST', '/projects', array( 'input' => $input ) );
$pid2 = (int) $res2->get_data()['id'];
$provider->fail_queue = array( new AIException( 'rate limited', true ), new AIException( 'Invalid API key', false ) );
$job_id = (int) rest( 'POST', "/projects/{$pid2}/generate" )->get_data()['id'];
$runner->tick( $job_id );
$j = $p->get( 'jobs' )->find( $job_id );
check( 'retryable error requeues the job', 'queued' === $j['status'] && 1 === (int) $j['attempts'] );
$runner->tick( $job_id );
$j = $p->get( 'jobs' )->find( $job_id );
check( 'non-retryable error fails the job with a message', 'failed' === $j['status'] && str_contains( (string) $j['last_error'], 'Invalid API key' ) );
check( 'project marked failed', 'failed' === $p->get( 'projects' )->get( $pid2 )['status'] );
$res = rest( 'POST', "/jobs/{$job_id}/resume" );
check( 'failed job can be resumed', 200 === $res->get_status() && 'queued' === $p->get( 'jobs' )->find( $job_id )['status'] );
$provider->garbage = true;
$j = drain( $runner, $p->get( 'jobs' ), $job_id, 10 );
check( 'persistently invalid AI output ends in failure, not fake success', 'failed' === $j['status'] && '' !== (string) $j['last_error'] );
check( 'no pages created from invalid output', ! $p->get( 'projects' )->pages( $pid2 ) );
$provider->garbage = false;
$res = rest( 'POST', "/jobs/{$job_id}/cancel" );
check( 'cancelling a finished job is a no-op', 'failed' === $p->get( 'jobs' )->find( $job_id )['status'] );

section( 'SEO plugin conflict' );
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '99.0-test' );
}
$p->get( 'seo_meta' )->write( $pages['services'], 'عنوان تست', 'توضیحات تست برای افزونه Yoast که باید در فیلدهای آن ذخیره شود.', false );
check( 'metadata written to Yoast fields', 'عنوان تست' === get_post_meta( $pages['services'], '_yoast_wpseo_title', true ) );
$meta_yoast = new \AIThemeStudio\SEO\MetaOutput( $p );
$meta_yoast->maybe_hook();
check( 'no own meta/schema output when Yoast is active', false === has_action( 'wp_head', array( $meta_yoast, 'head' ) ) && false === has_filter( 'pre_get_document_title', array( $meta_yoast, 'title' ) ) );
update_post_meta( $pages['services'], '_yoast_wpseo_title', 'owner custom title' );
$p->get( 'seo_meta' )->write( $pages['services'], 'عنوان جدید', 'توضیحات جدید برای بررسی عدم بازنویسی مقدار دستی کاربر در افزونه سئو.', false );
check( 'owner-entered SEO title not overwritten', 'owner custom title' === get_post_meta( $pages['services'], '_yoast_wpseo_title', true ) );

section( 'Uninstall' );
$p->get( 'settings' )->update( array( 'delete_on_uninstall' => true ) );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'ai-theme-studio/ai-theme-studio.php' );
}
include WP_PLUGIN_DIR . '/ai-theme-studio/uninstall.php';
check( 'plugin tables dropped', null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'ats_jobs' ) ) );
check( 'settings deleted', false === get_option( 'ats_settings' ) );
check( 'project records deleted', ! get_posts( array( 'post_type' => 'ats_project', 'post_status' => 'any' ) ) );
check( 'generated pages kept (user content)', 'page' === get_post_type( $home ) );
check( 'menus kept', (bool) wp_get_nav_menu_object( $menu_id ) );

summary();

function summary(): void {
	$r = $GLOBALS['ats_results'];
	echo "\nResult: {$r['pass']} passed, {$r['fail']} failed, {$r['skip']} skipped\n";
	exit( $r['fail'] ? 1 : 0 );
}
