<?php
/**
 * Cereal box generator.
 *
 * Replaces the "Cereal Box Generator Pro" plugin and the WPCode "new home"
 * snippet. Data stays where the plugin kept it: the cbg_* options and the
 * {prefix}cereal_generations table, so settings, API keys and history carry over.
 *
 * Files:
 *   setup.php     – default prompt, DB table, registered settings.
 *   admin.php     – admin menu: settings, moving background, history.
 *   generate.php  – AI pipeline (moderation, prompt, Seedream) + AJAX handler.
 *   frontend.php  – shortcodes, assets and markup of the wizard.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// The plugin uses the same option names, menu slugs and shortcodes. While it is
// still active, stay out of the way and ask for it to be deactivated.
if ( function_exists( 'cbg_handle_generate' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>מחולל הקורנפלקס עבר לתבנית. השביתו את התוסף <strong>Cereal Box Generator Pro</strong> כדי להפעיל את הגרסה החדשה.</p></div>';
			}
		}
	);
	return;
}

define( 'CORNFLEX_BOX_DIR', __DIR__ );
define( 'CORNFLEX_BOX_URL', get_stylesheet_directory_uri() . '/assets/cereal-box' );

/**
 * Asset version = file modification time, so browsers pick up changes after a pull.
 *
 * @param string $file File name in assets/cereal-box.
 * @return string
 */
function cornflex_box_asset_version( $file ) {
	$path = get_stylesheet_directory() . '/assets/cereal-box/' . $file;

	return file_exists( $path ) ? (string) filemtime( $path ) : '1';
}

require_once CORNFLEX_BOX_DIR . '/setup.php';
require_once CORNFLEX_BOX_DIR . '/admin.php';
require_once CORNFLEX_BOX_DIR . '/balance.php';
require_once CORNFLEX_BOX_DIR . '/gallery.php';
require_once CORNFLEX_BOX_DIR . '/generate.php';
require_once CORNFLEX_BOX_DIR . '/jobs.php';
require_once CORNFLEX_BOX_DIR . '/frontend.php';
