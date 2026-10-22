<?php
/**
 * 404 template rendering the generated Elementor 404 page.
 *
 * @package AIThemeStudio
 */

use AIThemeStudio\Elementor\CanvasLayout;
use AIThemeStudio\WordPress\NotFoundHandler;

defined( 'ABSPATH' ) || exit;

$ats_page_id = NotFoundHandler::page_id();
$ats_ctx     = CanvasLayout::context_for( $ats_page_id );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'ats-not-found' ); ?>>
<?php
wp_body_open();
if ( $ats_ctx ) {
	CanvasLayout::open( $ats_ctx );
} else {
	echo '<main id="ats-main" class="ats-main">';
}
CanvasLayout::render( $ats_page_id );
if ( $ats_ctx ) {
	CanvasLayout::close( $ats_ctx );
} else {
	echo '</main>';
}
wp_footer();
?>
</body>
</html>
