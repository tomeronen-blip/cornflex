<?php
/**
 * Cereal box: front-end shortcodes, assets and wizard markup.
 *
 * Shortcodes:
 *   [cereal_design_preview]                       – the full page (background, wizard, footer).
 *   [cereal_box_tool]                             – same as above (the plugin's old name).
 *   [cereal_animated_bg]...[/cereal_animated_bg]  – kept for old pages; the background
 *                                                   is now part of the page, so it only
 *                                                   renders its content.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Shortcodes that render the page.
 */
const CORNFLEX_BOX_SHORTCODES = [ 'cereal_design_preview', 'cereal_box_tool' ];

/**
 * Footer pop-ups: slug (also the URL hash, e.g. /#privacy) => title.
 * Content lives in legal/{slug}.php.
 *
 * @return array
 */
function cornflex_box_legal_pages() {
	return [
		'contact'       => 'יצירת קשר',
		'privacy'       => 'מדיניות פרטיות',
		'accessibility' => 'הצהרת נגישות',
	];
}

/**
 * Register the shortcodes.
 *
 * Runs late on init so it wins over the old WPCode snippet if that is still active.
 *
 * @return void
 */
function cornflex_box_register_shortcodes() {
	foreach ( CORNFLEX_BOX_SHORTCODES as $tag ) {
		add_shortcode( $tag, 'cornflex_box_render' );
	}

	add_shortcode(
		'cereal_animated_bg',
		function ( $atts, $content = '' ) {
			return do_shortcode( (string) $content );
		}
	);
}
add_action( 'init', 'cornflex_box_register_shortcodes', 99 );

/**
 * Register the assets, and enqueue them in the head on pages that use the shortcode
 * (so the full-screen page doesn't flash unstyled).
 *
 * @return void
 */
function cornflex_box_assets() {
	wp_register_style( 'cornflex-box', CORNFLEX_BOX_URL . '/frontend.css', [], cornflex_box_asset_version( 'frontend.css' ) );
	wp_register_script( 'cornflex-box', CORNFLEX_BOX_URL . '/frontend.js', [], cornflex_box_asset_version( 'frontend.js' ), true );
	wp_localize_script(
		'cornflex-box',
		'cornflexBox',
		[
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'requiresCode' => '' !== cornflex_box_access_code(),
		]
	);

	$post = get_post();
	if ( ! is_singular() || ! $post ) {
		return;
	}

	foreach ( CORNFLEX_BOX_SHORTCODES as $tag ) {
		if ( has_shortcode( $post->post_content, $tag ) ) {
			wp_enqueue_style( 'cornflex-box' );
			wp_enqueue_script( 'cornflex-box' );
			return;
		}
	}
}
add_action( 'wp_enqueue_scripts', 'cornflex_box_assets' );

/**
 * Background gallery images, swapped for their "medium" size when they are
 * in the media library. Cached until the gallery changes.
 *
 * @return string[]
 */
function cornflex_box_bg_images() {
	$images = get_option( 'cbg_bg_gallery_images', [] );
	if ( ! is_array( $images ) || empty( $images ) ) {
		return [];
	}

	$cache_key = 'cornflex_box_bg_' . md5( implode( '|', $images ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$optimized = [];
	foreach ( $images as $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		$thumb         = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'medium' ) : false;
		$optimized[]   = ( $thumb && ! empty( $thumb[0] ) ) ? $thumb[0] : $url;
	}

	set_transient( $cache_key, $optimized, WEEK_IN_SECONDS );

	return $optimized;
}

/**
 * Build the background columns: each column repeats the gallery in a random
 * order, never showing the same image twice in a row.
 *
 * @param string[] $images  Image URLs.
 * @param int      $columns Number of columns.
 * @return array[] One list of URLs per column.
 */
function cornflex_box_bg_columns( array $images, $columns ) {
	$count    = count( $images );
	$per_col  = max( 10, $count * 2 );
	$result   = [];

	for ( $c = 0; $c < $columns; $c++ ) {
		$items = [];
		$pool  = [];

		while ( count( $items ) < $per_col ) {
			if ( empty( $pool ) ) {
				$pool = $images;
				shuffle( $pool );
				if ( ! empty( $items ) && $count > 1 && end( $items ) === $pool[0] ) {
					$pool[0]            = $pool[ $count - 1 ];
					$pool[ $count - 1 ] = end( $items );
				}
			}
			$items[] = array_shift( $pool );
		}

		$result[] = $items;
	}

	return $result;
}

/**
 * Render the page.
 *
 * @return string
 */
function cornflex_box_render() {
	// The markup uses fixed IDs; render it once per page.
	static $rendered = false;
	if ( $rendered ) {
		return '';
	}
	$rendered = true;

	wp_enqueue_style( 'cornflex-box' );
	wp_enqueue_script( 'cornflex-box' );

	$bg_opacity = intval( get_option( 'cbg_bg_opacity', '65' ) ) / 100;
	$bg_color   = sanitize_hex_color( get_option( 'cbg_bg_overlay_color', '#050507' ) );
	if ( ! $bg_color ) {
		$bg_color = '#050507';
	}
	$bg_rgb  = implode( ', ', sscanf( $bg_color, '#%02x%02x%02x' ) );
	$overlay = intval( get_option( 'cbg_bg_overlay_strength', '78' ) ) / 100;

	// Mobile falls back to the desktop value until it is set.
	$overlay_mobile = get_option( 'cbg_bg_overlay_strength_mobile', '' );
	$overlay_mobile = '' === $overlay_mobile ? $overlay : intval( $overlay_mobile ) / 100;
	$sides          = intval( get_option( 'cbg_bg_sides_strength', '70' ) ) / 100;

	$columns = intval( get_option( 'cbg_bg_columns_desktop', '10' ) );
	if ( $columns < 6 ) {
		$columns = 10;
	}
	$speed = intval( get_option( 'cbg_bg_speed', '85' ) );
	if ( $speed <= 0 ) {
		$speed = 85;
	}

	$images   = cornflex_box_bg_images();
	$bg_cols  = $images ? cornflex_box_bg_columns( $images, $columns ) : [];
	$logo_url = 'https://www.cornflex.co.il/wp-content/uploads/2026/10/logo.png';
	$suffixes = [ 'FLAKES', 'CRUNCH', 'POPS', 'LOOPS', 'STARS', 'BITES' ];
	$arrow    = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';

	ob_start();
	?>
	<div class="cdp-wrapper" style="--cdp-speed: <?php echo esc_attr( $speed ); ?>s; --cdp-bg-rgb: <?php echo esc_attr( $bg_rgb ); ?>; --cdp-overlay: <?php echo esc_attr( $overlay ); ?>; --cdp-overlay-mobile: <?php echo esc_attr( $overlay_mobile ); ?>; --cdp-sides: <?php echo esc_attr( $sides ); ?>;">
		<div class="cdp-viewport" style="opacity: <?php echo esc_attr( $bg_opacity ); ?>;">
			<?php foreach ( $bg_cols as $c => $col_imgs ) : ?>
				<div class="cdp-col <?php echo 0 === $c % 2 ? 'direction-up' : 'direction-down'; ?>">
					<div class="cdp-track">
						<?php foreach ( $col_imgs as $img_url ) : ?>
							<div class="cdp-box-item">
								<img src="<?php echo esc_url( $img_url ); ?>" decoding="async" alt="">
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="cdp-overlay"></div>

		<div class="cdp-content-layer">
			<div class="cdp-main-screen">
				<div class="cdp-center-block">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cdp-logo-link" title="חזרה לדף הבית">
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="Cornflex" class="cdp-logo-img">
					</a>

					<div id="cdp_intro_section" style="width: 100%;">
						<h1 class="cdp-hero-title">קופסת הקורנפלקס <br class="cdp-mobile-only">של הילד.ה שלכם</h1>
						<p class="cdp-hero-subtitle">תמונה אחת, כמה שאלות קצרות וזה בדרך אליכם.</p>

						<div class="cdp-steps-row">
							<div class="cdp-step-card" data-cdp="start" role="button" tabindex="0">
								<div class="cdp-step-badge">01</div>
								<div class="cdp-step-text-title"><span class="cdp-desktop-only">בוחרים תמונה טובה</span><span class="cdp-mobile-only">בוחרים תמונה</span></div>
								<div class="cdp-step-text-sub"><span class="cdp-desktop-only">שאתם הכי-הכי אוהבים</span><span class="cdp-mobile-only">שאתם הכי<br>הכי אוהבים</span></div>
							</div>
							<div class="cdp-step-card" data-cdp="start" role="button" tabindex="0">
								<div class="cdp-step-badge">02</div>
								<div class="cdp-step-text-title"><span class="cdp-desktop-only">נותנים לנו כמה פרטים</span><span class="cdp-mobile-only">ממלאים פרטים</span></div>
								<div class="cdp-step-text-sub">שם, גיל ומה <br class="cdp-mobile-only">הם אוהבים</div>
							</div>
							<div class="cdp-step-card" data-cdp="start" role="button" tabindex="0">
								<div class="cdp-step-badge">03</div>
								<div class="cdp-step-text-title"><span class="cdp-desktop-only">מקבלים את הקופסה</span><span class="cdp-mobile-only">מקבלים הביתה</span></div>
								<div class="cdp-step-text-sub">במשלוח עד <br class="cdp-mobile-only">הבית</div>
							</div>
						</div>

						<button type="button" class="cdp-btn-solid-red" data-cdp="start">
							<span>בואו ניצור את זה</span>
							<?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</button>

						<div class="cdp-bottom-disclaimer">
							קודם תראו איך הקופסה שלכם נראית. אחרי שתאשרו, <br class="cdp-mobile-only">נדפיס אותה ונשלח אותה אליכם.
						</div>
					</div>

					<div id="cdp_wizard_container" class="cdp-wizard-container" style="display: none;">
						<div class="cdp-wizard-card">
							<div class="cdp-progress-bar">
								<?php for ( $i = 0; $i <= 4; $i++ ) : ?>
									<div class="cdp-progress-segment<?php echo 0 === $i ? ' active' : ''; ?>" id="cdp_prog_<?php echo esc_attr( $i ); ?>"></div>
								<?php endfor; ?>
							</div>

							<div class="cdp-step-viewport">
								<div class="cdp-wizard-step current" id="cdp_step_0">
									<div class="cdp-q-title">איזו תמונה תופיע על הקופסה?</div>
									<div class="cdp-q-desc">בחרו תמונה ברורה שאתם אוהבים. אנחנו כבר נדאג לשאר.</div>
									<div class="cdp-dropzone" id="cdp_dropzone" role="button" tabindex="0">
										<div id="cdp_drop_icon">
											<svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
												<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
												<circle cx="9" cy="9" r="2"/>
												<path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
											</svg>
										</div>
										<img id="cdp_preview_thumb" class="cdp-dropzone-thumb" style="display: none;" alt="התמונה שנבחרה">
										<div id="cdp_drop_text" class="cdp-dropzone-text">בחרו תמונה</div>
										<div id="cdp_drop_subtext" class="cdp-dropzone-subtext">JPG, PNG או WEBP עד 10MB</div>
										<input type="file" id="cdp_file" accept="image/jpeg,image/png,image/webp" style="display: none;">
									</div>
									<?php // Error messages; the script moves this into the current step. ?>
									<div id="cdp_alert" class="cdp-alert" role="alert"></div>
									<div class="cdp-btn-row">
										<button type="button" class="cdp-btn-next" data-cdp="next" data-step="1">המשך לשם</button>
									</div>
								</div>

								<div class="cdp-wizard-step" id="cdp_step_1">
									<div class="cdp-q-title">איזה שם הולך להיות על הקופסה?</div>
									<div class="cdp-q-desc">השם יופיע בגדול על האריזה, ממש כמו על קופסת קורנפלקס אמיתית.</div>
									<input type="text" id="cdp_name" class="cdp-input" placeholder="למשל: DANIEL" maxlength="20" autocomplete="off" style="text-transform: uppercase;">
									<div class="cdp-btn-row">
										<button type="button" class="cdp-btn-next" data-cdp="next" data-step="2">המשך לגיל</button>
									</div>
								</div>

								<div class="cdp-wizard-step" id="cdp_step_2">
									<div class="cdp-q-title">בן.בת כמה הם?</div>
									<div class="cdp-q-desc">בחרו את הגיל כדי שנוכל לתת לקופסה את הסגנון שמתאים להם.</div>
									<div class="cdp-age-stepper">
										<button type="button" class="cdp-age-btn" data-cdp="age" data-diff="-1" aria-label="פחות">-</button>
										<div class="cdp-age-value" id="cdp_age_display">8</div>
										<button type="button" class="cdp-age-btn" data-cdp="age" data-diff="1" aria-label="יותר">+</button>
									</div>
									<div class="cdp-btn-row">
										<button type="button" class="cdp-btn-next" data-cdp="next" data-step="3">המשך לקורנפלקס</button>
									</div>
								</div>

								<div class="cdp-wizard-step" id="cdp_step_3">
									<div class="cdp-q-title">מה יופיע ליד השם שלהם?</div>
									<div class="cdp-q-desc">בחרו מה לדעתכם שהכי מתאים להם.</div>
									<div class="cdp-chips-grid">
										<?php foreach ( $suffixes as $i => $suffix ) : ?>
											<button type="button" class="cdp-chip-btn<?php echo 0 === $i ? ' selected' : ''; ?>" data-cdp="suffix" data-suffix="<?php echo esc_attr( $suffix ); ?>"><span class="cdp-chip-name-slot">DANIEL</span> <?php echo esc_html( $suffix ); ?></button>
										<?php endforeach; ?>
									</div>
									<div class="cdp-btn-row">
										<button type="button" class="cdp-btn-next" data-cdp="next" data-step="4">המשך לעולמות התוכן</button>
									</div>
								</div>

								<div class="cdp-wizard-step" id="cdp_step_4">
									<div class="cdp-q-title">מה הכי עושה להם את זה?</div>
									<div class="cdp-q-desc">ספרו לנו על משהו שהם אוהבים, ואנחנו נשלב אותו בעיצוב הקופסה.</div>
									<input type="text" id="cdp_hobby" class="cdp-input" placeholder="למשל: כדורגל, חלל, לגו, נסיכות…" maxlength="120">
									<div class="cdp-btn-row">
										<button type="button" class="cdp-btn-next" data-cdp="generate">תראו לי את הקופסה!</button>
									</div>
								</div>
							</div>

							<div id="cdp_loader_box" class="cdp-loader-wrap">
								<div class="cdp-custom-loader"></div>
								<div class="cdp-q-title">מכינים את הקופסה שלכם!</div>
								<div id="cdp_loader_timer" style="font-size: 15px; font-weight: 700; color: #ffffff; margin-bottom: 6px; font-variant-numeric: tabular-nums;">זמן משוער: 02:00</div>
								<div id="cdp_loader_step_sub" style="font-size: 14px; color: #94a3b8;">התמונה בפנים...</div>
							</div>

							<div id="cdp_result_box" class="cdp-result-wrap">
								<div style="font-size: 22px; font-weight: 800; color: #ffffff; margin-bottom: 6px;">הקופסה שלכם מוכנה. <br class="cdp-mobile-only">מה אומרים?</div>
								<div style="font-size: 14.5px; color: #cbd5e1; margin-bottom: 18px;">ככה היא הולכת להיראות. אהבתם? <br class="cdp-mobile-only">בואו נשלח אותה אליכם.</div>

								<img id="cdp_result_display" class="cdp-result-img" src="" alt="קופסת קורנפלקס אישית" draggable="false" oncontextmenu="return false;">

								<button type="button" class="cdp-btn-solid-red" data-cdp="order">
									<span>אני רוצה אותה הביתה</span>
									<?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</button>

								<button type="button" class="cdp-btn-retry-text" data-cdp="restart">בא לי לנסות עיצוב אחר</button>
							</div>
						</div>

						<button type="button" id="cdp_back_outside_btn" class="cdp-btn-back-outside" data-cdp="back">חזור אחורה</button>
					</div>
				</div>
			</div>

			<footer class="cdp-site-footer">
				<div class="cdp-footer-social">
					<a href="https://instagram.com" target="_blank" rel="noopener" aria-label="Instagram">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>
					</a>
					<a href="https://facebook.com" target="_blank" rel="noopener" aria-label="Facebook">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
					</a>
					<a href="https://tiktok.com" target="_blank" rel="noopener" aria-label="TikTok">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12a4 4 0 1 0 4 4V4a5 5 0 0 0 5 5"/></svg>
					</a>
				</div>
				<div class="cdp-footer-links">
					<?php
					$legal_links = [];
					foreach ( cornflex_box_legal_pages() as $slug => $title ) {
						$legal_links[] = '<a href="#' . esc_attr( $slug ) . '" data-cdp="legal" data-legal="' . esc_attr( $slug ) . '">' . esc_html( $title ) . '</a>';
					}
					echo implode( '<span class="cdp-sep">|</span>', $legal_links ); // phpcs:ignore WordPress.Security.EscapeOutput
					?>
				</div>
				<p class="cdp-footer-disclaimer">
					Cornflex הוא שירות להתאמה אישית של מוצרי מתנה ומזכרות. כל הזכויות על המותג, העיצובים והטכנולוגיה שמורות. המשתמשים אחראים לכך שהתמונות והתכנים שהם מעלים מותרים לשימוש ואינם מפרים זכויות של צד שלישי.
				</p>
				<div class="cdp-footer-copy">
					© 2026 תומר רונן. כל הזכויות שמורות.
				</div>
			</footer>
		</div>
	</div>

	<div id="cdp_code_modal" class="cdp-toast-modal" role="dialog" aria-modal="true" aria-labelledby="cdp_code_title">
		<div class="cdp-toast-card">
			<div class="cdp-toast-title" id="cdp_code_title">רגע לפני שמתחילים</div>
			<div class="cdp-toast-desc">הזינו את קוד הגישה כדי ליצור את הקופסה.</div>
			<input type="password" id="cdp_code_input" class="cdp-input cdp-code-input" inputmode="numeric" autocomplete="off" placeholder="קוד גישה">
			<div id="cdp_code_error" class="cdp-code-error">הקוד שגוי. נסו שוב.</div>
			<div class="cdp-btn-row">
				<button type="button" class="cdp-btn-next" data-cdp="code-submit">יוצרים את הקופסה</button>
				<button type="button" class="cdp-btn-back-outside" data-cdp="code-cancel">ביטול</button>
			</div>
		</div>
	</div>

	<div id="cdp_toast_modal" class="cdp-toast-modal" role="dialog" aria-modal="true">
		<div class="cdp-toast-card">
			<div class="cdp-toast-title">יש! הקופסה בדרך אליכם</div>
			<div class="cdp-toast-desc">ההזמנה נקלטה בהצלחה. אנחנו נדפיס את הקופסה שלכם ונשלח אותה עד הבית.</div>
			<div class="cdp-btn-row">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="cdp-btn-next">חזרה לאתר</a>
			</div>
		</div>
	</div>
	<?php foreach ( cornflex_box_legal_pages() as $slug => $title ) : ?>
		<div id="cdp_legal_<?php echo esc_attr( $slug ); ?>" class="cdp-toast-modal cdp-legal-modal" role="dialog" aria-modal="true" aria-labelledby="cdp_legal_<?php echo esc_attr( $slug ); ?>_title">
			<div class="cdp-toast-card cdp-legal-card">
				<button type="button" class="cdp-legal-close" data-cdp="legal-close" aria-label="סגירה">&times;</button>
				<div class="cdp-toast-title" id="cdp_legal_<?php echo esc_attr( $slug ); ?>_title"><?php echo esc_html( $title ); ?></div>
				<div class="cdp-legal-body">
					<?php include CORNFLEX_BOX_DIR . '/legal/' . $slug . '.php'; ?>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
	<?php
	return ob_get_clean();
}
