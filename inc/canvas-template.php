<?php
/**
 * Pages still set to Elementor's "Canvas" template keep a blank layout once
 * Elementor is gone, by falling back to templates/canvas.php.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Swap in the theme's canvas template for orphaned "elementor_canvas" pages.
 *
 * @param string $template Template path WordPress picked.
 * @return string
 */
function cornflex_canvas_fallback( $template ) {
	if ( is_page() && 'elementor_canvas' === get_page_template_slug() && ! did_action( 'elementor/loaded' ) ) {
		return get_stylesheet_directory() . '/templates/canvas.php';
	}

	return $template;
}
add_filter( 'template_include', 'cornflex_canvas_fallback', 99 );
