<?php
/**
 * Cereal box: generation queue.
 *
 * Each generation is a row in {prefix}cereal_jobs that moves through:
 *
 *   queued → preparing → generating → finishing → done
 *                     ↘ error (any step)
 *
 * Nothing waits on the AI inside a PHP request. The visitor's browser polls
 * cbg_job_status every few seconds, and each poll moves its job at most one
 * short step: prepare (moderation + prompt + submit to Atlas, ~10s), check
 * Atlas (<1s), or finish (save + preview, a few seconds). So a PHP worker is
 * busy for seconds, not minutes, and many visitors can generate at once.
 *
 * Load is capped by two settings: how many jobs prepare at once (that step
 * uses a PHP worker for ~10s) and how many run at Atlas at once. Jobs beyond
 * that wait in line, first come first served, and the visitor sees their
 * place in the queue.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const CORNFLEX_BOX_JOB_TIMEOUT   = 600; // Give up on a job after 10 minutes.
const CORNFLEX_BOX_POLL_INTERVAL = 3;   // Ask Atlas at most this often per job.
const CORNFLEX_BOX_MAX_ATTEMPTS  = 6;   // Atlas submits before giving up on "busy".
const CORNFLEX_BOX_MOCK_SECONDS  = 40;  // How long a load-test job "generates".

/**
 * Name of the jobs table.
 *
 * @return string
 */
function cornflex_box_jobs_table() {
	global $wpdb;

	return $wpdb->prefix . 'cereal_jobs';
}

/**
 * Create the jobs table.
 *
 * @return void
 */
function cornflex_box_install_jobs_table() {
	global $wpdb;

	$table           = cornflex_box_jobs_table();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE $table (
			id varchar(40) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			created_at int(10) unsigned NOT NULL DEFAULT 0,
			updated_at int(10) unsigned NOT NULL DEFAULT 0,
			not_before int(10) unsigned NOT NULL DEFAULT 0,
			locked_until int(10) unsigned NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			input longtext NOT NULL,
			atlas_id varchar(64) NOT NULL DEFAULT '',
			prompt text NOT NULL,
			preview_url text NOT NULL,
			message text NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at)
		) $charset_collate;"
	);
}

/**
 * Max jobs in the "preparing" step at once.
 *
 * @return int
 */
function cornflex_box_max_preparing() {
	return max( 1, (int) get_option( 'cbg_max_preparing', 4 ) );
}

/**
 * Max jobs running at Atlas at once.
 *
 * @return int
 */
function cornflex_box_max_generating() {
	return max( 1, (int) get_option( 'cbg_max_generating', 30 ) );
}

/**
 * Read a job.
 *
 * @param string $id Job ID.
 * @return array|null
 */
function cornflex_box_job_get( $id ) {
	global $wpdb;

	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . cornflex_box_jobs_table() . ' WHERE id = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $job ) {
		return null;
	}

	$job['input'] = (array) json_decode( $job['input'], true );

	return $job;
}

/**
 * Update job columns.
 *
 * @param string $id     Job ID.
 * @param array  $fields Column => value.
 * @return void
 */
function cornflex_box_job_update( $id, array $fields ) {
	global $wpdb;

	$fields['updated_at'] = time();
	$wpdb->update( cornflex_box_jobs_table(), $fields, [ 'id' => $id ] );
}

/**
 * Take the job's lock for a step, atomically. Only one request runs a step.
 *
 * @param string $id      Job ID.
 * @param int    $seconds Lock length (longer than the step can take).
 * @return bool
 */
function cornflex_box_job_lock( $id, $seconds ) {
	global $wpdb;

	$now = time();

	return 1 === (int) $wpdb->query( // phpcs:ignore WordPress.DB
		$wpdb->prepare(
			'UPDATE ' . cornflex_box_jobs_table() . ' SET locked_until = %d WHERE id = %s AND locked_until < %d', // phpcs:ignore WordPress.DB
			$now + $seconds,
			$id,
			$now
		)
	);
}

/**
 * Release the job's lock.
 *
 * @param string $id Job ID.
 * @return void
 */
function cornflex_box_job_unlock( $id ) {
	cornflex_box_job_update( $id, [ 'locked_until' => 0 ] );
}

/**
 * Fail a job: tell the visitor, log it.
 *
 * @param array  $job     Job.
 * @param string $message For the visitor.
 * @param string $detail  For the history log.
 * @return void
 */
function cornflex_box_job_fail( array $job, $message, $detail = '' ) {
	cornflex_box_job_update(
		$job['id'],
		[
			'status'       => 'error',
			'message'      => $message,
			'locked_until' => 0,
		]
	);

	if ( empty( $job['input']['mock'] ) ) {
		cornflex_box_log(
			cornflex_box_job_log_row( $job ) + [
				'status'        => 'failed',
				'error_message' => '' !== $detail ? $detail : $message,
			]
		);
	}
}

/**
 * The job's columns for the generations log.
 *
 * @param array $job Job.
 * @return array
 */
function cornflex_box_job_log_row( array $job ) {
	return [
		'child_name'       => $job['input']['name'],
		'child_age'        => $job['input']['age'],
		'hobby'            => $job['input']['hobby'],
		'source_image_url' => $job['input']['source_url'],
		'prompt_used'      => $job['prompt'],
	];
}

/**
 * Where a queued job stands: 0 = may start now, N = Nth in line.
 *
 * @param array $job Queued job.
 * @return int
 */
function cornflex_box_job_queue_position( array $job ) {
	global $wpdb;

	$table = cornflex_box_jobs_table();
	$now   = time();

	// phpcs:disable WordPress.DB
	$preparing  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'preparing' AND locked_until >= %d", $now ) );
	$generating = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status IN ('preparing','generating','finishing')" );
	$ahead      = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE status = 'queued' AND not_before <= %d AND ( created_at < %d OR ( created_at = %d AND id < %s ) )",
			$now,
			$job['created_at'],
			$job['created_at'],
			$job['id']
		)
	);
	// phpcs:enable

	$free = min( cornflex_box_max_preparing() - $preparing, cornflex_box_max_generating() - $generating );

	return $ahead < $free ? 0 : $ahead - max( 0, $free ) + 1;
}

/**
 * Prepare step: moderation, prompt, submit to Atlas.
 *
 * @param array $job Job (locked, status preparing).
 * @return void
 */
function cornflex_box_job_prepare( array $job ) {
	$input = $job['input'];

	if ( ! empty( $input['mock'] ) ) {
		wp_delete_file( $input['source_path'] ); // Load-test photo, not needed.
		sleep( 2 ); // Roughly the cost of the Gemini calls.
		cornflex_box_job_update(
			$job['id'],
			[
				'status'       => 'generating',
				'atlas_id'     => 'mock',
				'not_before'   => time(),
				'locked_until' => 0,
			]
		);
		return;
	}

	$gemini_key = trim( (string) get_option( 'cbg_gemini_key' ) );
	$atlas_key  = trim( (string) get_option( 'cbg_atlas_key' ) );

	// Moderation and the prompt only need to run once, even if Atlas asks us to retry.
	if ( '' === $job['prompt'] ) {
		$moderation = cornflex_box_moderate_image( $input['source_path'], $gemini_key );
		if ( true !== $moderation ) {
			wp_delete_file( $input['source_path'] );
			cornflex_box_job_fail( $job, $moderation );
			return;
		}

		$job['prompt'] = cornflex_box_build_prompt( $input, $gemini_key );
		cornflex_box_job_update( $job['id'], [ 'prompt' => $job['prompt'] ] );
	}

	$submit = cornflex_box_atlas_submit( $job['prompt'], $input['source_url'], $atlas_key );

	if ( isset( $submit['id'] ) ) {
		cornflex_box_job_update(
			$job['id'],
			[
				'status'       => 'generating',
				'atlas_id'     => $submit['id'],
				'not_before'   => time(),
				'locked_until' => 0,
			]
		);
		return;
	}

	$attempts = (int) $job['attempts'] + 1;

	// Busy or a temporary error: back in line, try again a bit later.
	if ( ! empty( $submit['retry'] ) && $attempts < CORNFLEX_BOX_MAX_ATTEMPTS ) {
		cornflex_box_job_update(
			$job['id'],
			[
				'status'       => 'queued',
				'attempts'     => $attempts,
				'not_before'   => time() + 5 * $attempts,
				'locked_until' => 0,
			]
		);
		return;
	}

	cornflex_box_job_fail( $job, 'שגיאה ביצירת הקופסה. נסו שוב בעוד רגע.', $submit['error'] );
}

/**
 * Check step: ask Atlas, and finish the job when the image is ready.
 *
 * @param array $job Job (locked, status generating).
 * @return void
 */
function cornflex_box_job_check( array $job ) {
	if ( 'mock' === $job['atlas_id'] ) {
		if ( time() - (int) $job['not_before'] < CORNFLEX_BOX_MOCK_SECONDS ) {
			cornflex_box_job_update( $job['id'], [ 'locked_until' => 0 ] );
			return;
		}
		cornflex_box_job_update(
			$job['id'],
			[
				'status'       => 'done',
				'preview_url'  => 'https://www.cornflex.co.il/wp-content/uploads/2026/10/share.jpg',
				'locked_until' => 0,
			]
		);
		return;
	}

	$atlas_key = trim( (string) get_option( 'cbg_atlas_key' ) );
	$result    = cornflex_box_atlas_poll( $job['atlas_id'], $atlas_key );

	if ( 'processing' === $result['status'] ) {
		cornflex_box_job_update( $job['id'], [ 'locked_until' => 0 ] );
		return;
	}

	if ( 'failed' === $result['status'] ) {
		cornflex_box_job_fail( $job, 'שגיאה ביצירת הקופסה. נסו שוב בעוד רגע.', $result['error'] );
		return;
	}

	// Completed: save the full image, make the small preview, log.
	cornflex_box_job_update( $job['id'], [ 'status' => 'finishing' ] );

	// The visitor sees the slim version – the same file the "הפקות" page shares.
	$saved       = cornflex_box_save_remote_image( $result['url'], $job['input']['name'] );
	$lean        = $saved['path'] ? cornflex_box_lean_version( $saved['url'] ) : null;
	$preview_url = $lean && ! is_wp_error( $lean ) ? $lean['url'] : $saved['url'];

	cornflex_box_log( cornflex_box_job_log_row( $job ) + [ 'result_image_url' => $saved['url'] ] );

	cornflex_box_job_update(
		$job['id'],
		[
			'status'       => 'done',
			'preview_url'  => $preview_url,
			'locked_until' => 0,
		]
	);
}

/**
 * Move a job forward by at most one step, if it's its turn.
 *
 * @param string $id Job ID.
 * @return array|null The job after the step.
 */
function cornflex_box_job_advance( $id ) {
	$job = cornflex_box_job_get( $id );
	if ( ! $job || in_array( $job['status'], [ 'done', 'error' ], true ) ) {
		return $job;
	}

	$now = time();

	if ( $now - (int) $job['created_at'] > CORNFLEX_BOX_JOB_TIMEOUT ) {
		cornflex_box_job_fail( $job, 'היצירה לקחה יותר מדי זמן. נסו שוב.', 'Timed out in status ' . $job['status'] );
		return cornflex_box_job_get( $id );
	}

	// Another request is running a step for this job right now.
	if ( (int) $job['locked_until'] >= $now ) {
		return $job;
	}

	@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	ignore_user_abort( true );

	// A step that died half-way (its lock expired) is simply run again.
	if ( in_array( $job['status'], [ 'queued', 'preparing' ], true ) ) {
		if ( (int) $job['not_before'] > $now || 0 !== cornflex_box_job_queue_position( $job ) ) {
			return $job;
		}
		if ( ! cornflex_box_job_lock( $id, 90 ) ) {
			return $job;
		}
		cornflex_box_job_update( $id, [ 'status' => 'preparing' ] );
		$job['status'] = 'preparing';
		cornflex_box_job_prepare( $job );
	} elseif ( in_array( $job['status'], [ 'generating', 'finishing' ], true ) ) {
		if ( 'generating' === $job['status'] && $now - (int) $job['updated_at'] < CORNFLEX_BOX_POLL_INTERVAL ) {
			return $job;
		}
		if ( ! cornflex_box_job_lock( $id, 120 ) ) {
			return $job;
		}
		cornflex_box_job_check( $job );
	}

	return cornflex_box_job_get( $id );
}

/**
 * What the browser gets for a job.
 *
 * @param array|null $job Job.
 * @return array
 */
function cornflex_box_job_public( $job ) {
	if ( ! $job ) {
		return [
			'status'  => 'error',
			'message' => 'משהו השתבש ביצירת הקופסה. נסו שוב.',
		];
	}

	switch ( $job['status'] ) {
		case 'done':
			return [
				'status'      => 'done',
				'preview_url' => $job['preview_url'],
			];
		case 'error':
			return [
				'status'  => 'error',
				'message' => $job['message'],
			];
		case 'queued':
			return [
				'status'   => 'queued',
				'position' => max( 1, cornflex_box_job_queue_position( $job ) ),
			];
		default:
			return [ 'status' => 'working' ];
	}
}

/**
 * Send a JSON success response and close the connection, but keep running.
 *
 * @param array $data Response data.
 * @return void
 */
function cornflex_box_respond_and_continue( array $data ) {
	ignore_user_abort( true );

	$json = wp_json_encode(
		[
			'success' => true,
			'data'    => $data,
		]
	);

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'Connection: close' );
	}

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput

	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	} else {
		flush();
	}
}

/**
 * AJAX: start a generation.
 *
 * Validates and stores the photo, queues the job, answers with its ID right
 * away, then (connection closed) tries to run the first step.
 *
 * POST: image (file), name, age, suffix, hobby, access_code.
 * Success: { job }.
 *
 * @return void
 */
function cornflex_box_handle_generate() {
	global $wpdb;

	// phpcs:disable WordPress.Security.NonceVerification -- public form; access code instead of a nonce so cached pages keep working.
	$mock_secret = (string) get_option( 'cbg_mock_secret', '' );
	$is_mock     = '' !== $mock_secret && isset( $_POST['mock'] ) && hash_equals( $mock_secret, (string) wp_unslash( $_POST['mock'] ) );

	$required_code = cornflex_box_access_code();
	$given_code    = isset( $_POST['access_code'] ) ? sanitize_text_field( wp_unslash( $_POST['access_code'] ) ) : '';
	if ( ! $is_mock && '' !== $required_code && ! hash_equals( $required_code, $given_code ) ) {
		wp_send_json_error(
			[
				'message' => 'קוד הגישה שגוי.',
				'code'    => 'access_code',
			]
		);
	}

	if ( '' === trim( (string) get_option( 'cbg_gemini_key' ) ) || '' === trim( (string) get_option( 'cbg_atlas_key' ) ) ) {
		wp_send_json_error( [ 'message' => 'מפתחות ה-API אינם מוגדרים.' ] );
	}

	if ( empty( $_FILES['image'] ) || ! empty( $_FILES['image']['error'] ) ) {
		wp_send_json_error( [ 'message' => 'לא נבחרה תמונה או שההעלאה נכשלה.' ] );
	}

	$name   = isset( $_POST['name'] ) ? trim( preg_replace( '/[^A-Z ]/', '', strtoupper( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) ) ) : '';
	$age    = isset( $_POST['age'] ) ? absint( $_POST['age'] ) : 0;
	$suffix = isset( $_POST['suffix'] ) ? preg_replace( '/[^A-Z]/', '', strtoupper( sanitize_text_field( wp_unslash( $_POST['suffix'] ) ) ) ) : '';
	$hobby  = isset( $_POST['hobby'] ) ? sanitize_text_field( wp_unslash( $_POST['hobby'] ) ) : '';

	if ( '' === $name ) {
		wp_send_json_error( [ 'message' => 'נא להזין שם באנגלית.' ] );
	}
	if ( $age < 1 || $age > 99 ) {
		wp_send_json_error( [ 'message' => 'נא לבחור גיל תקין.' ] );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	$upload = wp_handle_upload(
		$_FILES['image'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		[
			'test_form' => false,
			'mimes'     => [
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
			],
		]
	);
	// phpcs:enable

	if ( isset( $upload['error'] ) ) {
		wp_send_json_error( [ 'message' => 'שגיאה בשמירת התמונה: ' . $upload['error'] ] );
	}

	$id  = wp_generate_password( 32, false );
	$now = time();

	$wpdb->insert(
		cornflex_box_jobs_table(),
		[
			'id'          => $id,
			'status'      => 'queued',
			'created_at'  => $now,
			'updated_at'  => $now,
			'input'       => wp_json_encode(
				[
					'name'        => $name,
					'age'         => $age,
					'suffix'      => '' === $suffix ? 'FLAKES' : $suffix,
					'hobby'       => '' === $hobby ? 'Classic cheerful breakfast cereal morning fun' : $hobby,
					'source_path' => $upload['file'],
					'source_url'  => $upload['url'],
					'mock'        => $is_mock,
				]
			),
			'prompt'      => '',
			'preview_url' => '',
			'message'     => '',
		]
	);

	// Old jobs are only needed while their visitor is waiting.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cornflex_box_jobs_table() . ' WHERE created_at < %d', $now - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB

	cornflex_box_respond_and_continue( [ 'job' => $id ] );
	cornflex_box_job_advance( $id );
	exit;
}
add_action( 'wp_ajax_cbg_generate', 'cornflex_box_handle_generate' );
add_action( 'wp_ajax_nopriv_cbg_generate', 'cornflex_box_handle_generate' );

/**
 * AJAX: where a job stands (and move it forward one step).
 *
 * GET: job.
 * Success: { status: queued, position } | { status: working } | { status: done, preview_url } | { status: error, message }.
 *
 * @return void
 */
function cornflex_box_handle_job_status() {
	$id = isset( $_REQUEST['job'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_REQUEST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

	nocache_headers();
	wp_send_json_success( cornflex_box_job_public( $id ? cornflex_box_job_advance( $id ) : null ) );
}
add_action( 'wp_ajax_cbg_job_status', 'cornflex_box_handle_job_status' );
add_action( 'wp_ajax_nopriv_cbg_job_status', 'cornflex_box_handle_job_status' );
