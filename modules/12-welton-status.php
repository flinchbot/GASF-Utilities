<?php
// Migrated from Code Snippet #12 "Welton Brewing Status Blurb" on 2026-06-14 (task 260614-gj8).
// Event-context branch repointed from retired MEC (mec-events) to the native
// GASF-Events calendar (gasf_event) on 2026-07-04 (v1.4.0).
// Gate: gasf_mec_enable_welton (default ON). Original backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_welton' ) ) {
add_shortcode( "welton_status", function( $atts = [] ) {

    $atts = shortcode_atts( [
        "event_start" => "",
        "event_end"   => "",
    ], $atts, "welton_status" );

    $tz  = new DateTimeZone( "America/New_York" );
    $now = new DateTime( "now", $tz );

    // Welton hours in minutes-of-day. 1=Mon ... 7=Sun.
    $hours = [
        1 => false,                  // Mon closed
        2 => false,                  // Tue closed
        3 => [ 16*60, 21*60 ],       // Wed 4-9pm
        4 => [ 16*60, 21*60 ],       // Thu 4-9pm
        5 => [ 11*60, 22*60 ],       // Fri 11am-10pm
        6 => [ 11*60, 22*60 ],       // Sat 11am-10pm
        7 => [ 12*60, 20*60 ],       // Sun noon-8pm
    ];

    // Detect event context
    $event_start = null;
    $event_end   = null;

    if ( ! empty( $atts["event_start"] ) ) {
        try {
            $event_start = new DateTime( $atts["event_start"], $tz );
            if ( ! empty( $atts["event_end"] ) ) {
                $event_end = new DateTime( $atts["event_end"], $tz );
            }
        } catch ( Exception $e ) {
            $event_start = null;
            $event_end   = null;
        }
    } else {
        // Event context: a native GASF-Events single page. `_gasf_start`/`_gasf_end`
        // are 'Y-m-d H:i:s' strings in the site timezone (the model's source of truth).
        $pid = get_queried_object_id();
        if ( $pid && get_post_type( $pid ) === "gasf_event" ) {
            $sd     = get_post_meta( $pid, "_gasf_start",   true );
            $ed     = get_post_meta( $pid, "_gasf_end",     true );
            $allday = get_post_meta( $pid, "_gasf_all_day", true );

            if ( $sd ) {
                try {
                    if ( $allday ) {
                        $event_start = new DateTime( substr( $sd, 0, 10 ) . " 00:00", $tz );
                        $event_end   = new DateTime( substr( ( $ed ?: $sd ), 0, 10 ) . " 23:59", $tz );
                    } else {
                        $event_start = new DateTime( $sd, $tz );
                        $event_end   = $ed ? new DateTime( $ed, $tz ) : null;
                    }
                } catch ( Exception $e ) {
                    $event_start = null;
                    $event_end   = null;
                }
            }
        }
    }

    $link = '<a href="https://www.weltonbrewingcompany.com/" target="_blank" rel="noopener">Welton Brewing Co. &amp; Oyster Bar</a>';

    // Event-aware branch
    if ( $event_start instanceof DateTime ) {

        if ( ! ( $event_end instanceof DateTime ) ) {
            $event_end = clone $event_start;
            $event_end->modify( "+2 hours" );
        }

        if ( $event_end < $now ) {
            return "";
        }

        $event_dow    = (int) $event_start->format( "N" );
        $welton_today = $hours[ $event_dow ];
        if ( ! $welton_today ) {
            return "";
        }

        $w_open  = clone $event_start;
        $w_open->setTime( intdiv( $welton_today[0], 60 ), $welton_today[0] % 60, 0 );
        $w_close = clone $event_start;
        $w_close->setTime( intdiv( $welton_today[1], 60 ), $welton_today[1] % 60, 0 );

        if ( $event_end <= $w_open || $event_start >= $w_close ) {
            return "";
        }

        $is_today = ( $event_start->format( "Y-m-d" ) === $now->format( "Y-m-d" ) );

        $now_dow         = (int) $now->format( "N" );
        $welton_now      = $hours[ $now_dow ];
        $welton_open_now = false;
        if ( $welton_now ) {
            $now_min = ( (int) $now->format( "H" ) ) * 60 + (int) $now->format( "i" );
            $welton_open_now = ( $now_min >= $welton_now[0] && $now_min < $welton_now[1] );
        }

        if ( $is_today && $welton_open_now ) {
            $msg = $link . " is open! Swing by for a delicious meal or a craft brew!";
        } else {
            $msg = "Good news! " . $link . " &mdash; the on-site restaurant and brewery &mdash; will be open to enjoy a meal or a craft beer before or after the event.";
        }

        return '<div class="welton-status-blurb">' . $msg . '</div>';
    }

    // No event context: original "open now" behavior
    $day   = (int) $now->format( "N" );
    $today = $hours[ $day ];
    if ( ! $today ) return "";

    $now_min = ( (int) $now->format( "H" ) ) * 60 + (int) $now->format( "i" );
    if ( $now_min >= $today[1] ) return "";

    if ( $now_min >= $today[0] ) {
        $msg = $link . " is open on-site right now &mdash; fresh Maine oysters, lobster rolls &amp; craft beer. Stop in!";
    } else {
        $open_dt = clone $now;
        $open_dt->setTime( intdiv( $today[0], 60 ), $today[0] % 60, 0 );
        $open_str = str_replace( ":00", "", $open_dt->format( "g:i a" ) );
        $msg = $link . " opens at " . $open_str . " today, right here on the property. Plan a visit!";
    }

    return '<div class="welton-status-blurb">' . $msg . '</div>';
} );
}
