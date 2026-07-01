<?php
/**
 * AI Event SEO — modules/34-ai-seo.php
 *
 * The in-house replacement for the old external "Yoast SEO access for Claude"
 * flow. Uses the Anthropic API (Claude) to write a meta description for every
 * event that lacks one, grouped by event name so all occurrences of e.g.
 * "Biergarten" share one description. Writes _gasf_seo_desc ONLY when it's
 * empty — never overwrites a hand-written description. The SEO module (30)
 * then serves it.
 *
 * Runs on demand (admin button) and a small daily cron backfill. The API key
 * lives server-side (autoload off) and is never emitted to the front end.
 *
 * Gate: gasf_site_enable_aiseo (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_aiseo' ) : true ) {

	function gasf_aiseo_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_aiseo_config', array() ), array(
			'key'   => '',
			'model' => 'claude-haiku-4-5-20251001',
			'batch' => 12,
		) );
	}

	/** Call the Anthropic Messages API; return generated text or WP_Error. */
	function gasf_aiseo_call( $key, $model, $prompt ) {
		if ( ! $key ) { return new WP_Error( 'nokey', 'No Anthropic API key set.' ); }
		$r = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 200,
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );
		if ( is_wp_error( $r ) ) { return $r; }
		$code = (int) wp_remote_retrieve_response_code( $r );
		$b    = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'api', 'Anthropic HTTP ' . $code . ': ' . ( $b['error']['message'] ?? substr( wp_remote_retrieve_body( $r ), 0, 140 ) ) );
		}
		return trim( (string) ( $b['content'][0]['text'] ?? '' ) );
	}

	function gasf_aiseo_prompt( $title, $content ) {
		$content = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );
		if ( mb_strlen( $content ) > 800 ) { $content = mb_substr( $content, 0, 800 ); }
		return "Write a single compelling SEO meta description (max 155 characters, plain text, no quotation marks, no line breaks) for this event page at the German-American Society Friendship of Pinellas County — a German-American cultural club in Pinellas Park, Tampa Bay, Florida.\n\nEvent title: {$title}\nDetails: {$content}\n\nRespond with ONLY the meta description, nothing else.";
	}

	/** Distinct published-event titles with no description on any occurrence. */
	function gasf_aiseo_pending() {
		global $wpdb;
		return $wpdb->get_col(
			"SELECT DISTINCT p.post_title
			   FROM {$wpdb->posts} p
			  WHERE p.post_type='gasf_event' AND p.post_status='publish' AND p.post_title<>''
			    AND NOT EXISTS (
			        SELECT 1 FROM {$wpdb->postmeta} pm
			         WHERE pm.post_id=p.ID AND pm.meta_key='_gasf_seo_desc' AND pm.meta_value<>''
			    )
			  ORDER BY p.post_title ASC"
		);
	}

	/** Generate for up to $limit distinct titles; apply each to all its occurrences that lack a desc. */
	function gasf_aiseo_run( $limit ) {
		$cfg = gasf_aiseo_cfg();
		if ( ! $cfg['key'] ) { return array( 'done' => 0, 'errors' => array( 'No API key set.' ) ); }
		global $wpdb;
		$titles = array_slice( gasf_aiseo_pending(), 0, max( 1, (int) $limit ) );
		$done = 0; $errors = array();
		$budget = microtime( true ) + 45; // stay under request limits
		foreach ( $titles as $title ) {
			if ( microtime( true ) > $budget ) { break; }
			$pid     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='gasf_event' AND post_status='publish' AND post_title=%s ORDER BY ID DESC LIMIT 1", $title ) );
			$content = $pid ? get_post( $pid )->post_content : '';
			$desc    = gasf_aiseo_call( $cfg['key'], $cfg['model'], gasf_aiseo_prompt( $title, $content ) );
			if ( is_wp_error( $desc ) ) { $errors[] = $title . ': ' . $desc->get_error_message(); continue; }
			$desc = trim( $desc, " \"'\n\r\t" );
			if ( '' === $desc ) { $errors[] = $title . ': empty response'; continue; }
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='gasf_event' AND post_status='publish' AND post_title=%s", $title ) ) as $id ) {
				if ( '' === (string) get_post_meta( $id, '_gasf_seo_desc', true ) ) { update_post_meta( $id, '_gasf_seo_desc', $desc ); }
			}
			$done++;
		}
		update_option( 'gasf_aiseo_last', array( 'ts' => time(), 'done' => $done, 'errors' => $errors, 'remaining' => count( gasf_aiseo_pending() ) ), false );
		return array( 'done' => $done, 'errors' => $errors );
	}

	/* small daily backfill so new event names get covered automatically */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_aiseo_cron' ) ) { wp_schedule_event( time() + 900, 'daily', 'gasf_aiseo_cron' ); }
	} );
	add_action( 'gasf_aiseo_cron', function () {
		$c = gasf_aiseo_cfg();
		if ( $c['key'] ) { gasf_aiseo_run( 8 ); }
	} );

	/* ---- admin ---- */
	add_action( 'admin_menu', function () {
		if ( function_exists( 'gasf_utilities_add_tab' ) ) { gasf_utilities_add_tab( 'aiseo', 'Event SEO (AI)', 'gasf_aiseo_admin', 28 ); }
	} );

	function gasf_aiseo_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		if ( isset( $_POST['gasf_aiseo_action'] ) && check_admin_referer( 'gasf_aiseo' ) ) {
			$act = sanitize_text_field( wp_unslash( $_POST['gasf_aiseo_action'] ) );
			if ( 'save' === $act ) {
				$c = gasf_aiseo_cfg();
				$newkey = trim( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
				if ( '' !== $newkey ) { $c['key'] = $newkey; }
				$c['model'] = sanitize_text_field( wp_unslash( $_POST['model'] ?? $c['model'] ) ) ?: 'claude-haiku-4-5-20251001';
				$c['batch'] = max( 1, min( 50, (int) ( $_POST['batch'] ?? 12 ) ) );
				update_option( 'gasf_aiseo_config', $c, false );
				echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
			} elseif ( 'test' === $act ) {
				$c = gasf_aiseo_cfg();
				$d = gasf_aiseo_call( $c['key'], $c['model'], gasf_aiseo_prompt( 'Euchre Night', 'Every third Thursday of the month. Cards, cold beer, and Gemütlichkeit. Free to play, all welcome.' ) );
				echo '<div class="notice notice-' . ( is_wp_error( $d ) ? 'error' : 'success' ) . '"><p>' . ( is_wp_error( $d ) ? esc_html( $d->get_error_message() ) : 'Sample: ' . esc_html( $d ) ) . '</p></div>';
			} elseif ( 'run' === $act ) {
				$res = gasf_aiseo_run( gasf_aiseo_cfg()['batch'] );
				echo '<div class="notice notice-' . ( empty( $res['errors'] ) ? 'success' : 'warning' ) . ' is-dismissible"><p>Generated ' . (int) $res['done'] . ' descriptions.' . ( ! empty( $res['errors'] ) ? ' Errors: ' . esc_html( implode( ' | ', array_slice( $res['errors'], 0, 3 ) ) ) : '' ) . '</p></div>';
			}
		}

		$c       = gasf_aiseo_cfg();
		$pending = gasf_aiseo_pending();
		global $wpdb;
		$withdesc = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.post_title) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_gasf_seo_desc' AND pm.meta_value<>'' WHERE p.post_type='gasf_event' AND p.post_status='publish'" );
		$last    = (array) get_option( 'gasf_aiseo_last', array() );
		?>
		<h2>Event SEO (AI)</h2>
		<p>Uses Claude to write a meta description for every event that lacks one, grouped by event name (all "Biergarten" occurrences share one). It only fills blanks — never overwrites descriptions you've written by hand. The SEO module serves them; the Event JSON-LD schema comes from the events plugin.</p>
		<table class="widefat striped" style="max-width:640px">
			<tr><td>Event names with a description</td><td><?php echo (int) $withdesc; ?></td></tr>
			<tr><td>Event names still needing one</td><td><strong><?php echo count( $pending ); ?></strong></td></tr>
			<?php if ( ! empty( $last['ts'] ) ) : ?><tr><td>Last run</td><td><?php echo esc_html( human_time_diff( (int) $last['ts'] ) ); ?> ago — generated <?php echo (int) ( $last['done'] ?? 0 ); ?><?php echo ! empty( $last['errors'] ) ? ' (' . count( $last['errors'] ) . ' errors)' : ''; ?></td></tr><?php endif; ?>
		</table>

		<h3 class="title">Settings</h3>
		<form method="post"><?php wp_nonce_field( 'gasf_aiseo' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Anthropic API key</th><td><input type="text" name="key" value="" class="regular-text code" placeholder="<?php echo $c['key'] ? 'saved — leave blank to keep' : 'sk-ant-…'; ?>"><p class="description">Stored server-side; never shown on the site.</p></td></tr>
				<tr><th scope="row">Model</th><td><input type="text" name="model" value="<?php echo esc_attr( $c['model'] ); ?>" class="regular-text code"><p class="description">Default <code>claude-haiku-4-5-20251001</code> — fast &amp; cheap for meta descriptions.</p></td></tr>
				<tr><th scope="row">Batch size</th><td><input type="number" name="batch" value="<?php echo (int) $c['batch']; ?>" min="1" max="50" class="small-text"> event names per click <span class="description">(keep ≤ ~15 to avoid request timeouts)</span></td></tr>
			</table>
			<p>
				<button name="gasf_aiseo_action" value="save" class="button button-primary">Save</button>
				<button name="gasf_aiseo_action" value="test" class="button">Test (sample, no save)</button>
				<button name="gasf_aiseo_action" value="run" class="button button-primary">Generate next <?php echo (int) $c['batch']; ?></button>
			</p>
		</form>
		<?php if ( $pending ) : ?>
		<p class="description">Click <strong>Generate</strong> repeatedly to work through the list — ~<?php echo count( $pending ); ?> names left (~<?php echo (int) ceil( count( $pending ) / max( 1, $c['batch'] ) ); ?> clicks). A daily cron also backfills a few automatically.</p>
		<?php else : ?>
		<p><em>All event names have descriptions. 🎉</em></p>
		<?php endif; ?>
		<?php
	}
}
