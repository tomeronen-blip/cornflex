<?php
/**
 * Cereal box: are the paid services (Atlas, Gemini) still funded?
 *
 * Atlas reports its balance through an API, so it's shown live and an alert
 * appears when it drops below a threshold. Gemini has no balance API; when
 * its credit or quota runs out it starts refusing calls, so that refusal is
 * recorded and shown as an alert until a call succeeds again.
 *
 * Alerts: a notice on every admin page, a dot in the admin bar, and one
 * email per episode.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const CORNFLEX_BOX_ATLAS_TOPUP_URL   = 'https://www.atlascloud.ai/console/billing';
const CORNFLEX_BOX_GEMINI_BILLING_URL = 'https://aistudio.google.com/billing';

/**
 * Alert when Atlas has less than this many dollars left.
 *
 * @return float
 */
function cornflex_box_atlas_low_threshold() {
	return (float) get_option( 'cbg_atlas_low_balance', 5 );
}

/**
 * Atlas balance in USD (cached for 10 minutes).
 *
 * @param bool $fresh Skip the cache.
 * @return float|null Null when it couldn't be read.
 */
function cornflex_box_atlas_balance( $fresh = false ) {
	$cached = get_transient( 'cornflex_box_atlas_balance' );
	if ( ! $fresh && false !== $cached ) {
		return '' === $cached ? null : (float) $cached;
	}

	$key = trim( (string) get_option( 'cbg_atlas_key' ) );
	$res = '' === $key ? null : wp_remote_get(
		'https://api.atlascloud.ai/public/v1/balance',
		[
			'headers' => [ 'Authorization' => 'Bearer ' . $key ],
			'timeout' => 10,
		]
	);

	$data    = $res && ! is_wp_error( $res ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
	$balance = isset( $data['available']['value'] ) ? (float) $data['available']['value'] : null;

	// Cache failures briefly too, so a slow API doesn't slow every admin page.
	set_transient( 'cornflex_box_atlas_balance', null === $balance ? '' : (string) $balance, null === $balance ? MINUTE_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

	if ( null !== $balance ) {
		cornflex_box_maybe_email_alert( 'atlas', $balance < cornflex_box_atlas_low_threshold() );
	}

	return $balance;
}

/**
 * Record how a Gemini call went. Called for every Gemini response.
 *
 * @param array|WP_Error $res Response.
 * @return void
 */
function cornflex_box_track_gemini( $res ) {
	if ( is_wp_error( $res ) ) {
		return; // Network trouble says nothing about billing.
	}

	$code = (int) wp_remote_retrieve_response_code( $res );

	if ( 200 === $code ) {
		if ( get_option( 'cornflex_box_gemini_problem' ) ) {
			delete_option( 'cornflex_box_gemini_problem' );
			cornflex_box_maybe_email_alert( 'gemini', false );
		}
		return;
	}

	$body    = json_decode( wp_remote_retrieve_body( $res ), true );
	$message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';

	// Out of credit / over quota / billing switched off.
	if ( in_array( $code, [ 402, 429, 403 ], true ) && preg_match( '/quota|credit|prepay|billing|exhausted|depleted/i', $message ) ) {
		update_option(
			'cornflex_box_gemini_problem',
			[
				'time'    => time(),
				'message' => mb_substr( $message, 0, 300 ),
			],
			false
		);
		cornflex_box_maybe_email_alert( 'gemini', true );
	}
}

/**
 * Email the site admin once when a service runs low, and reset when it recovers.
 *
 * @param string $service atlas|gemini.
 * @param bool   $low     Whether it's low/out right now.
 * @return void
 */
function cornflex_box_maybe_email_alert( $service, $low ) {
	$flag = 'cornflex_box_alert_sent_' . $service;

	if ( ! $low ) {
		delete_option( $flag );
		return;
	}
	if ( get_option( $flag ) ) {
		return;
	}

	update_option( $flag, time(), false );

	$subject = 'atlas' === $service ? 'Cornflex: היתרה ב-Atlas נמוכה' : 'Cornflex: הקרדיט ב-Gemini נגמר';
	$body    = 'atlas' === $service
		? "היתרה ב-Atlas Cloud ירדה מתחת ל-$" . cornflex_box_atlas_low_threshold() . ". כשהיא תיגמר, יצירת הקופסאות תיעצר.\n\nלטעינה: " . CORNFLEX_BOX_ATLAS_TOPUP_URL
		: "Gemini מסרב לבקשות בגלל קרדיט או מכסה. בלי Gemini התמונות לא נבדקות והפרומפט לא משופר.\n\nלבדיקה ולטעינה: " . CORNFLEX_BOX_GEMINI_BILLING_URL;

	wp_mail( get_option( 'admin_email' ), $subject, $body );
}

/**
 * Current problems, for notices and the admin bar.
 *
 * @return array List of [ text, link, link text ].
 */
function cornflex_box_balance_problems() {
	$problems = [];

	$atlas = cornflex_box_atlas_balance();
	if ( null !== $atlas && $atlas < cornflex_box_atlas_low_threshold() ) {
		$problems[] = [
			sprintf( 'היתרה ב-Atlas נמוכה: נשארו $%s. כשהיא תיגמר, יצירת הקופסאות תיעצר.', number_format( $atlas, 2 ) ),
			CORNFLEX_BOX_ATLAS_TOPUP_URL,
			'לטעינת יתרה',
		];
	}

	$gemini = get_option( 'cornflex_box_gemini_problem' );
	if ( $gemini ) {
		$problems[] = [
			'Gemini מסרב לבקשות (קרדיט או מכסה נגמרו). בינתיים התמונות לא נבדקות והפרומפט לא משופר.',
			CORNFLEX_BOX_GEMINI_BILLING_URL,
			'לבדיקה ב-AI Studio',
		];
	}

	return $problems;
}

/**
 * Admin notice on every page while something needs topping up.
 *
 * @return void
 */
function cornflex_box_balance_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	foreach ( cornflex_box_balance_problems() as $problem ) {
		printf(
			'<div class="notice notice-error"><p><strong>Cornflex:</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
			esc_html( $problem[0] ),
			esc_url( $problem[1] ),
			esc_html( $problem[2] )
		);
	}
}
add_action( 'admin_notices', 'cornflex_box_balance_notice' );

/**
 * Admin bar: a warning item while something needs topping up.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 * @return void
 */
function cornflex_box_balance_admin_bar( $bar ) {
	if ( ! current_user_can( 'manage_options' ) || ! cornflex_box_balance_problems() ) {
		return;
	}

	$bar->add_node(
		[
			'id'    => 'cornflex-balance',
			'title' => '⚠ יתרה נמוכה',
			'href'  => admin_url( 'admin.php?page=cereal-box-settings' ),
			'meta'  => [ 'title' => 'יש שירות שצריך לטעון בו יתרה' ],
		]
	);
}
add_action( 'admin_bar_menu', 'cornflex_box_balance_admin_bar', 101 );

/**
 * Status box for the settings page.
 *
 * @return void
 */
function cornflex_box_balance_box() {
	$atlas     = cornflex_box_atlas_balance( true );
	$threshold = cornflex_box_atlas_low_threshold();
	$gemini    = get_option( 'cornflex_box_gemini_problem' );
	$format    = get_option( 'date_format' ) . ' H:i';
	?>
	<div style="background: #fff; padding: 20px 25px; border: 1px solid #e4e4e4; border-radius: 12px; margin-top: 20px;">
		<h2 style="font-size: 16px; margin: 0 0 12px;">מצב החשבונות</h2>

		<p style="margin: 0 0 10px;">
			<strong>Atlas Cloud:</strong>
			<?php if ( null === $atlas ) : ?>
				<span style="color: #71717a;">לא הצלחתי לקרוא את היתרה.</span>
			<?php else : ?>
				<span style="font-weight: 700; color: <?php echo $atlas < $threshold ? '#dc2626' : '#16a34a'; ?>;">$<?php echo esc_html( number_format( $atlas, 2 ) ); ?></span>
				<?php if ( $atlas < $threshold ) : ?>
					<span style="color: #dc2626;">– נמוכה!</span>
				<?php endif; ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( CORNFLEX_BOX_ATLAS_TOPUP_URL ); ?>" target="_blank" rel="noopener" style="margin-right: 8px;">לטעינה</a>
		</p>

		<p style="margin: 0 0 14px;">
			<strong>Gemini:</strong>
			<?php if ( $gemini ) : ?>
				<span style="color: #dc2626; font-weight: 600;">מסרב לבקשות מאז <?php echo esc_html( wp_date( $format, $gemini['time'] ) ); ?></span>
				<span style="display: block; font-size: 12px; color: #7f1d1d; margin-top: 4px; direction: ltr; text-align: left;"><?php echo esc_html( $gemini['message'] ); ?></span>
			<?php else : ?>
				<span style="color: #16a34a; font-weight: 600;">עובד</span>
				<span style="color: #71717a;">(את יתרת הקרדיט Google מציגים רק ב-AI Studio)</span>
			<?php endif; ?>
			<a href="<?php echo esc_url( CORNFLEX_BOX_GEMINI_BILLING_URL ); ?>" target="_blank" rel="noopener" style="margin-right: 8px;">לחיוב ב-AI Studio</a>
		</p>

		<form method="post" action="options.php" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
			<?php settings_fields( 'cbg_balance_group' ); ?>
			<label for="cbg_atlas_low_balance">התראה כשב-Atlas נשארים פחות מ-$</label>
			<input type="number" id="cbg_atlas_low_balance" name="cbg_atlas_low_balance" min="0" step="1" value="<?php echo esc_attr( $threshold ); ?>" style="width: 80px;">
			<?php submit_button( 'שמירה', 'secondary', 'submit', false ); ?>
		</form>
		<p style="font-size: 12px; color: #71717a; margin: 10px 0 0;">כשמשהו נגמר מופיעה התראה בראש כל עמוד בניהול ו-"⚠ יתרה נמוכה" בסרגל העליון, ונשלח מייל אחד ל-<?php echo esc_html( get_option( 'admin_email' ) ); ?>.</p>
	</div>
	<?php
}

/**
 * Register the threshold setting.
 *
 * @return void
 */
function cornflex_box_balance_settings() {
	register_setting(
		'cbg_balance_group',
		'cbg_atlas_low_balance',
		[
			'sanitize_callback' => function ( $value ) {
				delete_transient( 'cornflex_box_atlas_balance' );
				return max( 0, (float) $value );
			},
		]
	);
}
add_action( 'admin_init', 'cornflex_box_balance_settings' );
