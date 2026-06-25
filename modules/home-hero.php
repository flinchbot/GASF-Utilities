<?php
/**
 * Home Page Hero (scheduled) — modules/home-hero.php
 *
 * Adds the [gas_hero] shortcode + a "Home Page Hero" admin screen that lets a
 * maintainer schedule the large image at the top of the home page. Each entry is
 * { image, optional image link, optional caption, optional button label + button
 * link, activation datetime }. Two independent links:
 *   - Image link  -> makes the whole image clickable.
 *   - Button link -> renders a button below the caption (can differ from the image link).
 *
 * The ACTIVE hero = the entry whose activation time is the latest one already
 * passed; future entries queue, and the current one stays up until the next
 * activates (never blank). When a future entry is saved, a one-off WP-Cron job
 * purges the home-page cache at activation time so the swap appears within ~a
 * minute despite the 24h nginx page cache.
 *
 * Gate: gasf_mec_enable_hero  (DEFAULT OFF — ships inert; set option to '1' to activate.)
 * Lives in the MEC plugin pending the planned consolidation into "GASF Utilities".
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_mec_enabled( 'gasf_mec_enable_hero', '0' ) ) {

	if ( ! defined( 'GASF_HERO_OPTION' ) ) { define( 'GASF_HERO_OPTION', 'gasf_hero_entries' ); }

	/* ---------- data helpers ---------- */
	function gasf_hero_get_entries() {
		$e = get_option( GASF_HERO_OPTION, array() );
		return is_array( $e ) ? $e : array();
	}
	function gasf_hero_save_entries( $entries ) {
		usort( $entries, function ( $a, $b ) { return ( (int) $a['activate_at'] ) <=> ( (int) $b['activate_at'] ); } );
		update_option( GASF_HERO_OPTION, $entries, false );
	}
	/* Active = latest entry whose activation time has already passed. */
	function gasf_hero_active() {
		$now = time();
		$active = null;
		foreach ( gasf_hero_get_entries() as $e ) {
			$ts = (int) $e['activate_at'];
			if ( $ts <= $now && ( $active === null || $ts >= (int) $active['activate_at'] ) ) {
				$active = $e;
			}
		}
		return $active;
	}

	/* ---------- one-time seed: keep the current image (#18254) live so cutover is seamless ---------- */
	add_action( 'init', function () {
		if ( get_option( 'gasf_hero_seeded' ) ) { return; }
		if ( ! gasf_hero_get_entries() ) {
			gasf_hero_save_entries( array( array(
				'id'           => 'seed_18254',
				'image_id'     => 18254,
				'image_url'    => 'https://germantampabay.com/world-cup/',
				'max_width'    => 450,
				'caption'      => '',
				'button_label' => '',
				'button_url'   => '',
				'activate_at'  => time() - 3600, // already active
				'created'      => time(),
			) ) );
		}
		update_option( 'gasf_hero_seeded', '1' );
	} );

	/* ---------- front-end shortcode ---------- */
	add_shortcode( 'gas_hero', 'gasf_hero_shortcode' );
	function gasf_hero_shortcode() {
		$e = gasf_hero_active();
		if ( ! $e ) { return ''; }
		$img = wp_get_attachment_image( (int) $e['image_id'], 'full', false, array(
			'class' => 'gasf-hero__img',
			'alt'   => $e['caption'] !== '' ? esc_attr( wp_strip_all_tags( $e['caption'] ) ) : get_bloginfo( 'name' ),
		) );
		if ( ! $img ) { return ''; }

		// Hero is above the fold: force a single eager load + high priority (no duplicate loading attr).
		$img = preg_replace( '/\sloading="[^"]*"/', '', $img );
		$img = preg_replace( '/<img /', '<img loading="eager" fetchpriority="high" ', $img, 1 );

		// Whole image clickable when an image link is set (independent of the button link).
		$image_url = isset( $e['image_url'] ) ? trim( $e['image_url'] ) : '';
		if ( $image_url !== '' ) {
			$img = '<a class="gasf-hero__imglink" href="' . esc_url( $image_url ) . '">' . $img . '</a>';
		}

		$has_caption = trim( $e['caption'] ) !== '';
		$has_button  = isset( $e['button_url'] ) && trim( $e['button_url'] ) !== '';

		$mw        = isset( $e['max_width'] ) ? (int) $e['max_width'] : 0;
		$fig_style = $mw > 0 ? ' style="max-width:' . $mw . 'px;margin-left:auto;margin-right:auto"' : '';
		$out  = gasf_hero_css();
		$out .= '<figure class="gasf-hero"' . $fig_style . '>' . $img;
		if ( $has_caption || $has_button ) {
			$out .= '<figcaption class="gasf-hero__cap">';
			if ( $has_caption ) {
				$out .= '<div class="gasf-hero__text">' . wp_kses_post( wpautop( $e['caption'] ) ) . '</div>';
			}
			if ( $has_button ) {
				$label = trim( $e['button_label'] ) !== '' ? $e['button_label'] : 'Learn More';
				$out  .= '<a class="gasf-hero__btn" href="' . esc_url( $e['button_url'] ) . '">' . esc_html( $label ) . '</a>';
			}
			$out .= '</figcaption>';
		}
		$out .= '</figure>';
		return $out;
	}

	function gasf_hero_css() {
		static $done = false;
		if ( $done ) { return ''; }
		$done = true;
		return '<style>'
			. '.gasf-hero{margin:0;width:100%}'
			. '.gasf-hero__imglink{display:block}'
			. '.gasf-hero__img{display:block;width:100%;height:auto;box-sizing:border-box;'
			. 'border:6px solid transparent;'
			. 'border-image:linear-gradient(45deg,var(--of-white) 25%,var(--of-teal) 25%,var(--of-teal) 50%,var(--of-white) 50%,var(--of-white) 75%,var(--of-teal) 75%) 1;'
			. 'border-image-slice:1;'
			. 'transition:transform .2s ease,box-shadow .2s ease}'
			. '.gasf-hero__img:hover{transform:scale(1.05);box-shadow:0 8px 20px rgba(0,0,0,0.35)}'
			. '.gasf-hero__cap{text-align:center;padding:14px 16px}'
			. '.gasf-hero__text{font-size:1.1rem;line-height:1.45;max-width:760px;margin:0 auto 12px}'
			. '.gasf-hero__btn{display:inline-block;padding:10px 24px;border-radius:6px;background:#a4161a;color:#fff;text-decoration:none;font-weight:600}'
			. '.gasf-hero__btn:hover{filter:brightness(1.08)}'
			. '</style>';
	}

	/* ---------- cache purge at activation time ---------- */
	add_action( 'gasf_hero_activate_event', 'gasf_hero_purge_home' );
	function gasf_hero_purge_home() {
		if ( function_exists( 'gasf_mec_log' ) ) { gasf_mec_log( 'HERO activation -> purging home-page cache' ); }
		do_action( 'epc_purge' );
		$home = (int) get_option( 'page_on_front' );
		if ( $home ) {
			clean_post_cache( $home );
			wp_update_post( array(
				'ID'                => $home,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			) );
		}
		do_action( 'epc_purge' );
	}
	function gasf_hero_schedule_purge( $ts ) {
		$ts = (int) $ts;
		if ( $ts > time() ) {
			wp_schedule_single_event( $ts, 'gasf_hero_activate_event' );
		}
	}

	/* ---------- upcoming MEC events (next N days) for quick-create ---------- */
	function gasf_hero_upcoming_events( $days = 7 ) {
		global $wpdb;
		$tz    = wp_timezone();
		$today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$end   = ( new DateTime( "+$days days", $tz ) )->format( 'Y-m-d' );
		$q = new WP_Query( array(
			'post_type'      => 'mec-events',
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'meta_key'       => 'mec_start_date',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => array( array(
				'key'     => 'mec_start_date',
				'value'   => array( $today, $end ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			) ),
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$sd = get_post_meta( $p->ID, 'mec_start_date', true );
			if ( ! $sd ) { continue; }
			$tsec  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT time_start FROM {$wpdb->prefix}mec_events WHERE post_id=%d LIMIT 1", $p->ID ) );
			$start = new DateTime( $sd, $tz );
			$start->modify( "+{$tsec} seconds" );
			$activate = ( clone $start )->modify( '-72 hours' );
			$tid   = (int) get_post_thumbnail_id( $p->ID );
			$out[] = array(
				'id'       => $p->ID,
				'title'    => get_the_title( $p->ID ),
				'image_id' => $tid,
				'thumb'    => $tid ? wp_get_attachment_image_url( $tid, 'medium' ) : '',
				'url'      => get_permalink( $p->ID ),
				'activate' => $activate->format( 'Y-m-d\TH:i' ),
				'when'     => wp_date( 'D M j · g:i a', $start->getTimestamp() ),
			);
		}
		wp_reset_postdata();
		return $out;
	}

	/* ---------- admin screen ---------- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'heroes', 'Heroes', 'gasf_hero_admin_page', 20 );
		}
	} );
	add_action( 'admin_enqueue_scripts', function ( $hook ) {
		if ( $hook === 'toplevel_page_gasf-utilities' ) { wp_enqueue_media(); }
	} );

	function gasf_hero_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		/* delete */
		if ( isset( $_POST['gasf_hero_delete'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$del     = sanitize_text_field( wp_unslash( $_POST['gasf_hero_delete'] ) );
			$entries = array_values( array_filter( gasf_hero_get_entries(), function ( $e ) use ( $del ) { return $e['id'] !== $del; } ) );
			gasf_hero_save_entries( $entries );
			echo '<div class="notice notice-success is-dismissible"><p>Hero entry deleted.</p></div>';
		}

		/* add */
		if ( isset( $_POST['gasf_hero_add'] ) && check_admin_referer( 'gasf_hero_action' ) ) {
			$image_id = (int) ( $_POST['gasf_hero_image_id'] ?? 0 );
			$when_raw = sanitize_text_field( wp_unslash( $_POST['gasf_hero_activate_at'] ?? '' ) );
			$dt       = $when_raw ? DateTime::createFromFormat( 'Y-m-d\TH:i', $when_raw, wp_timezone() ) : false;
			$ts       = $dt ? $dt->getTimestamp() : 0;

			if ( ! $image_id ) {
				echo '<div class="notice notice-error"><p>Please choose an image.</p></div>';
			} elseif ( ! $ts ) {
				echo '<div class="notice notice-error"><p>Please set a valid activation date/time.</p></div>';
			} else {
				$entries   = gasf_hero_get_entries();
				$entries[] = array(
					'id'           => uniqid( 'hero_', true ),
					'image_id'     => $image_id,
					'image_url'    => esc_url_raw( wp_unslash( $_POST['gasf_hero_image_url'] ?? '' ) ),
					'max_width'    => max( 0, (int) ( $_POST['gasf_hero_max_width'] ?? 0 ) ),
					'caption'      => wp_kses_post( wp_unslash( $_POST['gasf_hero_caption'] ?? '' ) ),
					'button_label' => sanitize_text_field( wp_unslash( $_POST['gasf_hero_button_label'] ?? '' ) ),
					'button_url'   => esc_url_raw( wp_unslash( $_POST['gasf_hero_button_url'] ?? '' ) ),
					'activate_at'  => $ts,
					'created'      => time(),
				);
				gasf_hero_save_entries( $entries );
				gasf_hero_schedule_purge( $ts );
				$verb = $ts > time() ? 'scheduled for' : 'live as of';
				echo '<div class="notice notice-success is-dismissible"><p>Hero ' . esc_html( $verb ) . ' ' . esc_html( wp_date( 'M j, Y g:i a', $ts ) ) . '.</p></div>';
			}
		}

		$entries   = gasf_hero_get_entries();
		$now       = time();
		$active    = gasf_hero_active();
		$active_id = $active ? $active['id'] : '';
		$tz        = wp_timezone_string();
		?>
			<h2>Home Page Hero</h2>
			<p>Schedule the large image at the top of the home page. Choose an image, optionally make it clickable, add a caption and a button, and set when it goes live. At its scheduled time it automatically replaces whatever is showing.</p>

			<?php $gasf_hero_up = gasf_hero_upcoming_events( 7 ); if ( $gasf_hero_up ) : ?>
			<h3 class="title">Quick-create from an upcoming event</h3>
			<p>Next 7 days from the calendar. Click one to pre-fill the form below with its image &amp; link and a go-live time <strong>72&nbsp;hours before</strong> the event &mdash; then edit and schedule.</p>
			<div class="gasf-hero-up">
				<?php foreach ( $gasf_hero_up as $ev ) : ?>
					<button type="button" class="gasf-hero-up__item"
						data-image-id="<?php echo (int) $ev['image_id']; ?>"
						data-thumb="<?php echo esc_attr( $ev['thumb'] ); ?>"
						data-url="<?php echo esc_attr( $ev['url'] ); ?>"
						data-activate="<?php echo esc_attr( $ev['activate'] ); ?>">
						<?php if ( $ev['thumb'] ) : ?><img src="<?php echo esc_url( $ev['thumb'] ); ?>" alt=""><?php endif; ?>
						<span class="gasf-hero-up__t"><?php echo esc_html( $ev['title'] ); ?></span>
						<span class="gasf-hero-up__d"><?php echo esc_html( $ev['when'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<style>
				.gasf-hero-up{display:flex;flex-wrap:wrap;gap:10px;margin:6px 0 24px}
				.gasf-hero-up__item{cursor:pointer;width:150px;text-align:left;background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:8px;display:flex;flex-direction:column;gap:6px}
				.gasf-hero-up__item:hover{border-color:#2271b1;box-shadow:0 1px 4px rgba(0,0,0,.12)}
				.gasf-hero-up__item img{width:100%;height:90px;object-fit:cover;border-radius:4px;display:block}
				.gasf-hero-up__t{font-weight:600;font-size:12px;line-height:1.25}
				.gasf-hero-up__d{font-size:11px;color:#666}
			</style>
			<?php endif; ?>

			<h3 class="title">Add / schedule a hero</h3>
			<form method="post">
				<?php wp_nonce_field( 'gasf_hero_action' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Image</th>
						<td>
							<input type="hidden" id="gasf_hero_image_id" name="gasf_hero_image_id" value="">
							<div id="gasf_hero_preview" style="margin-bottom:8px;max-width:460px"></div>
							<button type="button" class="button" id="gasf_hero_pick">Choose image from Media Library</button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_max_width">Display width (optional)</label></th>
						<td><input type="number" id="gasf_hero_max_width" name="gasf_hero_max_width" class="small-text" min="0" step="10" placeholder="450"> px
						<p class="description">Max image width, centered. Leave blank or 0 for full width.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_image_url">Image link (optional)</label></th>
						<td><input type="url" id="gasf_hero_image_url" name="gasf_hero_image_url" class="regular-text" placeholder="https://…">
						<p class="description">Makes the whole image clickable. Can be different from the button link below.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_caption">Caption (optional)</label></th>
						<td><textarea id="gasf_hero_caption" name="gasf_hero_caption" rows="3" class="large-text" placeholder="Shown below the image. Basic HTML / links allowed."></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_button_label">Button label (optional)</label></th>
						<td><input type="text" id="gasf_hero_button_label" name="gasf_hero_button_label" class="regular-text" placeholder="e.g. Get Tickets"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_button_url">Button link (optional)</label></th>
						<td><input type="url" id="gasf_hero_button_url" name="gasf_hero_button_url" class="regular-text" placeholder="https://…">
						<p class="description">A URL here adds a button below the caption.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="gasf_hero_activate_at">Go live on</label></th>
						<td>
							<input type="datetime-local" id="gasf_hero_activate_at" name="gasf_hero_activate_at" required>
							<p class="description">Site time (<?php echo esc_html( $tz ); ?>). Set to now or the past to show immediately.</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="gasf_hero_add" value="1" class="button button-primary">Schedule hero</button></p>
			</form>

			<h3 class="title">Scheduled heroes</h3>
			<table class="widefat striped">
				<thead><tr><th>Image</th><th>Goes live</th><th>Status</th><th>Links / caption</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $entries ) : ?>
					<tr><td colspan="5">No heroes yet.</td></tr>
				<?php else : foreach ( array_reverse( $entries ) as $e ) :
					$ts     = (int) $e['activate_at'];
					$status = ( $e['id'] === $active_id )
						? '<strong style="color:#1a7f37">● LIVE NOW</strong>'
						: ( $ts > $now ? '<span style="color:#8250df">queued</span>' : '<span style="color:#888">past</span>' );
					$thumb  = wp_get_attachment_image( (int) $e['image_id'], array( 90, 90 ) );
					$img_url = isset( $e['image_url'] ) ? $e['image_url'] : '';
				?>
					<tr>
						<td><?php echo $thumb ? $thumb : '#' . (int) $e['image_id']; // phpcs:ignore ?></td>
						<td><?php echo esc_html( wp_date( 'M j, Y g:i a', $ts ) ); ?></td>
						<td><?php echo $status; // phpcs:ignore ?></td>
						<td>
							<?php
							if ( $img_url !== '' ) { echo '<small>image &rarr; ' . esc_html( $img_url ) . '</small><br>'; }
							echo $e['caption'] !== '' ? esc_html( wp_trim_words( wp_strip_all_tags( $e['caption'] ), 12 ) ) : ( $img_url !== '' ? '' : '—' );
							if ( isset( $e['button_url'] ) && $e['button_url'] !== '' ) {
								echo '<br><small>button: ' . esc_html( $e['button_label'] !== '' ? $e['button_label'] : 'Learn More' ) . ' &rarr; ' . esc_html( $e['button_url'] ) . '</small>';
							}
							?>
						</td>
						<td>
							<form method="post" onsubmit="return confirm('Delete this hero entry?');" style="margin:0">
								<?php wp_nonce_field( 'gasf_hero_action' ); ?>
								<input type="hidden" name="gasf_hero_delete" value="<?php echo esc_attr( $e['id'] ); ?>">
								<button type="submit" class="button-link-delete">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		<script>
		jQuery(function($){
			$('#gasf_hero_pick').on('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title:'Select hero image', multiple:false, library:{ type:'image' }, button:{ text:'Use this image' } });
				frame.on('select', function(){
					var a = frame.state().get('selection').first().toJSON();
					$('#gasf_hero_image_id').val(a.id);
					var url = (a.sizes && a.sizes.large) ? a.sizes.large.url : ((a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url);
					$('#gasf_hero_preview').html('<img src="'+url+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">');
				});
				frame.open();
			});

			// Quick-create: clicking an upcoming-event card pre-fills the form below.
			$('.gasf-hero-up__item').on('click', function(){
				var b = $(this);
				$('#gasf_hero_image_id').val( b.data('image-id') || '' );
				$('#gasf_hero_image_url').val( b.data('url') || '' );
				$('#gasf_hero_activate_at').val( b.data('activate') || '' );
				var t = b.data('thumb');
				if ( t ) { $('#gasf_hero_preview').html('<img src="'+t+'" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px">'); }
				$('html,body').animate({ scrollTop: $('#gasf_hero_activate_at').closest('table').offset().top - 80 }, 300);
			});
		});
		</script>
		<?php
	}
}
