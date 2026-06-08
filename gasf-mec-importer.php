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
 *   gasf_mec_sweep_dryrun       DEFAULT ON - sweep only logs; set '0' to delete
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GASF_MEC_VERSION', '1.1.0' );

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
 * MODULE D — Facebook recurrence expansion (replaces snippet #18)
 *
 * MEC's importer fetches event_times but ignores it, storing a recurring FB
 * event as one event. Expand each occurrence into its own MEC event (distinct
 * occurrence id + date, mec_advimp_recurring=1, " (recurring)" title tag).
 * Hooks added_post_meta on mec_advimp_facebook_event_id. The static
 * $processing guard prevents recursion when this creates new posts.
 * ========================================================================= */
if ( gasf_mec_enabled( 'gasf_mec_enable_recurrence' ) ) {

	add_action( 'added_post_meta', function ( $mid, $post_id, $meta_key, $meta_value ) {

		if ( $meta_key !== 'mec_advimp_facebook_event_id' ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'mec-events' ) {
			return;
		}

		static $processing = array();
		if ( isset( $processing[ $meta_value ] ) ) {
			return;
		}
		$processing[ $meta_value ] = true;

		// Manual-sync window (if any).
		$win_start = null;
		$win_end   = null;
		$si = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$ei = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		if ( $si !== '' && $ei !== '' ) {
			$win_start = strtotime( $si . ' 00:00:00' );
			$win_end   = strtotime( $ei . ' 23:59:59' );
			if ( ! $win_start || ! $win_end ) {
				$win_start = null;
				$win_end   = null;
			}
		}
		$in_window = function ( $start_str ) use ( $win_start, $win_end ) {
			if ( $win_start === null ) {
				return true;
			}
			$t = strtotime( $start_str );
			return ( $t !== false && $t >= $win_start && $t <= $win_end );
		};

		$tag = function ( $title ) {
			return ( substr( $title, -12 ) === ' (recurring)' ) ? $title : $title . ' (recurring)';
		};

		// Live Facebook token from the importer's stored credential (no hardcoding).
		$config = get_option( 'mec_advimp_auth_facebook', array() );
		$token  = null;
		if ( is_array( $config ) ) {
			foreach ( $config as $account ) {
				if ( ! empty( $account['access_token'] ) ) {
					$token = $account['access_token'];
				}
			}
		}
		if ( ! $token ) {
			unset( $processing[ $meta_value ] );
			return;
		}

		$url  = 'https://graph.facebook.com/v18.0/' . rawurlencode( $meta_value )
			. '?fields=id,name,description,start_time,end_time,event_times,timezone,cover,place'
			. '&access_token=' . $token;
		$resp = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $resp ) ) {
			unset( $processing[ $meta_value ] );
			return;
		}

		$event = json_decode( wp_remote_retrieve_body( $resp ) );

		if ( ! $event || isset( $event->error ) || empty( $event->event_times ) || count( $event->event_times ) <= 1 ) {
			unset( $processing[ $meta_value ] );
			return;
		}

		$occurrences = $event->event_times;
		usort( $occurrences, function ( $a, $b ) {
			return strtotime( $a->start_time ) <=> strtotime( $b->start_time );
		} );

		$timezone = isset( $event->timezone ) ? $event->timezone : get_option( 'timezone_string', 'America/New_York' );

		$parse = function ( $time_str, $tz ) {
			try {
				$dt = new DateTime( $time_str, new DateTimeZone( $tz ) );
			} catch ( Exception $e ) {
				$dt = new DateTime( $time_str );
			}
			return array(
				'date'    => $dt->format( 'Y-m-d' ),
				'hour'    => $dt->format( 'g' ),
				'minutes' => $dt->format( 'i' ),
				'ampm'    => $dt->format( 'A' ),
			);
		};

		$apply_dates = function ( $pid, $start_str, $end_str, $tz ) use ( $parse ) {
			$s = $parse( $start_str, $tz );
			$e = $end_str ? $parse( $end_str, $tz ) : $s;
			update_post_meta( $pid, 'mec_start_date', $s['date'] );
			update_post_meta( $pid, 'mec_end_date', $e['date'] );
			update_post_meta( $pid, 'mec_start_time_hour', $s['hour'] );
			update_post_meta( $pid, 'mec_start_time_minutes', $s['minutes'] );
			update_post_meta( $pid, 'mec_start_time_ampm', $s['ampm'] );
			update_post_meta( $pid, 'mec_end_time_hour', $e['hour'] );
			update_post_meta( $pid, 'mec_end_time_minutes', $e['minutes'] );
			update_post_meta( $pid, 'mec_end_time_ampm', $e['ampm'] );
			update_post_meta( $pid, 'mec_date', array(
				'start'         => array( 'date' => $s['date'], 'hour' => $s['hour'], 'minutes' => $s['minutes'], 'ampm' => $s['ampm'] ),
				'end'           => array( 'date' => $e['date'], 'hour' => $e['hour'], 'minutes' => $e['minutes'], 'ampm' => $e['ampm'] ),
				'repeat'        => array( 'end' => 'date', 'end_at_date' => $e['date'] ),
				'allday'        => 0,
				'hide_time'     => 0,
				'hide_end_time' => 0,
				'comment'       => '',
			) );
		};

		$location_id    = get_post_meta( $post_id, 'mec_location_id', true );
		$organizer_id   = get_post_meta( $post_id, 'mec_organizer_id', true );
		$thumbnail_id   = get_post_thumbnail_id( $post_id );
		$category_terms = wp_get_object_terms( $post_id, 'mec_category', array( 'fields' => 'ids' ) );
		$read_more      = 'https://www.facebook.com/events/' . $event->id . '/';

		$created     = 0;
		$parent_used = false;

		foreach ( $occurrences as $occ ) {
			$occ_id = $occ->id;

			if ( ! $in_window( $occ->start_time ) ) {
				continue;
			}

			if ( ! $parent_used ) {
				$apply_dates( $post_id, $occ->start_time, isset( $occ->end_time ) ? $occ->end_time : null, $timezone );
				update_post_meta( $post_id, 'mec_advimp_facebook_event_id', $occ_id );
				update_post_meta( $post_id, 'mec_advimp_recurring', 1 );
				// Stamp the PARENT FB id as mec_source_event_id. The importer's own dedup
				// (sync.php remove_exists_event_ids checks mec_source_event_id) then
				// recognises the recurring event as already imported and stops re-creating
				// it every cycle — killing the duplicate-churn at its source. The sweep
				// inherits this marker onto the kept post if a dup is collapsed.
				update_post_meta( $post_id, 'mec_source_event_id', $meta_value );
				$p = get_post( $post_id );
				if ( $p ) {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => $tag( $p->post_title ) ) );
				}
				$parent_used = true;
				continue;
			}

			$processing[ $occ_id ] = true;

			$existing = get_posts( array(
				'post_type'      => 'mec-events',
				'post_status'    => 'any',
				'meta_key'       => 'mec_advimp_facebook_event_id',
				'meta_value'     => $occ_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			) );
			if ( ! empty( $existing ) ) {
				unset( $processing[ $occ_id ] );
				continue;
			}

			$new_id = wp_insert_post( array(
				'post_title'   => $tag( $event->name ),
				'post_content' => isset( $event->description ) ? $event->description : '',
				'post_status'  => 'publish',
				'post_type'    => 'mec-events',
			) );
			if ( ! $new_id || is_wp_error( $new_id ) ) {
				unset( $processing[ $occ_id ] );
				continue;
			}

			$apply_dates( $new_id, $occ->start_time, isset( $occ->end_time ) ? $occ->end_time : null, $timezone );
			update_post_meta( $new_id, 'mec_allday', 0 );
			update_post_meta( $new_id, 'mec_repeat_status', 0 );
			update_post_meta( $new_id, 'mec_repeat_type', '' );
			update_post_meta( $new_id, 'mec_source', 'facebook-calendar' );
			update_post_meta( $new_id, 'mec_advimp_facebook_event_id', $occ_id );
			update_post_meta( $new_id, 'mec_advimp_recurring', 1 );
			update_post_meta( $new_id, 'mec_more_info', $read_more );
			update_post_meta( $new_id, 'mec_read_more', '' );
			if ( $location_id ) {
				update_post_meta( $new_id, 'mec_location_id', $location_id );
			}
			if ( $organizer_id ) {
				update_post_meta( $new_id, 'mec_organizer_id', $organizer_id );
			}
			if ( ! empty( $category_terms ) ) {
				wp_set_object_terms( $new_id, $category_terms, 'mec_category' );
			}
			if ( $thumbnail_id ) {
				set_post_thumbnail( $new_id, $thumbnail_id );
			}

			$created++;
			unset( $processing[ $occ_id ] );
		}

		// If NO occurrence fell in the window, the importer-created parent is
		// out-of-window noise on a manual sync - remove it.
		if ( ! $parent_used && $win_start !== null ) {
			wp_delete_post( $post_id, true );
		}

		gasf_mec_log( sprintf( 'RECUR fb=%s occurrences=%d created=%d parent=%d',
			$meta_value, count( $occurrences ), $created, (int) $post_id ) );

		unset( $processing[ $meta_value ] );

	}, 10, 4 );
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
