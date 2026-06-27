<?php
/**
 * Module 26 — Calendar Sync (multi-ICS → one Google Calendar)
 *
 * Aggregates multiple ICS feeds into ONE destination Google Calendar via the
 * Google Calendar API v3. Authenticated with a Google service account using
 * pure-PHP RS256 JWT (no Composer / no google/apiclient). Each managed event
 * is stamped with extendedProperties.private (gasf_mgr, gasf_src, gasf_uid)
 * for an idempotent create/update/delete diff without local DB storage.
 *
 * Sources (label, ICS URL, colorId 1-11) and settings (destination Calendar ID,
 * sync interval) are managed from a "Calendar Sync" tab in the GASF Utilities
 * tabbed admin. The secret service-account JSON key lives OUTSIDE the web root
 * at /home4/germanta/gasf-calsync-key.json and is never logged or committed.
 *
 * Secret key path: defaults to dirname(ABSPATH)/gasf-calsync-key.json
 * Override: define('GASF_CALSYNC_KEY', '/abs/path.json') in wp-config.php
 *
 * WP-cron note (shared host): WP-cron fires on page traffic. If the site is
 * low-traffic, add a real cPanel cron job:
 *   wp --path=/home4/germanta/public_html cron event run --due-now
 * (wp-cli is available on this host.)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ==========================================================================
 * SECTION 1 — ADMIN TAB: Source CRUD + Settings + Last-run surface
 * ========================================================================== */

add_action( 'admin_menu', function () {
	if ( function_exists( 'gasf_utilities_add_tab' ) ) {
		gasf_utilities_add_tab( 'calsync', 'Calendar Sync', 'gasf_calsync_admin_page', 25 );
	}
} );

/**
 * Retrieve the list of calendar sources.
 *
 * @return array  Array of source arrays: { id, label, url, color }.
 */
function gasf_calsync_get_sources() {
	$v = get_option( 'gasf_calsync_sources', array() );
	return is_array( $v ) ? $v : array();
}

/**
 * Retrieve module settings with defaults.
 *
 * @return array { calendar_id: string, interval: 'hourly'|'twicedaily'|'daily' }
 */
function gasf_calsync_get_settings() {
	$defaults = array( 'calendar_id' => '', 'interval' => 'hourly', 'window_days' => 365 );
	$saved    = get_option( 'gasf_calsync_settings', array() );
	return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
}

/**
 * Main admin page callback — Source CRUD, settings, last-run panel.
 */
function gasf_calsync_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$notice = '';

	/* ---- ADD SOURCE ---- */
	if ( isset( $_POST['gasf_calsync_add'] ) && check_admin_referer( 'gasf_calsync_action' ) ) {
		$label = sanitize_text_field( wp_unslash( $_POST['gasf_calsync_label'] ?? '' ) );
		$url   = esc_url_raw( wp_unslash( $_POST['gasf_calsync_url'] ?? '' ) );
		$color = max( 1, min( 11, (int) ( $_POST['gasf_calsync_color'] ?? 1 ) ) );
		if ( $label && $url ) {
			$sources = gasf_calsync_get_sources();
			$edit_id = sanitize_text_field( wp_unslash( $_POST['gasf_calsync_edit_id'] ?? '' ) );
			$updated = false;
			if ( $edit_id !== '' ) {
				foreach ( $sources as &$row ) {
					if ( isset( $row['id'] ) && $row['id'] === $edit_id ) {
						$row['label'] = $label; $row['url'] = $url; $row['color'] = $color;
						$updated = true; break;
					}
				}
				unset( $row );
			}
			if ( ! $updated ) {
				$sources[] = array( 'id' => uniqid( 'src_', true ), 'label' => $label, 'url' => $url, 'color' => $color );
			}
			update_option( 'gasf_calsync_sources', $sources, false );
			$notice = '<div class="notice notice-success is-dismissible"><p>Source ' . ( $updated ? 'updated' : 'added' ) . '.</p></div>';
		} else {
			$notice = '<div class="notice notice-error"><p>Label and URL are required.</p></div>';
		}
	}

	/* ---- DELETE SOURCE ---- */
	if ( isset( $_POST['gasf_calsync_delete'] ) && check_admin_referer( 'gasf_calsync_action' ) ) {
		$del_id  = sanitize_text_field( wp_unslash( $_POST['gasf_calsync_delete'] ) );
		$sources = array_values( array_filter( gasf_calsync_get_sources(), function ( $s ) use ( $del_id ) {
			return isset( $s['id'] ) && $s['id'] !== $del_id;
		} ) );
		update_option( 'gasf_calsync_sources', $sources, false );
		$notice = '<div class="notice notice-success is-dismissible"><p>Source removed.</p></div>';
	}

	/* ---- SAVE SETTINGS ---- */
	if ( isset( $_POST['gasf_calsync_settings'] ) && check_admin_referer( 'gasf_calsync_action' ) ) {
		$cal_id        = sanitize_text_field( wp_unslash( $_POST['gasf_calsync_calendar_id'] ?? '' ) );
		$allowed_ivs   = array( 'hourly', 'twicedaily', 'daily' );
		$new_interval  = in_array( $_POST['gasf_calsync_interval'] ?? '', $allowed_ivs, true )
			? $_POST['gasf_calsync_interval']
			: 'hourly';
		$old_settings  = gasf_calsync_get_settings();
		$win_days      = max( 0, (int) ( $_POST['gasf_calsync_window_days'] ?? 365 ) );
		$new_settings  = array( 'calendar_id' => $cal_id, 'interval' => $new_interval, 'window_days' => $win_days );
		update_option( 'gasf_calsync_settings', $new_settings, false );
		/* reschedule cron if interval changed */
		if ( $old_settings['interval'] !== $new_interval ) {
			wp_clear_scheduled_hook( 'gasf_calsync_cron' );
			if ( function_exists( 'wp_schedule_event' ) ) {
				wp_schedule_event( time() + 60, $new_interval, 'gasf_calsync_cron' );
			}
		}
		$notice = '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}

	/* ---- MANUAL SYNC ---- */
	if ( isset( $_POST['gasf_calsync_run'] ) && check_admin_referer( 'gasf_calsync_action' ) ) {
		if ( function_exists( 'gasf_calsync_run_all' ) ) {
			gasf_calsync_run_all();
			$notice = '<div class="notice notice-success is-dismissible"><p>Sync triggered. Check the Last Run panel below.</p></div>';
		} else {
			$notice = '<div class="notice notice-warning"><p>Sync engine not yet loaded. Try again.</p></div>';
		}
	}

	/* ---- RENDER ---- */
	$sources  = gasf_calsync_get_sources();
	$settings = gasf_calsync_get_settings();
	$last_run = get_option( 'gasf_calsync_last_run', null );

	/* Google colorId palette labels (1..11) */
	$color_names = array(
		1  => 'Lavender',
		2  => 'Sage',
		3  => 'Grape',
		4  => 'Flamingo',
		5  => 'Banana',
		6  => 'Tangerine',
		7  => 'Peacock',
		8  => 'Graphite',
		9  => 'Blueberry',
		10 => 'Basil',
		11 => 'Tomato',
	);

	/* Google event color swatches (approximate hex) */
	$color_hex = array(
		1 => '#7986cb', 2 => '#33b679', 3 => '#8e24aa', 4 => '#e67c73',
		5 => '#f6c026', 6 => '#f5511d', 7 => '#039be5', 8 => '#616161',
		9 => '#3f51b5', 10 => '#0b8043', 11 => '#d60000',
	);

	echo $notice; // pre-escaped above via fixed strings or esc_html in data

	?>
	<h2>Calendar Sync</h2>
	<p>Aggregates multiple ICS feeds into ONE destination Google Calendar using a Google service account. Each source gets a <code>[LABEL]</code> title prefix and its own event color.</p>

	<form method="post">
		<?php wp_nonce_field( 'gasf_calsync_action' ); ?>

		<!-- ===== SOURCES TABLE ===== -->
		<h3>Calendar Sources</h3>
		<?php if ( $sources ) : ?>
		<table class="widefat striped" style="max-width:760px">
			<thead>
				<tr>
					<th>Label / Tag</th>
					<th>ICS URL</th>
					<th>Color</th>
					<th>Action</th>
				</tr>
			<tr>
				<th><label for="gasf_calsync_window_days">History Window (days)</label></th>
				<td>
					<input type="number" name="gasf_calsync_window_days" id="gasf_calsync_window_days" class="small-text" min="0" step="1" value="<?php echo esc_attr( (int) ( $settings['window_days'] ?? 365 ) ); ?>" />
					<p class="description">Only sync events whose end is within this many days in the past (plus all future). <strong>0 = no limit</strong> (keep all past events). Recurring events are always kept.</p>
				</td>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $sources as $src ) :
				$cid  = isset( $src['color'] ) ? (int) $src['color'] : 1;
				$chex = $color_hex[ $cid ] ?? '#7986cb';
				$cname = $color_names[ $cid ] ?? 'Unknown';
			?>
				<tr>
					<td><strong><?php echo esc_html( $src['label'] ?? '' ); ?></strong></td>
					<td style="word-break:break-all"><code><?php echo esc_html( $src['url'] ?? '' ); ?></code></td>
					<td>
						<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?php echo esc_attr( $chex ); ?>;vertical-align:middle;margin-right:4px"></span>
						<?php echo esc_html( $cid . ' — ' . $cname ); ?>
					</td>
					<td>
						<button type="button" class="button button-small gasf-calsync-edit"
							data-id="<?php echo esc_attr( $src['id'] ?? '' ); ?>"
							data-label="<?php echo esc_attr( $src['label'] ?? '' ); ?>"
							data-url="<?php echo esc_attr( $src['url'] ?? '' ); ?>"
							data-color="<?php echo esc_attr( isset( $src['color'] ) ? (int) $src['color'] : 1 ); ?>"
							style="margin-right:4px">Edit</button>
						<button type="submit" name="gasf_calsync_delete" value="<?php echo esc_attr( $src['id'] ?? '' ); ?>"
							class="button button-small button-link-delete"
							onclick="return confirm('Delete this source? Its events will NOT be removed from Google Calendar unless you sync again with this source absent.')">
							Delete
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
			<p style="color:#666"><em>No sources yet. Add one below.</em></p>
		<?php endif; ?>

		<!-- ===== ADD SOURCE ===== -->
		<h3>Add a Source</h3>
		<table class="form-table" style="max-width:760px">
			<tr>
				<th><label for="gasf_calsync_label">Label / Tag</label></th>
				<td>
					<input type="text" name="gasf_calsync_label" id="gasf_calsync_label" class="regular-text" placeholder="e.g. GASF" />
					<p class="description">Short identifier. Events get a <code>[LABEL] </code> title prefix in Google Calendar.</p>
				</td>
			</tr>
			<tr>
				<th><label for="gasf_calsync_url">ICS URL</label></th>
				<td>
					<input type="url" name="gasf_calsync_url" id="gasf_calsync_url" class="large-text" placeholder="https://..." />
					<p class="description">
						Public ICS feed URL. For this site's MEC events, use:<br>
						<code>https://germantampabay.com/?mec-ical-feed=1</code>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="gasf_calsync_color">Event Color</label></th>
				<td>
					<select name="gasf_calsync_color" id="gasf_calsync_color">
						<?php foreach ( $color_names as $cid => $cname ) : ?>
						<option value="<?php echo esc_attr( $cid ); ?>">
							<?php echo esc_html( $cid . ' — ' . $cname ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description">Google Calendar event color for this source (colorId 1–11).</p>
				</td>
			</tr>
		</table>
		<input type="hidden" name="gasf_calsync_edit_id" id="gasf_calsync_edit_id" value="" />
		<p><button type="submit" id="gasf_calsync_add_btn" name="gasf_calsync_add" value="1" class="button button-primary">Add Source</button>
		<button type="button" id="gasf_calsync_cancel_edit" class="button" style="display:none;margin-left:6px">Cancel edit</button></p>

		<hr>

		<!-- ===== SETTINGS ===== -->
		<h3>Settings</h3>
		<table class="form-table" style="max-width:760px">
			<tr>
				<th><label for="gasf_calsync_calendar_id">Destination Calendar ID</label></th>
				<td>
					<input type="text" name="gasf_calsync_calendar_id" id="gasf_calsync_calendar_id"
						class="large-text"
						value="<?php echo esc_attr( $settings['calendar_id'] ); ?>"
						placeholder="...@group.calendar.google.com" />
					<p class="description">
						From Google Calendar → calendar Settings → "Integrate calendar" → Calendar ID.<br>
						<strong>Important:</strong> Share this calendar with the service-account email (<em>Make changes to events</em>).
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="gasf_calsync_interval">Sync Interval</label></th>
				<td>
					<select name="gasf_calsync_interval" id="gasf_calsync_interval">
						<?php foreach ( array( 'hourly' => 'Hourly', 'twicedaily' => 'Twice daily', 'daily' => 'Daily' ) as $val => $lbl ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['interval'], $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description">WP-cron interval. On low-traffic sites, add a real cPanel cron: <code>wp --path=/home4/germanta/public_html cron event run --due-now</code></p>
				</td>
			</tr>
		</table>
		<p><button type="submit" name="gasf_calsync_settings" value="1" class="button">Save Settings</button></p>

		<hr>

		<!-- ===== MANUAL SYNC ===== -->
		<h3>Manual Sync</h3>
		<p>
			<button type="submit" name="gasf_calsync_run" value="1" class="button button-primary">Sync Now</button>
			<span style="color:#666;margin-left:10px">Runs all sources immediately (same as the WP-cron job).</span>
		</p>
	</form>
<script>
jQuery(function($){
  $('.gasf-calsync-edit').on('click', function(){
    var b=$(this);
    $('#gasf_calsync_edit_id').val(b.attr('data-id'));
    $('#gasf_calsync_label').val(b.attr('data-label'));
    $('#gasf_calsync_url').val(b.attr('data-url'));
    $('#gasf_calsync_color').val(b.attr('data-color'));
    $('#gasf_calsync_add_btn').text('Save Changes');
    $('#gasf_calsync_cancel_edit').show();
    $('html,body').animate({scrollTop: $('#gasf_calsync_label').closest('table').offset().top - 60}, 300);
  });
  $('#gasf_calsync_cancel_edit').on('click', function(){
    $('#gasf_calsync_edit_id').val('');
    $('#gasf_calsync_label').val('');
    $('#gasf_calsync_url').val('');
    $('#gasf_calsync_color').val('1');
    $('#gasf_calsync_add_btn').text('Add Source');
    $(this).hide();
  });
});
</script>

	<hr>

	<!-- ===== LAST RUN PANEL ===== -->
	<h3>Last Run</h3>
	<?php if ( ! $last_run ) : ?>
		<p style="color:#666"><em>Never run. Click "Sync Now" above to run the first sync.</em></p>
	<?php else :
		$ts = isset( $last_run['ts'] ) ? (int) $last_run['ts'] : 0;
		?>
		<p><strong>Ran:</strong> <?php echo $ts ? esc_html( wp_date( 'Y-m-d H:i:s T', $ts ) ) : '<em>unknown</em>'; ?></p>
		<?php if ( ! empty( $last_run['per_source'] ) ) : ?>
		<table class="widefat striped" style="max-width:760px">
			<thead>
				<tr>
					<th>Source</th>
					<th>Created</th>
					<th>Updated</th>
					<th>Deleted</th>
					<th>Skipped</th>
					<th>Errors</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $last_run['per_source'] as $ps ) : ?>
				<tr>
					<td><?php echo esc_html( $ps['label'] ?? '?' ); ?></td>
					<td><?php echo esc_html( (int) ( $ps['created'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (int) ( $ps['updated'] ?? 0 ) ); ?></td>
					<td><?php echo esc_html( (int) ( $ps['deleted'] ?? 0 ) ); ?></td>
					<td><?php echo ! empty( $ps['skipped'] ) ? '<span style="color:#b3122b">YES</span>' : 'no'; ?></td>
					<td>
						<?php
						$errs = $ps['errors'] ?? array();
						echo $errs ? '<span style="color:#b3122b">' . esc_html( implode( '; ', (array) $errs ) ) . '</span>' : '—';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<?php if ( ! empty( $last_run['errors'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( implode( ' | ', (array) $last_run['errors'] ) ); ?></p></div>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}


/* ==========================================================================
 * SECTION 2 — GOOGLE SERVICE-ACCOUNT AUTH (pure-PHP JWT) + ICS PARSER
 * ========================================================================== */

/**
 * Load and validate the service-account JSON key.
 * Key lives OUTSIDE the web root. NEVER log or echo private_key.
 *
 * @return array|null  Assoc array from JSON, or null if unreadable/invalid.
 */
function gasf_calsync_load_key() {
	$path = defined( 'GASF_CALSYNC_KEY' )
		? GASF_CALSYNC_KEY
		: dirname( untrailingslashit( ABSPATH ) ) . '/gasf-calsync-key.json';
	if ( ! @is_readable( $path ) ) {
		return null;
	}
	$data = json_decode( (string) @file_get_contents( $path ), true );
	if ( isset( $data['client_email'], $data['private_key'] ) ) {
		return $data;
	}
	return null;
}

/**
 * Obtain (or return cached) a Google API access token.
 * Signs a JWT with RS256 using the service-account private key (openssl).
 * Caches the token in a WP transient for up to (expires_in - 60) seconds.
 *
 * @return string|WP_Error  Bearer token string, or WP_Error on failure.
 */
function gasf_calsync_access_token() {
	if ( function_exists( 'get_transient' ) ) {
		$cached = get_transient( 'gasf_calsync_token' );
		if ( $cached ) {
			return $cached;
		}
	}

	$key = gasf_calsync_load_key();
	if ( ! $key ) {
		return new WP_Error( 'no_key', 'Service-account key not found or unreadable at expected path.' );
	}

	$now = time();

	/* JWT header + claims */
	$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
	$claim  = array(
		'iss'   => $key['client_email'],
		'scope' => 'https://www.googleapis.com/auth/calendar',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	);

	/* base64url encoder (RFC 4648 §5 — no padding, + → -, / → _) */
	$b64url = function ( $d ) {
		return rtrim( strtr( base64_encode( $d ), '+/', '-_' ), '=' );
	};

	$jh  = $b64url( wp_json_encode( $header ) );
	$jc  = $b64url( wp_json_encode( $claim ) );
	$sig = '';
	/* openssl_sign uses SHA-256 RSA (RS256) — private_key from JSON is a PEM string */
	if ( ! openssl_sign( "$jh.$jc", $sig, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'sign_fail', 'JWT signing failed (openssl_sign returned false).' );
	}
	$jwt = "$jh.$jc." . $b64url( $sig );

	/* Exchange JWT for access token */
	$resp = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'timeout' => 20,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $jwt,
		),
	) );

	if ( is_wp_error( $resp ) ) {
		return new WP_Error( 'token_http', 'Token HTTP error: ' . $resp->get_error_message() );
	}

	$body = wp_remote_retrieve_body( $resp );
	$data = json_decode( $body, true );

	if ( empty( $data['access_token'] ) ) {
		/* Log the error response body (never the key) for diagnostics */
		return new WP_Error( 'token_fail', 'Token exchange failed: ' . wp_strip_all_tags( $body ) );
	}

	$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 );
	if ( function_exists( 'set_transient' ) ) {
		set_transient( 'gasf_calsync_token', $data['access_token'], $ttl );
	}

	return $data['access_token'];
}

/**
 * Thin Google Calendar API HTTP helper with exponential backoff.
 * Retries on 403 rateLimitExceeded / 429 / 5xx (up to 5 attempts).
 *
 * @param  string      $method  HTTP method: GET, POST, PUT, DELETE.
 * @param  string      $path    Calendar API path (e.g. /calendars/{id}/events).
 * @param  array|null  $body    Request body (JSON-encoded automatically).
 * @return array|true|WP_Error  Decoded JSON body (or true on 204), or WP_Error.
 */
function gasf_calsync_api( $method, $path, $body = null ) {
	$token = gasf_calsync_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$url     = 'https://www.googleapis.com/calendar/v3' . $path;
	$headers = array(
		'Authorization' => 'Bearer ' . $token,
		'Content-Type'  => 'application/json',
	);
	$args = array(
		'method'  => strtoupper( $method ),
		'headers' => $headers,
		'timeout' => 30,
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$max_attempts = 5;
	$delay        = 1; // seconds
	for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			if ( $attempt < $max_attempts ) {
				sleep( $delay + rand( 0, 500 ) / 1000 );
				$delay *= 2;
				continue;
			}
			return $resp;
		}

		$code = wp_remote_retrieve_response_code( $resp );

		/* 204 No Content (DELETE success) */
		if ( $code === 204 ) {
			return true;
		}

		/* 2xx success */
		if ( $code >= 200 && $code < 300 ) {
			return json_decode( wp_remote_retrieve_body( $resp ), true );
		}

		/* Retry-eligible codes with backoff */
		if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true )
			|| ( $code === 403 )  /* includes rateLimitExceeded */
		) {
			if ( $attempt < $max_attempts ) {
				sleep( $delay + rand( 0, 500 ) / 1000 );
				$delay *= 2;
				continue;
			}
		}

		/* Non-retryable or max attempts exceeded */
		return new WP_Error(
			'api_' . $code,
			'Google API ' . $method . ' ' . $path . ' returned HTTP ' . $code . ': '
			. wp_strip_all_tags( wp_remote_retrieve_body( $resp ) )
		);
	}

	return new WP_Error( 'api_max_attempts', 'Max retry attempts reached for ' . $method . ' ' . $path );
}

/**
 * Minimal ICS VEVENT parser (~70 lines). RFC 5545.
 * - Line-unfolds before parsing.
 * - Parses only BEGIN:VEVENT..END:VEVENT blocks; ignores VTIMEZONE.
 * - Maps UID, SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, URL.
 * - Detects all-day (VALUE=DATE or 8-digit no T) vs timed events.
 * - Passes single-line RRULE through to Google recurrence array.
 * - Skips VEVENTs without UID.
 *
 * @param  string $raw  Raw ICS content.
 * @return array        Map of uid => event array.
 */
function gasf_calsync_parse_ics( $raw ) {
	/* Normalize CRLF and unfold continuation lines */
	$raw   = str_replace( "\r\n", "\n", $raw );
	$raw   = str_replace( "\r", "\n", $raw );
	$lines = explode( "\n", $raw );
	$unfolded = array();
	foreach ( $lines as $line ) {
		if ( ( $line !== '' ) && ( $line[0] === ' ' || $line[0] === "\t" ) ) {
			if ( $unfolded ) {
				$unfolded[ count( $unfolded ) - 1 ] .= ltrim( $line );
			}
		} else {
			$unfolded[] = $line;
		}
	}

	$events      = array();
	$in_vevent   = false;
	$in_vtimezone = false;
	$ev          = array();

	foreach ( $unfolded as $line ) {
		$line = rtrim( $line );
		if ( $line === '' ) { continue; }

		if ( $line === 'BEGIN:VTIMEZONE' ) { $in_vtimezone = true;  continue; }
		if ( $line === 'END:VTIMEZONE'   ) { $in_vtimezone = false; continue; }
		if ( $in_vtimezone ) { continue; } // skip DST RRULE lines etc.

		if ( $line === 'BEGIN:VEVENT' ) { $in_vevent = true; $ev = array(); continue; }
		if ( $line === 'END:VEVENT' ) {
			$in_vevent = false;
			if ( ! empty( $ev['uid'] ) ) {
				$events[ $ev['uid'] ] = $ev;
			}
			continue;
		}
		if ( ! $in_vevent ) { continue; }

		/* Split NAME;params:value — find first unescaped colon */
		$colon = strpos( $line, ':' );
		if ( $colon === false ) { continue; }
		$name_params = substr( $line, 0, $colon );
		$value       = substr( $line, $colon + 1 );

		/* Unescape value */
		$value = str_replace( array( '\\n', '\\N', '\\,', '\;', '\\\\' ),
							  array( "\n",  "\n",  ',',   ';',   '\\' ), $value );

		/* Parse name + params */
		$parts  = explode( ';', $name_params );
		$name   = strtoupper( array_shift( $parts ) );
		$params = array();
		foreach ( $parts as $p ) {
			if ( strpos( $p, '=' ) !== false ) {
				list( $pk, $pv ) = explode( '=', $p, 2 );
				$params[ strtoupper( $pk ) ] = $pv;
			}
		}

		/* Map fields */
		switch ( $name ) {
			case 'UID':
				$ev['uid'] = $value;
				break;

			case 'SUMMARY':
				$ev['summary'] = $value;
				break;

			case 'LOCATION':
				$ev['location'] = $value;
				break;

			case 'DESCRIPTION':
				$ev['description'] = $value;
				break;

			case 'URL':
				$ev['url'] = $value;
				break;

			case 'RRULE':
				$ev['rrule'] = $value; // pass-through to Google recurrence
				break;

			case 'DTSTART':
			case 'DTEND':
				$field   = ( $name === 'DTSTART' ) ? 'dtstart' : 'dtend';
				$is_date = ( isset( $params['VALUE'] ) && strtoupper( $params['VALUE'] ) === 'DATE' )
					|| ( strlen( $value ) === 8 && ctype_digit( $value ) );
				if ( $is_date ) {
					/* All-day: store YYYY-MM-DD */
					$ev[ $field ]        = substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
					$ev[ $field . '_allday' ] = true;
				} else {
					/* Timed: convert to RFC 3339 */
					$tzid = $params['TZID'] ?? null;
					$is_utc = ( substr( $value, -1 ) === 'Z' );
					/* Reformat YYYYMMDDTHHmmss[Z] to YYYY-MM-DDTHH:mm:ss[Z] */
					$fmt = strlen( $value ) >= 15
						? substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 )
						  . 'T' . substr( $value, 9, 2 ) . ':' . substr( $value, 11, 2 ) . ':' . substr( $value, 13, 2 )
						  . ( $is_utc ? 'Z' : '' )
						: $value;
					$ev[ $field ]          = $fmt;
					$ev[ $field . '_tzid' ] = $is_utc ? 'UTC' : ( $tzid ?: 'UTC' );
					$ev[ $field . '_allday' ] = false;
				}
				break;
		}
	}

	return $events;
}


/* ==========================================================================
 * SECTION 3 — SYNC ENGINE: idempotent upsert/tag/color/delete + WP-cron
 * ========================================================================== */

/**
 * Build the Google Calendar API event body for a given source + parsed ICS event.
 *
 * @param  array $src  Source row { id, label, url, color }.
 * @param  array $ev   Parsed event from gasf_calsync_parse_ics().
 * @return array       Google API event body.
 */
function gasf_calsync_event_body( $src, $ev ) {
	$label       = $src['label'] ?? 'GASF';
	$color_id    = (string) max( 1, min( 11, (int) ( $src['color'] ?? 1 ) ) );
	$uid         = $ev['uid'] ?? '';
	$summary     = '[' . $label . '] ' . ( $ev['summary'] ?? '' );
	$description = ( $ev['description'] ?? '' );
	if ( ! empty( $ev['url'] ) ) {
		$description .= "\n\n" . $ev['url'];
	}
	$location = $ev['location'] ?? '';

	/* Build start/end */
	$all_day = ! empty( $ev['dtstart_allday'] );
	$start_ts = strtotime( (string) ( $ev['dtstart'] ?? '' ) );
	$end_ts   = ! empty( $ev['dtend'] ) ? strtotime( (string) $ev['dtend'] ) : false;
	if ( $all_day ) {
		$start = array( 'date' => $ev['dtstart'] ?? '' );
		if ( $end_ts && $end_ts > $start_ts ) {
			$end = array( 'date' => $ev['dtend'] );
		} else {
			$end = array( 'date' => date( 'Y-m-d', ( $start_ts ?: time() ) + DAY_IN_SECONDS ) );
		}
	} else {
		$tzid  = $ev['dtstart_tzid'] ?? 'UTC';
		$start = array( 'dateTime' => $ev['dtstart'] ?? '', 'timeZone' => $tzid );
		if ( $end_ts && $end_ts > $start_ts ) {
			$end = array( 'dateTime' => $ev['dtend'], 'timeZone' => $ev['dtend_tzid'] ?? $tzid );
		} else {
			$z = ( substr( (string) ( $ev['dtstart'] ?? '' ), -1 ) === 'Z' );
			$end_dt = $ev['dtstart'] ?? '';
			try {
				$d = new DateTime( rtrim( (string) $ev['dtstart'], 'Z' ), new DateTimeZone( $z ? 'UTC' : ( $tzid ?: 'UTC' ) ) );
				$d->modify( '+1 hour' );
				$end_dt = $d->format( 'Y-m-d\TH:i:s' ) . ( $z ? 'Z' : '' );
			} catch ( Exception $e ) {}
			$end = array( 'dateTime' => $end_dt, 'timeZone' => $ev['dtend_tzid'] ?? $tzid );
		}
	}

	$body = array(
		'summary'             => $summary,
		'description'         => $description,
		'location'            => $location,
		'colorId'             => $color_id,
		'start'               => $start,
		'end'                 => $end,
		'extendedProperties'  => array(
			'private' => array(
				'gasf_mgr' => 'calsync',
				'gasf_src' => $label,
				'gasf_uid' => $uid,
			),
		),
	);

	/* Pass RRULE through to Google recurrence (pass-through only; no local expansion) */
	if ( ! empty( $ev['rrule'] ) ) {
		$body['recurrence'] = array( 'RRULE:' . $ev['rrule'] );
	}

	return $body;
}

/**
 * List ALL managed events for one source label in the destination calendar.
 * Follows nextPageToken to completion. Returns map uid => { id, body fields }.
 *
 * @param  string $cal_id  Google Calendar ID (URL-encoded internally).
 * @param  string $label   Source label (gasf_src extended property value).
 * @return array|WP_Error  uid => array{ 'id': ..., 'summary': ..., 'start': ..., ... }
 */
function gasf_calsync_list_managed( $cal_id, $label ) {
	$managed     = array();
	$page_token  = null;
	$encoded_id  = rawurlencode( $cal_id );

	do {
		$params = http_build_query( array_filter( array(
			'privateExtendedProperty' => array(
				'gasf_mgr=calsync',
				'gasf_src=' . $label,
			),
			'showDeleted'  => 'false',
			'singleEvents' => 'false',
			'maxResults'   => 250,
			'pageToken'    => $page_token,
		) ) );

		/* http_build_query with array values produces param[0]=val&param[1]=val;
		   Google requires repeated params: privateExtendedProperty=a&privateExtendedProperty=b */
		$params = preg_replace( '/%5B\d+%5D/', '', $params );

		$result = gasf_calsync_api( 'GET', '/calendars/' . $encoded_id . '/events?' . $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			$priv = $item['extendedProperties']['private'] ?? array();
			$uid  = $priv['gasf_uid'] ?? null;
			if ( $uid ) {
				$managed[ $uid ] = array(
					'id'          => $item['id'] ?? '',
					'summary'     => $item['summary'] ?? '',
					'description' => $item['description'] ?? '',
					'location'    => $item['location'] ?? '',
					'colorId'     => $item['colorId'] ?? '',
					'start'       => $item['start'] ?? array(),
					'end'         => $item['end'] ?? array(),
				);
			}
		}

		$page_token = $result['nextPageToken'] ?? null;

	} while ( $page_token );

	return $managed;
}

/**
 * Sync ONE source against the destination calendar.
 * CRITICAL fail-safe: if the feed fails to fetch or parses to 0 events,
 * skip the source entirely — never list, never delete its Google events.
 *
 * @param  array  $src     Source row.
 * @param  string $cal_id  Destination Google Calendar ID.
 * @return array           { label, created, updated, deleted, skipped, errors[] }
 */
function gasf_calsync_dt_key( $x ) {
	if ( ! is_array( $x ) ) { return ''; }
	if ( isset( $x['date'] ) ) { return 'd:' . $x['date']; }
	if ( isset( $x['dateTime'] ) ) {
		$dt = (string) $x['dateTime'];
		$tz = isset( $x['timeZone'] ) ? (string) $x['timeZone'] : 'UTC';
		try {
			if ( preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/', $dt ) ) {
				$d = new DateTime( $dt );
			} else {
				$d = new DateTime( $dt, new DateTimeZone( $tz ?: 'UTC' ) );
			}
			return 't:' . $d->getTimestamp();
		} catch ( Exception $e ) {
			return 't:' . $dt;
		}
	}
	return '';
}

function gasf_calsync_sync_source( $src, $cal_id ) {
	$result = array(
		'label'   => $src['label'] ?? '?',
		'created' => 0,
		'updated' => 0,
		'deleted' => 0,
		'skipped' => false,
		'errors'  => array(),
	);

	$label = $src['label'] ?? '';
	$url   = $src['url']   ?? '';

	if ( ! $url ) {
		$result['skipped'] = true;
		$result['errors'][] = 'No ICS URL configured.';
		return $result;
	}

	/* Step 1: Fetch the ICS feed */
	$fetch = wp_remote_get( $url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $fetch ) ) {
		$result['skipped'] = true;
		$result['errors'][] = 'Fetch error: ' . $fetch->get_error_message();
		return $result;
	}
	$http_code = wp_remote_retrieve_response_code( $fetch );
	if ( $http_code !== 200 ) {
		$result['skipped'] = true;
		$result['errors'][] = 'Feed returned HTTP ' . $http_code . '.';
		return $result;
	}
	$raw = wp_remote_retrieve_body( $fetch );

	/* Step 2: Parse ICS */
	$ics_events = gasf_calsync_parse_ics( $raw );
	if ( empty( $ics_events ) ) {
		$result['skipped'] = true;
		$result['errors'][] = 'Feed parsed to 0 events — skipping to avoid false deletes.';
		return $result;
	}

	/* Step 2b: Sync window - keep last 1 year + all future (and all recurring). */
	$win_days     = (int) ( gasf_calsync_get_settings()['window_days'] ?? 365 );
	$cutoff       = $win_days > 0 ? time() - $win_days * DAY_IN_SECONDS : 0;
	$total_parsed = count( $ics_events );
	$ics_events   = array_filter( $ics_events, function ( $ev ) use ( $cutoff ) {
		if ( ! empty( $ev['rrule'] ) ) { return true; } // recurring may extend into the future
		$ref = isset( $ev['dtend'] ) ? $ev['dtend'] : ( $ev['dtstart'] ?? '' );
		if ( $ref === '' ) { return true; }
		$ts = strtotime( $ref );
		if ( $ts === false ) { return true; }
		return $ts >= $cutoff;
	} );
	if ( empty( $ics_events ) && $total_parsed > 0 ) {
		$result['skipped'] = true;
		$result['errors'][] = 'All ' . $total_parsed . ' events fall outside the 1-year window - skipping.';
		return $result;
	}

	/* Step 3: List existing managed events for this source */
	$managed = gasf_calsync_list_managed( $cal_id, $label );
	if ( is_wp_error( $managed ) ) {
		$result['skipped'] = true;
		$result['errors'][] = 'API list error: ' . $managed->get_error_message();
		return $result;
	}

	/* Execution-time budget guard: 5-minute cap for huge feeds */
	$budget_end = microtime( true ) + 300;

	$encoded_cal = rawurlencode( $cal_id );

	/* Step 4: Diff and upsert */
	foreach ( $ics_events as $uid => $ev ) {
		if ( microtime( true ) > $budget_end ) {
			$result['errors'][] = 'Time budget exceeded; re-run to continue (each item is idempotent).';
			break;
		}

		$body = gasf_calsync_event_body( $src, $ev );

		if ( isset( $managed[ $uid ] ) ) {
			/* ---- UPDATE (only if changed) ---- */
			$existing = $managed[ $uid ];
			$changed  = false;
			$changed  = $changed || ( trim( (string) ( $existing['summary']     ?? '' ) ) !== trim( (string) ( $body['summary']     ?? '' ) ) );
			$changed  = $changed || ( trim( (string) ( $existing['description'] ?? '' ) ) !== trim( (string) ( $body['description'] ?? '' ) ) );
			$changed  = $changed || ( trim( (string) ( $existing['location']    ?? '' ) ) !== trim( (string) ( $body['location']    ?? '' ) ) );
			$changed  = $changed || ( ( (string) ( $existing['colorId'] ?? '' ) ) !== ( (string) ( $body['colorId'] ?? '' ) ) );
			/* Start/end by canonical instant (Google adds the tz offset to dateTime, so a raw compare always differs). */
			$changed  = $changed || ( gasf_calsync_dt_key( $existing['start'] ?? array() ) !== gasf_calsync_dt_key( $body['start'] ?? array() ) );
			$changed  = $changed || ( gasf_calsync_dt_key( $existing['end']   ?? array() ) !== gasf_calsync_dt_key( $body['end']   ?? array() ) );
			if ( $changed ) {
				$put = gasf_calsync_api( 'PUT', '/calendars/' . $encoded_cal . '/events/' . $existing['id'], $body );
				if ( is_wp_error( $put ) ) {
					$result['errors'][] = 'Update ' . $uid . ': ' . $put->get_error_message();
				} else {
					$result['updated']++;
				}
			}
			/* Mark as processed so we know what's left = deletions */
			unset( $managed[ $uid ] );
		} else {
			/* ---- INSERT ---- */
			$post = gasf_calsync_api( 'POST', '/calendars/' . $encoded_cal . '/events', $body );
			if ( is_wp_error( $post ) ) {
				$result['errors'][] = 'Insert ' . $uid . ': ' . $post->get_error_message();
			} else {
				$result['created']++;
			}
		}
	}

	/* Step 4b: DELETE — only events still in $managed (not in ICS) */
	/* This block only runs if we reached here (successful fetch + non-empty parse) */
	foreach ( $managed as $uid => $existing ) {
		if ( microtime( true ) > $budget_end ) {
			$result['errors'][] = 'Time budget exceeded during delete phase; re-run to finish.';
			break;
		}
		$del = gasf_calsync_api( 'DELETE', '/calendars/' . $encoded_cal . '/events/' . $existing['id'] );
		if ( is_wp_error( $del ) ) {
			$result['errors'][] = 'Delete ' . $uid . ': ' . $del->get_error_message();
		} else {
			$result['deleted']++;
		}
	}

	return $result;
}

/**
 * Main sync entrypoint — called by WP-cron and by the "Sync now" button.
 * Bails early (with log) if feature-gated off, no key, or no calendar_id.
 */
function gasf_calsync_run_all() {
	/* Feature gate */
	if ( function_exists( 'gasf_mec_enabled' ) && ! gasf_mec_enabled( 'gasf_mec_enable_calsync' ) ) {
		if ( function_exists( 'gasf_mec_log' ) ) {
			gasf_mec_log( '[calsync] Sync skipped: feature gate gasf_mec_enable_calsync is off.' );
		}
		return;
	}

	$settings = gasf_calsync_get_settings();
	$cal_id   = $settings['calendar_id'] ?? '';
	if ( ! $cal_id ) {
		if ( function_exists( 'gasf_mec_log' ) ) {
			gasf_mec_log( '[calsync] Sync skipped: no destination Calendar ID configured.' );
		}
		return;
	}

	/* Check key exists before even fetching (fail fast) */
	if ( ! gasf_calsync_load_key() ) {
		if ( function_exists( 'gasf_mec_log' ) ) {
			gasf_mec_log( '[calsync] Sync skipped: service-account key not found/unreadable.' );
		}
		return;
	}

	$sources      = gasf_calsync_get_sources();
	$per_source   = array();
	$global_errors = array();

	foreach ( $sources as $src ) {
		$res = gasf_calsync_sync_source( $src, $cal_id );
		$per_source[] = $res;

		/* Log one line per source */
		if ( function_exists( 'gasf_mec_log' ) ) {
			$skipped_str = $res['skipped'] ? ' SKIPPED' : '';
			$err_str     = $res['errors'] ? ' errors=[' . implode( '|', $res['errors'] ) . ']' : '';
			gasf_mec_log(
				'[calsync] src=' . ( $res['label'] ?? '?' ) . $skipped_str
				. ' created=' . (int) $res['created']
				. ' updated=' . (int) $res['updated']
				. ' deleted=' . (int) $res['deleted']
				. $err_str
			);
		}

		if ( $res['errors'] ) {
			foreach ( $res['errors'] as $e ) {
				$global_errors[] = ( $res['label'] ?? '?' ) . ': ' . $e;
			}
		}
	}

	/* Persist last-run status */
	update_option( 'gasf_calsync_last_run', array(
		'ts'         => time(),
		'per_source' => $per_source,
		'errors'     => $global_errors,
	), false );
}

/* ---- WP-Cron registration ---- */
/* Hook the sync function to the cron event */
add_action( 'gasf_calsync_cron', 'gasf_calsync_run_all' );

/* Ensure the cron event is scheduled on every WordPress init */
add_action( 'init', function () {
	/* Only schedule if function_exists guard passes (defensive coding) */
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}
	/* Only schedule if there are sources + a calendar_id configured */
	$settings = gasf_calsync_get_settings();
	if ( empty( $settings['calendar_id'] ) ) {
		return; // don't schedule until configured
	}
	if ( ! wp_next_scheduled( 'gasf_calsync_cron' ) ) {
		$interval = $settings['interval'] ?? 'hourly';
		$allowed  = array( 'hourly', 'twicedaily', 'daily' );
		if ( ! in_array( $interval, $allowed, true ) ) {
			$interval = 'hourly';
		}
		wp_schedule_event( time() + 60, $interval, 'gasf_calsync_cron' );
	}
} );
