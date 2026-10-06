<?php
/**
 * Cereal box: default prompt, DB table and registered settings.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Access code used when none has been saved yet (the plugin's hard-coded one).
 */
define( 'CORNFLEX_BOX_DEFAULT_ACCESS_CODE', '1114' );

/**
 * Default prompt template. Placeholders: {name}, {age}, {suffix}, {hobby}.
 *
 * @return string
 */
function cornflex_box_default_prompt_template() {
	return "Flat 2D commercial cereal box front cover artwork only, direct frontal view, print-ready sticker layout, absolutely no 3D box mockup, no packaging angles, no perspective distortion.

Upper-body medium close-up portrait of the {age}-year-old child from the reference image, sitting happily at a table eating cereal with a spoon from a vibrant bowl. Realistic commercial studio photography style, keeping strict facial likeness, exact eye shape, authentic nose structure, natural skin texture, and genuine recognizable smile from the photo, enhanced with polished commercial advertisement lighting rather than heavy cartoonish 3D rendering. The child is prominent, large, and centered in the frame.

Theme and wardrobe styling: {hobby}.

Dynamic commercial cereal packaging background art: bold colorful graphic design, splash of fresh milk, airborne crunchy cereal pieces where the cereal shapes include custom themed novelty icons based on '{hobby}' mixed with classic crispy flakes. Energetic commercial pop aesthetic.

Clean design restrictions: No external manufacturer logos, no brand emblems, no barcodes, no nutritional label stamps, no legal text, and no clutter.

At the top center, large prominent bold 3D bubble-letter logo header reading \"{name} {suffix}\". Strict two-tone typography rule: the entire first word \"{name}\" must be rendered in one solid distinct vibrant color, and the entire second word \"{suffix}\" must be rendered in a completely different contrasting solid vibrant color, with clean solid color separation between the two words (no rainbow mixing within the same word, no muddy gradients). High-end packaging graphic art, ultra-detailed.";
}

/**
 * The saved prompt template, or the default.
 *
 * @return string
 */
function cornflex_box_prompt_template() {
	$template = get_option( 'cbg_base_prompt_template' );

	return empty( $template ) ? cornflex_box_default_prompt_template() : $template;
}

/**
 * The access code visitors must enter before generating. Empty = no code.
 *
 * @return string
 */
function cornflex_box_access_code() {
	return trim( (string) get_option( 'cbg_access_code', CORNFLEX_BOX_DEFAULT_ACCESS_CODE ) );
}

/**
 * Name of the generations log table.
 *
 * @return string
 */
function cornflex_box_table() {
	global $wpdb;

	return $wpdb->prefix . 'cereal_generations';
}

/**
 * Create the log table if missing, add columns older versions lacked,
 * and seed the prompt template.
 *
 * @return void
 */
function cornflex_box_maybe_install() {
	global $wpdb;

	$table = cornflex_box_table();

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE $table (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				child_name varchar(100) NOT NULL,
				child_age int(3) NOT NULL,
				hobby varchar(255) NOT NULL,
				source_image_url text NOT NULL,
				result_image_url text NOT NULL,
				prompt_used text NOT NULL,
				status varchar(50) DEFAULT 'success' NOT NULL,
				error_message text NOT NULL,
				PRIMARY KEY  (id)
			) $charset_collate;"
		);
	} else {
		$columns = $wpdb->get_col( "DESC $table", 0 ); // phpcs:ignore WordPress.DB
		if ( ! in_array( 'status', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN status varchar(50) DEFAULT 'success' NOT NULL" ); // phpcs:ignore WordPress.DB
		}
		if ( ! in_array( 'error_message', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN error_message text NOT NULL" ); // phpcs:ignore WordPress.DB
		}
	}

	if ( ! get_option( 'cbg_base_prompt_template' ) ) {
		update_option( 'cbg_base_prompt_template', cornflex_box_default_prompt_template() );
	}
}
add_action( 'admin_init', 'cornflex_box_maybe_install' );

/**
 * Register the settings saved through options.php.
 *
 * @return void
 */
function cornflex_box_register_settings() {
	register_setting( 'cbg_settings_group', 'cbg_gemini_key' );
	register_setting( 'cbg_settings_group', 'cbg_atlas_key' );
	register_setting( 'cbg_settings_group', 'cbg_base_prompt_template' );
	register_setting( 'cbg_settings_group', 'cbg_access_code', [ 'sanitize_callback' => 'sanitize_text_field' ] );

	register_setting( 'cbg_bg_settings_group', 'cbg_bg_opacity', [ 'sanitize_callback' => 'absint' ] );
	register_setting( 'cbg_bg_settings_group', 'cbg_bg_overlay_color', [ 'sanitize_callback' => 'sanitize_hex_color' ] );
	register_setting( 'cbg_bg_settings_group', 'cbg_bg_overlay_strength', [ 'sanitize_callback' => 'absint' ] );
	register_setting( 'cbg_bg_settings_group', 'cbg_bg_columns_desktop', [ 'sanitize_callback' => 'absint' ] );
	register_setting( 'cbg_bg_settings_group', 'cbg_bg_speed', [ 'sanitize_callback' => 'absint' ] );
}
add_action( 'admin_init', 'cornflex_box_register_settings' );
