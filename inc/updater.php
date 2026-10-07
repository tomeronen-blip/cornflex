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
 * Headers for GitHub requests. With a read-only token saved on the update
 * page, the repo can be private.
 *
 * @return array
 */
function cornflex_updater_github_headers() {
	$headers = [
		'Accept'               => 'application/vnd.github+json',
		'X-GitHub-Api-Version' => '2022-11-28',
	];

	$token = trim( (string) get_option( 'cornflex_updater_token', '' ) );
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	return $headers;
}

/**
 * Save the GitHub token from the update page.
 *
 * @return void
 */
function cornflex_updater_save_token() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	check_admin_referer( 'cornflex_updater_token' );

	$token = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

	// An empty field keeps the saved token; "remove" clears it.
	if ( isset( $_POST['remove'] ) ) {
		delete_option( 'cornflex_updater_token' );
	} elseif ( '' !== $token ) {
		update_option( 'cornflex_updater_token', $token, false );
	}

	delete_transient( 'cornflex_updater_latest' );
	wp_safe_redirect( admin_url( 'admin.php?page=cornflex-updater&cornflex_token=saved' ) );
	exit;
}
add_action( 'admin_post_cornflex_updater_token', 'cornflex_updater_save_token' );

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
			'headers' => cornflex_updater_github_headers(),
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

	// The API's zipball works for private repos too (with the token).
	$zip = wp_tempnam( 'cornflex.zip' );
	$res = wp_remote_get(
		'https://api.github.com/repos/' . CORNFLEX_UPDATER_REPO . '/zipball/' . $sha,
		[
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $zip,
			'headers'  => cornflex_updater_github_headers(),
		]
	);
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		wp_delete_file( $zip );
		return is_wp_error( $res ) ? $res : new WP_Error( 'github', 'ההורדה מ-GitHub נכשלה (' . wp_remote_retrieve_response_code( $res ) . ').' );
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

	delete_transient( 'cornflex_updater_latest' );

	// From the admin bar: back to the page the button was clicked on.
	$back = isset( $_REQUEST['back'] ) ? wp_get_referer() : false; // phpcs:ignore WordPress.Security.NonceVerification
	$back = $back ? remove_query_arg( 'cornflex_update', $back ) : admin_url( 'admin.php?page=cornflex-updater' );

	wp_safe_redirect( add_query_arg( 'cornflex_update', $notice, $back ) );
	exit;
}
add_action( 'admin_post_cornflex_update', 'cornflex_updater_handle' );

/**
 * Is there a newer commit on GitHub than the one deployed?
 *
 * Asks GitHub at most every 2 minutes (it allows 60 requests an hour).
 *
 * @return bool
 */
function cornflex_updater_has_update() {
	$latest = get_transient( 'cornflex_updater_latest' );

	if ( false === $latest ) {
		$commit = cornflex_updater_latest_commit();
		$latest = is_wp_error( $commit ) ? '' : $commit['sha'];
		set_transient( 'cornflex_updater_latest', $latest, 2 * MINUTE_IN_SECONDS );
	}

	$state = get_option( CORNFLEX_UPDATER_OPTION, [] );

	return '' !== $latest && ( empty( $state['sha'] ) || $state['sha'] !== $latest );
}

/**
 * "עדכן עכשיו" in the admin bar – runs the update without opening the page.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 * @return void
 */
function cornflex_updater_admin_bar( $bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = isset( $_GET['cornflex_update'] ) ? sanitize_key( $_GET['cornflex_update'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	if ( 'updated' === $result ) {
		$title = '✓ עודכן';
	} elseif ( 'failed' === $result ) {
		$title = '✗ העדכון נכשל';
	} else {
		$title = 'עדכן עכשיו';
		if ( cornflex_updater_has_update() ) {
			$title .= ' <span class="cornflex-update-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#f43f5e;margin-right:6px;vertical-align:middle;"></span>';
		}
	}

	$bar->add_node(
		[
			'id'    => 'cornflex-update',
			'title' => $title,
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=cornflex_update&back=1' ), 'cornflex_updater' ),
			'meta'  => [
				'title'   => 'מעדכן את התבנית לגרסה האחרונה מ-GitHub',
				'onclick' => "this.innerText = 'מעדכן...';",
			],
		]
	);
}
add_action( 'admin_bar_menu', 'cornflex_updater_admin_bar', 100 );

/**
 * After an update from the admin bar, say how it went (the updater page has its own notice).
 *
 * @return void
 */
function cornflex_updater_admin_notice() {
	$result = isset( $_GET['cornflex_update'] ) ? sanitize_key( $_GET['cornflex_update'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$page   = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

	if ( '' === $result || 'cornflex-updater' === $page || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( 'updated' === $result ) {
		echo '<div class="notice notice-success is-dismissible"><p>האתר עודכן לגרסה האחרונה מ-GitHub.</p></div>';
		return;
	}

	$error = get_transient( 'cornflex_updater_error' );
	delete_transient( 'cornflex_updater_error' );
	echo '<div class="notice notice-error"><p>העדכון נכשל' . ( $error ? ': ' . esc_html( $error ) : '.' ) . '</p></div>';
}
add_action( 'admin_notices', 'cornflex_updater_admin_notice' );

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

			<?php $has_token = '' !== trim( (string) get_option( 'cornflex_updater_token', '' ) ); ?>
			<hr style="margin: 24px 0;">
			<h2 style="font-size: 15px; margin: 0 0 6px;">טוקן GitHub</h2>
			<p style="margin: 0 0 10px; color: #52525b;">
				<?php if ( $has_token ) : ?>
					<span style="color: #16a34a; font-weight: 600;">שמור טוקן.</span> העדכון עובד גם כשה-repo פרטי.
				<?php else : ?>
					<span style="color: #dc2626; font-weight: 600;">אין טוקן.</span> בלי טוקן העדכון עובד רק כשה-repo ציבורי.
				<?php endif; ?>
				טוקן Fine-grained עם הרשאת קריאה בלבד (Contents: Read-only) ל-<?php echo esc_html( CORNFLEX_UPDATER_REPO ); ?>.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: flex; gap: 8px; flex-wrap: wrap;">
				<input type="hidden" name="action" value="cornflex_updater_token">
				<?php wp_nonce_field( 'cornflex_updater_token' ); ?>
				<input type="password" name="token" autocomplete="off" placeholder="<?php echo $has_token ? 'להחלפה – הדביקו טוקן חדש' : 'github_pat_...'; ?>" style="flex: 1; min-width: 260px; direction: ltr;">
				<button type="submit" class="button">שמירה</button>
				<?php if ( $has_token ) : ?>
					<button type="submit" name="remove" value="1" class="button-link-delete" style="margin-inline-start: 8px;">מחיקת הטוקן</button>
				<?php endif; ?>
			</form>

			<p class="description" style="margin-top: 16px;">מוריד את הגרסה האחרונה מ-<?php echo esc_html( CORNFLEX_UPDATER_REPO ); ?> (<?php echo esc_html( CORNFLEX_UPDATER_BRANCH ); ?>) ומחליף את קבצי התבנית. אחרי ההחלפה נבדק שדף הבית עולה; אם לא, הגרסה הקודמת חוזרת אוטומטית.</p>
		</div>
	</div>
	<?php
}
