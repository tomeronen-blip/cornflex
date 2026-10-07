<?php
/**
 * Template Name: Cornflex Canvas
 * Template Post Type: page
 *
 * Blank page: no theme header, footer or page title – only the content.
 * Replaces Elementor's "Canvas" template so the site doesn't need Elementor.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'cornflex-canvas' ); ?>>
<?php
wp_body_open();

while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

wp_footer();
?>
</body>
</html>
