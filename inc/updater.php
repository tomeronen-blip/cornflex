<?php
/**
 * Update the theme from GitHub with one click.
 *
 * Cornflex → עדכון מ-GitHub shows the deployed commit and the latest one on
 * the main branch. "עדכן עכשיו" downloads that commit, swaps it in for the
 * theme folder, checks the home page still loads, and rolls back if not.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const CORNFLEX_UPDATER_REPO   = 'tomeronen-blip/cornflex';
const CORNFLEX_UPDATER_BRANCH = 'main';
const CORNFLEX_UPDATER_OPTION = 'cornflex_updater_state';

/**
 * Admin page under the Cornflex menu.
 *
 * @return void
 */
function cornflex_updater_menu() {
	add_submenu_page( 'cereal-box-settings', 'עדכון מ-GitHub', 'עדכון מ-GitHub', 'manage_options', 'cornflex-updater', 'cornflex_updater_page' );
}
add_action( 'admin_menu', 'cornflex_updater_menu', 20 );

/**
 * Latest commit on the branch, from the GitHub API.
 *
 * @return array|WP_Error { sha, message, date }
 */
function cornflex_updater_latest_commit() {
	$res = wp_remote_get(
		'https://api.github.com/repos/' . CORNFLEX_UPDATER_REPO . '/commits/' . CORNFLEX_UPDATER_BRANCH,
		[
			'timeout' => 15,
			'headers' => [ 'Accept' => 'application/vnd.github+json' ],
		]
	);

	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $data['sha'] ) ) {
		return new WP_Error( 'github', isset( $data['message'] ) ? $data['message'] : 'GitHub לא החזיר תשובה תקינה.' );
	}

	return [
		'sha'     => $data['sha'],
		'message' => strtok( (string) $data['commit']['message'], "\n" ),
		'date'    => $data['commit']['committer']['date'],
	];
}

/**
 * Does the site's home page load without a fatal error?
 *
 * @return true|string True (or couldn't check), or what went wrong.
 */
function cornflex_updater_site_ok() {
	$res = wp_remote_get(
		add_query_arg( 'cornflex_updater_check', time(), home_url( '/' ) ),
		[
			'timeout'   => 30,
			'sslverify' => false,
		]
	);

	// Some hosts block requests from the site to itself. That says nothing
	// about the new code, so don't roll back over it.
	if ( is_wp_error( $res ) ) {
		return true;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );

	if ( $code >= 500 || false !== stripos( $body, 'There has been a critical error' ) ) {
		return 'דף הבית החזיר שגיאה (' . $code . ').';
	}

	return true;
}

/**
 * Download a commit and swap it in for the theme folder.
 *
 * @param string $sha Commit SHA.
 * @return true|WP_Error
 */
function cornflex_updater_deploy( $sha ) {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( ! WP_Filesystem() || 'direct' !== $wp_filesystem->method ) {
		return new WP_Error( 'fs', 'אין לשרת הרשאת כתיבה ישירה לקבצים, אז אי אפשר לעדכן מכאן.' );
	}

	$theme_dir = get_stylesheet_directory();
	$work_dir  = WP_CONTENT_DIR . '/upgrade/cornflex-' . time();
	$backup    = $work_dir . '-backup';

	$zip = download_url( 'https://codeload.github.com/' . CORNFLEX_UPDATER_REPO . '/zip/' . $sha, 120 );
	if ( is_wp_error( $zip ) ) {
		return $zip;
	}

	$unzipped = unzip_file( $zip, $work_dir );
	wp_delete_file( $zip );
	if ( is_wp_error( $unzipped ) ) {
		$wp_filesystem->delete( $work_dir, true );
		return $unzipped;
	}

	// GitHub zips hold a single folder: {repo}-{sha}.
	$inner = glob( $work_dir . '/*', GLOB_ONLYDIR );
	$new   = $inner ? $inner[0] : '';
	if ( ! $new || ! file_exists( $new . '/style.css' ) || ! file_exists( $new . '/functions.php' ) ) {
		$wp_filesystem->delete( $work_dir, true );
		return new WP_Error( 'zip', 'הקובץ שהורד מ-GitHub לא נראה כמו התבנית.' );
	}

	// Swap: current theme → backup, new files → theme folder.
	if ( ! $wp_filesystem->move( $theme_dir, $backup ) ) {
		$wp_filesystem->delete( $work_dir, true );
		return new WP_Error( 'move', 'לא הצלחתי להזיז את התבנית הנוכחית.' );
	}
	if ( ! $wp_filesystem->move( $new, $theme_dir ) ) {
		$wp_filesystem->move( $backup, $theme_dir );
		$wp_filesystem->delete( $work_dir, true );
		return new WP_Error( 'move', 'לא הצלחתי להעתיק את הקבצים החדשים. התבנית הקודמת הוחזרה.' );
	}

	if ( function_exists( 'opcache_reset' ) ) {
		opcache_reset();
	}

	// Roll back if the new code breaks the site.
	$check = cornflex_updater_site_ok();
	if ( true !== $check ) {
		$wp_filesystem->move( $theme_dir, $work_dir . '-broken' );
		$wp_filesystem->move( $backup, $theme_dir );
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}
		$wp_filesystem->delete( $work_dir, true );
		$wp_filesystem->delete( $work_dir . '-broken', true );
		return new WP_Error( 'broken', 'הגרסה החדשה שברה את האתר, אז הוחזרה הגרסה הקודמת. ' . $check );
	}

	$wp_filesystem->delete( $work_dir, true );
	$wp_filesystem->delete( $backup, true );

	return true;
}

/**
 * Handle the update button.
 *
 * @return void
 */
function cornflex_updater_handle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	check_admin_referer( 'cornflex_updater' );

	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	$latest = cornflex_updater_latest_commit();
	$result = is_wp_error( $latest ) ? $latest : cornflex_updater_deploy( $latest['sha'] );

	if ( true === $result ) {
		update_option(
			CORNFLEX_UPDATER_OPTION,
			[
				'sha'     => $latest['sha'],
				'message' => $latest['message'],
				'time'    => time(),
			],
			false
		);
		$notice = 'updated';
	} else {
		set_transient( 'cornflex_updater_error', $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		$notice = 'failed';
	}

	wp_safe_redirect( admin_url( 'admin.php?page=cornflex-updater&cornflex_update=' . $notice ) );
	exit;
}
add_action( 'admin_post_cornflex_update', 'cornflex_updater_handle' );

/**
 * The admin page.
 *
 * @return void
 */
function cornflex_updater_page() {
	$state  = get_option( CORNFLEX_UPDATER_OPTION, [] );
	$latest = cornflex_updater_latest_commit();
	$format = get_option( 'date_format' ) . ' H:i';
	$notice = isset( $_GET['cornflex_update'] ) ? sanitize_key( $_GET['cornflex_update'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$error  = get_transient( 'cornflex_updater_error' );

	$up_to_date = ! is_wp_error( $latest ) && ! empty( $state['sha'] ) && $state['sha'] === $latest['sha'];
	?>
	<div class="wrap" style="direction: rtl; text-align: right; max-width: 760px;">
		<h1>עדכון מ-GitHub</h1>

		<?php if ( 'updated' === $notice ) : ?>
			<div class="notice notice-success"><p>האתר עודכן לגרסה האחרונה.</p></div>
		<?php elseif ( 'failed' === $notice && $error ) : ?>
			<div class="notice notice-error"><p>העדכון נכשל: <?php echo esc_html( $error ); ?></p></div>
			<?php delete_transient( 'cornflex_updater_error' ); ?>
		<?php endif; ?>

		<div style="background: #fff; padding: 24px; border: 1px solid #e4e4e4; border-radius: 12px; margin-top: 20px;">
			<p style="margin-top: 0;">
				<strong>באתר עכשיו:</strong>
				<?php if ( ! empty( $state['sha'] ) ) : ?>
					<code><?php echo esc_html( substr( $state['sha'], 0, 7 ) ); ?></code>
					<?php echo esc_html( $state['message'] ); ?>
					<span style="color: #71717a;">(עודכן <?php echo esc_html( wp_date( $format, $state['time'] ) ); ?>)</span>
				<?php else : ?>
					<span style="color: #71717a;">לא ידוע – עדיין לא עודכן מכאן.</span>
				<?php endif; ?>
			</p>

			<p>
				<strong>אחרון ב-GitHub:</strong>
				<?php if ( is_wp_error( $latest ) ) : ?>
					<span style="color: #dc2626;">לא הצלחתי לבדוק: <?php echo esc_html( $latest->get_error_message() ); ?></span>
				<?php else : ?>
					<code><?php echo esc_html( substr( $latest['sha'], 0, 7 ) ); ?></code>
					<?php echo esc_html( $latest['message'] ); ?>
					<span style="color: #71717a;">(<?php echo esc_html( wp_date( $format, strtotime( $latest['date'] ) ) ); ?>)</span>
				<?php endif; ?>
			</p>

			<?php if ( $up_to_date ) : ?>
				<p style="color: #16a34a; font-weight: 600;">האתר מעודכן.</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').innerText = 'מעדכן...';">
				<input type="hidden" name="action" value="cornflex_update">
				<?php wp_nonce_field( 'cornflex_updater' ); ?>
				<button type="submit" class="button button-primary button-hero"<?php disabled( is_wp_error( $latest ) ); ?>>עדכן עכשיו</button>
			</form>

			<p class="description" style="margin-top: 16px;">מוריד את הגרסה האחרונה מ-<?php echo esc_html( CORNFLEX_UPDATER_REPO ); ?> (<?php echo esc_html( CORNFLEX_UPDATER_BRANCH ); ?>) ומחליף את קבצי התבנית. אחרי ההחלפה נבדק שדף הבית עולה; אם לא, הגרסה הקודמת חוזרת אוטומטית.</p>
		</div>
	</div>
	<?php
}
