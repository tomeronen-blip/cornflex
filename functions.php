<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

/**
 * Google Sans, site-wide (moved here from the WPCode header).
 *
 * @return void
 */
function cornflex_google_font() {
	wp_enqueue_style( 'cornflex-google-sans', 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
}
add_action( 'wp_enqueue_scripts', 'cornflex_google_font', 5 );

/**
 * Preconnect to Google Fonts so the font starts loading sooner.
 *
 * @param array  $urls          URLs to print for resource hints.
 * @param string $relation_type The relation type.
 * @return array
 */
function cornflex_font_preconnect( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = 'https://fonts.googleapis.com';
		$urls[] = [
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		];
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'cornflex_font_preconnect', 10, 2 );

require_once get_stylesheet_directory() . '/inc/claude-access.php';
require_once get_stylesheet_directory() . '/inc/canvas-template.php';
require_once get_stylesheet_directory() . '/inc/seo.php';
require_once get_stylesheet_directory() . '/inc/updater.php';
require_once get_stylesheet_directory() . '/inc/cereal-box/cereal-box.php';

