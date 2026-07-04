<?php
// Migrated from Code Snippet #24 "Shortcode: [bayern_match_events] (next 5 FC Bayern matches)" on 2026-06-14 (task 260614-gj8).
// Repointed from the retired MEC calendar (mec-events) to the native GASF-Events
// calendar (gasf_event) on 2026-07-04 (v1.4.0).
// Gate: gasf_mec_enable_bayern_events (default ON). Original backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_bayern_events' ) ) {
/**
 * Shortcode: [bayern_match_events]
 * Shows the next 5 upcoming FC Bayern matches from the GASF events calendar.
 *
 * Filter: title CONTAINS "FC Bayern v" — catches "FC Bayern v X", the newer
 * "FC Bayern vs X" naming, and cup naming like "DFB Pokalfinale FC Bayern v
 * Stuttgart". Upcoming only (start >= now).
 *
 * Date/time: `_gasf_start` is the site-local 'Y-m-d H:i:s' source of truth;
 * `_gasf_start_ts` (UTC unix) is what GASF-Events derives for range queries.
 */
function bayern_match_events_shortcode() {

    $target_title = 'FC Bayern v';

    $query = new WP_Query( array(
        'post_type'      => 'gasf_event',
        'posts_per_page' => 100,
        'post_status'    => array( 'publish', 'future' ),
        'meta_query'     => array(
            array(
                'key'     => '_gasf_start_ts',
                'value'   => time(),
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ),
        ),
        'orderby'   => 'meta_value_num',
        'meta_key'  => '_gasf_start_ts',
        'order'     => 'ASC',
    ) );

    $output  = '<div class="bayern-match-block">';
    $output .= '<h2>Upcoming FC Bayern Matches</h2>';
    $output .= '<ul>';

    $matches = array();

    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $event_id = get_the_ID();
            $title    = trim( get_the_title() );

            // CONTAINS "FC Bayern v" (not just starts-with) so cup finals are included.
            if ( stripos( $title, $target_title ) !== false ) {

                $start = get_post_meta( $event_id, '_gasf_start', true ); // site-local Y-m-d H:i:s
                $ts    = $start ? strtotime( $start ) : false;

                $formatted_date = $ts ? date( 'F j, Y', $ts ) : 'Date not available';

                $all_day   = get_post_meta( $event_id, '_gasf_all_day', true );
                $hide_time = get_post_meta( $event_id, '_gasf_hide_time', true );
                $formatted_time = ( $ts && ! $all_day && ! $hide_time ) ? date( 'g:i a', $ts ) : '';

                // Hand-written excerpt only — auto-generated ones are noise here.
                $desc = has_excerpt( $event_id ) ? trim( wp_strip_all_tags( get_the_excerpt() ) ) : '';

                $matches[] = array(
                    'title' => $title,
                    'date'  => $formatted_date,
                    'time'  => $formatted_time,
                    'desc'  => $desc,
                    'link'  => get_permalink( $event_id ),
                );
            }
        }
        wp_reset_postdata();
    }

    $matches = array_slice( $matches, 0, 5 );

    if ( empty( $matches ) ) {
        $output .= '<li>No Bayern games scheduled in the near future.</li>';
    } else {
        foreach ( $matches as $match ) {
            $output .= '<li>';
            $output .= '<strong><a href="' . esc_url( $match['link'] ) . '">' . esc_html( $match['title'] ) . '</a></strong><br>';
            $output .= esc_html( $match['date'] );
            if ( ! empty( $match['time'] ) ) {
                $output .= ' at ' . esc_html( $match['time'] );
            }
            if ( ! empty( $match['desc'] ) ) {
                $output .= '<br><em>' . esc_html( $match['desc'] ) . '</em>';
            }
            $output .= '</li>';
        }
    }

    $output .= '</ul></div>';
    return $output;
}
add_shortcode( 'bayern_match_events', 'bayern_match_events_shortcode' );
}
