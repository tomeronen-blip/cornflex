<?php
/**
 * Cereal box: admin menu – settings, moving background, generation history.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Register the "מחולל קורנפלקס" menu and its sub pages.
 *
 * @return void
 */
function cornflex_box_admin_menu() {
	add_menu_page( 'מחולל קורנפלקס', 'מחולל קורנפלקס', 'manage_options', 'cereal-box-settings', 'cornflex_box_settings_page', 'dashicons-art', 30 );
	add_submenu_page( 'cereal-box-settings', 'הגדרות מערכת', 'הגדרות מערכת', 'manage_options', 'cereal-box-settings', 'cornflex_box_settings_page' );
	add_submenu_page( 'cereal-box-settings', 'הגדרות רקע נע', 'הגדרות רקע נע', 'manage_options', 'cereal-box-bg-manager', 'cornflex_box_bg_page' );
	add_submenu_page( 'cereal-box-settings', 'היסטוריית הפקות', 'היסטוריית הפקות', 'manage_options', 'cereal-box-history', 'cornflex_box_history_page' );
}
add_action( 'admin_menu', 'cornflex_box_admin_menu' );

/**
 * Assets for the moving background page.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function cornflex_box_admin_assets( $hook ) {
	if ( false === strpos( $hook, 'cereal-box-bg-manager' ) ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script(
		'cornflex-box-admin-bg',
		CORNFLEX_BOX_URL . '/admin-bg.js',
		[ 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ],
		cornflex_box_asset_version( 'admin-bg.js' ),
		true
	);
	wp_localize_script( 'cornflex-box-admin-bg', 'cornflexBoxAdmin', [ 'nonce' => wp_create_nonce( 'cornflex_box_gallery' ) ] );
}
add_action( 'admin_enqueue_scripts', 'cornflex_box_admin_assets' );

/**
 * AJAX: save the background gallery (image URLs, in order).
 *
 * @return void
 */
function cornflex_box_save_gallery() {
	check_ajax_referer( 'cornflex_box_gallery', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$images = isset( $_POST['images'] ) ? array_filter( array_map( 'esc_url_raw', (array) wp_unslash( $_POST['images'] ) ) ) : [];

	update_option( 'cbg_bg_gallery_images', array_values( $images ) );
	wp_send_json_success( 'Saved' );
}
add_action( 'wp_ajax_cbg_save_bg_gallery', 'cornflex_box_save_gallery' );

/**
 * Settings page: API keys, access code, prompt template.
 *
 * @return void
 */
function cornflex_box_settings_page() {
	$gemini      = get_option( 'cbg_gemini_key' );
	$atlas       = get_option( 'cbg_atlas_key' );
	$access_code = cornflex_box_access_code();
	?>
	<div class="wrap" style="direction: rtl; text-align: right; max-width: 850px;">
		<h1>הגדרות מחולל קורנפלקס</h1>
		<p>ניהול מפתחות API, קוד הגישה ותבנית הפרומפט המרכזית.</p>

		<form method="post" action="options.php" style="background: #fff; padding: 25px; border: 1px solid #e4e4e4; border-radius: 12px; margin-top: 20px;">
			<?php settings_fields( 'cbg_settings_group' ); ?>

			<div style="margin-bottom: 20px;">
				<label style="display: block; font-weight: 600; margin-bottom: 6px;">Google Gemini API Key:</label>
				<input type="password" name="cbg_gemini_key" value="<?php echo esc_attr( $gemini ); ?>" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #dcdcdc;" placeholder="AIzaSy...">
				<div style="margin-top: 5px; font-size: 13px;">
					<?php if ( ! empty( $gemini ) ) : ?>
						<span style="color: #16a34a; font-weight: bold;">מפתח מחובר</span>
					<?php else : ?>
						<span style="color: #dc2626; font-weight: bold;">חסר מפתח Gemini</span>
					<?php endif; ?>
				</div>
			</div>

			<div style="margin-bottom: 20px;">
				<label style="display: block; font-weight: 600; margin-bottom: 6px;">Atlas Cloud API Key:</label>
				<input type="password" name="cbg_atlas_key" value="<?php echo esc_attr( $atlas ); ?>" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #dcdcdc;" placeholder="at_...">
				<div style="margin-top: 5px; font-size: 13px;">
					<?php if ( ! empty( $atlas ) ) : ?>
						<span style="color: #16a34a; font-weight: bold;">מפתח מחובר</span>
					<?php else : ?>
						<span style="color: #dc2626; font-weight: bold;">חסר מפתח Atlas Cloud</span>
					<?php endif; ?>
				</div>
			</div>

			<div style="margin-bottom: 20px;">
				<label style="display: block; font-weight: 600; margin-bottom: 6px;">קוד גישה ליצירה:</label>
				<input type="text" name="cbg_access_code" value="<?php echo esc_attr( $access_code ); ?>" style="width: 200px; padding: 8px; border-radius: 6px; border: 1px solid #dcdcdc;">
				<p style="font-size: 13px; color: #71717a; margin: 5px 0 0;">הגולשים יתבקשו להזין את הקוד לפני ההפקה. השאירו ריק כדי לאפשר יצירה לכולם.</p>
			</div>

			<div style="margin-bottom: 20px;">
				<label style="display: block; font-weight: 600; margin-bottom: 6px;">תבנית פרומפט הבסיס:</label>
				<p style="font-size: 13px; color: #71717a; margin-top: 0;">משתנים דינמיים: <code>{name}</code>, <code>{age}</code>, <code>{suffix}</code>, <code>{hobby}</code></p>
				<textarea name="cbg_base_prompt_template" rows="12" style="width: 100%; padding: 10px; font-family: monospace; font-size: 13px; direction: ltr; border-radius: 6px; border: 1px solid #dcdcdc;"><?php echo esc_textarea( cornflex_box_prompt_template() ); ?></textarea>
			</div>

			<?php submit_button( 'שמור הגדרות ותבנית' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Moving background page: display settings + gallery manager.
 *
 * @return void
 */
function cornflex_box_bg_page() {
	$images = get_option( 'cbg_bg_gallery_images', [] );
	if ( ! is_array( $images ) ) {
		$images = [];
	}

	$bg_opacity      = get_option( 'cbg_bg_opacity', '65' );
	$overlay_color   = get_option( 'cbg_bg_overlay_color', '#050507' );
	$overlay         = get_option( 'cbg_bg_overlay_strength', '78' );
	$columns_desktop = get_option( 'cbg_bg_columns_desktop', '10' );
	$speed           = get_option( 'cbg_bg_speed', '85' );
	?>
	<div class="wrap" style="direction: rtl; text-align: right; max-width: 950px;">
		<h1>הגדרות הרקע הנע</h1>
		<p>שקיפות, צבע, מהירות, כמות עמודות ותמונות הרקע.</p>

		<form method="post" action="options.php" style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e4e4e4; margin-top: 20px;">
			<?php settings_fields( 'cbg_bg_settings_group' ); ?>

			<h2 style="font-size: 16px; margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0;">הגדרות תצוגה</h2>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
				<div>
					<label style="display: block; font-weight: 600; margin-bottom: 6px;">נראות הקופסאות ברקע (10% עד 100%):</label>
					<input type="range" name="cbg_bg_opacity" min="10" max="100" value="<?php echo esc_attr( $bg_opacity ); ?>" oninput="document.getElementById('op_val').innerText = this.value + '%'" style="width: 70%; vertical-align: middle;">
					<span id="op_val" style="font-weight: bold; margin-right: 8px;"><?php echo esc_html( $bg_opacity ); ?>%</span>
				</div>

				<div>
					<label style="display: block; font-weight: 600; margin-bottom: 6px;">כהות שכבת הכיסוי (0% עד 100%):</label>
					<input type="range" name="cbg_bg_overlay_strength" min="0" max="100" value="<?php echo esc_attr( $overlay ); ?>" oninput="document.getElementById('ov_val').innerText = this.value + '%'" style="width: 70%; vertical-align: middle;">
					<span id="ov_val" style="font-weight: bold; margin-right: 8px;"><?php echo esc_html( $overlay ); ?>%</span>
					<p style="font-size: 13px; color: #71717a; margin: 5px 0 0;">השכבה הכהה שמעל הקופסאות. פחות = רקע בהיר יותר. 78% הוא המראה המקורי.</p>
				</div>

				<div>
					<label style="display: block; font-weight: 600; margin-bottom: 6px;">צבע הרקע ושכבת הכיסוי:</label>
					<input type="text" name="cbg_bg_overlay_color" value="<?php echo esc_attr( $overlay_color ); ?>" class="cbg-color-field" data-default-color="#050507">
				</div>

				<div>
					<label style="display: block; font-weight: 600; margin-bottom: 6px;">מספר עמודות בדסקטופ:</label>
					<input type="number" name="cbg_bg_columns_desktop" min="6" max="16" value="<?php echo esc_attr( $columns_desktop ); ?>" style="width: 100px; padding: 6px; border-radius: 6px; border: 1px solid #dcdcdc;">
				</div>

				<div>
					<label style="display: block; font-weight: 600; margin-bottom: 6px;">משך סיבוב האנימציה (בשניות):</label>
					<input type="number" name="cbg_bg_speed" min="20" max="160" value="<?php echo esc_attr( $speed ); ?>" style="width: 100px; padding: 6px; border-radius: 6px; border: 1px solid #dcdcdc;">
				</div>
			</div>

			<div style="margin-top: 20px;">
				<?php submit_button( 'שמור הגדרות תצוגה', 'primary', 'submit', false ); ?>
			</div>
		</form>

		<div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e4e4e4; margin-top: 25px;">
			<h2 style="font-size: 16px; margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0;">תמונות בגלריית הרקע</h2>
			<div style="display: flex; gap: 12px; margin: 15px 0 20px 0;">
				<button type="button" id="cbg_add_images_btn" class="button button-primary">הוספת תמונות מספריית המדיה</button>
				<button type="button" id="cbg_save_images_btn" class="button button-secondary">שמירת סדר ותמונות</button>
			</div>

			<div id="cbg_save_status" style="display: none; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;"></div>

			<ul id="cbg_images_sortable" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 15px; margin: 0; padding: 0; list-style: none;">
				<?php foreach ( $images as $url ) : ?>
					<li class="cbg-sortable-item" data-url="<?php echo esc_url( $url ); ?>" style="background: #fafafa; border: 1px solid #dcdcdc; border-radius: 8px; padding: 8px; cursor: grab; text-align: center;">
						<img src="<?php echo esc_url( $url ); ?>" style="width: 100%; height: 160px; object-fit: cover; border-radius: 6px; display: block; margin-bottom: 6px;">
						<button type="button" class="button button-link-delete cbg-remove-item-btn" style="font-size: 12px;">מחק</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php
}

/**
 * History page: the 50 latest generations, with errors.
 *
 * @return void
 */
function cornflex_box_history_page() {
	global $wpdb;

	cornflex_box_maybe_install();
	$table   = cornflex_box_table();
	$records = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB
	?>
	<div class="wrap" style="direction: rtl; text-align: right; max-width: 1200px;">
		<h1>היסטוריית הפקות</h1>
		<p>כל היצירות שהופקו באתר, כולל סטטוסים ושגיאות שהוחזרו מהשרת.</p>

		<?php if ( empty( $records ) ) : ?>
			<div style="background: #fff; padding: 24px; border: 1px solid #e4e4e4; border-radius: 8px; margin-top: 20px; color: #71717a;">אין עדיין רשומות במערכת.</div>
		<?php else : ?>
			<div style="margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 20px;">
				<?php
				foreach ( $records as $item ) :
					$is_success = empty( $item['status'] ) || 'success' === $item['status'];
					?>
					<div style="background: #fff; border: 1px solid <?php echo $is_success ? '#e4e4e4' : '#fca5a5'; ?>; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
							<span style="font-size: 12px; color: #71717a;"><?php echo esc_html( $item['created_at'] ); ?></span>
							<?php if ( $is_success ) : ?>
								<span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 999px;">הופק בהצלחה</span>
							<?php else : ?>
								<span style="background: #fee2e2; color: #b91c1c; font-size: 11px; font-weight: bold; padding: 3px 8px; border-radius: 999px;">שגיאה ביצירה</span>
							<?php endif; ?>
						</div>

						<div style="display: flex; gap: 10px; margin-bottom: 12px;">
							<?php if ( ! empty( $item['result_image_url'] ) ) : ?>
								<div style="flex: 2;">
									<img src="<?php echo esc_url( $item['result_image_url'] ); ?>" style="width: 100%; height: 180px; object-fit: cover; border-radius: 6px; border: 1px solid #e4e4e4;">
									<div style="font-size: 11px; color: #71717a; text-align: center; margin-top: 4px;">חזית אריזה מקורית</div>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $item['source_image_url'] ) ) : ?>
								<div style="flex: 1;">
									<img src="<?php echo esc_url( $item['source_image_url'] ); ?>" style="width: 100%; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid #e4e4e4;">
									<div style="font-size: 10px; color: #71717a; text-align: center; margin-top: 4px;">מקור שהועלה</div>
								</div>
							<?php endif; ?>
						</div>

						<div style="font-weight: 700; font-size: 15px; color: #09090b;"><?php echo esc_html( $item['child_name'] ); ?> (גיל <?php echo esc_html( $item['child_age'] ); ?>)</div>
						<div style="font-size: 13px; color: #52525b; margin: 4px 0 10px 0;">נושא/תחביב: <?php echo esc_html( ! empty( $item['hobby'] ) ? $item['hobby'] : 'ללא' ); ?></div>

						<?php if ( ! $is_success && ! empty( $item['error_message'] ) ) : ?>
							<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px; margin-bottom: 10px;">
								<div style="font-size: 12px; font-weight: bold; color: #991b1b; margin-bottom: 4px;">סיבת השגיאה:</div>
								<div style="font-size: 11px; color: #7f1d1d; word-break: break-word; line-height: 1.4;"><?php echo esc_html( $item['error_message'] ); ?></div>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $item['prompt_used'] ) ) : ?>
							<details style="background: #f4f4f5; border-radius: 6px; padding: 8px; font-size: 12px; margin-bottom: 10px;">
								<summary style="cursor: pointer; font-weight: 600; color: #27272a;">הצג פרומפט מלא שנוצר</summary>
								<p style="margin: 8px 0 0 0; direction: ltr; font-family: monospace; font-size: 11px; color: #3f3f46; word-break: break-word;"><?php echo esc_html( $item['prompt_used'] ); ?></p>
							</details>
						<?php endif; ?>

						<?php if ( ! empty( $item['result_image_url'] ) ) : ?>
							<a href="<?php echo esc_url( $item['result_image_url'] ); ?>" target="_blank" download style="display: block; text-align: center; background: #003fa3; color: #fff; padding: 8px; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">הורדת קובץ הדפסה מקורי (איכות מלאה)</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
