<?php
/**
 * Claude access token.
 *
 * Adds Tools → Claude Access, where an administrator can generate a
 * time-limited token. A request carrying the token (X-Claude-Token header,
 * or Authorization: Bearer) is authenticated as the administrator who
 * generated it, for REST requests only. It also unlocks:
 *
 *   GET  /wp-json/claude/v1/ping  – verify the token, basic site info.
 *   POST /wp-json/claude/v1/sql   – run a raw SQL query: {"query": "..."}.
 *
 * Only a SHA-256 hash of the token is stored. Revoking or expiry kills it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'CLAUDE_ACCESS_OPTION', 'claude_access_token' );

/**
 * Return the stored token record if it exists and has not expired.
 *
 * @return array|null { hash, user_id, expires, created, last_used }
 */
function claude_access_get_record() {
	$record = get_option( CLAUDE_ACCESS_OPTION );

	if ( ! is_array( $record ) || empty( $record['hash'] ) ) {
		return null;
	}

	if ( time() > (int) $record['expires'] ) {
		delete_option( CLAUDE_ACCESS_OPTION );
		return null;
	}

	return $record;
}

/**
 * Read the token sent with the current request, if any.
 *
 * @return string
 */
function claude_access_request_token() {
	if ( ! empty( $_SERVER['HTTP_X_CLAUDE_TOKEN'] ) ) {
		return trim( wp_unslash( $_SERVER['HTTP_X_CLAUDE_TOKEN'] ) );
	}

	foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) && 0 === stripos( $_SERVER[ $key ], 'Bearer ' ) ) {
			return trim( substr( wp_unslash( $_SERVER[ $key ] ), 7 ) );
		}
	}

	return '';
}

/**
 * Whether the current request is a REST API request.
 *
 * Runs before WordPress parses the request, so it inspects the URL directly.
 *
 * @return bool
 */
function claude_access_is_rest_request() {
	if ( ! empty( $_GET['rest_route'] ) ) {
		return true;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
}

/**
 * Authenticate REST requests that carry a valid token as the token's owner.
 *
 * @param int|false $user_id User ID determined so far.
 * @return int|false
 */
function claude_access_determine_user( $user_id ) {
	// user_can() and update_option() can fire hooks that call
	// wp_get_current_user(), which re-runs this filter. Guard against recursion.
	static $running = false;

	if ( $running || $user_id || ! claude_access_is_rest_request() ) {
		return $user_id;
	}

	$token = claude_access_request_token();
	if ( '' === $token ) {
		return $user_id;
	}

	$record = claude_access_get_record();
	if ( ! $record || ! hash_equals( $record['hash'], hash( 'sha256', $token ) ) ) {
		return $user_id;
	}

	$running = true;

	$allowed = user_can( (int) $record['user_id'], 'manage_options' );
	if ( $allowed ) {
		$record['last_used'] = time();
		update_option( CLAUDE_ACCESS_OPTION, $record, false );
	}

	$running = false;

	return $allowed ? (int) $record['user_id'] : $user_id;
}
add_filter( 'determine_current_user', 'claude_access_determine_user', 30 );

/**
 * Register the claude/v1 REST routes.
 *
 * @return void
 */
function claude_access_register_routes() {
	$permission = function () {
		return null !== claude_access_get_record()
			&& '' !== claude_access_request_token()
			&& current_user_can( 'manage_options' );
	};

	register_rest_route(
		'claude/v1',
		'/ping',
		[
			'methods'             => 'GET',
			'permission_callback' => $permission,
			'callback'            => function () {
				global $wpdb;

				return [
					'site'       => home_url(),
					'user'       => wp_get_current_user()->user_login,
					'wp_version' => get_bloginfo( 'version' ),
					'php'        => PHP_VERSION,
					'db_prefix'  => $wpdb->prefix,
					'theme'      => get_stylesheet(),
					'plugins'    => get_option( 'active_plugins' ),
					'limits'     => [
						'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
						'post_max_size'       => ini_get( 'post_max_size' ),
						'memory_limit'        => ini_get( 'memory_limit' ),
						'max_execution_time'  => ini_get( 'max_execution_time' ),
						'finish_request'      => function_exists( 'fastcgi_finish_request' ) ? 'fastcgi' : ( function_exists( 'litespeed_finish_request' ) ? 'litespeed' : 'none' ),
					],
				];
			},
		]
	);

	register_rest_route(
		'claude/v1',
		'/sql',
		[
			'methods'             => 'POST',
			'permission_callback' => $permission,
			'args'                => [
				'query' => [
					'type'     => 'string',
					'required' => true,
				],
			],
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;

				$query = trim( $request->get_param( 'query' ) );

				if ( preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $query ) ) {
					$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB
					$data = [ 'rows' => $rows, 'count' => is_array( $rows ) ? count( $rows ) : 0 ];
				} else {
					$affected = $wpdb->query( $query ); // phpcs:ignore WordPress.DB
					$data     = [ 'affected' => $affected, 'insert_id' => $wpdb->insert_id ];
				}

				if ( $wpdb->last_error ) {
					return new WP_Error( 'claude_sql_error', $wpdb->last_error, [ 'status' => 400 ] );
				}

				return $data;
			},
		]
	);
}
add_action( 'rest_api_init', 'claude_access_register_routes' );

/**
 * Add Tools → Claude Access.
 *
 * @return void
 */
function claude_access_admin_menu() {
	add_management_page( 'Claude Access', 'Claude Access', 'manage_options', 'claude-access', 'claude_access_admin_page' );
}
add_action( 'admin_menu', 'claude_access_admin_menu' );

/**
 * Render the admin page and handle generate / revoke.
 *
 * @return void
 */
function claude_access_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$new_token = '';
	$durations = [
		HOUR_IN_SECONDS     => 'שעה',
		DAY_IN_SECONDS      => '24 שעות',
		WEEK_IN_SECONDS     => 'שבוע',
		30 * DAY_IN_SECONDS => '30 יום',
	];

	if ( isset( $_POST['claude_access_action'] ) && check_admin_referer( 'claude_access' ) ) {
		if ( 'generate' === $_POST['claude_access_action'] ) {
			$duration = isset( $_POST['duration'] ) ? (int) $_POST['duration'] : DAY_IN_SECONDS;
			if ( ! isset( $durations[ $duration ] ) ) {
				$duration = DAY_IN_SECONDS;
			}

			$new_token = 'cfx_' . bin2hex( random_bytes( 32 ) );

			update_option(
				CLAUDE_ACCESS_OPTION,
				[
					'hash'      => hash( 'sha256', $new_token ),
					'user_id'   => get_current_user_id(),
					'created'   => time(),
					'expires'   => time() + $duration,
					'last_used' => 0,
				],
				false
			);
		} elseif ( 'revoke' === $_POST['claude_access_action'] ) {
			delete_option( CLAUDE_ACCESS_OPTION );
		}
	}

	$record = claude_access_get_record();
	$format = get_option( 'date_format' ) . ' H:i';
	?>
	<div class="wrap">
		<h1>Claude Access</h1>

		<?php if ( ! is_ssl() ) : ?>
			<div class="notice notice-error"><p>האתר לא נטען ב-HTTPS. אל תשתמש בטוקן בלי HTTPS.</p></div>
		<?php endif; ?>

		<?php if ( $new_token ) : ?>
			<div class="notice notice-success">
				<p><strong>הטוקן נוצר. העתק אותו עכשיו – הוא לא יוצג שוב:</strong></p>
				<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select()"></p>
				<p>כתובת האתר: <code><?php echo esc_html( home_url() ); ?></code></p>
			</div>
		<?php endif; ?>

		<?php if ( $record ) : ?>
			<p>
				יש טוקן פעיל. פג תוקף: <strong><?php echo esc_html( wp_date( $format, $record['expires'] ) ); ?></strong>.
				שימוש אחרון: <?php echo $record['last_used'] ? esc_html( wp_date( $format, $record['last_used'] ) ) : 'עדיין לא'; ?>.
			</p>
			<form method="post">
				<?php wp_nonce_field( 'claude_access' ); ?>
				<input type="hidden" name="claude_access_action" value="revoke">
				<?php submit_button( 'בטל טוקן', 'delete', 'submit', false ); ?>
			</form>
			<hr>
		<?php else : ?>
			<p>אין טוקן פעיל.</p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'claude_access' ); ?>
			<input type="hidden" name="claude_access_action" value="generate">
			<p>
				<label>תוקף:
					<select name="duration">
						<?php foreach ( $durations as $seconds => $label ) : ?>
							<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $seconds, DAY_IN_SECONDS ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<?php submit_button( $record ? 'צור טוקן חדש (מחליף את הקיים)' : 'צור טוקן', 'primary', 'submit', false ); ?>
		</form>

		<p class="description">הטוקן נותן גישת מנהל מלאה לאתר ולמסד הנתונים. בטל אותו כשסיימת.</p>
	</div>
	<?php
}
