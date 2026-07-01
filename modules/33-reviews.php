<?php
/**
 * Reviews wall — modules/33-reviews.php
 *
 * Pulls reviews live from Google (Places API, up to 5), Yelp (Fusion, up to 3),
 * and TripAdvisor (Content API, up to 5), merges in hand-curated reviews
 * (Facebook + any extras, since Meta's review API is locked down), caches the
 * result, refreshes daily, and renders a theme-native reviews wall via the
 * [gasf_reviews] shortcode.
 *
 * Config + API keys/IDs + curated reviews live under GASF Utilities → Reviews.
 * Keys are stored server-side (autoload off) and never emitted to the front end.
 *
 * Gate: gasf_site_enable_reviews (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_reviews' ) : true ) {

	function gasf_reviews_config() {
		return wp_parse_args( (array) get_option( 'gasf_reviews_config', array() ), array(
			'google'      => array( 'key' => '', 'place' => '', 'on' => 0 ),
			'yelp'        => array( 'key' => '', 'biz' => '', 'on' => 0 ),
			'tripadvisor' => array( 'key' => '', 'loc' => '', 'on' => 0 ),
			'layout'      => 'grid',
			'count'       => 9,
			'columns'     => 3,
			'min_rating'  => 4,
			'ttl'         => 43200, // 12h
		) );
	}
	function gasf_reviews_manual() { $m = get_option( 'gasf_reviews_manual', array() ); return is_array( $m ) ? $m : array(); }

	/* ============================ fetchers ============================ */

	function gasf_reviews_fetch_google( $key, $place ) {
		if ( ! $key || ! $place ) { return array( array(), 'not configured' ); }
		$url = add_query_arg( array(
			'place_id' => $place,
			'fields'   => 'reviews,rating,user_ratings_total',
			'reviews_sort' => 'newest',
			'language' => 'en',
			'key'      => $key,
		), 'https://maps.googleapis.com/maps/api/place/details/json' );
		$r = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $r ) ) { return array( array(), $r->get_error_message() ); }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ( $b['status'] ?? '' ) !== 'OK' ) { return array( array(), 'Google: ' . ( $b['status'] ?? '?' ) . ' ' . ( $b['error_message'] ?? '' ) ); }
		$out = array();
		foreach ( (array) ( $b['result']['reviews'] ?? array() ) as $rv ) {
			$out[] = array(
				'source' => 'google',
				'author' => $rv['author_name'] ?? '',
				'avatar' => $rv['profile_photo_url'] ?? '',
				'rating' => (float) ( $rv['rating'] ?? 0 ),
				'text'   => (string) ( $rv['text'] ?? '' ),
				'time'   => (int) ( $rv['time'] ?? 0 ),
				'date'   => $rv['relative_time_description'] ?? '',
				'url'    => $rv['author_url'] ?? '',
			);
		}
		return array( $out, null );
	}

	function gasf_reviews_fetch_yelp( $key, $biz ) {
		if ( ! $key || ! $biz ) { return array( array(), 'not configured' ); }
		$r = wp_remote_get( 'https://api.yelp.com/v3/businesses/' . rawurlencode( $biz ) . '/reviews?limit=3&sort_by=yelp_sort', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		) );
		if ( is_wp_error( $r ) ) { return array( array(), $r->get_error_message() ); }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( isset( $b['error'] ) ) { return array( array(), 'Yelp: ' . ( $b['error']['description'] ?? $b['error']['code'] ?? '?' ) ); }
		$out = array();
		foreach ( (array) ( $b['reviews'] ?? array() ) as $rv ) {
			$out[] = array(
				'source' => 'yelp',
				'author' => $rv['user']['name'] ?? '',
				'avatar' => $rv['user']['image_url'] ?? '',
				'rating' => (float) ( $rv['rating'] ?? 0 ),
				'text'   => (string) ( $rv['text'] ?? '' ),
				'time'   => strtotime( (string) ( $rv['time_created'] ?? '' ) ) ?: 0,
				'date'   => isset( $rv['time_created'] ) ? date_i18n( 'M Y', strtotime( $rv['time_created'] ) ) : '',
				'url'    => $rv['url'] ?? '',
			);
		}
		return array( $out, null );
	}

	function gasf_reviews_fetch_tripadvisor( $key, $loc ) {
		if ( ! $key || ! $loc ) { return array( array(), 'not configured' ); }
		$url = 'https://api.content.tripadvisor.com/api/v1/location/' . rawurlencode( $loc ) . '/reviews?language=en&key=' . rawurlencode( $key );
		$r = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => array( 'accept' => 'application/json', 'Referer' => home_url( '/' ) ),
		) );
		if ( is_wp_error( $r ) ) { return array( array(), $r->get_error_message() ); }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( isset( $b['error'] ) ) { return array( array(), 'TripAdvisor: ' . ( $b['error']['message'] ?? '?' ) ); }
		$out = array();
		foreach ( (array) ( $b['data'] ?? array() ) as $rv ) {
			$txt = trim( ( $rv['title'] ?? '' ) . ( ! empty( $rv['title'] ) ? ' — ' : '' ) . ( $rv['text'] ?? '' ) );
			$out[] = array(
				'source' => 'tripadvisor',
				'author' => $rv['user']['username'] ?? 'TripAdvisor member',
				'avatar' => $rv['user']['avatar']['small']['url'] ?? '',
				'rating' => (float) ( $rv['rating'] ?? 0 ),
				'text'   => $txt,
				'time'   => strtotime( (string) ( $rv['published_date'] ?? '' ) ) ?: 0,
				'date'   => isset( $rv['published_date'] ) ? date_i18n( 'M Y', strtotime( $rv['published_date'] ) ) : '',
				'url'    => $rv['url'] ?? '',
			);
		}
		return array( $out, null );
	}

	/* ============================ refresh + cache ============================ */

	function gasf_reviews_refresh() {
		$c = gasf_reviews_config();
		$items = array();
		$status = array();
		if ( ! empty( $c['google']['on'] ) ) { list( $g, $e ) = gasf_reviews_fetch_google( $c['google']['key'], $c['google']['place'] ); $items = array_merge( $items, $g ); $status['google'] = $e ?: count( $g ) . ' reviews'; }
		if ( ! empty( $c['yelp']['on'] ) ) { list( $y, $e ) = gasf_reviews_fetch_yelp( $c['yelp']['key'], $c['yelp']['biz'] ); $items = array_merge( $items, $y ); $status['yelp'] = $e ?: count( $y ) . ' reviews'; }
		if ( ! empty( $c['tripadvisor']['on'] ) ) { list( $t, $e ) = gasf_reviews_fetch_tripadvisor( $c['tripadvisor']['key'], $c['tripadvisor']['loc'] ); $items = array_merge( $items, $t ); $status['tripadvisor'] = $e ?: count( $t ) . ' reviews'; }
		// curated (Facebook + extras) always included
		foreach ( gasf_reviews_manual() as $m ) {
			$items[] = array(
				'source' => $m['source'] ?? 'facebook',
				'author' => $m['author'] ?? '',
				'avatar' => '',
				'rating' => (float) ( $m['rating'] ?? 5 ),
				'text'   => (string) ( $m['text'] ?? '' ),
				'time'   => isset( $m['date'] ) ? ( strtotime( $m['date'] ) ?: 0 ) : 0,
				'date'   => ! empty( $m['date'] ) ? date_i18n( 'M Y', strtotime( $m['date'] ) ) : '',
				'url'    => $m['url'] ?? '',
			);
		}
		usort( $items, function ( $a, $b ) { return ( $b['time'] ?? 0 ) <=> ( $a['time'] ?? 0 ); } );
		update_option( 'gasf_reviews_cache', array( 'ts' => time(), 'items' => $items, 'status' => $status ), false );
		return array( $items, $status );
	}

	function gasf_reviews_get() {
		$c = gasf_reviews_config();
		$cache = (array) get_option( 'gasf_reviews_cache', array() );
		$fresh = isset( $cache['ts'] ) && ( time() - (int) $cache['ts'] ) < (int) $c['ttl'];
		if ( $fresh && isset( $cache['items'] ) ) { return $cache['items']; }
		if ( get_transient( 'gasf_reviews_fetching' ) && ! empty( $cache['items'] ) ) { return $cache['items']; }
		set_transient( 'gasf_reviews_fetching', 1, 120 );
		list( $items ) = gasf_reviews_refresh();
		delete_transient( 'gasf_reviews_fetching' );
		return $items;
	}

	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_reviews_cron' ) ) { wp_schedule_event( time() + 600, 'daily', 'gasf_reviews_cron' ); }
	} );
	add_action( 'gasf_reviews_cron', 'gasf_reviews_refresh' );

	/* ============================ shortcode ============================ */
	add_shortcode( 'gasf_reviews', 'gasf_reviews_shortcode' );
	function gasf_reviews_shortcode( $atts ) {
		$c = gasf_reviews_config();
		$a = shortcode_atts( array(
			'layout'     => $c['layout'],
			'count'      => $c['count'],
			'columns'    => $c['columns'],
			'min_rating' => $c['min_rating'],
			'source'     => '', // filter: google|yelp|tripadvisor|facebook
		), $atts, 'gasf_reviews' );

		$items = gasf_reviews_get();
		$min   = (float) $a['min_rating'];
		$items = array_filter( $items, function ( $r ) use ( $min, $a ) {
			if ( (float) ( $r['rating'] ?? 0 ) < $min ) { return false; }
			if ( $a['source'] && strtolower( $a['source'] ) !== ( $r['source'] ?? '' ) ) { return false; }
			return true;
		} );
		if ( ! $items ) {
			return current_user_can( 'manage_options' ) ? '<p style="color:#b3122b">[gasf_reviews] No reviews yet — add API keys or curated reviews in GASF Utilities → Reviews.</p>' : '';
		}
		$items  = array_slice( array_values( $items ), 0, max( 1, (int) $a['count'] ) );
		$layout = in_array( $a['layout'], array( 'grid', 'carousel', 'list' ), true ) ? $a['layout'] : 'grid';
		$cols   = max( 1, min( 4, (int) $a['columns'] ) );
		$uid    = 'grv' . wp_rand( 1000, 9999 );

		ob_start();
		gasf_reviews_assets();
		echo '<div class="grv grv--' . esc_attr( $layout ) . '" id="' . esc_attr( $uid ) . '" style="--grv-cols:' . (int) $cols . '">';
		if ( $layout === 'carousel' ) { echo '<button class="grv-arrow grv-prev" aria-label="Previous">&#8249;</button>'; }
		echo '<div class="grv-track">';
		foreach ( $items as $r ) {
			$src   = $r['source'] ?? 'manual';
			$rate  = (float) ( $r['rating'] ?? 0 );
			$text  = wp_strip_all_tags( (string) $r['text'] );
			$short = mb_strlen( $text ) > 240 ? mb_substr( $text, 0, 237 ) . '…' : $text;
			echo '<figure class="grv-card">';
			echo '<div class="grv-head">';
			if ( ! empty( $r['avatar'] ) ) {
				echo '<img class="grv-av" loading="lazy" src="' . esc_url( $r['avatar'] ) . '" alt="">';
			} else {
				echo '<span class="grv-av grv-av--i">' . esc_html( mb_strtoupper( mb_substr( (string) $r['author'], 0, 1 ) ) ) . '</span>';
			}
			echo '<div><div class="grv-name">' . esc_html( $r['author'] ?: 'Guest' ) . '</div><div class="grv-stars" title="' . esc_attr( $rate ) . '/5">' . gasf_reviews_stars( $rate ) . '</div></div>';
			echo '<span class="grv-badge grv-badge--' . esc_attr( $src ) . '" title="' . esc_attr( ucfirst( $src ) ) . '">' . gasf_reviews_icon( $src ) . '</span>';
			echo '</div>';
			echo '<blockquote class="grv-text">' . esc_html( $short ) . '</blockquote>';
			echo '<figcaption class="grv-foot">' . esc_html( $r['date'] ?? '' );
			if ( ! empty( $r['url'] ) ) { echo ' · <a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener nofollow">read on ' . esc_html( ucfirst( $src ) ) . '</a>'; }
			echo '</figcaption>';
			echo '</figure>';
		}
		echo '</div>';
		if ( $layout === 'carousel' ) { echo '<button class="grv-arrow grv-next" aria-label="Next">&#8250;</button>'; }
		echo '</div>';
		return ob_get_clean();
	}

	function gasf_reviews_stars( $r ) {
		$full = (int) floor( $r );
		$half = ( $r - $full ) >= 0.5;
		$out  = str_repeat( '★', $full );
		if ( $half ) { $out .= '⯪'; }
		$out .= str_repeat( '☆', max( 0, 5 - $full - ( $half ? 1 : 0 ) ) );
		return '<span class="grv-star">' . $out . '</span>';
	}
	function gasf_reviews_icon( $src ) {
		$m = array( 'google' => 'G', 'yelp' => 'Yelp', 'tripadvisor' => 'TA', 'facebook' => 'f' );
		return esc_html( $m[ $src ] ?? '★' );
	}

	function gasf_reviews_assets() {
		static $done = false; if ( $done ) { return; } $done = true;
		?>
<style>
.grv{position:relative;--grv-cols:3}
.grv-track{display:grid;grid-template-columns:repeat(var(--grv-cols),1fr);gap:16px}
.grv--list .grv-track{grid-template-columns:1fr}
.grv--carousel .grv-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:16px;scrollbar-width:none}
.grv--carousel .grv-track::-webkit-scrollbar{display:none}
.grv--carousel .grv-card{flex:0 0 calc((100% - (var(--grv-cols) - 1)*16px)/var(--grv-cols));scroll-snap-align:start}
.grv-card{margin:0;background:var(--grv-bg,#fff);border:1px solid var(--grv-border,#e3e3e3);border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:10px}
.grv-head{display:flex;align-items:center;gap:10px}
.grv-av{width:42px;height:42px;border-radius:50%;object-fit:cover;flex:0 0 auto}
.grv-av--i{display:flex;align-items:center;justify-content:center;background:var(--gasf-gold,#EF9F27);color:#1a1a2e;font-weight:700;font-size:18px}
.grv-name{font-weight:700;line-height:1.2}
.grv-stars{color:#f5b301;font-size:15px;letter-spacing:1px}
.grv-badge{margin-left:auto;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff}
.grv-badge--google{background:#4285F4}.grv-badge--yelp{background:#d32323;font-size:8px}.grv-badge--tripadvisor{background:#00aa6c}.grv-badge--facebook{background:#1877f2}
.grv-text{margin:0;font-size:14px;line-height:1.5;color:var(--grv-fg,#333)}
.grv-foot{font-size:12px;color:#777}
.grv-foot a{color:#2b6cb0 !important;text-decoration:underline}
.grv-foot a:hover{color:#1a1a2e !important}
.grv-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.95);box-shadow:0 1px 6px rgba(0,0,0,.25);font-size:22px;cursor:pointer}
.grv-prev{left:-6px}.grv-next{right:-6px}
@media(max-width:900px){.grv{--grv-cols:2}}
@media(max-width:600px){.grv{--grv-cols:1}.grv--carousel{--grv-cols:1}}
</style>
<script>
(function(){if(window.grvInit)return;window.grvInit=1;document.addEventListener('click',function(e){var a=e.target.closest('.grv-arrow');if(!a)return;var t=a.closest('.grv').querySelector('.grv-track');t.scrollBy({left:(a.classList.contains('grv-next')?1:-1)*t.clientWidth*0.85,behavior:'smooth'});});})();
</script>
		<?php
	}

	/* ============================ admin ============================ */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'reviews', 'Reviews', 'gasf_reviews_admin', 26 ); }
	} );

	function gasf_reviews_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_rv_action'] ) && check_admin_referer( 'gasf_rv' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_rv_action'] ) );
			if ( 'save' === $act ) {
				$c = gasf_reviews_config();
				foreach ( array( 'google' => 'place', 'yelp' => 'biz', 'tripadvisor' => 'loc' ) as $s => $idf ) {
					$newkey = trim( sanitize_text_field( wp_unslash( $_POST[ $s . '_key' ] ?? '' ) ) );
					if ( '' !== $newkey ) { $c[ $s ]['key'] = $newkey; } // keep existing key when field left blank
					$c[ $s ][ $idf ] = trim( sanitize_text_field( wp_unslash( $_POST[ $s . '_id' ] ?? '' ) ) );
					$c[ $s ]['on']   = ! empty( $_POST[ $s . '_on' ] ) ? 1 : 0;
				}
				$c['layout']     = in_array( $_POST['layout'] ?? 'grid', array( 'grid', 'carousel', 'list' ), true ) ? $_POST['layout'] : 'grid';
				$c['count']      = max( 1, min( 30, (int) ( $_POST['count'] ?? 9 ) ) );
				$c['columns']    = max( 1, min( 4, (int) ( $_POST['columns'] ?? 3 ) ) );
				$c['min_rating'] = max( 1, min( 5, (int) ( $_POST['min_rating'] ?? 4 ) ) );
				update_option( 'gasf_reviews_config', $c, false );
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
			} elseif ( 'fetch' === $act ) {
				list( , $status ) = gasf_reviews_refresh();
				echo '<div class="notice notice-success is-dismissible"><p>Fetched. ' . esc_html( implode( ' · ', array_map( function ( $k, $v ) { return "$k: $v"; }, array_keys( $status ), $status ) ) ) . '</p></div>';
			} elseif ( 'addm' === $act ) {
				$m = gasf_reviews_manual();
				$m[] = array(
					'source' => in_array( $_POST['m_source'] ?? 'facebook', array( 'facebook', 'google', 'yelp', 'tripadvisor', 'other' ), true ) ? $_POST['m_source'] : 'facebook',
					'author' => sanitize_text_field( wp_unslash( $_POST['m_author'] ?? '' ) ),
					'rating' => max( 1, min( 5, (int) ( $_POST['m_rating'] ?? 5 ) ) ),
					'text'   => sanitize_textarea_field( wp_unslash( $_POST['m_text'] ?? '' ) ),
					'date'   => sanitize_text_field( wp_unslash( $_POST['m_date'] ?? '' ) ),
					'url'    => esc_url_raw( wp_unslash( $_POST['m_url'] ?? '' ) ),
				);
				update_option( 'gasf_reviews_manual', array_values( $m ), false );
				delete_option( 'gasf_reviews_cache' ); // force rebuild incl. new curated
				echo '<div class="notice notice-success is-dismissible"><p>Curated review added.</p></div>';
			} elseif ( 'delm' === $act ) {
				$i = (int) ( $_POST['idx'] ?? -1 );
				$m = gasf_reviews_manual(); if ( isset( $m[ $i ] ) ) { unset( $m[ $i ] ); update_option( 'gasf_reviews_manual', array_values( $m ), false ); delete_option( 'gasf_reviews_cache' ); }
				echo '<div class="notice notice-success is-dismissible"><p>Removed.</p></div>';
			}
		}

		$c = gasf_reviews_config();
		$cache = (array) get_option( 'gasf_reviews_cache', array() );
		$mask = function ( $v ) { $v = (string) $v; return $v === '' ? '' : ( substr( $v, 0, 4 ) . str_repeat( '•', max( 0, min( 20, strlen( $v ) - 4 ) ) ) ); };
		?>
		<h2>Reviews</h2>
		<p>Live reviews from Google, Yelp &amp; TripAdvisor, plus hand-curated ones (Facebook &amp; extras). Add the shortcode <code>[gasf_reviews]</code> to any page. Refreshes daily.</p>
		<table class="widefat striped" style="max-width:640px"><tr><td>Last fetch</td><td><?php echo ! empty( $cache['ts'] ) ? esc_html( human_time_diff( (int) $cache['ts'] ) ) . ' ago · ' . count( $cache['items'] ?? array() ) . ' reviews cached' : 'never'; ?></td></tr>
		<?php foreach ( (array) ( $cache['status'] ?? array() ) as $s => $st ) : ?><tr><td><?php echo esc_html( ucfirst( $s ) ); ?></td><td><?php echo esc_html( $st ); ?></td></tr><?php endforeach; ?></table>

		<h3 class="title">Live sources</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_rv' ); ?>
			<table class="form-table" role="presentation">
				<?php
				$srcs = array(
					'google'      => array( 'Google', 'place', 'Place ID', 'API key with Places API enabled + billing' ),
					'yelp'        => array( 'Yelp', 'biz', 'Business ID or alias', 'Yelp Fusion API key' ),
					'tripadvisor' => array( 'TripAdvisor', 'loc', 'Location ID', 'Content API key (key must allow this site as Referer)' ),
				);
				foreach ( $srcs as $s => $meta ) : list( $label, $idf, $idlabel, $hint ) = $meta; ?>
					<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td>
						<label><input type="checkbox" name="<?php echo $s; ?>_on" value="1" <?php checked( ! empty( $c[ $s ]['on'] ) ); ?>> Enable</label><br>
						<input type="text" name="<?php echo $s; ?>_id" value="<?php echo esc_attr( $c[ $s ][ $idf ] ?? '' ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( $idlabel ); ?>" style="margin:4px 0"><br>
						<input type="text" name="<?php echo $s; ?>_key" value="" class="regular-text code" placeholder="<?php echo $c[ $s ]['key'] ? 'saved: ' . esc_attr( $mask( $c[ $s ]['key'] ) ) . ' (leave blank to keep)' : 'API key'; ?>">
						<p class="description"><?php echo esc_html( $hint ); ?></p>
					</td></tr>
				<?php endforeach; ?>
			</table>
			<h3 class="title">Display</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Layout</th><td><select name="layout"><?php foreach ( array( 'grid', 'carousel', 'list' ) as $l ) { echo '<option ' . selected( $c['layout'], $l, false ) . '>' . $l . '</option>'; } ?></select></td></tr>
				<tr><th scope="row">Max reviews</th><td><input type="number" name="count" value="<?php echo (int) $c['count']; ?>" min="1" max="30" class="small-text"></td></tr>
				<tr><th scope="row">Columns</th><td><input type="number" name="columns" value="<?php echo (int) $c['columns']; ?>" min="1" max="4" class="small-text"></td></tr>
				<tr><th scope="row">Minimum rating</th><td><input type="number" name="min_rating" value="<?php echo (int) $c['min_rating']; ?>" min="1" max="5" class="small-text"> stars and up</td></tr>
			</table>
			<p><button name="gasf_rv_action" value="save" class="button button-primary">Save settings</button>
			<button name="gasf_rv_action" value="fetch" class="button">Fetch reviews now</button></p>
		</form>

		<h3 class="title">Curated reviews (Facebook &amp; extras)</h3>
		<form method="post" style="margin-bottom:14px"><?php wp_nonce_field( 'gasf_rv' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Source</th><td><select name="m_source"><?php foreach ( array( 'facebook', 'google', 'yelp', 'tripadvisor', 'other' ) as $s ) { echo "<option>$s</option>"; } ?></select>
					&nbsp; Rating <select name="m_rating"><?php for ( $i = 5; $i >= 1; $i-- ) { echo "<option>$i</option>"; } ?></select> stars
					&nbsp; Date <input type="text" name="m_date" placeholder="2026-05-01 or May 2026" class="regular-text" style="width:150px"></td></tr>
				<tr><th scope="row">Author</th><td><input type="text" name="m_author" class="regular-text" placeholder="Reviewer name"></td></tr>
				<tr><th scope="row">Review text</th><td><textarea name="m_text" rows="3" class="large-text"></textarea></td></tr>
				<tr><th scope="row">Link (optional)</th><td><input type="url" name="m_url" class="large-text" placeholder="https://…"></td></tr>
			</table>
			<p><button name="gasf_rv_action" value="addm" class="button button-primary">Add curated review</button></p>
		</form>
		<table class="widefat striped">
			<thead><tr><th>Source</th><th>Author</th><th>Rating</th><th>Text</th><th></th></tr></thead>
			<tbody>
			<?php $m = gasf_reviews_manual(); if ( ! $m ) : ?><tr><td colspan="5">None yet.</td></tr><?php else : foreach ( $m as $i => $rv ) : ?>
				<tr><td><?php echo esc_html( $rv['source'] ?? '' ); ?></td><td><?php echo esc_html( $rv['author'] ?? '' ); ?></td><td><?php echo (int) ( $rv['rating'] ?? 0 ); ?>★</td>
				<td><small><?php echo esc_html( wp_trim_words( (string) ( $rv['text'] ?? '' ), 16 ) ); ?></small></td>
				<td><form method="post" style="margin:0" onsubmit="return confirm('Remove?');"><?php wp_nonce_field( 'gasf_rv' ); ?><input type="hidden" name="idx" value="<?php echo (int) $i; ?>"><button name="gasf_rv_action" value="delm" class="button-link-delete">Delete</button></form></td></tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}
}
