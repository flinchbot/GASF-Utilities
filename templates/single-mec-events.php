<?php
/**
 * single-mec-events.php
 * Custom single event template for germantampabay.com
 *
 * INSTALL: /wp-content/themes/hoot-du-premium/single-mec-events.php
 * PREFIX: All custom classes use "gasf-" (German-American Society Friendship)
 *
 * DATA SOURCES:
 *   - Title, content, thumbnail: standard WordPress functions
 *   - MEC date/time: post meta component fields (mec_start_time_hour/minutes/ampm,
 *       mec_end_time_hour/minutes/ampm) — converted to 24-hour H:i for internal use.
 *       NOTE: mec_start_time / mec_end_time (H:i) are NOT stored by MEC; use components.
 *   - Calendar links: constructed from MEC meta
 *   - Event status: mec_event_status (cancelled, postponed, sold_out, online_only)
 *
 * STYLING: gasf-events.css, enqueued via Code Snippet on is_singular('mec-events')
 *
 * SCHEMA: Outputs Event JSON-LD directly in <head> via wp_head action.
 *   eventStatus reads from mec_event_status meta — falls back to EventScheduled.
 *
 * CHANGES:
 *   - mec_event_status read and mapped to schema.org eventStatus URL
 *   - Visible status badge renders above title for cancelled/postponed events
 *   - iCal URL uses atomic occurrence (occurrence query var or start_date fallback)
 *   - Google Calendar button only shown when start + end time both present
 *   - Time block only renders if start time is present (and mec_allday is not set)
 *   - Welton Brewing status blurb added below description (time-aware via shortcode)
 *   - Fixed time reading: MEC stores time in component fields, not a single H:i meta
 */

get_header();

// ── Pull event data ──────────────────────────────────────────
$post_id   = get_the_ID();
$title     = get_the_title();
$content   = apply_filters( 'the_content', get_the_content() );
$image_url = get_the_post_thumbnail_url( $post_id, 'large' );

// MEC stores dates as post meta, and times as separate hour/minutes/ampm fields.
// There is no single 'mec_start_time' meta key — it must be assembled from components.
$start_date   = get_post_meta( $post_id, 'mec_start_date',   true ); // e.g. 2026-04-04
$end_date     = get_post_meta( $post_id, 'mec_end_date',     true );
$event_status = get_post_meta( $post_id, 'mec_event_status', true ); // cancelled, postponed, sold_out, online_only, or empty
$allday       = get_post_meta( $post_id, 'mec_allday',       true );
$hide_time    = get_post_meta( $post_id, 'mec_hide_time',    true );

// ── Convert MEC component time fields to 24-hour H:i ────────
function gasf_mec_to_24h( $hour, $minutes, $ampm ) {
    $h = intval( $hour );
    $m = str_pad( intval( $minutes ), 2, '0', STR_PAD_LEFT );
    if ( strtoupper( $ampm ) === 'AM' ) {
        $h = ( $h === 12 ) ? 0 : $h;
    } else {
        $h = ( $h === 12 ) ? 12 : $h + 12;
    }
    return sprintf( '%02d:%s', $h, $m );
}

$start_time = '';
$end_time   = '';

if ( ! $allday && ! $hide_time ) {
    $st_h  = get_post_meta( $post_id, 'mec_start_time_hour',    true );
    $st_m  = get_post_meta( $post_id, 'mec_start_time_minutes', true );
    $st_ap = get_post_meta( $post_id, 'mec_start_time_ampm',    true );
    $et_h  = get_post_meta( $post_id, 'mec_end_time_hour',      true );
    $et_m  = get_post_meta( $post_id, 'mec_end_time_minutes',   true );
    $et_ap = get_post_meta( $post_id, 'mec_end_time_ampm',      true );

    if ( $st_h !== '' && $st_h !== false ) {
        $start_time = gasf_mec_to_24h( $st_h, $st_m, $st_ap );
    }
    if ( $et_h !== '' && $et_h !== false ) {
        $end_time = gasf_mec_to_24h( $et_h, $et_m, $et_ap );
    }
}

// ── Map mec_event_status to schema.org URL + display label ───
$status_map = [
    'cancelled'   => [
        'schema'  => 'https://schema.org/EventCancelled',
        'label'   => '⚠ This event has been cancelled',
        'class'   => 'gasf-status-badge gasf-status-badge--cancelled',
    ],
    'postponed'   => [
        'schema'  => 'https://schema.org/EventPostponed',
        'label'   => '⏸ This event has been postponed',
        'class'   => 'gasf-status-badge gasf-status-badge--postponed',
    ],
    'sold_out'    => [
        'schema'  => 'https://schema.org/EventScheduled',
        'label'   => '🎟 This event is sold out',
        'class'   => 'gasf-status-badge gasf-status-badge--soldout',
    ],
    'online_only' => [
        'schema'  => 'https://schema.org/EventScheduled',
        'label'   => '💻 This event is online only',
        'class'   => 'gasf-status-badge gasf-status-badge--online',
    ],
];

$status_info    = isset( $status_map[ $event_status ] ) ? $status_map[ $event_status ] : null;
$schema_status  = $status_info ? $status_info['schema'] : 'https://schema.org/EventScheduled';
$attendance_mode = ( $event_status === 'online_only' )
    ? 'https://schema.org/OnlineEventAttendanceMode'
    : 'https://schema.org/OfflineEventAttendanceMode';

// ── Format display date ──────────────────────────────────────
$display_date = '';
$display_time = '';

if ( $start_date ) {
    $dt = DateTime::createFromFormat( 'Y-m-d', $start_date );
    if ( $dt ) {
        $display_date = $dt->format( 'l, F j, Y' );
    }
}

if ( $start_time ) {
    $t_start = DateTime::createFromFormat( 'H:i', $start_time );
    $display_time = $t_start ? $t_start->format( 'g:i A' ) : $start_time;
    if ( $end_time ) {
        $t_end = DateTime::createFromFormat( 'H:i', $end_time );
        if ( $t_end ) {
            $display_time .= ' – ' . $t_end->format( 'g:i A' );
        }
    }
}

// ── Build Google Calendar link ───────────────────────────────
$gcal_url = '';
if ( $start_date && $start_time && $end_time ) {
    $start_dt   = new DateTime( $start_date . ' ' . $start_time );
    $end_dt     = new DateTime( ( $end_date ?: $start_date ) . ' ' . $end_time );
    $gcal_start = $start_dt->format( 'Ymd' ) . 'T' . $start_dt->format( 'His' );
    $gcal_end   = $end_dt->format( 'Ymd' )   . 'T' . $end_dt->format( 'His' );
    $gcal_url   = add_query_arg( [
        'action'   => 'TEMPLATE',
        'text'     => urlencode( $title ),
        'dates'    => $gcal_start . '/' . $gcal_end,
        'location' => urlencode( '8098 66th Street North, Pinellas Park, FL 33781' ),
        'details'  => urlencode( wp_strip_all_tags( $content ) ),
    ], 'https://calendar.google.com/calendar/render' );
}

// ── Build iCal URL ───────────────────────────────────────────
$occurrence = get_query_var( 'occurrence' ) ?: $start_date;
$ical_url = add_query_arg( [
    'method'     => 'ical',
    'id'         => $post_id,
    'occurrence' => $occurrence,
], home_url( '/' ) );

// ── Calendar page URL ────────────────────────────────────────
$calendar_url = home_url( '/calendar-of-events/' );

// ── Build Event JSON-LD schema ───────────────────────────────
add_action( 'wp_head', function() use ( $post_id, $title, $content, $image_url, $start_date, $end_date, $start_time, $end_time, $schema_status, $attendance_mode ) {

    if ( ! $start_date ) return;

    $iso_start = $start_date;
    $iso_end   = $end_date ?: $start_date;

    if ( $start_time ) {
        $iso_start .= 'T' . $start_time . ':00';
    }
    if ( $end_time ) {
        $iso_end .= 'T' . $end_time . ':00';
    } elseif ( $start_time ) {
        $end_dt  = new DateTime( $start_date . ' ' . $start_time );
        $end_dt->modify( '+2 hours' );
        $iso_end = $end_dt->format( 'Y-m-d\TH:i:s' );
    }

    $schema = [
        '@context'            => 'https://schema.org',
        '@type'               => 'Event',
        'name'                => $title,
        'startDate'           => $iso_start,
        'endDate'             => $iso_end,
        'eventStatus'         => $schema_status,
        'eventAttendanceMode' => $attendance_mode,
        'location'            => [
            '@type'   => 'Place',
            'name'    => 'German-American Society Friendship of Pinellas County',
            'address' => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => '8098 66th Street North',
                'addressLocality' => 'Pinellas Park',
                'addressRegion'   => 'FL',
                'postalCode'      => '33781',
                'addressCountry'  => 'US',
            ],
        ],
        'organizer' => [
            '@type' => 'Organization',
            'name'  => 'German-American Society Friendship of Pinellas County',
            'url'   => 'https://germantampabay.com',
        ],
        'performer' => [
            '@type' => 'Organization',
            'name'  => 'German-American Society Friendship of Pinellas County',
            'url'   => 'https://germantampabay.com',
        ],
        'url' => get_permalink( $post_id ),
    ];

    if ( $image_url ) {
        $schema['image'] = $image_url;
    }

    $plain_content = wp_strip_all_tags( $content );
    if ( $plain_content ) {
        $schema['description'] = mb_substr( $plain_content, 0, 500 );
    }

    echo '<script type="application/ld+json">' . "\n";
    echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    echo "\n</script>\n";

}, 5 );

?>

<div id="gasf-event-page">
    <div class="gasf-event-container">

        <!-- ── Back link ── -->
        <a href="<?php echo esc_url( $calendar_url ); ?>" class="gasf-back-link">
            <span class="gasf-back-arrow">&#8592;</span> Back to Calendar
        </a>

        <!-- ── Status badge — only renders for non-scheduled events ── -->
        <?php if ( $status_info ) : ?>
        <div class="<?php echo esc_attr( $status_info['class'] ); ?>">
            <?php echo esc_html( $status_info['label'] ); ?>
        </div>
        <?php endif; ?>

        <!-- ── Event title ── -->
        <h1 class="gasf-event-title"><?php echo esc_html( $title ); ?></h1>

        <!-- ── Hero row: meta left, image right ── -->
        <div class="gasf-hero-row">

            <!-- Left: date / time / location / buttons -->
            <div class="gasf-event-meta">

                <?php if ( $display_date ) : ?>
                <div class="gasf-meta-block">
                    <span class="gasf-meta-label">Date</span>
                    <span class="gasf-meta-value"><?php echo esc_html( $display_date ); ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $display_time ) : ?>
                <div class="gasf-meta-block">
                    <span class="gasf-meta-label">Time</span>
                    <span class="gasf-meta-value"><?php echo esc_html( $display_time ); ?></span>
                </div>
                <?php endif; ?>

                <div class="gasf-meta-block">
                    <span class="gasf-meta-label">Location</span>
                    <span class="gasf-meta-value">
                        German-American Society<br>
                        <small>8098 66th St N, Pinellas Park FL</small>
                    </span>
                </div>

                <!-- Calendar export buttons -->
                <div class="gasf-cal-buttons">
                    <?php if ( $gcal_url ) : ?>
                    <a href="<?php echo esc_url( $gcal_url ); ?>"
                       class="gasf-cal-btn gasf-cal-btn--google"
                       target="_blank" rel="noopener">
                        + Google Calendar
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $ical_url ); ?>"
                       class="gasf-cal-btn gasf-cal-btn--ical">
                        + iCal / Outlook
                    </a>
                </div>

            </div><!-- .gasf-event-meta -->

            <!-- Right: hero image -->
            <?php if ( $image_url ) : ?>
            <div class="gasf-event-image">
                <img src="<?php echo esc_url( $image_url ); ?>"
                     alt="<?php echo esc_attr( $title ); ?>"
                     loading="eager">
            </div>
            <?php endif; ?>

        </div><!-- .gasf-hero-row -->

        <!-- ── Description ── -->
        <?php if ( $content ) : ?>
        <div class="gasf-event-description">
            <?php echo $content; ?>
        </div>
        <?php endif; ?>

        <!-- ── Welton Brewing status blurb (time-aware, hidden when closed) ── -->
        <?php echo do_shortcode( '[welton_status]' ); ?>

        <!-- ── Bottom back link ── -->
        <div class="gasf-bottom-nav">
            <a href="<?php echo esc_url( $calendar_url ); ?>" class="gasf-back-link">
                <span class="gasf-back-arrow">&#8592;</span> Back to Calendar
            </a>
        </div>

    </div><!-- .gasf-event-container -->
</div><!-- #gasf-event-page -->

<?php get_footer(); ?>
