<?php
/**
 * Recent Posts rail — modules/41-recent-posts.php
 *
 * [gasf_recent_posts count="3" title="Latest News"] — a compact card list of
 * the newest blog posts, designed for the home-page right rail (between the
 * Calendar/Newsletter/Donations buttons and the Instagram feed). Blog posts
 * mostly arrive via the FB → Blog importer (module 40), so this is the
 * home-page surface for those.
 *
 * Styled to match the rail buttons block: same gradient container, gold
 * heading, white cards with the post's featured image, title, and date.
 *
 * Also purges the page cache when a post publishes, so a new post shows on
 * the (24h-cached) home page promptly instead of a day later.
 *
 * Gate: gasf_site_enable_recentposts (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_recentposts' ) : true ) {

	add_shortcode( 'gasf_recent_posts', 'gasf_rp_shortcode' );
	function gasf_rp_shortcode( $atts ) {
		$a = shortcode_atts( array(
			'count' => 3,
			'title' => 'Latest News',
		), $atts, 'gasf_recent_posts' );

		$q = new WP_Query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 10, (int) $a['count'] ) ),
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		) );
		if ( ! $q->have_posts() ) { return ''; }

		$cards = '';
		foreach ( $q->posts as $p ) {
			$thumb = get_the_post_thumbnail( $p->ID, 'medium_large', array( 'class' => 'gasf-rp__img', 'loading' => 'lazy' ) );
			$cards .= '<a class="gasf-rp__card" href="' . esc_url( get_permalink( $p->ID ) ) . '">'
				. $thumb
				. '<span class="gasf-rp__body">'
				. '<span class="gasf-rp__t">' . esc_html( get_the_title( $p->ID ) ) . '</span>'
				. '<span class="gasf-rp__d">' . esc_html( get_the_date( 'F j, Y', $p->ID ) ) . '</span>'
				. '</span></a>';
		}
		wp_reset_postdata();

		$all_url = '';
		$blog    = (int) get_option( 'page_for_posts' );
		if ( $blog ) { $all_url = get_permalink( $blog ); }

		return '<div class="gasf-rp-wrap">' . gasf_rp_css()
			. '<div class="gasf-rp">'
			. '<h3 class="gasf-rp__h">' . esc_html( $a['title'] ) . '</h3>'
			. $cards
			. ( $all_url ? '<a class="gasf-rp__all" href="' . esc_url( $all_url ) . '">All posts &rarr;</a>' : '' )
			. '</div></div>';
	}

	function gasf_rp_css() {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		return '<style>'
			. '.gasf-rp-wrap{display:flex;justify-content:center;background:var(--gasf-hero-gradient,linear-gradient(135deg,#1a1a2e 0%,#0F6E56 55%,#085041 100%));margin:2rem;padding:2rem;border-radius:16px;font-family:"Segoe UI",Roboto,sans-serif}'
			. '.gasf-rp{width:280px;display:flex;flex-direction:column;gap:18px}'
			. '.gasf-rp__h{margin:0;color:var(--gasf-gold,#EF9F27);font-size:1.35em;font-weight:700;text-transform:uppercase;letter-spacing:1px;text-align:center}'
			. '.gasf-rp__card{display:block;background:#f5f5f0;border-radius:12px;overflow:hidden;text-decoration:none;box-shadow:0 8px 16px rgba(0,0,0,.3);transition:transform .25s ease}'
			. '.gasf-rp__card:hover{transform:scale(1.04)}'
			. '.gasf-rp__img{display:block;width:100%;height:130px;object-fit:cover}'
			. '.gasf-rp__body{display:block;padding:12px 14px}'
			. '.gasf-rp__t{display:block;color:var(--gasf-dark-bg,#1a1a2e);font-weight:700;font-size:1em;line-height:1.3}'
			. '.gasf-rp__d{display:block;color:#666;font-size:.82em;margin-top:4px}'
			. '.gasf-rp__all{display:block;text-align:center;color:var(--gasf-gold,#EF9F27);font-weight:700;text-decoration:none}'
			. '.gasf-rp__all:hover{text-decoration:underline}'
			. '</style>';
	}

	/* New post published → purge the page cache so it appears on the home page
	 * promptly (mirrors the hero-activation purge; epc_purge is a no-op if the
	 * cache plugin is absent). */
	add_action( 'transition_post_status', function ( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && 'post' === $post->post_type ) {
			do_action( 'epc_purge' );
			$home = (int) get_option( 'page_on_front' );
			if ( $home ) { clean_post_cache( $home ); }
		}
	}, 10, 3 );
}
