<?php
/**
 * Native Instagram Feed — modules/28-instagram.php
 *
 * A theme-native Instagram feed for @germanamericansocietytampabay, built to
 * replace Smash Balloon's display with full styling control. Pulls the
 * account's own media (posts, carousels, reels) from the Instagram Graph API
 * (graph.instagram.com, "Instagram API with Instagram Login"), sideloads
 * images locally for speed, caches the result, and renders grid / masonry /
 * carousel layouts with a built-in lightbox.
 *
 * Token: we own it. gasf_ig_import_from_sb() lifts the existing long-lived
 * token from Smash Balloon once; gasf_ig_cron refreshes it (ig_refresh_token)
 * well before the 60-day expiry, so this keeps working even if SB is removed.
 * No Meta app or App Review needed — this uses only /me/media, which the
 * account's own token already permits. (Hashtag/tagged feeds are intentionally
 * out of scope: they require FB-Page Graph access + Meta App Review.)
 *
 * Shortcode: [gasf_instagram layout="grid|masonry|carousel" count="12"
 *            columns="4" captions="0" gap="10" radius="8"]
 *
 * Gate: gasf_site_enable_instagram (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_instagram' ) : true ) {

	define( 'GASF_IG_GRAPH', 'https://graph.instagram.com/v21.0' );
	define( 'GASF_IG_DIR', 'gasf-ig' ); // under uploads/

	/* ============================ token ============================ */

	function gasf_ig_token() { return (string) get_option( 'gasf_ig_token', '' ); }
	function gasf_ig_meta()  { return (array) get_option( 'gasf_ig_meta', array() ); }

	/** Lift the current long-lived token out of Smash Balloon (one-time bootstrap). */
	function gasf_ig_import_from_sb() {
		global $wpdb;
		$t = $wpdb->prefix . 'sbi_sources';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) !== $t ) { return 'Smash Balloon source table not found.'; }
		$row = $wpdb->get_row( "SELECT account_id, access_token, username, expires FROM $t LIMIT 1", ARRAY_A );
		if ( ! $row || empty( $row['access_token'] ) ) { return 'No Smash Balloon source/token found.'; }

		$tok = $row['access_token'];
		$cls = null;
		foreach ( array( 'SB_Instagram_Data_Encryption', '\\InstagramFeed\\SB_Instagram_Data_Encryption' ) as $c ) {
			if ( class_exists( $c ) ) { $cls = $c; break; }
		}
		if ( ! $cls ) {
			$f = WP_PLUGIN_DIR . '/instagram-feed/inc/class-sb-instagram-data-encryption.php';
			if ( file_exists( $f ) ) { require_once $f; if ( class_exists( 'SB_Instagram_Data_Encryption' ) ) { $cls = 'SB_Instagram_Data_Encryption'; } }
		}
		if ( $cls ) { $enc = new $cls(); $dec = $enc->decrypt( $tok ); if ( $dec ) { $tok = $dec; } }

		// Validate against the API before storing.
		$me = gasf_ig_api( '/me', array( 'fields' => 'id,username,account_type,media_count' ), $tok );
		if ( is_wp_error( $me ) ) { return 'Token did not validate: ' . $me->get_error_message(); }

		update_option( 'gasf_ig_token', $tok, false );
		update_option( 'gasf_ig_meta', array(
			'user_id'    => $me['id'] ?? ( $row['account_id'] ?? '' ),
			'username'   => $me['username'] ?? $row['username'] ?? '',
			'type'       => $me['account_type'] ?? '',
			'media_count'=> (int) ( $me['media_count'] ?? 0 ),
			'expires'    => strtotime( (string) ( $row['expires'] ?? '' ) ) ?: ( time() + 50 * DAY_IN_SECONDS ),
			'imported'   => time(),
		), false );
		return true;
	}

	/** Refresh the long-lived token (safe to call anytime; only refreshes when it makes sense). */
	function gasf_ig_refresh_token( $force = false ) {
		$tok  = gasf_ig_token();
		$meta = gasf_ig_meta();
		if ( ! $tok ) { return 'No token to refresh.'; }
		$exp = (int) ( $meta['expires'] ?? 0 );
		if ( ! $force && $exp && $exp - time() > 10 * DAY_IN_SECONDS ) { return 'ok (not near expiry)'; }
		$r = wp_remote_get( GASF_IG_GRAPH . '/refresh_access_token?grant_type=ig_refresh_token&access_token=' . rawurlencode( $tok ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $r ) ) { return $r->get_error_message(); }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( empty( $b['access_token'] ) ) { return 'Refresh failed: ' . wp_remote_retrieve_body( $r ); }
		update_option( 'gasf_ig_token', $b['access_token'], false );
		$meta['expires'] = time() + (int) ( $b['expires_in'] ?? ( 60 * DAY_IN_SECONDS ) );
		$meta['refreshed'] = time();
		update_option( 'gasf_ig_meta', $meta, false );
		return true;
	}

	/* ============================ API ============================ */

	function gasf_ig_api( $path, $args, $token = null ) {
		$token = $token ?: gasf_ig_token();
		if ( ! $token ) { return new WP_Error( 'no_token', 'No Instagram token configured.' ); }
		$args['access_token'] = $token;
		$url = GASF_IG_GRAPH . $path . '?' . http_build_query( $args );
		$r = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $r ) ) { return $r; }
		$b = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( isset( $b['error'] ) ) { return new WP_Error( 'ig_' . ( $b['error']['code'] ?? 0 ), $b['error']['message'] ?? 'Instagram API error' ); }
		return $b;
	}

	/* ==================== image sideloading ==================== */

	function gasf_ig_cache_dir() {
		$u = wp_upload_dir();
		return array( 'dir' => trailingslashit( $u['basedir'] ) . GASF_IG_DIR, 'url' => trailingslashit( $u['baseurl'] ) . GASF_IG_DIR );
	}

	/** Download an image once; return a local URL (or the remote URL on failure). */
	function gasf_ig_sideload( $remote_url ) {
		if ( ! $remote_url ) { return ''; }
		$c    = gasf_ig_cache_dir();
		$name = md5( $remote_url ) . '.jpg';
		$path = $c['dir'] . '/' . $name;
		$url  = $c['url'] . '/' . $name;
		if ( file_exists( $path ) ) { return $url; }
		if ( ! wp_mkdir_p( $c['dir'] ) ) { return $remote_url; }
		$r = wp_remote_get( $remote_url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) { return $remote_url; }
		$body = wp_remote_retrieve_body( $r );
		if ( ! $body ) { return $remote_url; }
		if ( ! function_exists( 'WP_Filesystem' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		file_put_contents( $path, $body ); // phpcs:ignore -- cache dir, not user content
		return file_exists( $path ) ? $url : $remote_url;
	}

	/* ==================== fetch + normalize + cache ==================== */

	function gasf_ig_settings() {
		return wp_parse_args( (array) get_option( 'gasf_ig_settings', array() ), array(
			'count' => 24, 'ttl' => 3600, 'layout' => 'grid', 'columns' => 4, 'captions' => 0, 'gap' => 10, 'radius' => 8,
		) );
	}

	/** Normalized items from cache; refetches when stale. */
	function gasf_ig_get_media( $force = false ) {
		$s     = gasf_ig_settings();
		$cache = (array) get_option( 'gasf_ig_media_cache', array() );
		$fresh = isset( $cache['ts'] ) && ( time() - (int) $cache['ts'] ) < (int) $s['ttl'];
		if ( ! $force && $fresh && ! empty( $cache['items'] ) ) { return $cache['items']; }

		// Single-flight lock so concurrent hits don't all fetch.
		if ( ! $force && get_transient( 'gasf_ig_fetching' ) && ! empty( $cache['items'] ) ) { return $cache['items']; }
		set_transient( 'gasf_ig_fetching', 1, 120 );

		$fields = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,children{media_type,media_url,thumbnail_url}';
		$res = gasf_ig_api( '/me/media', array( 'fields' => $fields, 'limit' => max( 1, min( 90, (int) $s['count'] ) ) ) );
		if ( is_wp_error( $res ) || empty( $res['data'] ) ) {
			delete_transient( 'gasf_ig_fetching' );
			return $cache['items'] ?? array();
		}

		$items = array();
		foreach ( $res['data'] as $m ) {
			$type = strtoupper( $m['media_type'] ?? 'IMAGE' );
			$is_reel = ( ( $m['media_product_type'] ?? '' ) === 'REELS' );
			$poster_src = ( $type === 'VIDEO' ) ? ( $m['thumbnail_url'] ?? $m['media_url'] ?? '' ) : ( $m['media_url'] ?? '' );
			$item = array(
				'id'        => $m['id'] ?? '',
				'type'      => $type === 'VIDEO' ? ( $is_reel ? 'reel' : 'video' ) : ( $type === 'CAROUSEL_ALBUM' ? 'album' : 'image' ),
				'permalink' => $m['permalink'] ?? '',
				'caption'   => (string) ( $m['caption'] ?? '' ),
				'time'      => $m['timestamp'] ?? '',
				'poster'    => gasf_ig_sideload( $poster_src ),
				'video'     => $type === 'VIDEO' ? ( $m['media_url'] ?? '' ) : '',
				'children'  => array(),
			);
			if ( ! empty( $m['children']['data'] ) ) {
				foreach ( $m['children']['data'] as $ch ) {
					$ct = strtoupper( $ch['media_type'] ?? 'IMAGE' );
					$item['children'][] = array(
						'type'   => $ct === 'VIDEO' ? 'video' : 'image',
						'poster' => gasf_ig_sideload( $ct === 'VIDEO' ? ( $ch['thumbnail_url'] ?? '' ) : ( $ch['media_url'] ?? '' ) ),
						'video'  => $ct === 'VIDEO' ? ( $ch['media_url'] ?? '' ) : '',
					);
				}
			}
			$items[] = $item;
		}
		update_option( 'gasf_ig_media_cache', array( 'ts' => time(), 'items' => $items ), false );
		delete_transient( 'gasf_ig_fetching' );
		return $items;
	}

	/* ==================== cron: refresh token + warm cache ==================== */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_ig_cron' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_ig_cron' );
		}
	} );
	add_action( 'gasf_ig_cron', function () {
		if ( gasf_ig_token() ) { gasf_ig_refresh_token( false ); gasf_ig_get_media( true ); }
	} );

	/* ============================ shortcode ============================ */
	add_shortcode( 'gasf_instagram', 'gasf_ig_shortcode' );
	function gasf_ig_shortcode( $atts ) {
		$s = gasf_ig_settings();
		$a = shortcode_atts( array(
			'layout'   => $s['layout'],
			'count'    => $s['count'],
			'columns'  => $s['columns'],
			'captions' => $s['captions'],
			'gap'      => $s['gap'],
			'radius'   => $s['radius'],
		), $atts, 'gasf_instagram' );

		$items = gasf_ig_get_media();
		if ( ! $items ) {
			return current_user_can( 'manage_options' )
				? '<p style="color:#b3122b">[gasf_instagram] No Instagram media yet — connect the token in GASF Utilities → Instagram.</p>'
				: '';
		}
		$items  = array_slice( $items, 0, max( 1, (int) $a['count'] ) );
		$layout = in_array( $a['layout'], array( 'grid', 'masonry', 'carousel' ), true ) ? $a['layout'] : 'grid';
		$cols   = max( 1, min( 8, (int) $a['columns'] ) );
		$uid    = 'gig' . wp_rand( 1000, 9999 );

		$style = sprintf(
			'--gig-cols:%d;--gig-gap:%dpx;--gig-radius:%dpx;',
			$cols, max( 0, (int) $a['gap'] ), max( 0, (int) $a['radius'] )
		);

		ob_start();
		gasf_ig_assets();
		echo '<div class="gig gig--' . esc_attr( $layout ) . '" id="' . esc_attr( $uid ) . '" style="' . esc_attr( $style ) . '">';
		if ( $layout === 'carousel' ) {
			echo '<button class="gig-arrow gig-prev" aria-label="Previous">&#8249;</button>';
		}
		echo '<div class="gig-track">';
		foreach ( $items as $i => $it ) {
			$badge = $it['type'] === 'reel' ? '&#9658;' : ( $it['type'] === 'video' ? '&#9658;' : ( $it['type'] === 'album' ? '&#9783;' : '' ) );
			echo '<button class="gig-tile" data-idx="' . (int) $i . '" aria-label="Open post">';
			echo '<img loading="lazy" src="' . esc_url( $it['poster'] ) . '" alt="' . esc_attr( wp_trim_words( wp_strip_all_tags( $it['caption'] ), 12 ) ) . '">';
			if ( $badge ) { echo '<span class="gig-badge">' . $badge . '</span>'; } // phpcs:ignore
			if ( (int) $a['captions'] && $it['caption'] !== '' ) {
				echo '<span class="gig-cap">' . esc_html( wp_trim_words( wp_strip_all_tags( $it['caption'] ), 14 ) ) . '</span>';
			}
			echo '</button>';
		}
		echo '</div>';
		if ( $layout === 'carousel' ) {
			echo '<button class="gig-arrow gig-next" aria-label="Next">&#8250;</button>';
		}
		echo '</div>';
		// Data for the lightbox.
		$data = array_map( function ( $it ) {
			return array(
				't'  => $it['type'],
				'p'  => $it['poster'],
				'v'  => $it['video'],
				'c'  => wp_strip_all_tags( $it['caption'] ),
				'l'  => $it['permalink'],
				'ch' => array_map( function ( $x ) { return array( 't' => $x['type'], 'p' => $x['poster'], 'v' => $x['video'] ); }, $it['children'] ),
			);
		}, $items );
		echo '<script>window.gasfIg=window.gasfIg||{};window.gasfIg[' . wp_json_encode( $uid ) . ']=' . wp_json_encode( $data ) . ';</script>';
		return ob_get_clean();
	}

	/* ============================ assets (once) ============================ */
	function gasf_ig_assets() {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		?>
<style>
.gig{position:relative;--gig-cols:4;--gig-gap:10px;--gig-radius:8px}
.gig-track{display:grid;grid-template-columns:repeat(var(--gig-cols),1fr);gap:var(--gig-gap)}
.gig--masonry .gig-track{display:block;column-count:var(--gig-cols);column-gap:var(--gig-gap)}
.gig--masonry .gig-tile{width:100%;margin:0 0 var(--gig-gap);break-inside:avoid}
.gig--carousel .gig-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:var(--gig-gap);scrollbar-width:none}
.gig--carousel .gig-track::-webkit-scrollbar{display:none}
.gig--carousel .gig-tile{flex:0 0 calc((100% - (var(--gig-cols) - 1)*var(--gig-gap))/var(--gig-cols));scroll-snap-align:start}
.gig-tile{position:relative;display:block;padding:0;border:0;margin:0;cursor:pointer;background:#eee;border-radius:var(--gig-radius);overflow:hidden;aspect-ratio:1/1}
.gig--masonry .gig-tile{aspect-ratio:auto}
.gig-tile img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}
.gig--masonry .gig-tile img{height:auto}
.gig-tile:hover img{transform:scale(1.06)}
.gig-badge{position:absolute;top:6px;right:8px;color:#fff;font-size:13px;text-shadow:0 1px 3px rgba(0,0,0,.6);pointer-events:none}
.gig-cap{position:absolute;left:0;right:0;bottom:0;padding:10px 9px 8px;color:#fff;font-size:12px;line-height:1.35;text-align:left;background:linear-gradient(rgba(38,38,38,.45),rgba(38,38,38,.82));text-shadow:0 1px 2px rgba(0,0,0,.55);opacity:0;transition:opacity .25s}
.gig-tile:hover .gig-cap{opacity:1}
.gig-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.92);box-shadow:0 1px 6px rgba(0,0,0,.25);font-size:22px;line-height:1;cursor:pointer}
.gig-prev{left:-6px}.gig-next{right:-6px}
.gig-lb{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.9);display:none;align-items:center;justify-content:center}
.gig-lb.open{display:flex}
.gig-lb__stage{max-width:min(92vw,900px);max-height:88vh;display:flex;flex-direction:column;gap:10px}
.gig-lb__media{max-width:100%;max-height:70vh;display:flex;align-items:center;justify-content:center}
.gig-lb__media img,.gig-lb__media video{max-width:100%;max-height:70vh;border-radius:6px;display:block}
.gig-lb__cap{color:#eee;font-size:14px;line-height:1.45;max-height:14vh;overflow:auto}
.gig-lb__cap a{color:#f6c026}
.gig-lb__x,.gig-lb__nav{position:absolute;top:50%;transform:translateY(-50%);background:none;border:0;color:#fff;font-size:46px;cursor:pointer;opacity:.8;padding:0 14px}
.gig-lb__x{top:18px;right:14px;transform:none;font-size:34px}
.gig-lb__prev{left:6px}.gig-lb__next{right:6px}
.gig-lb__dots{display:flex;gap:6px;justify-content:center}
.gig-lb__dots i{width:8px;height:8px;border-radius:50%;background:#666}
.gig-lb__dots i.on{background:#fff}
@media(max-width:600px){.gig{--gig-cols:3}.gig--carousel{--gig-cols:2}}
</style>
<script>
(function(){
if(window.gasfIgInit)return;window.gasfIgInit=1;
function el(t,c){var e=document.createElement(t);if(c)e.className=c;return e;}
var lb,stage,media,cap,dots,state={items:[],i:0,ci:0};
function build(){
  lb=el('div','gig-lb');
  lb.innerHTML='<button class="gig-lb__x" aria-label="Close">&times;</button><button class="gig-lb__nav gig-lb__prev" aria-label="Previous">&#8249;</button><button class="gig-lb__nav gig-lb__next" aria-label="Next">&#8250;</button>';
  stage=el('div','gig-lb__stage');media=el('div','gig-lb__media');cap=el('div','gig-lb__cap');dots=el('div','gig-lb__dots');
  stage.appendChild(media);stage.appendChild(dots);stage.appendChild(cap);lb.appendChild(stage);document.body.appendChild(lb);
  lb.querySelector('.gig-lb__x').onclick=close;
  lb.querySelector('.gig-lb__prev').onclick=function(e){e.stopPropagation();nav(-1);};
  lb.querySelector('.gig-lb__next').onclick=function(e){e.stopPropagation();nav(1);};
  lb.onclick=function(e){if(e.target===lb)close();};
  document.addEventListener('keydown',function(e){if(!lb.classList.contains('open'))return;if(e.key==='Escape')close();if(e.key==='ArrowLeft')nav(-1);if(e.key==='ArrowRight')nav(1);});
}
function frames(it){ if(it.t==='album'&&it.ch&&it.ch.length)return it.ch; return [{t:(it.t==='reel'||it.t==='video')?'video':'image',p:it.p,v:it.v}]; }
function render(){
  var it=state.items[state.i];var fr=frames(it);var f=fr[state.ci]||fr[0];
  media.innerHTML='';
  if(f.t==='video'&&f.v){var v=el('video');v.src=f.v;v.controls=true;v.autoplay=true;v.playsInline=true;v.poster=f.p||'';media.appendChild(v);}
  else{var img=el('img');img.src=f.p;media.appendChild(img);}
  dots.innerHTML='';if(fr.length>1){fr.forEach(function(_,k){var d=el('i',k===state.ci?'on':'');dots.appendChild(d);});}
  var txt=(it.c||'');if(txt.length>320)txt=txt.slice(0,320)+'…';
  cap.innerHTML=(txt?txt.replace(/</g,'&lt;')+'<br>':'')+(it.l?'<a href="'+it.l+'" target="_blank" rel="noopener">View on Instagram ↗</a>':'');
}
function nav(d){var it=state.items[state.i];var fr=frames(it);
  if(fr.length>1){var n=state.ci+d;if(n>=0&&n<fr.length){state.ci=n;return render();}}
  state.i=(state.i+d+state.items.length)%state.items.length;state.ci=0;render();
}
function open(items,i){state.items=items;state.i=i;state.ci=0;if(!lb)build();render();lb.classList.add('open');document.body.style.overflow='hidden';}
function close(){lb.classList.remove('open');media.innerHTML='';document.body.style.overflow='';}
document.addEventListener('click',function(e){
  var t=e.target.closest('.gig-tile');if(t){var box=t.closest('.gig');var items=window.gasfIg&&window.gasfIg[box.id];if(items){e.preventDefault();open(items,+t.getAttribute('data-idx'));}return;}
  var ar=e.target.closest('.gig-arrow');if(ar){var g=ar.closest('.gig');var tr=g.querySelector('.gig-track');tr.scrollBy({left:(ar.classList.contains('gig-next')?1:-1)*tr.clientWidth*0.8,behavior:'smooth'});}
});
})();
</script>
		<?php
	}

	/* ============================ admin tab ============================ */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'instagram', 'Instagram', 'gasf_ig_admin_page', 22 );
		}
	} );

	function gasf_ig_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_ig_action'] ) && check_admin_referer( 'gasf_ig_admin' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_ig_action'] ) );
			if ( $act === 'import' ) {
				$r = gasf_ig_import_from_sb();
				echo $r === true ? '<div class="notice notice-success is-dismissible"><p>Token imported from Smash Balloon and validated.</p></div>'
					: '<div class="notice notice-error"><p>' . esc_html( $r ) . '</p></div>';
			} elseif ( $act === 'refresh_token' ) {
				$r = gasf_ig_refresh_token( true );
				echo $r === true ? '<div class="notice notice-success is-dismissible"><p>Token refreshed.</p></div>'
					: '<div class="notice notice-warning"><p>' . esc_html( is_string( $r ) ? $r : 'done' ) . '</p></div>';
			} elseif ( $act === 'refresh_feed' ) {
				gasf_ig_get_media( true );
				echo '<div class="notice notice-success is-dismissible"><p>Feed cache refreshed.</p></div>';
			} elseif ( $act === 'save' ) {
				update_option( 'gasf_ig_settings', array(
					'count'    => max( 1, min( 90, (int) ( $_POST['count'] ?? 24 ) ) ),
					'ttl'      => max( 300, (int) ( $_POST['ttl'] ?? 3600 ) ),
					'layout'   => in_array( $_POST['layout'] ?? 'grid', array( 'grid', 'masonry', 'carousel' ), true ) ? $_POST['layout'] : 'grid',
					'columns'  => max( 1, min( 8, (int) ( $_POST['columns'] ?? 4 ) ) ),
					'captions' => ! empty( $_POST['captions'] ) ? 1 : 0,
					'gap'      => max( 0, (int) ( $_POST['gap'] ?? 10 ) ),
					'radius'   => max( 0, (int) ( $_POST['radius'] ?? 8 ) ),
				), false );
				echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
			}
		}

		$meta  = gasf_ig_meta();
		$s     = gasf_ig_settings();
		$cache = (array) get_option( 'gasf_ig_media_cache', array() );
		$has   = gasf_ig_token() !== '';
		$exp   = (int) ( $meta['expires'] ?? 0 );
		?>
		<h2>Native Instagram Feed</h2>
		<p>A theme-native replacement for Smash Balloon's display. Pulls <strong>@<?php echo esc_html( $meta['username'] ?? 'your account' ); ?></strong>'s own posts, carousels and reels; sideloads images locally and caches them. Runs alongside Smash Balloon — nothing breaks while you switch.</p>

		<h3 class="title">Connection</h3>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Token</td><td><?php echo $has ? '<span style="color:#1a7f37">● connected</span>' : '<span style="color:#b3122b">○ not connected</span>'; ?></td></tr>
			<tr><td>Account</td><td><?php echo esc_html( ( $meta['username'] ?? '—' ) . ( $meta['type'] ? ' (' . $meta['type'] . ')' : '' ) ); ?></td></tr>
			<tr><td>Token expires</td><td><?php echo $exp ? esc_html( wp_date( 'M j, Y', $exp ) ) . ' (auto-refreshed by cron)' : '—'; ?></td></tr>
			<tr><td>Cached posts</td><td><?php echo esc_html( count( $cache['items'] ?? array() ) ); ?><?php echo ! empty( $cache['ts'] ) ? ' · updated ' . esc_html( human_time_diff( (int) $cache['ts'] ) ) . ' ago' : ''; ?></td></tr>
		</table>
		<form method="post" style="margin-top:10px">
			<?php wp_nonce_field( 'gasf_ig_admin' ); ?>
			<?php if ( ! $has ) : ?>
				<button name="gasf_ig_action" value="import" class="button button-primary">Import token from Smash Balloon</button>
			<?php else : ?>
				<button name="gasf_ig_action" value="refresh_feed" class="button button-primary">Refresh feed now</button>
				<button name="gasf_ig_action" value="refresh_token" class="button">Refresh token</button>
				<button name="gasf_ig_action" value="import" class="button">Re-import token from SB</button>
			<?php endif; ?>
		</form>

		<h3 class="title">Display defaults</h3>
		<form method="post">
			<?php wp_nonce_field( 'gasf_ig_admin' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Layout</th><td>
					<select name="layout">
						<?php foreach ( array( 'grid' => 'Grid', 'masonry' => 'Masonry', 'carousel' => 'Carousel' ) as $k => $lbl ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['layout'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row">Posts to show</th><td><input type="number" name="count" min="1" max="90" value="<?php echo (int) $s['count']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Columns</th><td><input type="number" name="columns" min="1" max="8" value="<?php echo (int) $s['columns']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Gap (px)</th><td><input type="number" name="gap" min="0" max="40" value="<?php echo (int) $s['gap']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Corner radius (px)</th><td><input type="number" name="radius" min="0" max="40" value="<?php echo (int) $s['radius']; ?>" class="small-text"></td></tr>
				<tr><th scope="row">Hover captions</th><td><label><input type="checkbox" name="captions" value="1" <?php checked( $s['captions'], 1 ); ?>> Show caption on hover</label></td></tr>
				<tr><th scope="row">Cache TTL (sec)</th><td><input type="number" name="ttl" min="300" step="300" value="<?php echo (int) $s['ttl']; ?>" class="small-text"> <span class="description">how often to re-pull from Instagram</span></td></tr>
			</table>
			<p><button name="gasf_ig_action" value="save" class="button button-primary">Save defaults</button></p>
		</form>

		<h3 class="title">Use it</h3>
		<p>Drop this shortcode on any page/widget (attributes override the defaults above):</p>
		<p><code>[gasf_instagram layout="grid" count="12" columns="4"]</code></p>
		<p class="description">Then, when you're happy, remove the Smash Balloon block from that page. Smash Balloon can stay installed as a fallback until you're ready to retire it.</p>
		<?php
	}
}
