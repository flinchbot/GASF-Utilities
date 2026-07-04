<?php
/**
 * [german_dinner_events] — upcoming "Dinner Night at the German American Society" events.
 * Restored from theme functions.php (wiped by the 2026-06-08 theme reinstall) as a
 * durable git-backed module (task 260614-gj8 follow-up). Gate: gasf_mec_enable_dinner_events.
 * Repointed from the retired MEC calendar (mec-events) to the native GASF-Events
 * calendar (gasf_event) on 2026-07-04 (v1.4.0).
 * NOTE: as of 2026-07-04 this shortcode is not placed on any live page — kept
 * available for reuse.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_mec_enabled( 'gasf_mec_enable_dinner_events' ) ) {

	function german_dinner_events_shortcode() {
		$target_title = 'Dinner Night at the German American Society';

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
			'orderby'  => 'meta_value_num',
			'meta_key' => '_gasf_start_ts',
			'order'    => 'ASC',
		) );

		$output  = '<div class="german-dinner-block">';
		$output .= '<h2>Upcoming German Dinner Nights</h2>';
		$output .= '<ul>';

		$match_found = false;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$event_id = get_the_ID();
				$title    = get_the_title();

				if ( trim( $title ) === $target_title ) {
					$match_found = true;
					$start = get_post_meta( $event_id, '_gasf_start', true ); // site-local Y-m-d H:i:s
					$formatted_date = $start && strtotime( $start )
						? date( 'F j, Y', strtotime( $start ) )
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
