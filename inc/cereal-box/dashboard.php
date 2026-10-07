<?php
/**
 * Cornflex panel at the top of the WordPress dashboard.
 *
 * Generations today / 7 days / 30 days / total, success rate, generation time,
 * queue, Atlas balance (with how many covers it's roughly worth) and Gemini
 * status, a 14-day chart, top themes and cereal types, and the latest covers.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Show the panel on the dashboard (full width, above the widgets) and clear
 * out WordPress's own news / quick-draft boxes.
 *
 * @return void
 */
function cornflex_dashboard_setup() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
	remove_action( 'welcome_panel', 'wp_welcome_panel' );

	add_action( 'all_admin_notices', 'cornflex_dashboard_render', 1 );
}
add_action( 'load-index.php', 'cornflex_dashboard_setup' );

/**
 * Assets for the panel.
 *
 * @param string $hook Admin page hook.
 * @return void
 */
function cornflex_dashboard_assets( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'cornflex-dashboard', CORNFLEX_BOX_URL . '/admin-dashboard.css', [], cornflex_box_asset_version( 'admin-dashboard.css' ) );
	wp_enqueue_script( 'cornflex-dashboard', CORNFLEX_BOX_URL . '/admin-dashboard.js', [], cornflex_box_asset_version( 'admin-dashboard.js' ), true );
}
add_action( 'admin_enqueue_scripts', 'cornflex_dashboard_assets' );

/**
 * Remember Atlas balance against the number of covers made, to estimate
 * what one cover costs. A top-up (balance going up) starts over.
 *
 * @param float $balance Current Atlas balance.
 * @param int   $total   Successful covers so far.
 * @return float|null Estimated USD per cover.
 */
function cornflex_dashboard_cost_per_cover( $balance, $total ) {
	$snapshots = (array) get_option( 'cornflex_box_atlas_snapshots', [] );
	$last      = end( $snapshots );

	if ( $last && $balance > $last['balance'] + 0.01 ) {
		$snapshots = []; // Topped up.
	}

	$today = wp_date( 'Y-m-d' );
	if ( ! isset( $snapshots[ $today ] ) ) {
		$snapshots[ $today ] = [
			'balance' => $balance,
			'total'   => $total,
		];
		update_option( 'cornflex_box_atlas_snapshots', array_slice( $snapshots, -30, null, true ), false );
	}

	$first = reset( $snapshots );
	$made  = $total - $first['total'];
	$spent = $first['balance'] - $balance;

	return $made >= 3 && $spent > 0 ? $spent / $made : null;
}

/**
 * Everything the panel shows.
 *
 * @return array
 */
function cornflex_dashboard_data() {
	global $wpdb;

	$table = cornflex_box_table();
	$jobs  = cornflex_box_jobs_table();
	$ok    = "(status = 'success' OR status = '')";

	// phpcs:disable WordPress.DB
	$counts = $wpdb->get_row(
		"SELECT
			SUM( $ok AND DATE(created_at) = CURDATE() ) AS today,
			SUM( status = 'failed' AND DATE(created_at) = CURDATE() ) AS failed_today,
			SUM( $ok AND created_at >= CURDATE() - INTERVAL 6 DAY ) AS week,
			SUM( status = 'failed' AND created_at >= CURDATE() - INTERVAL 6 DAY ) AS failed_week,
			SUM( $ok AND created_at >= CURDATE() - INTERVAL 29 DAY ) AS month,
			SUM( $ok ) AS total
		FROM $table",
		ARRAY_A
	);

	$per_day = $wpdb->get_results(
		"SELECT DATE(created_at) AS day, SUM( $ok ) AS ok, SUM( status = 'failed' ) AS failed
		FROM $table WHERE created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)",
		OBJECT_K
	);

	$recent_rows = $wpdb->get_results(
		"SELECT * FROM $table WHERE $ok AND created_at >= CURDATE() - INTERVAL 29 DAY ORDER BY id DESC",
		ARRAY_A
	);

	$latest = $wpdb->get_results( "SELECT * FROM $table WHERE $ok AND result_image_url <> '' ORDER BY id DESC LIMIT 6", ARRAY_A );

	$job_stats = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				SUM( status IN ('queued','preparing','generating','finishing') ) AS active,
				AVG( CASE WHEN status = 'done' AND input NOT LIKE %s THEN updated_at - created_at END ) AS avg_seconds
			FROM $jobs",
			'%"mock":true%'
		),
		ARRAY_A
	);
	// phpcs:enable

	// 14 days, oldest first, zero-filled.
	$days = [];
	for ( $i = 13; $i >= 0; $i-- ) {
		$day    = wp_date( 'Y-m-d', strtotime( "-$i days", current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$days[] = [
			'day'    => $day,
			'label'  => wp_date( 'j.n', strtotime( $day ) ),
			'ok'     => isset( $per_day[ $day ] ) ? (int) $per_day[ $day ]->ok : 0,
			'failed' => isset( $per_day[ $day ] ) ? (int) $per_day[ $day ]->failed : 0,
		];
	}

	// Top themes and cereal types, last 30 days.
	$hobbies  = [];
	$suffixes = [];
	foreach ( $recent_rows as $row ) {
		$hobby = trim( (string) $row['hobby'] );
		if ( '' !== $hobby && false === stripos( $hobby, 'Classic cheerful breakfast' ) ) {
			$hobbies[ $hobby ] = ( $hobbies[ $hobby ] ?? 0 ) + 1;
		}
		$suffix = cornflex_box_row_suffix( $row );
		if ( '' !== $suffix ) {
			$suffixes[ $suffix ] = ( $suffixes[ $suffix ] ?? 0 ) + 1;
		}
	}
	arsort( $hobbies );
	arsort( $suffixes );

	$week_total = (int) $counts['week'] + (int) $counts['failed_week'];
	$total      = (int) $counts['total'];
	$balance    = cornflex_box_atlas_balance();
	$per_cover  = null !== $balance ? cornflex_dashboard_cost_per_cover( $balance, $total ) : null;

	return [
		'today'        => (int) $counts['today'],
		'failed_today' => (int) $counts['failed_today'],
		'week'         => (int) $counts['week'],
		'month'        => (int) $counts['month'],
		'total'        => $total,
		'success_rate' => $week_total ? round( 100 * (int) $counts['week'] / $week_total ) : null,
		'avg_seconds'  => $job_stats['avg_seconds'] ? (int) round( $job_stats['avg_seconds'] ) : null,
		'active'       => (int) $job_stats['active'],
		'balance'      => $balance,
		'per_cover'    => $per_cover,
		'covers_left'  => $per_cover ? (int) floor( $balance / $per_cover ) : null,
		'gemini'       => get_option( 'cornflex_box_gemini_problem' ),
		'days'         => $days,
		'hobbies'      => array_slice( $hobbies, 0, 5, true ),
		'suffixes'     => array_slice( $suffixes, 0, 5, true ),
		'latest'       => $latest,
	];
}

/**
 * A horizontal bar list (top themes / types).
 *
 * @param string $title Heading.
 * @param array  $items Label => count.
 * @return void
 */
function cornflex_dashboard_bar_list( $title, array $items ) {
	$max = $items ? max( $items ) : 0;
	?>
	<div class="cfd-card">
		<h3><?php echo esc_html( $title ); ?></h3>
		<?php if ( ! $items ) : ?>
			<p class="cfd-muted">עדיין אין נתונים.</p>
		<?php else : ?>
			<ul class="cfd-bars">
				<?php foreach ( $items as $label => $count ) : ?>
					<li>
						<span class="cfd-bars-label"><?php echo esc_html( $label ); ?></span>
						<span class="cfd-bars-track"><span class="cfd-bars-fill" style="width: <?php echo esc_attr( max( 4, round( 100 * $count / $max ) ) ); ?>%;"></span></span>
						<span class="cfd-bars-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The panel.
 *
 * @return void
 */
function cornflex_dashboard_render() {
	$d         = cornflex_dashboard_data();
	$threshold = cornflex_box_atlas_low_threshold();
	$low       = null !== $d['balance'] && $d['balance'] < $threshold;
	$max_day   = max( 1, max( array_column( $d['days'], 'ok' ) ) );
	$chart_h   = 140;
	$bar_w     = 22;
	$gap       = 14;
	$chart_w   = count( $d['days'] ) * ( $bar_w + $gap );
	?>
	<div class="cfd">
		<div class="cfd-head">
			<h2>Cornflex</h2>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cereal-box-history' ) ); ?>" class="button">לכל ההפקות</a>
		</div>

		<div class="cfd-tiles">
			<div class="cfd-tile cfd-tile-hero">
				<div class="cfd-tile-label">נוצרו היום</div>
				<div class="cfd-tile-value"><?php echo esc_html( number_format_i18n( $d['today'] ) ); ?></div>
				<?php if ( $d['failed_today'] ) : ?>
					<div class="cfd-tile-note">+ <?php echo esc_html( $d['failed_today'] ); ?> שנכשלו</div>
				<?php endif; ?>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">7 ימים</div>
				<div class="cfd-tile-value"><?php echo esc_html( number_format_i18n( $d['week'] ) ); ?></div>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">30 יום</div>
				<div class="cfd-tile-value"><?php echo esc_html( number_format_i18n( $d['month'] ) ); ?></div>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">סה״כ</div>
				<div class="cfd-tile-value"><?php echo esc_html( number_format_i18n( $d['total'] ) ); ?></div>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">הצלחה (7 ימים)</div>
				<div class="cfd-tile-value"><?php echo null === $d['success_rate'] ? '—' : esc_html( $d['success_rate'] ) . '%'; ?></div>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">זמן יצירה ממוצע</div>
				<div class="cfd-tile-value"><?php echo null === $d['avg_seconds'] ? '—' : esc_html( gmdate( 'i:s', $d['avg_seconds'] ) ); ?></div>
				<div class="cfd-tile-note">ב-24 השעות האחרונות</div>
			</div>
			<div class="cfd-tile">
				<div class="cfd-tile-label">בתהליך עכשיו</div>
				<div class="cfd-tile-value"><?php echo esc_html( $d['active'] ); ?></div>
			</div>
		</div>

		<div class="cfd-row">
			<div class="cfd-card cfd-chart-card">
				<h3>קופסאות שנוצרו ב-14 הימים האחרונים</h3>
				<div class="cfd-chart" dir="ltr">
					<svg viewBox="0 0 <?php echo esc_attr( $chart_w ); ?> <?php echo esc_attr( $chart_h + 22 ); ?>" role="img" aria-label="קופסאות שנוצרו בכל יום ב-14 הימים האחרונים">
						<line class="cfd-baseline" x1="0" y1="<?php echo esc_attr( $chart_h ); ?>" x2="<?php echo esc_attr( $chart_w ); ?>" y2="<?php echo esc_attr( $chart_h ); ?>"></line>
						<?php
						foreach ( $d['days'] as $i => $day ) :
							$x  = $i * ( $bar_w + $gap ) + $gap / 2;
							$bh = $day['ok'] ? max( 4, round( ( $chart_h - 18 ) * $day['ok'] / $max_day ) ) : 0;
							$tip = $day['label'] . ': ' . $day['ok'] . ' נוצרו' . ( $day['failed'] ? ', ' . $day['failed'] . ' נכשלו' : '' );
							?>
							<g class="cfd-col" data-tip="<?php echo esc_attr( $tip ); ?>">
								<rect class="cfd-hit" x="<?php echo esc_attr( $x - $gap / 2 ); ?>" y="0" width="<?php echo esc_attr( $bar_w + $gap ); ?>" height="<?php echo esc_attr( $chart_h ); ?>"></rect>
								<?php if ( $bh ) : ?>
									<path class="cfd-bar" d="<?php echo esc_attr( sprintf( 'M%1$s %2$s V%3$s Q%1$s %4$s %5$s %4$s H%6$s Q%7$s %4$s %7$s %3$s V%2$s Z', $x, $chart_h, $chart_h - $bh + 4, $chart_h - $bh, $x + 4, $x + $bar_w - 4, $x + $bar_w ) ); ?>"></path>
								<?php endif; ?>
								<?php if ( $day['ok'] && ( $day['ok'] === $max_day || $i === count( $d['days'] ) - 1 ) ) : ?>
									<text class="cfd-value" x="<?php echo esc_attr( $x + $bar_w / 2 ); ?>" y="<?php echo esc_attr( $chart_h - $bh - 6 ); ?>"><?php echo esc_html( $day['ok'] ); ?></text>
								<?php endif; ?>
								<?php if ( 0 === $i % 2 || $i === count( $d['days'] ) - 1 ) : ?>
									<text class="cfd-axis" x="<?php echo esc_attr( $x + $bar_w / 2 ); ?>" y="<?php echo esc_attr( $chart_h + 16 ); ?>"><?php echo esc_html( $day['label'] ); ?></text>
								<?php endif; ?>
							</g>
						<?php endforeach; ?>
					</svg>
					<div class="cfd-tooltip" hidden></div>
				</div>
				<details class="cfd-table">
					<summary>הצגה כטבלה</summary>
					<table>
						<thead><tr><th>יום</th><th>נוצרו</th><th>נכשלו</th></tr></thead>
						<tbody>
							<?php foreach ( array_reverse( $d['days'] ) as $day ) : ?>
								<tr><td><?php echo esc_html( $day['label'] ); ?></td><td><?php echo esc_html( $day['ok'] ); ?></td><td><?php echo esc_html( $day['failed'] ); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			</div>

			<div class="cfd-card cfd-accounts">
				<h3>חשבונות</h3>

				<div class="cfd-account">
					<div class="cfd-account-name">Atlas Cloud <span class="cfd-muted">(יצירת התמונות)</span></div>
					<?php if ( null === $d['balance'] ) : ?>
						<div class="cfd-status cfd-status-warn">⚠ לא הצלחתי לקרוא את היתרה</div>
					<?php else : ?>
						<div class="cfd-account-value">$<?php echo esc_html( number_format( $d['balance'], 2 ) ); ?></div>
						<div class="cfd-status <?php echo $low ? 'cfd-status-bad' : 'cfd-status-good'; ?>"><?php echo $low ? '⚠ נמוכה – כדאי לטעון' : '✓ תקין'; ?></div>
						<div class="cfd-muted">
							<?php if ( $d['covers_left'] ) : ?>
								מספיק לעוד כ-<?php echo esc_html( number_format_i18n( $d['covers_left'] ) ); ?> קופסאות ($<?php echo esc_html( number_format( $d['per_cover'], 3 ) ); ?> לקופסה)
							<?php else : ?>
								הערכת "כמה קופסאות נשארו" תופיע אחרי עוד כמה יצירות.
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<a href="<?php echo esc_url( CORNFLEX_BOX_ATLAS_TOPUP_URL ); ?>" target="_blank" rel="noopener">לטעינת יתרה</a>
				</div>

				<div class="cfd-account">
					<div class="cfd-account-name">Gemini <span class="cfd-muted">(בדיקת תמונה ופרומפט)</span></div>
					<?php if ( $d['gemini'] ) : ?>
						<div class="cfd-status cfd-status-bad">⚠ מסרב לבקשות – קרדיט או מכסה נגמרו</div>
					<?php else : ?>
						<div class="cfd-status cfd-status-good">✓ עובד</div>
						<div class="cfd-muted">את יתרת הקרדיט Google מציגים רק ב-AI Studio.</div>
					<?php endif; ?>
					<a href="<?php echo esc_url( CORNFLEX_BOX_GEMINI_BILLING_URL ); ?>" target="_blank" rel="noopener">לחיוב ב-AI Studio</a>
				</div>
			</div>
		</div>

		<div class="cfd-row cfd-row-3">
			<?php cornflex_dashboard_bar_list( 'הנושאים האהובים (30 יום)', $d['hobbies'] ); ?>
			<?php cornflex_dashboard_bar_list( 'סוגי הקורנפלקס (30 יום)', $d['suffixes'] ); ?>

			<div class="cfd-card">
				<h3>האחרונות</h3>
				<?php if ( ! $d['latest'] ) : ?>
					<p class="cfd-muted">עדיין אין קופסאות.</p>
				<?php else : ?>
					<div class="cfd-latest">
						<?php
						foreach ( $d['latest'] as $row ) :
							$file  = cornflex_box_image_file( $row['result_image_url'] );
							$thumb = $file['id'] ? wp_get_attachment_image_url( $file['id'], 'medium' ) : '';
							if ( ! $thumb ) {
								continue;
							}
							?>
							<a href="<?php echo esc_url( add_query_arg( 's', $row['child_name'], admin_url( 'admin.php?page=cereal-box-history' ) ) ); ?>" title="<?php echo esc_attr( $row['child_name'] . ' · ' . mysql2date( 'j.n H:i', $row['created_at'] ) ); ?>">
								<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $row['child_name'] ); ?>" loading="lazy">
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}
