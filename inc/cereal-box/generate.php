<?php
/**
 * Cereal box: AI generation pipeline and its AJAX handler.
 *
 * Upload → Gemini moderation → Gemini prompt polish → Seedream (Atlas Cloud)
 * → save to the media library → small preview → log.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ask a Gemini text model and return its answer.
 *
 * @param string $model       Model name.
 * @param string $instruction Prompt.
 * @param string $gemini_key  API key.
 * @return string|false
 */
function cornflex_box_gemini_text( $model, $instruction, $gemini_key ) {
	$res = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $gemini_key,
		[
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode(
				[
					'contents' => [
						[
							'role'  => 'user',
							'parts' => [ [ 'text' => $instruction ] ],
						],
					],
				]
			),
			'timeout' => 25,
		]
	);

	if ( is_wp_error( $res ) ) {
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( isset( $data['error'] ) || ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
		return false;
	}

	return trim( $data['candidates'][0]['content']['parts'][0]['text'] );
}

/**
 * Check the uploaded photo has a person and is safe for kids.
 *
 * Fails open: if Gemini can't be reached, the photo is accepted.
 *
 * @param string $image_path Local file path.
 * @param string $gemini_key API key.
 * @return true|string True, or an error message for the visitor.
 */
function cornflex_box_moderate_image( $image_path, $gemini_key ) {
	if ( ! file_exists( $image_path ) ) {
		return true;
	}

	$mime = mime_content_type( $image_path );

	$prompt = "Look at this image. Answer strictly in this exact format:
HAS_PERSON: [YES or NO]
IS_SAFE: [YES or NO]
(IS_SAFE means NO nudity, NO porn, NO sexual content, NO violence, completely suitable for a kids cereal box).";

	$res = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=' . $gemini_key,
		[
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode(
				[
					'contents' => [
						[
							'parts' => [
								[ 'text' => $prompt ],
								[
									'inline_data' => [
										'mime_type' => $mime ? $mime : 'image/jpeg',
										'data'      => base64_encode( file_get_contents( $image_path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
									],
								],
							],
						],
					],
				]
			),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $res ) ) {
		return true;
	}

	$data   = json_decode( wp_remote_retrieve_body( $res ), true );
	$answer = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? strtoupper( $data['candidates'][0]['content']['parts'][0]['text'] ) : '';

	if ( false !== strpos( $answer, 'IS_SAFE: NO' ) ) {
		return 'התמונה שהועלתה אינה מתאימה לשימוש (זוהה תוכן לא הולם). נא להעלות תמונה אחרת.';
	}

	if ( false !== strpos( $answer, 'HAS_PERSON: NO' ) ) {
		return 'לא זוהו פנים בתמונה. כדי לקבל תוצאה מדויקת, נא להעלות תמונת פנים ברורה של הילד.ה.';
	}

	return true;
}

/**
 * Upload a local file to Atlas Cloud media storage.
 *
 * @param string $file_path Local file path.
 * @param string $atlas_key API key.
 * @return string|false Remote URL.
 */
function cornflex_box_atlas_upload( $file_path, $atlas_key ) {
	if ( ! file_exists( $file_path ) || ! function_exists( 'curl_init' ) ) {
		return false;
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions -- multipart file upload.
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, 'https://api.atlascloud.ai/api/v1/model/uploadMedia' );
	curl_setopt( $ch, CURLOPT_POST, 1 );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, [ 'file' => new CURLFile( $file_path, mime_content_type( $file_path ), basename( $file_path ) ) ] );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Authorization: Bearer ' . $atlas_key ] );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 60 );

	$response  = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	// phpcs:enable

	if ( ! $response || $http_code >= 400 ) {
		return false;
	}

	$data = json_decode( $response, true );

	if ( ! empty( $data['url'] ) ) {
		return $data['url'];
	}

	return ! empty( $data['data']['url'] ) ? $data['data']['url'] : false;
}

/**
 * Save a 750px-wide JPEG preview next to the full-size result.
 *
 * @param string $image_path Full-size local file.
 * @param string $child_name Used in the file name.
 * @return string|false Preview URL.
 */
function cornflex_box_create_preview( $image_path, $child_name ) {
	$editor = wp_get_image_editor( $image_path );
	if ( is_wp_error( $editor ) ) {
		return false;
	}

	$editor->resize( 750, null );
	$editor->set_quality( 75 );

	$upload_dir = wp_upload_dir();
	$filename   = 'preview_' . sanitize_title( $child_name ) . '_' . time() . '.jpg';
	$saved      = $editor->save( $upload_dir['path'] . '/' . $filename, 'image/jpeg' );

	return is_wp_error( $saved ) ? false : $upload_dir['url'] . '/' . $saved['file'];
}

/**
 * Download the generated image into the media library.
 *
 * @param string $image_url  Remote URL.
 * @param string $child_name Used in the file name.
 * @return array { url, path } – path is false if saving failed (url is then remote).
 */
function cornflex_box_save_remote_image( $image_url, $child_name ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $image_url, 180 );
	if ( is_wp_error( $tmp ) ) {
		return [
			'url'  => $image_url,
			'path' => false,
		];
	}

	$attachment_id = media_handle_sideload(
		[
			'name'     => 'cereal_' . sanitize_title( $child_name ) . '_' . time() . '.png',
			'tmp_name' => $tmp,
		],
		0
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $tmp );
		return [
			'url'  => $image_url,
			'path' => false,
		];
	}

	return [
		'url'  => wp_get_attachment_url( $attachment_id ),
		'path' => get_attached_file( $attachment_id ),
	];
}

/**
 * wp_remote_post() that retries when the connection itself fails.
 *
 * Only errors that happen before the request is sent are retried (DNS, connect,
 * TLS handshake), so a generation is never sent – or billed – twice. Timeouts
 * are not retried.
 *
 * @param string $url     URL.
 * @param array  $args    wp_remote_post() args.
 * @param int    $retries Extra attempts.
 * @return array|WP_Error
 */
function cornflex_box_post_with_retry( $url, array $args, $retries = 2 ) {
	for ( $attempt = 0; ; $attempt++ ) {
		$res = wp_remote_post( $url, $args );

		$connect_failed = is_wp_error( $res ) && preg_match( '/cURL error (6|7|35):/', $res->get_error_message() );
		if ( ! $connect_failed || $attempt >= $retries ) {
			return $res;
		}

		sleep( 2 );
	}
}

/**
 * Write one row to the generations log.
 *
 * @param array $row Column => value.
 * @return void
 */
function cornflex_box_log( array $row ) {
	global $wpdb;

	$wpdb->insert(
		cornflex_box_table(),
		wp_parse_args(
			$row,
			[
				'child_name'       => '',
				'child_age'        => 0,
				'hobby'            => '',
				'source_image_url' => '',
				'result_image_url' => '',
				'prompt_used'      => '',
				'status'           => 'success',
				'error_message'    => '',
			]
		)
	);
}

/**
 * Pull the image URL out of an Atlas Cloud response.
 *
 * @param array $data Decoded response.
 * @return string
 */
function cornflex_box_atlas_output_url( $data ) {
	if ( isset( $data['data']['outputs'][0] ) ) {
		return $data['data']['outputs'][0];
	}
	if ( isset( $data['outputs'][0] ) ) {
		return $data['outputs'][0];
	}
	if ( isset( $data['data'][0]['url'] ) ) {
		return $data['data'][0]['url'];
	}
	if ( isset( $data['output'][0] ) ) {
		return $data['output'][0];
	}

	return '';
}

/**
 * AJAX: generate a cereal box cover.
 *
 * POST: image (file), name, age, suffix, hobby, access_code.
 * Success: { preview_url, prompt }.
 *
 * @return void
 */
function cornflex_box_handle_generate() {
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// phpcs:disable WordPress.Security.NonceVerification -- public form; access code instead of a nonce so cached pages keep working.
	$required_code = cornflex_box_access_code();
	$given_code    = isset( $_POST['access_code'] ) ? sanitize_text_field( wp_unslash( $_POST['access_code'] ) ) : '';
	if ( '' !== $required_code && ! hash_equals( $required_code, $given_code ) ) {
		wp_send_json_error(
			[
				'message' => 'קוד הגישה שגוי.',
				'code'    => 'access_code',
			]
		);
	}

	$gemini_key = trim( (string) get_option( 'cbg_gemini_key' ) );
	$atlas_key  = trim( (string) get_option( 'cbg_atlas_key' ) );
	if ( '' === $gemini_key || '' === $atlas_key ) {
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
	if ( '' === $suffix ) {
		$suffix = 'FLAKES';
	}
	if ( '' === $hobby ) {
		$hobby = 'Classic cheerful breakfast cereal morning fun';
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

	$source_path = $upload['file'];
	$source_url  = $upload['url'];
	$log         = [
		'child_name'       => $name,
		'child_age'        => $age,
		'hobby'            => $hobby,
		'source_image_url' => $source_url,
	];

	$moderation = cornflex_box_moderate_image( $source_path, $gemini_key );
	if ( true !== $moderation ) {
		wp_delete_file( $source_path );
		cornflex_box_log( $log + [ 'status' => 'failed', 'error_message' => $moderation ] );
		wp_send_json_error( [ 'message' => $moderation ] );
	}

	$model_image = cornflex_box_atlas_upload( $source_path, $atlas_key );
	if ( ! $model_image ) {
		$mime        = mime_content_type( $source_path );
		$model_image = 'data:' . ( $mime ? $mime : 'image/jpeg' ) . ';base64,' . base64_encode( file_get_contents( $source_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	$base_prompt = strtr(
		cornflex_box_prompt_template(),
		[
			'{name}'   => $name,
			'{age}'    => $age,
			'{suffix}' => $suffix,
			'{hobby}'  => $hobby,
		]
	);

	$instruction = "You are an expert prompt engineer for the Seedream 5.0 image editing model. Enhance and finalize the cereal box cover prompt below.
CRITICAL SAFETY & COPYRIGHT RULE:
Strictly convert any copyrighted brand, official football club name (e.g. Real Madrid, Barcelona, Liverpool, Maccabi), Disney, Marvel, Lego, or trademarked characters into safe, descriptive generic equivalents (e.g. describe team jersey colors, stadium atmosphere, soccer balls, superhero gear) without ever using the trademarked or copyrighted brand names. Output ONLY the raw final English prompt without quotes, markdown, or chat text. Base prompt: " . $base_prompt;

	$final_prompt = cornflex_box_gemini_text( 'gemini-3.8-flash', $instruction, $gemini_key );
	if ( ! $final_prompt ) {
		$final_prompt = cornflex_box_gemini_text( 'gemini-3.5-flash-lite', $instruction, $gemini_key );
	}
	if ( ! $final_prompt ) {
		$final_prompt = $base_prompt;
	}
	$log['prompt_used'] = $final_prompt;

	$res = cornflex_box_post_with_retry(
		'https://api.atlascloud.ai/api/v1/model/generateImage',
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $atlas_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode(
				[
					'model'                    => 'bytedance/seedream-v5.0-pro/edit',
					'prompt'                   => $final_prompt,
					'images'                   => [ $model_image ],
					'size'                     => '1664*2496',
					'output_format'            => 'png',
					'thinking'                 => 'disabled',
					'prompt_optimization_mode' => 'standard',
					'enable_sync_mode'         => true,
				]
			),
			'timeout' => 240,
		]
	);

	if ( is_wp_error( $res ) ) {
		cornflex_box_log( $log + [ 'status' => 'failed', 'error_message' => $res->get_error_message() ] );
		wp_send_json_error( [ 'message' => 'שגיאה ביצירת הקופסה. נסו שוב בעוד רגע.' ] );
	}

	$body = wp_remote_retrieve_body( $res );
	$data = json_decode( $body, true );

	if ( isset( $data['error'] ) || ( isset( $data['code'] ) && $data['code'] >= 400 ) ) {
		$error = $data['msg'] ?? $data['error']['message'] ?? $data['message'] ?? $body;
		cornflex_box_log( $log + [ 'status' => 'failed', 'error_message' => is_string( $error ) ? $error : wp_json_encode( $error ) ] );
		wp_send_json_error( [ 'message' => 'שגיאה ביצירת הקופסה. נסו שוב בעוד רגע.' ] );
	}

	$remote_url = cornflex_box_atlas_output_url( (array) $data );
	if ( '' === $remote_url ) {
		$error = $data['message'] ?? substr( $body, 0, 250 );
		cornflex_box_log( $log + [ 'status' => 'failed', 'error_message' => is_string( $error ) ? $error : wp_json_encode( $error ) ] );
		wp_send_json_error( [ 'message' => 'לא התקבלה תמונה. נסו שוב בעוד רגע.' ] );
	}

	$saved       = cornflex_box_save_remote_image( $remote_url, $name );
	$preview_url = $saved['url'];
	if ( $saved['path'] ) {
		$preview_url = cornflex_box_create_preview( $saved['path'], $name ) ?: $saved['url'];
	}

	cornflex_box_log( $log + [ 'result_image_url' => $saved['url'] ] );

	wp_send_json_success(
		[
			'preview_url' => $preview_url,
			'prompt'      => $final_prompt,
		]
	);
}
add_action( 'wp_ajax_cbg_generate', 'cornflex_box_handle_generate' );
add_action( 'wp_ajax_nopriv_cbg_generate', 'cornflex_box_handle_generate' );
