<?php
/**
 * Module H — Refresh existing events from Facebook (FB = source of truth).
 * Overwrites title, description, and date/time on UPCOMING FB-imported events on each
 * sync, so edits made on Facebook propagate to existing MEC posts. (Location + featured
 * image are added in a later pass.) Recurring series get their dates from Module D, so
 * this module updates their title/content/time only and leaves date math to Module D.
 *
 * Gate: gasf_mec_enable_fb_refresh (DEFAULT OFF — must be turned on explicitly).
 * Manual test (works even when gate off):
 *   wp eval 'print_r(gasf_mec_fb_refresh_event(13479, true));'   // dry-run one event
 *   wp eval 'print_r(gasf_mec_fb_refresh_event(13479, false));'  // apply to one event
 * Task 260620-2mv.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Refresh ONE event's fields from its Facebook source. Returns a change report. */
function gasf_mec_fb_refresh_event( $post_id, $dry_run = false ) {
	$post_id = (int) $post_id;
	$p = get_post( $post_id );
	if ( ! $p || $p->post_type !== 'mec-events' ) return array( 'skip' => 'not a mec-event' );

	$fb_id = get_post_meta( $post_id, 'mec_advimp_facebook_event_id', true );
	if ( ! $fb_id ) return array( 'skip' => 'no facebook id' );
	$token = function_exists( 'gasf_mec_fb_token' ) ? gasf_mec_fb_token() : null;
	if ( ! $token ) return array( 'skip' => 'no fb token' );

	$resp = wp_remote_get(
		'https://graph.facebook.com/v18.0/' . rawurlencode( $fb_id )
		. '?fields=id,name,description,start_time,end_time,timezone&access_token=' . $token,
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $resp ) ) return array( 'skip' => 'http error: ' . $resp->get_error_message() );
	$ev = json_decode( wp_remote_retrieve_body( $resp ) );
	if ( ! $ev || isset( $ev->error ) ) {
		return array( 'skip' => 'fb error/deleted', 'http' => wp_remote_retrieve_response_code( $resp ) );
	}

	$changes = array();
	$is_recurring = (bool) get_post_meta( $post_id, 'gasf_mec_recurring_parent', true );

	// ---- title + description ----
	$post_update = array();
	if ( isset( $ev->name ) && trim( $ev->name ) !== '' && $ev->name !== $p->post_title ) {
		$changes['title'] = array( 'from' => $p->post_title, 'to' => $ev->name );
		$post_update['post_title'] = $ev->name;
	}
	$desc = isset( $ev->description ) ? (string) $ev->description : '';
	if ( $desc !== $p->post_content ) {
		$changes['content'] = 'changed (' . strlen( $p->post_content ) . ' -> ' . strlen( $desc ) . ' chars)';
		$post_update['post_content'] = $desc;
	}

	// ---- date/time (skip date for recurring series — Module D owns those) ----
	$dt_fields = null;
	if ( ! empty( $ev->start_time ) ) {
		try {
			$tzname = ! empty( $ev->timezone ) ? $ev->timezone : 'America/New_York';
			$start = new DateTime( $ev->start_time );
			$start->setTimezone( new DateTimeZone( 'America/New_York' ) );
			$end = ! empty( $ev->end_time ) ? new DateTime( $ev->end_time ) : ( clone $start )->modify( '+2 hours' );
			if ( $end instanceof DateTime ) $end->setTimezone( new DateTimeZone( 'America/New_York' ) );

			$dt_fields = array(
				'sdate' => $start->format( 'Y-m-d' ), 'sh' => (int) $start->format( 'g' ), 'sm' => (int) $start->format( 'i' ), 'sap' => $start->format( 'A' ),
				'ssec'  => (int) $start->format( 'G' ) * 3600 + (int) $start->format( 'i' ) * 60,
				'edate' => $end->format( 'Y-m-d' ), 'eh' => (int) $end->format( 'g' ), 'em' => (int) $end->format( 'i' ), 'eap' => $end->format( 'A' ),
				'esec'  => (int) $end->format( 'G' ) * 3600 + (int) $end->format( 'i' ) * 60,
			);
			$cur_sd = get_post_meta( $post_id, 'mec_start_date', true );
			$cur_ss = get_post_meta( $post_id, 'mec_start_day_seconds', true );
			$cur_es = get_post_meta( $post_id, 'mec_end_day_seconds', true );
			if ( $is_recurring ) {
				if ( (int) $cur_ss !== $dt_fields['ssec'] || (int) $cur_es !== $dt_fields['esec'] ) $changes['time'] = 'changed (recurring: time only)';
			} else {
				if ( $cur_sd !== $dt_fields['sdate'] || (int) $cur_ss !== $dt_fields['ssec'] || (int) $cur_es !== $dt_fields['esec'] ) {
					$changes['datetime'] = array( 'to' => $dt_fields['sdate'] . ' ' . $dt_fields['sh'] . ':' . sprintf('%02d',$dt_fields['sm']) . $dt_fields['sap'] );
				}
			}
		} catch ( Exception $e ) { $dt_fields = null; $changes['datetime_error'] = $e->getMessage(); }
	}

	if ( $dry_run ) return array( 'post_id' => $post_id, 'fb_id' => $fb_id, 'recurring' => $is_recurring, 'changes' => $changes ?: 'none' );
	if ( empty( $changes ) ) return array( 'post_id' => $post_id, 'changes' => 'none' );

	// ---- APPLY ----
	if ( $post_update ) { $post_update['ID'] = $post_id; wp_update_post( $post_update ); }

	if ( $dt_fields && isset( $changes['datetime'] ) ) {
		gasf_mec_write_single_datetime( $post_id, $dt_fields );
	} elseif ( $dt_fields && $is_recurring && isset( $changes['time'] ) ) {
		// recurring: update only the time-of-day meta; Module D re-applies the date math next refresh
		gasf_mec_write_time_meta_only( $post_id, $dt_fields );
	}

	if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( 'FB-REFRESH post=' . $post_id . ' changes=' . implode( ',', array_keys( $changes ) ) );
	return array( 'post_id' => $post_id, 'applied' => array_keys( $changes ) );
}

/** Write full single-event date/time (meta + mec_events table) and reschedule. Mirrors importer output. */
function gasf_mec_write_single_datetime( $post_id, $f ) {
	global $wpdb;
	update_post_meta( $post_id, 'mec_start_date', $f['sdate'] );
	update_post_meta( $post_id, 'mec_end_date', $f['edate'] );
	update_post_meta( $post_id, 'mec_start_time_hour', $f['sh'] );
	update_post_meta( $post_id, 'mec_start_time_minutes', $f['sm'] );
	update_post_meta( $post_id, 'mec_start_time_ampm', $f['sap'] );
	update_post_meta( $post_id, 'mec_end_time_hour', $f['eh'] );
	update_post_meta( $post_id, 'mec_end_time_minutes', $f['em'] );
	update_post_meta( $post_id, 'mec_end_time_ampm', $f['eap'] );
	update_post_meta( $post_id, 'mec_start_day_seconds', $f['ssec'] );
	update_post_meta( $post_id, 'mec_end_day_seconds', $f['esec'] );
	update_post_meta( $post_id, 'mec_start_datetime', $f['sdate'] . ' ' . sprintf('%d:%02d %s', $f['sh'], $f['sm'], $f['sap']) );
	update_post_meta( $post_id, 'mec_end_datetime', $f['edate'] . ' ' . sprintf('%d:%02d %s', $f['eh'], $f['em'], $f['eap']) );
	$mec_date = get_post_meta( $post_id, 'mec_date', true );
	if ( ! is_array( $mec_date ) ) $mec_date = array();
	$mec_date['start'] = array( 'date' => $f['sdate'], 'hour' => (string) $f['sh'], 'minutes' => (string) $f['sm'], 'ampm' => $f['sap'] );
	$mec_date['end']   = array( 'date' => $f['edate'], 'hour' => (string) $f['eh'], 'minutes' => (string) $f['em'], 'ampm' => $f['eap'] );
	update_post_meta( $post_id, 'mec_date', $mec_date );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}mec_events SET start=%s, end=%s, time_start=%d, time_end=%d WHERE post_id=%d",
		$f['sdate'], $f['edate'], $f['ssec'], $f['esec'], (int) $post_id ) );
	if ( class_exists( 'MEC' ) ) {
		$sch = MEC::getInstance( 'app.libraries.schedule' );
		if ( $sch && method_exists( $sch, 'reschedule' ) ) $sch->reschedule( $post_id, 300 );
	}
}

/** Update only time-of-day meta (used for recurring series; date math stays with Module D). */
function gasf_mec_write_time_meta_only( $post_id, $f ) {
	update_post_meta( $post_id, 'mec_start_time_hour', $f['sh'] );
	update_post_meta( $post_id, 'mec_start_time_minutes', $f['sm'] );
	update_post_meta( $post_id, 'mec_start_time_ampm', $f['sap'] );
	update_post_meta( $post_id, 'mec_end_time_hour', $f['eh'] );
	update_post_meta( $post_id, 'mec_end_time_minutes', $f['em'] );
	update_post_meta( $post_id, 'mec_end_time_ampm', $f['eap'] );
	update_post_meta( $post_id, 'mec_start_day_seconds', $f['ssec'] );
	update_post_meta( $post_id, 'mec_end_day_seconds', $f['esec'] );
}

/** Loop upcoming FB-imported events and refresh them. Throttled ~hourly. */
function gasf_mec_fb_refresh_all() {
	$last = (int) get_option( 'gasf_mec_fb_refresh_at', 0 );
	if ( ( time() - $last ) < 3300 ) return;
	update_option( 'gasf_mec_fb_refresh_at', time() );
	global $wpdb;
	$today = date( 'Y-m-d' );
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT p.ID FROM {$wpdb->prefix}posts p
		 JOIN {$wpdb->prefix}postmeta fb ON fb.post_id=p.ID AND fb.meta_key='mec_advimp_facebook_event_id'
		 JOIN {$wpdb->prefix}postmeta sd ON sd.post_id=p.ID AND sd.meta_key='mec_start_date'
		 WHERE p.post_type='mec-events' AND p.post_status='publish' AND sd.meta_value >= %s", $today ) );
	$n = 0;
	foreach ( $ids as $pid ) { gasf_mec_fb_refresh_event( (int) $pid, false ); $n++; }
	if ( function_exists( 'gasf_mec_log' ) ) gasf_mec_log( 'FB-REFRESH-ALL upcoming=' . $n );
}

// Only hook into the sync when explicitly enabled. The manual functions above work regardless.
if ( gasf_mec_enabled( 'gasf_mec_enable_fb_refresh', '0' ) ) {  // DEFAULT OFF
	add_action( 'mec_advimp_sync_hook', 'gasf_mec_fb_refresh_all', 997 );
}
