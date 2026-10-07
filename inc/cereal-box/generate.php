<?php
/**
 * Cereal box: the AI steps. jobs.php runs them as a queue.
 *
 * Gemini moderation → Gemini prompt polish → Seedream (Atlas Cloud, async)
 * → save to the media library → small preview → log.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * POST to Gemini, retrying once when it is busy (429/503).
 *
 * @param string $model      Model name.
 * @param array  $payload    Request body.
 * @param string $gemini_key API key.
 * @param int    $timeout    Seconds.
 * @return array|WP_Error
 */
function cornflex_box_gemini_post( $model, array $payload, $gemini_key, $timeout ) {
	for ( $attempt = 0; ; $attempt++ ) {
		$res = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $gemini_key,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			]
		);

		$busy = ! is_wp_error( $res ) && in_array( (int) wp_remote_retrieve_response_code( $res ), [ 429, 503 ], true );
		if ( ! $busy || $attempt >= 1 ) {
			return $res;
		}

		sleep( 2 );
	}
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
	$res = cornflex_box_gemini_post(
		$model,
		[
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [ [ 'text' => $instruction ] ],
				],
			],
		],
		$gemini_key,
		25
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

	$payload = [
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
	];

	// The lighter model has a separate (and larger) quota, so it covers for the main one.
	foreach ( [ 'gemini-3.8-flash', 'gemini-3.5-flash-lite' ] as $model ) {
		$res = cornflex_box_gemini_post( $model, $payload, $gemini_key, 15 );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			break;
		}
	}

	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
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
 * Remove a plain frame around a generated cover, so it prints edge to edge.
 *
 * Sometimes the model draws the cover as a product shot: the artwork sits on
 * a white (or other plain) background. Walk in from each edge while the line
 * is a plain run of the corner color, cut that off (plus a hair more for the
 * soft shadow), then center-crop back to the original proportions and size.
 * Images that already fill the canvas are left alone.
 *
 * @param string $path PNG/JPEG file, overwritten in place as PNG.
 * @return bool Whether a frame was removed.
 */
function cornflex_box_trim_frame( $path ) {
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return false;
	}

	$img = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
	if ( ! $img ) {
		return false;
	}

	$w = imagesx( $img );
	$h = imagesy( $img );

	// Frame color: average of the four corners.
	$bg = [ 0, 0, 0 ];
	foreach ( [ [ 2, 2 ], [ $w - 3, 2 ], [ 2, $h - 3 ], [ $w - 3, $h - 3 ] ] as $corner ) {
		$c      = imagecolorat( $img, $corner[0], $corner[1] );
		$bg[0] += ( $c >> 16 ) & 255;
		$bg[1] += ( $c >> 8 ) & 255;
		$bg[2] += $c & 255;
	}
	$bg = array_map(
		function ( $v ) {
			return $v / 4;
		},
		$bg
	);

	// Is this row/column plain frame color?
	$is_frame = function ( $horizontal, $index ) use ( $img, $w, $h, $bg ) {
		$len  = $horizontal ? $w : $h;
		$step = max( 1, (int) ( $len / 300 ) );
		$far  = 0;
		$n    = 0;
		for ( $i = 0; $i < $len; $i += $step ) {
			$c = $horizontal ? imagecolorat( $img, $i, $index ) : imagecolorat( $img, $index, $i );
			$d = abs( ( ( $c >> 16 ) & 255 ) - $bg[0] ) + abs( ( ( $c >> 8 ) & 255 ) - $bg[1] ) + abs( ( $c & 255 ) - $bg[2] );
			if ( $d > 60 ) {
				$far++;
			}
			$n++;
		}
		return $far / $n < 0.02;
	};

	$max_x = (int) ( $w * 0.2 );
	$max_y = (int) ( $h * 0.2 );

	for ( $top = 0; $top < $max_y && $is_frame( true, $top ); $top++ );
	for ( $bottom = 0; $bottom < $max_y && $is_frame( true, $h - 1 - $bottom ); $bottom++ );
	for ( $left = 0; $left < $max_x && $is_frame( false, $left ); $left++ );
	for ( $right = 0; $right < $max_x && $is_frame( false, $w - 1 - $right ); $right++ );

	// Nothing worth cutting: the artwork already reaches the edges.
	if ( max( $top / $h, $bottom / $h, $left / $w, $right / $w ) < 0.008 ) {
		imagedestroy( $img );
		return false;
	}

	// A little extra for the soft edge/shadow where the artwork meets the frame.
	$pad    = (int) round( min( $w, $h ) * 0.012 );
	$x      = $left ? $left + $pad : 0;
	$y      = $top ? $top + $pad : 0;
	$cw     = $w - $x - ( $right ? $right + $pad : 0 );
	$ch     = $h - $y - ( $bottom ? $bottom + $pad : 0 );

	// Back to the original proportions (center crop), then the original size.
	$ratio = $w / $h;
	if ( $cw / $ch > $ratio ) {
		$new_w = (int) round( $ch * $ratio );
		$x    += (int) ( ( $cw - $new_w ) / 2 );
		$cw    = $new_w;
	} else {
		$new_h = (int) round( $cw / $ratio );
		$y    += (int) ( ( $ch - $new_h ) / 2 );
		$ch    = $new_h;
	}

	$out = imagecreatetruecolor( $w, $h );
	imagecopyresampled( $out, $img, 0, 0, $x, $y, $w, $h, $cw, $ch );
	$saved = imagepng( $out, $path, 6 );

	imagedestroy( $img );
	imagedestroy( $out );

	return (bool) $saved;
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

	// Before WordPress makes its thumbnails, so they match the trimmed image.
	wp_raise_memory_limit( 'image' );
	cornflex_box_trim_frame( $tmp );

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
				'suffix'           => '',
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
 * Build the final prompt: template → Gemini polish (brands → generic).
 *
 * @param array  $input      name, age, suffix, hobby.
 * @param string $gemini_key API key.
 * @return string
 */
function cornflex_box_build_prompt( array $input, $gemini_key ) {
	$base_prompt = strtr(
		cornflex_box_prompt_template(),
		[
			'{name}'   => $input['name'],
			'{age}'    => $input['age'],
			'{suffix}' => $input['suffix'],
			'{hobby}'  => $input['hobby'],
		]
	);

	$instruction = "You are an expert prompt engineer for the Seedream 5.0 image editing model. Rewrite and finalize the image prompt below.

OUTPUT FORMAT RULE (most important):
The image must be a flat, full-bleed 2D poster illustration whose artwork fills the entire canvas, touching all four edges. Describe ONLY the artwork itself: the child, the bowl, the background art, the floating cereal, the title lettering.
Never use these words or ideas anywhere in the output, not even in a negative sentence such as 'no X': box, cereal box, package, packaging, carton, cardboard, mockup, product shot, product photo, 3D object, side panel, frame, border, margin, white background. Image models tend to draw whatever is named, so express every restriction positively instead (for example: 'the colorful background art continues to every edge of the canvas').

CRITICAL SAFETY & COPYRIGHT RULE:
Strictly convert any copyrighted brand, official football club name (e.g. Real Madrid, Barcelona, Liverpool, Maccabi), Disney, Marvel, Lego, or trademarked characters into safe, descriptive generic equivalents (e.g. describe team jersey colors, stadium atmosphere, soccer balls, superhero gear) without ever using the trademarked or copyrighted brand names.
For a character from a movie, series, game or toy (e.g. Minions, Frozen, Paw Patrol, Spider-Man, Pokemon, Mario, Barbie): never draw the character itself or any creature or figure that looks like it, and never use its name. Instead, bring its world to the CHILD and the background: dress the child in the character's signature outfit elements and colors, and fill the background with its typical props, color palette and energy. Examples:
- Minions: the child wears round silver goggles and denim overalls over a bright yellow shirt; playful mischief, bananas flying around, a sunny yellow-and-blue palette.
- Frozen: the child wears an icy blue sparkly gown or cape; snowflakes, ice crystals, a winter wonderland palette.
- Spider-Man: the child wears a red-and-blue superhero suit with a web pattern; city skyline, web lines.
Do not add any other people, creatures or characters to the image.

Keep the child's likeness, age, theme and the exact title text from the base prompt. Translate any non-English text (except the title) into English. Output ONLY the raw final English prompt without quotes, markdown, or chat text.

Base prompt: " . $base_prompt;

	$prompt = cornflex_box_gemini_text( 'gemini-3.8-flash', $instruction, $gemini_key );
	if ( ! $prompt ) {
		$prompt = cornflex_box_gemini_text( 'gemini-3.5-flash-lite', $instruction, $gemini_key );
	}

	return cornflex_box_strip_mockup_words( $prompt ? $prompt : $base_prompt );
}

/**
 * Drop "no box / no mockup / no white background…" sentences from a prompt.
 *
 * Image models tend to draw what a prompt names, even after "no", and Gemini
 * doesn't always remove them. Only sentences that are restrictions ("no",
 * "without", "avoid") and name one of these things are dropped.
 *
 * @param string $prompt Prompt.
 * @return string
 */
function cornflex_box_strip_mockup_words( $prompt ) {
	$things    = '(box|boxes|package|packaging|carton|cardboard|mock-?ups?|product (shot|photo|mockup)|side flaps?|side panels?|borders?|margins?|white (space|background)|drop shadows?|3d object)';
	$sentences = preg_split( '/(?<=[.!?])\s+|\n+/', trim( $prompt ) );

	$kept = array_filter(
		$sentences,
		function ( $sentence ) use ( $things ) {
			$is_restriction = preg_match( '/\b(no|not|without|avoid|never|absolutely no)\b/i', $sentence );
			return ! ( $is_restriction && preg_match( '/\b' . $things . '\b/i', $sentence ) );
		}
	);

	return implode( ' ', $kept );
}

/**
 * Start a Seedream generation at Atlas Cloud (async).
 *
 * @param string $prompt    Final prompt.
 * @param string $image_url Public URL of the uploaded photo.
 * @param string $atlas_key API key.
 * @return array { id } | { retry: true, error } for busy/temporary errors | { error }.
 */
function cornflex_box_atlas_submit( $prompt, $image_url, $atlas_key ) {
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
					'prompt'                   => $prompt,
					'images'                   => [ $image_url ],
					'size'                     => '1664*2496',
					'output_format'            => 'png',
					'thinking'                 => 'disabled',
					'prompt_optimization_mode' => 'standard',
					'enable_sync_mode'         => false,
				]
			),
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $res ) ) {
		return [
			'retry' => true,
			'error' => $res->get_error_message(),
		];
	}

	$http = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );
	$data = json_decode( $body, true );
	$code = isset( $data['code'] ) ? (int) $data['code'] : $http;

	if ( ! empty( $data['data']['id'] ) && $code < 400 ) {
		return [ 'id' => $data['data']['id'] ];
	}

	$error = $data['msg'] ?? $data['message'] ?? substr( $body, 0, 250 );
	$error = is_string( $error ) && '' !== $error ? $error : 'HTTP ' . $http;

	// Rate limits and server errors are worth another try later.
	return [
		'retry' => 429 === $code || 429 === $http || $http >= 500 || $code >= 500,
		'error' => $error,
	];
}

/**
 * Check a Seedream generation at Atlas Cloud.
 *
 * @param string $id        Prediction ID.
 * @param string $atlas_key API key.
 * @return array { status: processing } | { status: completed, url } | { status: failed, error }.
 */
function cornflex_box_atlas_poll( $id, $atlas_key ) {
	$res = wp_remote_get(
		'https://api.atlascloud.ai/api/v1/model/prediction/' . rawurlencode( $id ),
		[
			'headers' => [ 'Authorization' => 'Bearer ' . $atlas_key ],
			'timeout' => 15,
		]
	);

	// Network hiccup: just check again on the next poll.
	if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) >= 500 ) {
		return [ 'status' => 'processing' ];
	}

	$data   = json_decode( wp_remote_retrieve_body( $res ), true );
	$status = isset( $data['data']['status'] ) ? strtolower( (string) $data['data']['status'] ) : '';

	if ( in_array( $status, [ 'completed', 'succeeded', 'success' ], true ) ) {
		$url = cornflex_box_atlas_output_url( (array) $data );
		return '' !== $url
			? [ 'status' => 'completed', 'url' => $url ]
			: [ 'status' => 'failed', 'error' => 'No output image' ];
	}

	if ( in_array( $status, [ 'failed', 'error', 'canceled', 'cancelled' ], true ) ) {
		$error = isset( $data['data']['error'] ) && '' !== $data['data']['error'] ? $data['data']['error'] : 'Generation failed';
		return [ 'status' => 'failed', 'error' => is_string( $error ) ? $error : wp_json_encode( $error ) ];
	}

	if ( isset( $data['code'] ) && 404 === (int) $data['code'] ) {
		return [ 'status' => 'failed', 'error' => 'Prediction not found' ];
	}

	return [ 'status' => 'processing' ];
}
