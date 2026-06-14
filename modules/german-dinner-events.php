<?php
/**
 * [german_dinner_events] — upcoming "Dinner Night at the German American Society" events.
 * Restored from theme functions.php (wiped by the 2026-06-08 theme reinstall) as a
 * durable git-backed module (task 260614-gj8 follow-up). Gate: gasf_mec_enable_dinner_events.
 * Change vs original: removed the per-event error_log() debug spam.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_mec_enabled( 'gasf_mec_enable_dinner_events' ) ) {

	function german_dinner_events_shortcode() {
		$today        = date( 'Y-m-d' );
		$target_title = 'Dinner Night at the German American Society';

		$args = array(
			'post_type'      => 'mec-events',
			'posts_per_page' => 100,
			'post_status'    => array( 'publish', 'future', 'draft', 'private' ),
			'meta_query'     => array(
				array(
					'key'     => 'mec_start_date',
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
			'orderby'  => 'meta_value',
			'meta_key' => 'mec_start_date',
			'order'    => 'ASC',
		);

		$query = new WP_Query( $args );

		$output  = '<div class="german-dinner-block">';
		$output .= '<h2>Upcoming German Dinner Nights</h2>';
		$output .= '<ul>';

		$match_found = false;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$event_id       = get_the_ID();
				$title          = get_the_title();
				$event_date_raw = get_post_meta( $event_id, 'mec_start_date', true );

				if ( trim( $title ) === $target_title ) {
					$match_found    = true;
					$formatted_date = $event_date_raw && strtotime( $event_date_raw )
						? date( 'F j, Y', strtotime( $event_date_raw ) )
						: 'Date not available';
					$output .= '<li><a href="' . get_permalink() . '">' . esc_html( $title ) . ' &ndash; ' . $formatted_date . '</a></li>';
				}
			}
			wp_reset_postdata();
		}

		if ( ! $match_found ) {
			$output .= '<li>No upcoming German dinner events found.</li>';
		}

		$output .= '</ul></div>';
		return $output;
	}

	add_shortcode( 'german_dinner_events', 'german_dinner_events_shortcode' );
}
