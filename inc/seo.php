<?php
/**
 * Page title, meta description and share tags (Open Graph / Twitter).
 *
 * Replaces Yoast SEO. Stays off while Yoast is still active, so the tags
 * aren't printed twice.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( defined( 'WPSEO_VERSION' ) ) {
	return;
}

/**
 * Site-wide SEO texts.
 *
 * @return array
 */
function cornflex_seo() {
	return [
		'title'       => 'קורנפלקס | Cornflex',
		'description' => 'מה אם הייתה להם קופסת קורנפלקס משלהם? מעלים תמונה, מעצבים אריזת דגני בוקר בעיצוב אישי ומקבלים קופסה אמיתית עד הבית. המתנה המושלמת לילדים!',
		'image'       => 'https://www.cornflex.co.il/wp-content/uploads/2026/10/share.jpg',
		'image_w'     => 1200,
		'image_h'     => 630,
	];
}

/**
 * Browser tab title.
 *
 * @param string $title Title WordPress would use.
 * @return string
 */
function cornflex_document_title( $title ) {
	$seo = cornflex_seo();

	if ( is_front_page() ) {
		return $seo['title'];
	}

	$page_title = wp_strip_all_tags( cornflex_page_title_part() );

	return $page_title ? $page_title . ' | ' . $seo['title'] : $seo['title'];
}
add_filter( 'pre_get_document_title', 'cornflex_document_title' );

/**
 * Title of the current page alone, for inner pages.
 *
 * @return string
 */
function cornflex_page_title_part() {
	if ( is_singular() ) {
		return single_post_title( '', false );
	}
	if ( is_404() ) {
		return 'הדף לא נמצא';
	}
	if ( is_search() ) {
		return 'חיפוש';
	}

	return '';
}

/**
 * Meta description and share tags in <head>.
 *
 * @return void
 */
function cornflex_seo_head() {
	$seo   = cornflex_seo();
	$title = wp_get_document_title();
	$url   = is_front_page() ? home_url( '/' ) : get_permalink();

	$tags = [
		[ 'name', 'description', $seo['description'] ],
		[ 'property', 'og:locale', 'he_IL' ],
		[ 'property', 'og:type', 'website' ],
		[ 'property', 'og:site_name', $seo['title'] ],
		[ 'property', 'og:title', $title ],
		[ 'property', 'og:description', $seo['description'] ],
		[ 'property', 'og:url', $url ],
		[ 'property', 'og:image', $seo['image'] ],
		[ 'property', 'og:image:width', $seo['image_w'] ],
		[ 'property', 'og:image:height', $seo['image_h'] ],
		[ 'property', 'og:image:type', 'image/jpeg' ],
		[ 'name', 'twitter:card', 'summary_large_image' ],
		[ 'name', 'twitter:title', $title ],
		[ 'name', 'twitter:description', $seo['description'] ],
		[ 'name', 'twitter:image', $seo['image'] ],
	];

	foreach ( $tags as list( $attr, $key, $value ) ) {
		printf( '<meta %s="%s" content="%s">' . "\n", $attr, esc_attr( $key ), esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	if ( $url ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
	}
}
add_action( 'wp_head', 'cornflex_seo_head', 1 );

// WordPress prints its own canonical on singular pages; ours replaces it.
remove_action( 'wp_head', 'rel_canonical' );
