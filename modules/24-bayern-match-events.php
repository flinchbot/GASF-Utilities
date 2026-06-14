<?php
// Migrated from Code Snippet #24 "Shortcode: [bayern_match_events] (next 5 FC Bayern matches)" on 2026-06-14 (task 260614-gj8).
// Gate: gasf_mec_enable_bayern_events (default ON). Original backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_bayern_events' ) ) {
/**
 * Shortcode: [bayern_match_events]
 * Shows the next 5 upcoming FC Bayern matches stored as MEC events.
 *
 * Moved out of theme functions.php (which is wiped on theme updates) into a
 * Code Snippet so it survives updates.
 *
 * Filter: title CONTAINS "FC Bayern v" (catches "FC Bayern v X" plus cup-final
 * naming like "DFB Pokalfinale FC Bayern v Stuttgart"). Upcoming only (date >= today).
 */
function bayern_match_events_shortcode() {

    $today        = date('Y-m-d');
    $target_title = 'FC Bayern v';

    $args = array(
        'post_type'      => 'mec-events',
        'posts_per_page' => 100,
        'post_status'    => array('publish', 'future'),
        'meta_query'     => array(
            array(
                'key'     => 'mec_start_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ),
        ),
        'orderby'   => 'meta_value',
        'meta_key'  => 'mec_start_date',
        'order'     => 'ASC',
    );

    $query = new WP_Query($args);

    $output  = '<div class="bayern-match-block">';
    $output .= '<h2>Upcoming FC Bayern Matches</h2>';
    $output .= '<ul>';

    $matches = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $event_id = get_the_ID();
            $title    = trim(get_the_title());

            // CONTAINS "FC Bayern v" (not just starts-with) so cup finals are included.
            if (stripos($title, $target_title) !== false) {

                $event_date_raw = get_post_meta($event_id, 'mec_start_date', true);
                $formatted_date = $event_date_raw
                    ? date('F j, Y', strtotime($event_date_raw))
                    : 'Date not available';

                $mec_date = maybe_unserialize(get_post_meta($event_id, 'mec_date', true));
                if (!empty($mec_date['start']['hour'])) {
                    $formatted_time = $mec_date['start']['hour'] . ':' . $mec_date['start']['minutes'] . ' ' . $mec_date['start']['ampm'];
                } else {
                    // Fallback to the separate start-time meta keys MEC also writes
                    $h  = get_post_meta($event_id, 'mec_start_time_hour', true);
                    $m  = get_post_meta($event_id, 'mec_start_time_minutes', true);
                    $ap = get_post_meta($event_id, 'mec_start_time_ampm', true);
                    $formatted_time = $h ? ($h . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ' ' . $ap) : '';
                }

                $event_desc = get_post_meta($event_id, 'mec_description', true);

                $matches[] = array(
                    'title' => $title,
                    'date'  => $formatted_date,
                    'time'  => $formatted_time,
                    'desc'  => !empty($event_desc) ? $event_desc : '',
                    'link'  => get_permalink($event_id),
                );
            }
        }
        wp_reset_postdata();
    }

    $matches = array_slice($matches, 0, 5);

    if (empty($matches)) {
        $output .= '<li>No Bayern games scheduled in the near future.</li>';
    } else {
        foreach ($matches as $match) {
            $output .= '<li>';
            $output .= '<strong><a href="' . esc_url($match['link']) . '">' . esc_html($match['title']) . '</a></strong><br>';
            $output .= esc_html($match['date']);
            if (!empty($match['time'])) {
                $output .= ' at ' . esc_html($match['time']);
            }
            if (!empty($match['desc'])) {
                $output .= '<br><em>' . esc_html($match['desc']) . '</em>';
            }
            $output .= '</li>';
        }
    }

    $output .= '</ul></div>';
    return $output;
}
add_shortcode('bayern_match_events', 'bayern_match_events_shortcode');
}
