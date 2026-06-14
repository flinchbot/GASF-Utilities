<?php
// Migrated from Code Snippet #16 "MEC Upcoming Dates Shortcode" on 2026-06-14 (task 260614-gj8).
// Gate: gasf_mec_enable_feierabend (default ON). Original backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_feierabend' ) ) {
/**
 * Generic MEC Upcoming Events Shortcode [mec_upcoming_dates]
 *
 * Attributes:
 *   title   = MEC post_title to match exactly (required)
 *   count   = events to show (default 3)
 *   heading = heading text (default "Upcoming Dates")
 *   icon    = emoji (default 🗓)
 *   color   = accent hex (default var(--gasf-gold,#e8b04b))
 */
add_shortcode( 'mec_upcoming_dates', function( $atts ) {
    $atts = shortcode_atts( [
        'title'   => '',
        'count'   => 3,
        'heading' => 'Upcoming Dates',
        'icon'    => '🗓',
        'color'   => 'var(--gasf-gold,#e8b04b)',
    ], $atts );

    if ( empty( $atts['title'] ) ) return '<!-- mec_upcoming_dates: no title -->';

    $count = max( 1, intval( $atts['count'] ) );
    $today = date('Y-m-d');
    global $wpdb;

    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, p.post_name, pm_date.meta_value AS start_date,
                pm_hour.meta_value AS hour, pm_min.meta_value AS minutes, pm_ampm.meta_value AS ampm
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key  = 'mec_start_date'
         LEFT JOIN {$wpdb->postmeta} pm_hour ON p.ID = pm_hour.post_id AND pm_hour.meta_key = 'mec_start_time_hour'
         LEFT JOIN {$wpdb->postmeta} pm_min  ON p.ID = pm_min.post_id  AND pm_min.meta_key  = 'mec_start_time_minutes'
         LEFT JOIN {$wpdb->postmeta} pm_ampm ON p.ID = pm_ampm.post_id AND pm_ampm.meta_key = 'mec_start_time_ampm'
         WHERE p.post_type   = 'mec-events'
           AND p.post_status = 'publish'
           AND p.post_title  = %s
           AND pm_date.meta_value >= %s
         ORDER BY pm_date.meta_value ASC
         LIMIT %d",
        $atts['title'], $today, $count
    ) );

    if ( empty( $events ) ) return '<p class="gasf-mec-none">No upcoming dates scheduled — check back soon!</p>';

    $accent = esc_attr( $atts['color'] );
    $html   = '<div class="gasf-mec-wrap">';
    $html  .= '<h3 class="gasf-mec-heading" style="color:' . $accent . ';border-bottom-color:' . $accent . '33;">' . esc_html( $atts['icon'] . ' ' . $atts['heading'] ) . '</h3>';
    $html  .= '<ul class="gasf-mec-list">';

    foreach ( $events as $e ) {
        $dt       = DateTime::createFromFormat( 'Y-m-d', $e->start_date );
        $date_str = $dt ? $dt->format( 'l, F j, Y' ) : $e->start_date;
        $time_str = '';
        if ( $e->hour ) {
            $h = intval( $e->hour );
            $m = $e->minutes ? str_pad( intval($e->minutes), 2, '0', STR_PAD_LEFT ) : '00';
            $time_str = $h . ':' . $m . ' ' . ( $e->ampm ?: 'PM' );
        }
        $url = home_url( '/events/' . $e->post_name . '/?occurrence=' . $e->start_date );
        $html .= '<li class="gasf-mec-item"><a href="' . esc_url($url) . '" class="gasf-mec-link">';
        $html .= '<span class="gasf-mec-date">' . esc_html($date_str) . '</span>';
        if ( $time_str ) $html .= '<span class="gasf-mec-time">' . esc_html($time_str) . '</span>';
        $html .= '</a></li>';
    }

    $html .= '</ul></div>';

    static $css_done = false;
    if ( ! $css_done ) {
        $css_done = true;
        $html .= '<style>
.gasf-mec-wrap{background:var(--gasf-dark-bg,#1a1a1a);border-radius:6px;padding:24px 28px;margin:32px 0;}
.gasf-mec-heading{font-family:"Germania One","Georgia",serif;font-size:18px;margin:0 0 16px;border-bottom:1px solid;padding-bottom:10px;}
.gasf-mec-list{list-style:none;margin:0;padding:0;}
.gasf-mec-item{border-bottom:1px solid rgba(255,255,255,0.07);padding:10px 0;}
.gasf-mec-item:last-child{border-bottom:none;padding-bottom:0;}
.gasf-mec-link{display:flex;justify-content:space-between;align-items:center;text-decoration:none;gap:12px;}
.gasf-mec-link:hover .gasf-mec-date{color:var(--gasf-gold,#e8b04b);}
.gasf-mec-date{font-family:"Rubik",Arial,sans-serif;font-size:15px;font-weight:600;color:#fff;}
.gasf-mec-time{font-family:"Rubik",Arial,sans-serif;font-size:13px;color:rgba(255,255,255,0.5);white-space:nowrap;}
.gasf-mec-none{color:#999;font-style:italic;font-size:14px;}
@media(max-width:480px){.gasf-mec-link{flex-direction:column;align-items:flex-start;gap:3px;}}
</style>';
    }
    return $html;
} );

// feierabend_dates alias — keeps existing page embed working
add_shortcode( 'feierabend_dates', function( $atts ) {
    $atts = shortcode_atts( [ 'count' => 3 ], $atts );
    return do_shortcode( '[mec_upcoming_dates title="Feierabend auf Deutsch" heading="Nächste Termine · Upcoming Dates" icon="🗓" count="' . intval($atts['count']) . '"]' );
} );
}
