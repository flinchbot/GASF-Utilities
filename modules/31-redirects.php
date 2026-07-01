<?php
/**
 * Redirects & 404 monitor — modules/31-redirects.php
 *
 * The "automatic redirects, no more dead links" piece. WordPress core already
 * 301s a post/page when you change its slug; this adds what core doesn't:
 *   - a manager for arbitrary 301/302 redirects (deleted pages, old URLs, typos)
 *   - a 404 log so you can see what's breaking and fix it in one click
 *
 * Redirects live in option `gasf_redirects`; the 404 log in `gasf_404_log`
 * (capped at the 300 most-recent). Admin: GASF Utilities → Redirects.
 *
 * Gate: gasf_site_enable_redirects (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_redirects' ) : true ) {

	function gasf_redirects_get() { $r = get_option( 'gasf_redirects', array() ); return is_array( $r ) ? $r : array(); }
	function gasf_redirects_save( $r ) { update_option( 'gasf_redirects', array_values( $r ), false ); }
	function gasf_404_log_get() { $r = get_option( 'gasf_404_log', array() ); return is_array( $r ) ? $r : array(); }

	/** Normalize a path for matching: drop query/hash, ensure leading slash, drop trailing slash. */
	function gasf_redirects_norm( $path ) {
		$path = (string) strtok( (string) $path, '?#' );
		$path = '/' . ltrim( $path, '/' );
		if ( strlen( $path ) > 1 ) { $path = rtrim( $path, '/' ); }
		return $path;
	}

	/* ---- apply redirects (early) ---- */
	add_action( 'template_redirect', function () {
		$req = gasf_redirects_norm( $_SERVER['REQUEST_URI'] ?? '/' );
		if ( '/' === $req ) { return; }
		foreach ( gasf_redirects_get() as $i => $r ) {
			if ( empty( $r['from'] ) || gasf_redirects_norm( $r['from'] ) !== $req ) { continue; }
			$all = gasf_redirects_get();
			$all[ $i ]['hits'] = (int) ( $all[ $i ]['hits'] ?? 0 ) + 1;
			$all[ $i ]['last'] = time();
			gasf_redirects_save( $all );
			$to = (string) $r['to'];
			if ( '' !== $to && '/' === $to[0] ) { $to = home_url( $to ); }
			wp_safe_redirect( $to, (int) ( $r['code'] ?? 301 ) );
			exit;
		}
	}, 1 );

	/* ---- log 404s (later) ---- */
	add_action( 'template_redirect', function () {
		if ( ! is_404() || is_admin() ) { return; }
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#\.(css|js|png|jpe?g|gif|webp|svg|ico|map|woff2?|ttf|eot|pdf|txt|xml|json)(\?|$)#i', $uri ) ) { return; }
		$path = gasf_redirects_norm( $uri );
		if ( '/' === $path ) { return; }
		$log = gasf_404_log_get();
		if ( ! isset( $log[ $path ] ) ) { $log[ $path ] = array( 'hits' => 0, 'first' => time(), 'ref' => '' ); }
		$log[ $path ]['hits'] = (int) $log[ $path ]['hits'] + 1;
		$log[ $path ]['last'] = time();
		$ref = $_SERVER['HTTP_REFERER'] ?? '';
		if ( $ref ) { $log[ $path ]['ref'] = esc_url_raw( $ref ); }
		if ( count( $log ) > 300 ) {
			uasort( $log, function ( $a, $b ) { return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 ); } );
			$log = array_slice( $log, 0, 300, true );
		}
		update_option( 'gasf_404_log', $log, false );
	}, 20 );

	/* ---- admin ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) {
			gasf_utilities_add_tab( 'redirects', 'Redirects', 'gasf_redirects_admin', 41 );
		}
	} );

	function gasf_redirects_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_redir_action'] ) && check_admin_referer( 'gasf_redir' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_redir_action'] ) );
			if ( 'add' === $act ) {
				$from = gasf_redirects_norm( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
				$to   = trim( sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ) );
				$code = in_array( (int) ( $_POST['code'] ?? 301 ), array( 301, 302, 307, 410 ), true ) ? (int) $_POST['code'] : 301;
				if ( strlen( $from ) > 1 && ( '' !== $to || 410 === $code ) ) {
					$all = gasf_redirects_get();
					$all = array_values( array_filter( $all, function ( $r ) use ( $from ) { return gasf_redirects_norm( $r['from'] ?? '' ) !== $from; } ) );
					$all[] = array( 'from' => $from, 'to' => $to, 'code' => $code, 'hits' => 0, 'created' => time() );
					gasf_redirects_save( $all );
					// If this resolves a logged 404, drop it from the log.
					$log = gasf_404_log_get(); unset( $log[ $from ] ); update_option( 'gasf_404_log', $log, false );
					echo '<div class="notice notice-success is-dismissible"><p>Redirect saved: <code>' . esc_html( $from ) . '</code> → <code>' . esc_html( $to ?: '(410 Gone)' ) . '</code></p></div>';
				} else {
					echo '<div class="notice notice-error"><p>Need a source path and a destination.</p></div>';
				}
			} elseif ( 'delete' === $act ) {
				$from = gasf_redirects_norm( sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ) );
				gasf_redirects_save( array_filter( gasf_redirects_get(), function ( $r ) use ( $from ) { return gasf_redirects_norm( $r['from'] ?? '' ) !== $from; } ) );
				echo '<div class="notice notice-success is-dismissible"><p>Redirect removed.</p></div>';
			} elseif ( 'clearlog' === $act ) {
				update_option( 'gasf_404_log', array(), false );
				echo '<div class="notice notice-success is-dismissible"><p>404 log cleared.</p></div>';
			} elseif ( 'dismiss' === $act ) {
				$p = gasf_redirects_norm( sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) ) );
				$log = gasf_404_log_get(); unset( $log[ $p ] ); update_option( 'gasf_404_log', $log, false );
			}
		}

		$redirects = gasf_redirects_get();
		$log       = gasf_404_log_get();
		uasort( $log, function ( $a, $b ) { return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 ); } );
		?>
		<h2>Redirects</h2>
		<?php
		if ( function_exists( 'gasf_utilities_doc_panel' ) ) {
			gasf_utilities_doc_panel( array(
				'what'   => 'Sends old or broken incoming URLs to the right place, and logs every 404 (page-not-found) visitors and search engines hit so you can spot and fix broken links. WordPress already auto-redirects when you rename a page\'s slug — this tab covers everything else: deleted pages, links from old flyers/emails, typos, and retired plugins\' URLs.',
				'needs'  => array(
					'Nothing external — it runs on every page request automatically.',
				),
				'fields' => array(
					'From (path on this site)' => 'The incoming URL path to catch, starting with <code>/</code> — e.g. <code>/biergarten</code>. Exact-match on the path (query strings ignored).',
					'To (path or full URL)'    => 'Where to send the visitor: another path on this site (<code>/the-biergarten/</code>) or a full external URL. Leave the destination out only for 410s.',
					'Type'                     => '<strong>301 Permanent</strong> — the page moved for good; passes SEO value, browsers cache it (the usual choice). <strong>302 Temporary</strong> — short-term detour you\'ll undo. <strong>410 Gone</strong> — the page is intentionally dead with no replacement; tells Google to drop it from the index faster than a 404.',
					'Hits'                     => 'How many times each redirect has fired — proof of which old URLs are still circulating out there.',
					'404 log'                  => 'Every broken URL hit recently, with count, last-seen, and referrer (where the bad link lives). Click <strong>→ Redirect</strong> on a row to pre-fill the form and fix it in one step; ✕ dismisses noise (bot scans for wp-login variants etc. are normal and ignorable).',
				),
				'notes'  => 'This is for <em>incoming</em> broken URLs. For creating branded outbound links (<code>/join</code>, <code>/75th</code>) use the <strong>Short Links</strong> tab.',
			) );
		}
		?>

		<h3 class="title">Add a redirect</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_redir' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gr_from">From (path on this site)</label></th><td><input type="text" id="gr_from" name="from" class="regular-text code" placeholder="/old-page"></td></tr>
				<tr><th scope="row"><label for="gr_to">To (path or full URL)</label></th><td><input type="text" id="gr_to" name="to" class="large-text code" placeholder="/new-page or https://…"></td></tr>
				<tr><th scope="row">Type</th><td><select name="code"><option value="301">301 Permanent</option><option value="302">302 Temporary</option><option value="410">410 Gone (dead, no target)</option></select></td></tr>
			</table>
			<p><button name="gasf_redir_action" value="add" class="button button-primary">Add redirect</button></p>
		</form>

		<h3 class="title">Active redirects (<?php echo count( $redirects ); ?>)</h3>
		<table class="widefat striped">
			<thead><tr><th>From</th><th>To</th><th>Type</th><th>Hits</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $redirects ) : ?>
				<tr><td colspan="5">No redirects yet.</td></tr>
			<?php else : foreach ( $redirects as $r ) : ?>
				<tr>
					<td><code><?php echo esc_html( $r['from'] ); ?></code></td>
					<td><code><?php echo esc_html( $r['to'] ?: '(410 Gone)' ); ?></code></td>
					<td><?php echo (int) $r['code']; ?></td>
					<td><?php echo (int) ( $r['hits'] ?? 0 ); ?></td>
					<td><form method="post" style="margin:0"><?php wp_nonce_field( 'gasf_redir' ); ?><input type="hidden" name="from" value="<?php echo esc_attr( $r['from'] ); ?>"><button name="gasf_redir_action" value="delete" class="button-link-delete">Delete</button></form></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<h3 class="title" style="margin-top:24px">404 log (<?php echo count( $log ); ?>)</h3>
		<?php if ( $log ) : ?>
		<p>Broken URLs visitors/bots have hit. Click <strong>→ Redirect</strong> to fix one (it pre-fills the form above).</p>
		<table class="widefat striped">
			<thead><tr><th>Requested URL</th><th>Hits</th><th>Last seen</th><th>Referrer</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $log, 0, 100, true ) as $path => $d ) : ?>
				<tr>
					<td><code><?php echo esc_html( $path ); ?></code></td>
					<td><?php echo (int) $d['hits']; ?></td>
					<td><?php echo esc_html( human_time_diff( (int) ( $d['last'] ?? 0 ) ) ); ?> ago</td>
					<td><small><?php echo $d['ref'] ? esc_html( $d['ref'] ) : '<span style="color:#999">—</span>'; ?></small></td>
					<td style="white-space:nowrap">
						<button type="button" class="button button-small gasf-fix" data-path="<?php echo esc_attr( $path ); ?>">&rarr; Redirect</button>
						<form method="post" style="display:inline;margin:0"><?php wp_nonce_field( 'gasf_redir' ); ?><input type="hidden" name="path" value="<?php echo esc_attr( $path ); ?>"><button name="gasf_redir_action" value="dismiss" class="button-link" title="Remove from log">✕</button></form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" style="margin-top:8px"><?php wp_nonce_field( 'gasf_redir' ); ?><button name="gasf_redir_action" value="clearlog" class="button">Clear 404 log</button></form>
		<script>
		jQuery(function($){ $('.gasf-fix').on('click', function(){ $('#gr_from').val($(this).data('path')); $('#gr_to').focus(); $('html,body').animate({scrollTop:0},250); }); });
		</script>
		<?php else : ?>
		<p><em>No 404s logged yet. 🎉</em></p>
		<?php endif; ?>
		<?php
	}
}
