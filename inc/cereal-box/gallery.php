<?php
/**
 * Cereal box: "הפקות" admin page – every generated cover at a glance.
 *
 * Shows small thumbnails (WordPress's own "medium" size), so the page loads
 * fast even though the originals are ~8MB PNGs. Each cover can be downloaded
 * in full quality or as a slim 750px JPEG, and the slim version can be copied
 * as a link or sent on WhatsApp.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

const CORNFLEX_BOX_GALLERY_PER_PAGE = 24;
const CORNFLEX_BOX_LEAN_WIDTH       = 750;

/**
 * Local file and attachment for a generated image URL.
 *
 * @param string $url Image URL from the log.
 * @return array { id, path } – id 0 / path '' when not found.
 */
function cornflex_box_image_file( $url ) {
	$id   = attachment_url_to_postid( $url );
	$path = $id ? get_attached_file( $id ) : '';

	if ( ! $path ) {
		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		}
	}

	return [
		'id'   => (int) $id,
		'path' => $path && file_exists( $path ) ? $path : '',
	];
}

/**
 * The slim JPEG of a generated image, created the first time it's needed.
 *
 * Saved next to the original as {name}-lean.jpg.
 *
 * @param string $url Full image URL.
 * @return array|WP_Error { url, path }
 */
function cornflex_box_lean_version( $url ) {
	$file = cornflex_box_image_file( $url );
	if ( ! $file['path'] ) {
		return new WP_Error( 'missing', 'הקובץ המקורי לא נמצא בשרת.' );
	}

	$lean_path = preg_replace( '/\.[^.\/]+$/', '', $file['path'] ) . '-lean.jpg';
	$lean_url  = preg_replace( '/\.[^.\/]+$/', '', $url ) . '-lean.jpg';

	if ( ! file_exists( $lean_path ) ) {
		$editor = wp_get_image_editor( $file['path'] );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}
		$editor->resize( CORNFLEX_BOX_LEAN_WIDTH, null );
		$editor->set_quality( 82 );
		$saved = $editor->save( $lean_path, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
	}

	return [
		'url'  => $lean_url,
		'path' => $lean_path,
	];
}

/**
 * The cereal type chosen. Older rows didn't store it, so read it from the
 * prompt's logo line ("NAME SUFFIX").
 *
 * @param array $row Log row.
 * @return string
 */
function cornflex_box_row_suffix( array $row ) {
	if ( ! empty( $row['suffix'] ) ) {
		return $row['suffix'];
	}

	if ( $row['child_name'] && preg_match( '/' . preg_quote( $row['child_name'], '/' ) . '\s+([A-Z]{3,})\b/', (string) $row['prompt_used'], $m ) ) {
		return $m[1];
	}

	return '';
}

/**
 * A generation log row by ID.
 *
 * @param int $id Row ID.
 * @return array|null
 */
function cornflex_box_generation( $id ) {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . cornflex_box_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * File name for a download: cornflex-{name}-{id}[-lean].{ext}.
 *
 * @param array  $row  Log row.
 * @param string $ext  Extension.
 * @param bool   $lean Slim version.
 * @return string
 */
function cornflex_box_download_name( array $row, $ext, $lean ) {
	return 'cornflex-' . sanitize_title( $row['child_name'] ) . '-' . $row['id'] . ( $lean ? '-lean' : '' ) . '.' . $ext;
}

/**
 * Download a cover (full or slim) as an attachment.
 *
 * @return void
 */
function cornflex_box_handle_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$lean = isset( $_GET['size'] ) && 'lean' === $_GET['size'];
	check_admin_referer( 'cornflex_box_download_' . $id );

	$row = cornflex_box_generation( $id );
	if ( ! $row || empty( $row['result_image_url'] ) ) {
		wp_die( 'התמונה לא נמצאה.' );
	}

	if ( $lean ) {
		$lean_file = cornflex_box_lean_version( $row['result_image_url'] );
		if ( is_wp_error( $lean_file ) ) {
			wp_die( esc_html( $lean_file->get_error_message() ) );
		}
		$path = $lean_file['path'];
	} else {
		$path = cornflex_box_image_file( $row['result_image_url'] )['path'];
		if ( ! $path ) {
			wp_safe_redirect( $row['result_image_url'] );
			exit;
		}
	}

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

	nocache_headers();
	header( 'Content-Type: ' . ( 'png' === $ext ? 'image/png' : 'image/jpeg' ) );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Content-Disposition: attachment; filename="' . cornflex_box_download_name( $row, $ext, $lean ) . '"' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}
add_action( 'admin_post_cornflex_box_download', 'cornflex_box_handle_download' );

/**
 * AJAX: public link to the slim version (created if needed), for sharing.
 *
 * @return void
 */
function cornflex_box_handle_lean_link() {
	check_ajax_referer( 'cornflex_box_gallery', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$row = cornflex_box_generation( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	if ( ! $row || empty( $row['result_image_url'] ) ) {
		wp_send_json_error( 'התמונה לא נמצאה.' );
	}

	$lean = cornflex_box_lean_version( $row['result_image_url'] );
	if ( is_wp_error( $lean ) ) {
		wp_send_json_error( $lean->get_error_message() );
	}

	wp_send_json_success( [ 'url' => $lean['url'] ] );
}
add_action( 'wp_ajax_cornflex_box_lean_link', 'cornflex_box_handle_lean_link' );

/**
 * Assets for the gallery page.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function cornflex_box_gallery_assets( $hook ) {
	if ( false === strpos( $hook, 'cereal-box-history' ) ) {
		return;
	}

	wp_enqueue_style( 'cornflex-box-gallery', CORNFLEX_BOX_URL . '/admin-gallery.css', [], cornflex_box_asset_version( 'admin-gallery.css' ) );
	wp_enqueue_script( 'cornflex-box-gallery', CORNFLEX_BOX_URL . '/admin-gallery.js', [], cornflex_box_asset_version( 'admin-gallery.js' ), true );
	wp_localize_script(
		'cornflex-box-gallery',
		'cornflexBoxGallery',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cornflex_box_gallery' ),
		]
	);
}
add_action( 'admin_enqueue_scripts', 'cornflex_box_gallery_assets' );

/**
 * The gallery page.
 *
 * @return void
 */
function cornflex_box_gallery_page() {
	global $wpdb;

	cornflex_box_maybe_install();

	// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'success';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	// phpcs:enable

	$table  = cornflex_box_table();
	$where  = [ '1=1' ];
	$params = [];

	if ( 'success' === $status ) {
		$where[] = "(status = 'success' OR status = '')";
	} elseif ( 'failed' === $status ) {
		$where[] = "status = 'failed'";
	}
	if ( '' !== $search ) {
		$where[]  = '(child_name LIKE %s OR hobby LIKE %s)';
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$params[] = $like;
		$params[] = $like;
	}

	$where_sql = implode( ' AND ', $where );
	$offset    = ( $paged - 1 ) * CORNFLEX_BOX_GALLERY_PER_PAGE;

	// phpcs:disable WordPress.DB
	$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", $params ) : "SELECT COUNT(*) FROM $table WHERE $where_sql" );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( $params, [ CORNFLEX_BOX_GALLERY_PER_PAGE, $offset ] )
		),
		ARRAY_A
	);
	// phpcs:enable

	$pages    = max( 1, (int) ceil( $total / CORNFLEX_BOX_GALLERY_PER_PAGE ) );
	$base_url = admin_url( 'admin.php?page=cereal-box-history' );
	$tabs     = [
		'success' => 'הופקו בהצלחה',
		'failed'  => 'שגיאות',
		'all'     => 'הכל',
	];
	?>
	<div class="wrap cbx-gallery">
		<h1>הפקות</h1>

		<div class="cbx-toolbar">
			<ul class="subsubsub">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, $base_url ) ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>

			<form method="get" class="cbx-search">
				<input type="hidden" name="page" value="cereal-box-history">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="חיפוש לפי שם או תחביב">
				<button type="submit" class="button">חיפוש</button>
			</form>
		</div>

		<p class="cbx-count"><?php echo esc_html( number_format_i18n( $total ) ); ?> תוצאות</p>

		<?php if ( empty( $rows ) ) : ?>
			<div class="cbx-empty">אין הפקות להצגה.</div>
		<?php else : ?>
			<div class="cbx-grid">
				<?php
				foreach ( $rows as $row ) :
					$ok    = empty( $row['status'] ) || 'success' === $row['status'];
					$file  = $ok && $row['result_image_url'] ? cornflex_box_image_file( $row['result_image_url'] ) : [ 'id' => 0 ];
					$thumb = $file['id'] ? wp_get_attachment_image_url( $file['id'], 'medium' ) : '';
					$large = $file['id'] ? wp_get_attachment_image_url( $file['id'], 'large' ) : $row['result_image_url'];
					$dl    = wp_nonce_url( admin_url( 'admin-post.php?action=cornflex_box_download&id=' . $row['id'] ), 'cornflex_box_download_' . $row['id'] );
					$date  = mysql2date( 'j.n.Y H:i', $row['created_at'] );
					?>
					<div class="cbx-card<?php echo $ok ? '' : ' is-failed'; ?>">
						<?php if ( $ok && $row['result_image_url'] ) : ?>
							<button type="button" class="cbx-thumb" data-cbx="view" data-large="<?php echo esc_url( $large ); ?>" aria-label="הגדלה">
								<img src="<?php echo esc_url( $thumb ? $thumb : $row['result_image_url'] ); ?>" loading="lazy" alt="<?php echo esc_attr( $row['child_name'] ); ?>">
							</button>
						<?php else : ?>
							<?php // The original photo is full size; it loads only via "תמונת מקור". ?>
							<div class="cbx-thumb cbx-thumb-empty"><span>לא הופק</span></div>
						<?php endif; ?>

						<div class="cbx-info">
							<div class="cbx-name"><?php echo esc_html( $row['child_name'] ); ?> <span>(<?php echo esc_html( $row['child_age'] ); ?>)</span></div>
							<dl class="cbx-fields">
								<?php $suffix = cornflex_box_row_suffix( $row ); ?>
								<?php if ( $suffix ) : ?>
									<dt>סוג</dt><dd><?php echo esc_html( $suffix ); ?></dd>
								<?php endif; ?>
								<dt>אוהבים</dt><dd><?php echo esc_html( $row['hobby'] ? $row['hobby'] : '—' ); ?></dd>
								<dt>נוצר</dt><dd><?php echo esc_html( $date ); ?> · #<?php echo esc_html( $row['id'] ); ?></dd>
							</dl>

							<div class="cbx-links">
								<?php if ( $row['source_image_url'] ) : ?>
									<button type="button" class="button-link" data-cbx="view" data-large="<?php echo esc_url( $row['source_image_url'] ); ?>">תמונת מקור</button>
								<?php endif; ?>
								<?php if ( $row['prompt_used'] ) : ?>
									<button type="button" class="button-link" data-cbx="prompt">פרומפט</button>
								<?php endif; ?>
							</div>
							<?php if ( $row['prompt_used'] ) : ?>
								<div class="cbx-prompt" hidden>
									<p><?php echo esc_html( $row['prompt_used'] ); ?></p>
									<button type="button" class="button button-small" data-cbx="copy-prompt">העתקת פרומפט</button>
								</div>
							<?php endif; ?>

							<?php if ( ! $ok && $row['error_message'] ) : ?>
								<details class="cbx-error"><summary>סיבת השגיאה</summary><?php echo esc_html( $row['error_message'] ); ?></details>
							<?php endif; ?>
						</div>

						<?php if ( $ok && $row['result_image_url'] ) : ?>
							<div class="cbx-actions">
								<a class="button button-primary" href="<?php echo esc_url( $dl ); ?>">הורדה מלאה</a>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'size', 'lean', $dl ) ); ?>">הורדה רזה</a>
								<button type="button" class="button" data-cbx="copy" data-id="<?php echo esc_attr( $row['id'] ); ?>">העתקת קישור</button>
								<button type="button" class="button cbx-wa" data-cbx="whatsapp" data-id="<?php echo esc_attr( $row['id'] ); ?>">וואטסאפ</button>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<div class="cbx-pages">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
						[
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => 'הקודם',
							'next_text' => 'הבא',
						]
					);
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="cbx-lightbox" hidden>
			<button type="button" class="cbx-lightbox-close" data-cbx="close" aria-label="סגירה">&times;</button>
			<img alt="">
		</div>
		<div class="cbx-toast" hidden></div>
	</div>
	<?php
}
