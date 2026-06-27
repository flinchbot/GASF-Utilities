<?php
// Migrated from Code Snippet #5 "Update Yoast/MEC missing Schema" 2026-06-14 (task 260614-gj8).
// Gate: gasf_mec_enable_schema (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_schema' ) ) {
/**
 * Fix MEC Event JSON-LD schema — inject missing GSC fields
 * Runs on ALL pages since MEC outputs event schema on list/calendar pages too
 */
add_action('template_redirect', function() {
    ob_start(function($html) {
        $html = preg_replace_callback(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            function($matches) {
                $data = json_decode($matches[1], true);
                if (!$data || !isset($data['@type']) || $data['@type'] !== 'Event') {
                    return $matches[0];
                }

                // Fix organizer
                $data['organizer'] = [
                    '@type' => 'Organization',
                    'name'  => 'German-American Society Friendship of Pinellas County',
                    'url'   => 'https://germantampabay.com'
                ];

                // Fix location address
                $data['location'] = [
                    '@type'   => 'Place',
                    'name'    => 'German-American Society',
                    'address' => [
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => '8098 66th Street North',
                        'addressLocality' => 'Pinellas Park',
                        'addressRegion'   => 'FL',
                        'postalCode'      => '33781',
                        'addressCountry'  => 'US'
                    ]
                ];

                // Add performer if missing or empty
                if (empty($data['performer'])) {
                    $data['performer'] = [
                        '@type' => 'Organization',
                        'name'  => 'German-American Society Friendship of Pinellas County'
                    ];
                }

                return '<script type="application/ld+json">'
                    . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    . '</script>';
            },
            $html
        );
        return $html;
    });
});

/**
 * Inject a COMPLETE Event JSON-LD block on single MEC event pages.
 * MEC emits no Event schema on single pages, so we build one from post meta
 * (independent of MEC's own schema output). Organizer/location/performer mirror
 * the patch above so the result is consistent whether or not the buffer re-touches it.
 * Added 2026-06-27 (MEC Event Schema Injection handoff).
 */
add_action('wp_footer', function () {
    if ( ! is_singular('mec-events') ) { return; }
    $pid = get_the_ID();
    if ( ! $pid ) { return; }

    $start_date = get_post_meta($pid, 'mec_start_date', true);
    if ( ! $start_date ) { return; } // no usable date -> bail rather than emit broken schema
    $end_date = get_post_meta($pid, 'mec_end_date', true);
    $allday   = (string) get_post_meta($pid, 'mec_allday', true) === '1';

    $startDate = $start_date;
    $endDate   = null;
    if ( $allday ) {
        if ( $end_date && $end_date !== $start_date ) { $endDate = $end_date; }
    } else {
        $tz   = wp_timezone(); // DST-aware site timezone
        $ssec = (int) get_post_meta($pid, 'mec_start_day_seconds', true);
        $esec = (int) get_post_meta($pid, 'mec_end_day_seconds', true);
        try {
            $sd = new DateTime($start_date, $tz); $sd->setTime(0, 0, 0); $sd->modify('+' . $ssec . ' seconds');
            $startDate = $sd->format('c');
        } catch ( Exception $e ) { return; }
        $ed_date = $end_date ?: $start_date;
        if ( $ed_date && $esec ) {
            try {
                $ed = new DateTime($ed_date, $tz); $ed->setTime(0, 0, 0); $ed->modify('+' . $esec . ' seconds');
                $endDate = $ed->format('c');
            } catch ( Exception $e ) {}
        }
    }

    $event = array(
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        'name'                => html_entity_decode( (string) get_the_title($pid), ENT_QUOTES, 'UTF-8' ),
        'startDate'           => $startDate,
        'eventStatus'         => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'url'                 => get_permalink($pid),
        'location'            => array(
            '@type'   => 'Place',
            'name'    => 'German-American Society',
            'address' => array(
                '@type'           => 'PostalAddress',
                'streetAddress'   => '8098 66th Street North',
                'addressLocality' => 'Pinellas Park',
                'addressRegion'   => 'FL',
                'postalCode'      => '33781',
                'addressCountry'  => 'US',
            ),
        ),
        'organizer'           => array(
            '@type' => 'Organization',
            'name'  => 'German-American Society Friendship of Pinellas County',
            'url'   => 'https://germantampabay.com',
        ),
        'performer'           => array(
            '@type' => 'Organization',
            'name'  => 'German-American Society Friendship of Pinellas County',
        ),
    );
    if ( $endDate ) { $event['endDate'] = $endDate; }

    $desc = html_entity_decode( trim( wp_strip_all_tags( (string) get_the_excerpt($pid) ) ), ENT_QUOTES, 'UTF-8' );
    if ( $desc !== '' ) { $event['description'] = $desc; }

    if ( has_post_thumbnail($pid) ) {
        $img = wp_get_attachment_image_url( get_post_thumbnail_id($pid), 'large' );
        if ( $img ) { $event['image'] = $img; }
    }

    // Offers only when MEC actually has a positive cost on this event.
    $cost_raw = (string) get_post_meta($pid, 'mec_cost', true);
    $cost_num = (float) preg_replace('/[^0-9.]/', '', $cost_raw);
    if ( $cost_raw !== '' && $cost_num > 0 ) {
        $event['offers'] = array(
            '@type'         => 'Offer',
            'price'         => (string) $cost_num,
            'priceCurrency' => 'USD',
            'availability'  => 'https://schema.org/InStock',
            'url'           => get_permalink($pid),
        );
    }

    echo "\n<script type=\"application/ld+json\">"
        . wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}, 99);

}
