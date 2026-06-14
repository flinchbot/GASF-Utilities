<?php
/**
 * Plugin Name: GASF MEC Importer Fixes
 * Description: Consolidates the GASF MEC Advanced Importer fixes into one update-safe must-use plugin: cron registration, Facebook page-import defaults, manual-sync window filter, Facebook recurrence expansion, a deterministic duplicate sweep, and a request-log bloat cap. Replaces Code Snippets #17-#21.
 * Version:     1.0.0
 * Author:      GASF
 * License:     GPL-2.0-or-later
 *
 * Repo: https://github.com/flinchbot/GASF-MUPlugin_MECalendar
 *
 * ---------------------------------------------------------------------------
 * SECURITY: This file is tracked in a PUBLIC repo. NEVER hardcode secrets.
 * The Facebook Graph token is read at runtime from option
 * 'mec_advimp_auth_facebook' (the importer's own stored credential).
 * ---------------------------------------------------------------------------
 *
 * No MEC / MEC Advanced Importer core files are modified, so both stay
 * update-safe (security patches keep flowing). All behaviour lives here.
 *
 * Feature gates (wp_options, default ON unless noted). Set to '0' to disable:
 *   gasf_mec_enable_cron        Module A  - cron registration (replaces #17)
 *   gasf_mec_enable_defaults    Module B  - force FB page defaults (replaces #20)
 *   gasf_mec_enable_window      Module C  - manual-sync window filter (replaces #19)
 *   gasf_mec_enable_recurrence  Module D  - FB recurrence expansion (replaces #18)
 *   gasf_mec_enable_sweep       Module E  - deterministic dedup sweep (replaces #21)
 *   gasf_mec_enable_reqcap      Module F  - 'request' meta bloat cap (new)
 *   gasf_mec_enable_single_template Module G - branded single-event template + CSS (replaces #8/#9)
 *   gasf_mec_sweep_dryrun       DEFAULT ON - sweep only logs; set '0' to delete
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GASF_MEC_VERSION', '1.3.0' );

// Log lives OUTSIDE the web root (parent of ABSPATH), not web-fetchable.
// Falls back silently if unwritable (logging is best-effort).
define( 'GASF_MEC_LOG', dirname( untrailingslashit( ABSPATH ) ) . '/gasf-mec-importer.log' );
define( 'GASF_MEC_LOG_MAX', 1048576 ); // 1 MB, then rotate to .1

/**
 * Feature gate: true unless the option is explicitly '0'/0/false.
 */
function gasf_mec_enabled( $option, $default = '1' ) {
	$v = get_option( $option, $default );
	return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
}

/**
 * Best-effort, size-capped log line. Server-local time.
 */
function gasf_mec_log( $msg ) {
	$f = GASF_MEC_LOG;
	if ( @file_exists( $f ) && @filesize( $f ) > GASF_MEC_LOG_MAX ) {
		@rename( $f, $f . '.1' );
	}
	@file_put_contents( $f, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
}

/* =========================================================================
 * MODULE A — Cron registration (replaces snippet #17)
 *
 * The importer's MEC_Advanced_Importer_Base::setup_sync_cron() runs on EVERY
 * `init` and clears + re-schedules its hooks to time(), so on a trafficked
 * site the next-run is perpetually pushed forward and the sync hook never
 * matures. Remove that handler and register the schedule ONCE (never clear).
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_cron' ) ) {

	add_action( 'init', function () {
		remove_action( 'init', array( 'MEC_Advanced_Importer\\MEC_Advanced_Importer_Base', 'setup_sync_cron' ) );
	}, 1 );

	add_action( 'init', function () {
		add_filter( 'cron_schedules', function ( $schedules ) {
			if ( ! isset( $schedules['every_minute'] ) ) {
				$schedules['every_minute'] = array(
					'interval' => 60,
					'display'  => 'Every Minute',
				);
			}
			return $schedules;
		} );

		// Register once; never clear an existing schedule.
		if ( ! wp_next_scheduled( 'mec_advimp_sync_hook' ) ) {
			wp_schedule_event( time(), 'every_minute', 'mec_advimp_sync_hook' );
		}
		if ( ! wp_next_scheduled( 'mec_advimp_cleanup_hook' ) ) {
			wp_schedule_event( time(), 'every_minute', 'mec_advimp_cleanup_hook' );
		}
	}, 20 );
}

/* =========================================================================
 * MODULE B — Force Facebook page-import defaults (replaces snippet #20)
 *
 * Hard-locks every manual Facebook import to importType=page,
 * importTypeVal=GermanTampa (server-side, before the importer reads $_POST),
 * and defaults the date window in the admin UI to today .. today+60d.
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_defaults' ) ) {

	// 1) Server-side enforcement - runs before the importer reads $_POST.
	add_action( 'admin_init', function () {
		if ( ! isset( $_POST['action'] ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_POST['action'] ) );

		$fb_actions = array( 'facebook_get_events', 'mec_advimp_schedule_events' );
		if ( ! in_array( $action, $fb_actions, true ) ) {
			return;
		}

		$is_fb = ( isset( $_POST['importType'] ) || strpos( $action, 'facebook' ) !== false );
		if ( ! $is_fb ) {
			return;
		}

		$_POST['importType']    = 'page';
		$_POST['importTypeVal'] = 'GermanTampa';
	}, 1 );

	// 2) Admin UI: lock import-by/page and default the date window.
	add_action( 'admin_footer', function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( $screen->id, 'MEC-advimp' ) === false ) {
			return;
		}

		$today  = date_i18n( 'Y-m-d' );
		$plus60 = date_i18n( 'Y-m-d', strtotime( '+60 days' ) );
		?>
		<script>
		(function(){
			var GASF_SDATE = <?php echo wp_json_encode( $today ); ?>;
			var GASF_EDATE = <?php echo wp_json_encode( $plus60 ); ?>;

			function lockUI(){
				var sel  = document.getElementById('mec-advimp-importby-inp');
				var page = document.getElementById('mec-advimp-importby-page-inp');
				if ( ! sel || ! page ) return false;

				var hasPage = false;
				for ( var i = 0; i < sel.options.length; i++ ) {
					if ( sel.options[i].value === 'page' ) { hasPage = true; break; }
				}
				if ( hasPage ) {
					sel.value = 'page';
					if ( window.jQuery ) { jQuery(sel).trigger('change'); }
					else { sel.dispatchEvent( new Event('change', { bubbles: true }) ); }
				}
				sel.setAttribute('disabled','disabled');
				sel.style.opacity = '0.7';

				page.value = 'GermanTampa';
				page.setAttribute('readonly','readonly');
				page.style.backgroundColor = '#f0f0f0';
				page.style.cursor = 'not-allowed';

				var row = document.getElementById('mec-advimp-importby-page');
				if ( row ) { row.style.display = 'block'; }

				var sd = document.getElementById('mec-advimp-import-sdate');
				var ed = document.getElementById('mec-advimp-import-edate');
				if ( sd && ! sd.getAttribute('data-gasf-set') ) {
					sd.value = GASF_SDATE;
					sd.setAttribute('data-gasf-set','1');
					if ( window.jQuery ) { jQuery(sd).trigger('change'); }
				}
				if ( ed && ! ed.getAttribute('data-gasf-set') ) {
					ed.value = GASF_EDATE;
					ed.setAttribute('data-gasf-set','1');
					if ( window.jQuery ) { jQuery(ed).trigger('change'); }
				}

				return true;
			}
			function attempt(n){ if ( lockUI() ) return; if ( n>0 ) setTimeout(function(){ attempt(n-1); }, 300); }
			if ( document.readyState !== 'loading' ) attempt(20);
			else document.addEventListener('DOMContentLoaded', function(){ attempt(20); });
		})();
		</script>
		<?php
	}, 99 );
}

/* =========================================================================
 * MODULE C — Manual-sync window response filter (replaces snippet #19)
 *
 * On a manual date-range sync (POST start_date + end_date), strip Facebook
 * events whose start date is outside the window from the Graph API response
 * before the importer parses it. Scheduled cron syncs pass through untouched.
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_window' ) ) {

	add_filter( 'http_response', function ( $response, $args, $url ) {

		if ( strpos( $url, 'graph.facebook.com' ) === false ) {
			return $response;
		}
		if ( empty( $_POST['start_date'] ) || empty( $_POST['end_date'] ) ) {
			return $response;
		}

		$win_start = sanitize_text_field( wp_unslash( $_POST['start_date'] ) );
		$win_end   = sanitize_text_field( wp_unslash( $_POST['end_date'] ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $win_start ) ) {
			return $response;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $win_end ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return $response;
		}

		$data = json_decode( $body );
		if ( ! $data || ! isset( $data->data ) || ! is_array( $data->data ) ) {
			return $response;
		}

		$local_date = function ( $ts ) {
			if ( ! is_string( $ts ) || strlen( $ts ) < 10 ) {
				return null;
			}
			return substr( $ts, 0, 10 );
		};

		$kept = array();
		foreach ( $data->data as $event ) {

			// Recurring series: keep if any occurrence date is in window.
			if ( ! empty( $event->event_times ) && is_array( $event->event_times ) ) {
				$any_in = false;
				foreach ( $event->event_times as $occ ) {
					if ( isset( $occ->start_time ) ) {
						$d = $local_date( $occ->start_time );
						if ( $d !== null && $d >= $win_start && $d <= $win_end ) {
							$any_in = true;
							break;
						}
					}
				}
				if ( $any_in ) {
					$kept[] = $event;
				}
				continue;
			}

			if ( isset( $event->start_time ) ) {
				$d = $local_date( $event->start_time );
				if ( $d !== null && $d >= $win_start && $d <= $win_end ) {
					$kept[] = $event;
				}
				continue;
			}

			$kept[] = $event;
		}

		$data->data = array_values( $kept );
		if ( isset( $data->total_records ) ) {
			$data->total_records = count( $data->data );
		}

		$new_body = wp_json_encode( $data );
		if ( $new_body !== false && is_array( $response ) ) {
			$response['body'] = $new_body;
		}

		return $response;

	}, 10, 3 );
}

/* =========================================================================
 * MODULE D — Facebook recurrence as NATIVE MEC recurring events (replaces #18)
 *
 * A recurring Facebook event is returned by the importer as ONE parent id whose
 * event_times lists the occurrences. MEC renders from its own mec_events/mec_dates
 * tables, so the correct model is ONE MEC event marked recurring (repeat_type
 * 'custom_days' = the explicit occurrence dates) — MEC's scheduler then generates a
 * calendar entry per date. (The old approach created a post per occurrence; those
 * never got mec_dates rows, so they never rendered.)
 *
 *  - On import: convert the imported post into a native custom_days recurring event
 *    covering all the Facebook occurrence dates.
 *  - Hourly refresh pass: re-sync each managed series' dates from Facebook, so dates
 *    added/removed on Facebook appear automatically (Facebook stays source of truth).
 *
 * The post keeps the parent FB id as mec_advimp_facebook_event_id, so the importer's
 * own remove_exists_event_ids recognises it and never re-creates it (no churn).
 * Managed posts carry meta gasf_mec_recurring_parent = parent id.
 * ========================================================================= */

/** Facebook Graph token from the importer's stored credential (never hardcoded). */
function gasf_mec_fb_token() {
	$config = get_option( 'mec_advimp_auth_facebook', array() );
	$token  = null;
	if ( is_array( $config ) ) {
		foreach ( $config as $account ) {
			if ( ! empty( $account['access_token'] ) ) {
				$token = $account['access_token'];
			}
		}
	}
	return $token;
}

/**
 * Convert/refresh a post into a native MEC custom_days recurring event whose dates
 * match the Facebook recurring event's current occurrences. Idempotent. Returns the
 * occurrence-date count, or 0 if the event is not (or no longer) recurring.
 * Preserves the post's existing time-of-day, location, organizer, title, content.
 */
function gasf_mec_apply_recurrence( $post_id, $parent_fb_id ) {
	global $wpdb;

	$token = gasf_mec_fb_token();
	if ( ! $token ) {
		return 0;
	}

	$resp = wp_remote_get(
		'https://graph.facebook.com/v18.0/' . rawurlencode( $parent_fb_id )
			. '?fields=id,name,start_time,end_time,event_times,timezone&access_token=' . $token,
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $resp ) ) {
		return 0;
	}

	$event = json_decode( wp_remote_retrieve_body( $resp ) );
	if ( ! $event || isset( $event->error ) ) {
		return 0;
	}

	// Not recurring (or no longer): leave the post as a plain single event.
	if ( empty( $event->event_times ) || ! is_array( $event->event_times ) || count( $event->event_times ) <= 1 ) {
		return 0;
	}

	$tz = isset( $event->timezone ) ? $event->timezone : ( get_option( 'timezone_string' ) ?: 'America/New_York' );

	// Unique occurrence dates (Y-m-d), chronological.
	$dates = array();
	foreach ( $event->event_times as $occ ) {
		if ( empty( $occ->start_time ) ) {
			continue;
		}
		try {
			$dt = new DateTime( $occ->start_time, new DateTimeZone( $tz ) );
		} catch ( Exception $e ) {
			$dt = new DateTime( $occ->start_time );
		}
		$dates[ $dt->format( 'Y-m-d' ) ] = true;
	}
	$dates = array_keys( $dates );
	sort( $dates );
	if ( count( $dates ) <= 1 ) {
		return 0;
	}

	$first = $dates[0];
	$last  = end( $dates );
	$rest  = array_slice( $dates, 1 ); // render adds the start date itself; days = the rest

	// Preserve the post's existing time-of-day (set by the importer for occurrence #1).
	$mec_date = get_post_meta( $post_id, 'mec_date', true );
	if ( ! is_array( $mec_date ) || ! isset( $mec_date['start'] ) || ! isset( $mec_date['end'] ) ) {
		$mec_date = array(
			'start' => array( 'date' => $first, 'hour' => '6', 'minutes' => '00', 'ampm' => 'PM' ),
			'end'   => array( 'date' => $first, 'hour' => '8', 'minutes' => '00', 'ampm' => 'PM' ),
		);
	}
	$mec_date['start']['date'] = $first;
	$mec_date['end']['date']   = $first;
	$mec_date['repeat']        = array( 'end' => 'date', 'end_at_date' => $last );
	$mec_date['allday']        = isset( $mec_date['allday'] ) ? $mec_date['allday'] : 0;
	$mec_date['hide_time']     = isset( $mec_date['hide_time'] ) ? $mec_date['hide_time'] : 0;
	$mec_date['hide_end_time'] = isset( $mec_date['hide_end_time'] ) ? $mec_date['hide_end_time'] : 0;
	$mec_date['comment']       = isset( $mec_date['comment'] ) ? $mec_date['comment'] : '';

	update_post_meta( $post_id, 'mec_repeat_status', 1 );
	update_post_meta( $post_id, 'mec_repeat_type', 'custom_days' );
	update_post_meta( $post_id, 'mec_start_date', $first );
	update_post_meta( $post_id, 'mec_end_date', $first );
	update_post_meta( $post_id, 'mec_repeat_end', 'date' );
	update_post_meta( $post_id, 'mec_repeat_end_at_date', $last );
	update_post_meta( $post_id, 'mec_date', $mec_date );
	update_post_meta( $post_id, 'gasf_mec_recurring_parent', (string) $parent_fb_id );

	// Build the custom-day list in MEC's format: "startdate:enddate:HH-MM-AMPM:HH-MM-AMPM"
	// per occurrence (render.php reads cday[0]=start date, cday[1]=end date, cday[2]=start
	// time, cday[3]=end time). Times come from the event's own mec_date so every occurrence
	// keeps the same time-of-day.
	$sh = (int) ( $mec_date['start']['hour'] ?? 6 );
	$sm = (int) ( $mec_date['start']['minutes'] ?? 0 );
	$sa = $mec_date['start']['ampm'] ?? 'PM';
	$eh = (int) ( $mec_date['end']['hour'] ?? 8 );
	$em = (int) ( $mec_date['end']['minutes'] ?? 0 );
	$ea = $mec_date['end']['ampm'] ?? 'PM';
	$st_str = sprintf( '%02d-%02d-%s', $sh, $sm, $sa );
	$et_str = sprintf( '%02d-%02d-%s', $eh, $em, $ea );
	$day_entries = array();
	foreach ( $rest as $d ) {
		$day_entries[] = $d . ':' . $d . ':' . $st_str . ':' . $et_str;
	}

	// Update the event's row in MEC's own table. CRITICAL: `end` is the end date of the
	// FIRST occurrence (single day = $first), NOT the repeat-finish — MEC's custom_days
	// renderer uses mec_events.end as the first occurrence's end, so setting it to the last
	// date makes the first occurrence span the whole range (a 57-day bar on every day).
	// The repeat-finish lives in the mec_repeat_end_at_date meta set above.
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}mec_events SET `start`=%s, `end`=%s, `repeat`=1, `rinterval`=NULL, `days`=%s WHERE `post_id`=%d",
		$first, $first, implode( ',', $day_entries ), (int) $post_id
	) );

	// Let MEC regenerate mec_dates (clean + schedule) from the custom-day list.
	if ( class_exists( 'MEC' ) ) {
		$schedule = MEC::getInstance( 'app.libraries.schedule' );
		if ( $schedule && method_exists( $schedule, 'reschedule' ) ) {
			$schedule->reschedule( $post_id, 300 );
		}
	}

	gasf_mec_log( sprintf( 'RECUR-NATIVE post=%d parent=%s dates=%d (%s..%s)',
		(int) $post_id, $parent_fb_id, count( $dates ), $first, $last ) );

	return count( $dates );
}

if ( gasf_mec_enabled( 'gasf_mec_enable_recurrence' ) ) {

	// On a fresh import of a Facebook event, convert it to a native recurring event
	// if Facebook says it recurs. Single events are left untouched. No sibling posts.
	add_action( 'added_post_meta', function ( $mid, $post_id, $meta_key, $meta_value ) {
		if ( $meta_key !== 'mec_advimp_facebook_event_id' ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'mec-events' ) {
			return;
		}
		static $busy = array();
		if ( isset( $busy[ $meta_value ] ) ) {
			return;
		}
		$busy[ $meta_value ] = true;
		gasf_mec_apply_recurrence( $post_id, (string) $meta_value );
		unset( $busy[ $meta_value ] );
	}, 10, 4 );

	// Hourly refresh: re-sync every managed recurring series from Facebook so dates
	// added/removed on Facebook show up. Throttled to ~once an hour across all series.
	function gasf_mec_refresh_recurring() {
		$last = (int) get_option( 'gasf_mec_recur_refresh_at', 0 );
		if ( ( time() - $last ) < 3300 ) {
			return;
		}
		update_option( 'gasf_mec_recur_refresh_at', time() );

		$ids = get_posts( array(
			'post_type'      => 'mec-events',
			'post_status'    => 'publish',
			'meta_key'       => 'gasf_mec_recurring_parent',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		) );
		foreach ( $ids as $pid ) {
			$parent = get_post_meta( $pid, 'gasf_mec_recurring_parent', true );
			if ( $parent ) {
				gasf_mec_apply_recurrence( $pid, $parent );
			}
		}
	}
	add_action( 'mec_advimp_sync_hook', 'gasf_mec_refresh_recurring', 998 );
}

/* =========================================================================
 * MODULE E — Deterministic duplicate sweep (replaces snippet #21)  *** CORE ***
 *
 * Does NOT depend on catching any MEC save hook. After each importer sync
 * tick, collapse duplicate mec-events. Dedup key = facebook event id +
 * mec_start_date + normalised title, keep the OLDEST (lowest ID).
 *
 *   same id + same date + same title = re-import duplicate -> delete newer
 *   same id + same date + DIFF title = distinct same-day event   -> KEEP
 *   same id + different date         = legitimate recurrence      -> KEEP
 *
 * The candidate (id,date) grouping is done in SQL (short varchar columns);
 * the title sub-grouping is done in PHP, so we never GROUP BY / filesort the
 * TEXT title column (this host has a tiny sort_buffer_size).
 *
 * Dry-run by default (option gasf_mec_sweep_dryrun): logs candidates without
 * deleting. Set the option to '0' to enable real deletion.
 * ========================================================================= */
function gasf_mec_dedup_sweep( $dry_run = null ) {
	global $wpdb;

	if ( $dry_run === null ) {
		$dry_run = gasf_mec_enabled( 'gasf_mec_sweep_dryrun' ); // default ON = safe
	}

	static $running = false;
	if ( $running ) {
		return array( 'groups' => 0, 'deleted' => 0, 'dry_run' => $dry_run, 'skipped' => 'reentrant' );
	}
	$running = true;

	$tag = $dry_run ? '[DRY]' : '';

	// Step 1: candidate (fb_id, start_date) groups with >1 live post. Cheap:
	// joins on indexed short meta_value columns, no TEXT in GROUP BY.
	$candidates = $wpdb->get_results(
		"SELECT fb.meta_value AS fb_id, sd.meta_value AS start_date, COUNT(DISTINCT p.ID) AS n
		 FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} fb ON fb.post_id = p.ID AND fb.meta_key = 'mec_advimp_facebook_event_id'
		 JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = 'mec_start_date'
		 WHERE p.post_type = 'mec-events'
		   AND p.post_status NOT IN ('trash','auto-draft','inherit')
		   AND fb.meta_value <> '' AND sd.meta_value <> ''
		 GROUP BY fb.meta_value, sd.meta_value
		 HAVING n > 1"
	);

	$groups  = 0;
	$deleted = 0;

	if ( $candidates ) {
		foreach ( $candidates as $c ) {

			// Step 2: pull the posts in this (fb_id,date) group with titles,
			// oldest first, and sub-group by normalised title in PHP.
			$posts = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_title
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} fb ON fb.post_id = p.ID AND fb.meta_key = 'mec_advimp_facebook_event_id' AND fb.meta_value = %s
				 JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = 'mec_start_date' AND sd.meta_value = %s
				 WHERE p.post_type = 'mec-events'
				   AND p.post_status NOT IN ('trash','auto-draft','inherit')
				 ORDER BY p.ID ASC",
				$c->fb_id, $c->start_date
			) );

			$by_title = array();
			foreach ( $posts as $po ) {
				$k = strtolower( trim( (string) $po->post_title ) );
				// Normalise away the " (recurring)" tag Module D appends, so an occurrence
				// imported as "X" and re-imported/expanded as "X (recurring)" dedupe together
				// (while genuinely different same-date titles still stay separate).
				if ( substr( $k, -12 ) === ' (recurring)' ) {
					$k = trim( substr( $k, 0, -12 ) );
				}
				$by_title[ $k ][] = (int) $po->ID;
			}

			foreach ( $by_title as $ids ) {
				if ( count( $ids ) < 2 ) {
					continue; // distinct title on same (id,date) => keep both
				}
				sort( $ids, SORT_NUMERIC );
				$keep   = array_shift( $ids ); // oldest
				$groups++;
				gasf_mec_log( sprintf(
					'SWEEP%s fb=%s date=%s keep=%d delete=%s',
					$tag, $c->fb_id, $c->start_date, $keep, implode( ',', $ids )
				) );
				if ( ! $dry_run ) {
					$keep_marker = get_post_meta( $keep, 'mec_source_event_id', true );
					foreach ( $ids as $del_id ) {
						// Inherit the recurring-parent marker (mec_source_event_id) onto the
						// kept (oldest) post before deleting, so the importer's own dedup
						// recognises this occurrence as already imported — stops the churn
						// while preserving the established (oldest) post + its permalink.
						if ( empty( $keep_marker ) ) {
							$m = get_post_meta( $del_id, 'mec_source_event_id', true );
							if ( ! empty( $m ) ) {
								update_post_meta( $keep, 'mec_source_event_id', $m );
								$keep_marker = $m;
							}
						}
						wp_delete_post( $del_id, true );
						$deleted++;
					}
				}
			}
		}
	}

	if ( $groups ) {
		gasf_mec_log( sprintf( 'SWEEP%s done: %d dup-group(s), %d deleted', $tag, $groups, $deleted ) );
	}

	$running = false;
	return array( 'groups' => $groups, 'deleted' => $deleted, 'dry_run' => (bool) $dry_run );
}

if ( gasf_mec_enabled( 'gasf_mec_enable_sweep' ) ) {
	// Runs right AFTER the importer's run_sync_cron (default priority 10).
	add_action( 'mec_advimp_sync_hook', 'gasf_mec_dedup_sweep', 999 );
	// Manual trigger: do_action('gasf_mec_run_sweep');  (honours dry-run option)
	add_action( 'gasf_mec_run_sweep', 'gasf_mec_dedup_sweep' );
}

/* =========================================================================
 * MODULE F — 'request' meta bloat cap (new)
 *
 * The importer appends a 'request' postmeta row on every step of every sync
 * (facebook.php / google.php / thirdparty.php) onto the schedule/history post.
 * These are read back only by the admin progress UI. On the cron path nobody
 * watches that UI, so the rows are pure bloat (19k+ rows, 6,991 on post 14656).
 * Block the write when NOT in an admin-ajax request (i.e. the cron/CLI path),
 * preserving the live manual-import progress UI.
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_reqcap' ) ) {

	add_filter( 'add_post_metadata', function ( $check, $object_id, $meta_key, $meta_value, $unique ) {
		if ( $meta_key === 'request' && ! wp_doing_ajax() ) {
			return true; // short-circuit add_metadata(): skip the DB write
		}
		return $check; // null normally -> proceed as usual
	}, 10, 5 );
}

/* =========================================================================
 * Module G - Branded single-event template + CSS  (replaces Code Snippets #8/#9)
 *
 * Points MEC at our git-tracked single-event template and injects gasf-events.css
 * inline (the mu-plugin dir lives OUTSIDE the web root, so plugins_url() can't
 * resolve it). Survives WordPress theme reinstalls - no theme-dir dependency.
 * Gate: gasf_mec_enable_single_template (default ON).
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_single_template' ) ) {

	// MEC has NO 'mec_single_event_template' filter (the old Code Snippet #8 hooked a
	// non-existent filter, so it never actually swapped the template — the theme-dir
	// file was picked up by locate_template() instead, and the 2026-06-08 reinstall
	// wiped it). MEC chooses the single template on 'template_include' at priority 99
	// (app/libraries/factory.php -> parser::template -> locate_template). Hook at 100
	// to override MEC's choice with our git-tracked template.
	add_filter( 'template_include', function ( $template ) {
		if ( is_singular( 'mec-events' ) ) {
			$custom = __DIR__ . '/templates/single-mec-events.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
	}, 100 );

	add_action( 'wp_enqueue_scripts', function () {
		if ( ! is_singular( 'mec-events' ) ) {
			return;
		}
		$css = __DIR__ . '/assets/gasf-events.css';
		if ( ! is_readable( $css ) ) {
			return;
		}
		wp_register_style( 'gasf-events', false );
		wp_enqueue_style( 'gasf-events' );
		wp_add_inline_style( 'gasf-events', file_get_contents( $css ) );
	} );
}
