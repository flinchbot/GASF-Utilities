<?php
/**
 * Unified "GASF Utilities" admin page — ONE menu, tabbed.
 *
 * A single top-level "GASF Utilities" menu renders a tabbed page. Modules add
 * their own tab with:
 *     gasf_utilities_add_tab( 'slug', 'Label', 'render_callback', $priority );
 * Built-in tabs: Overview, Event Calendars. (Home Page Hero adds "Heroes".)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ---------- tab registry ---------- */
function gasf_utilities_add_tab( $slug, $label, $callback, $priority = 10 ) {
	global $gasf_util_tabs;
	if ( ! is_array( $gasf_util_tabs ) ) { $gasf_util_tabs = array(); }
	$gasf_util_tabs[ $slug ] = array(
		'label'    => $label,
		'callback' => $callback,
		'priority' => (int) $priority,
	);
}

/* ---------- single top-level menu ---------- */
add_action( 'admin_menu', function () {
	add_menu_page( 'GASF Utilities', 'GASF Utilities', 'manage_options', 'gasf-utilities', 'gasf_utilities_render', 'dashicons-admin-tools', 26 );
}, 9 );

/* register the built-in tabs (modules register theirs on admin_menu too) */
add_action( 'admin_menu', function () {
	gasf_utilities_add_tab( 'overview', 'Overview', 'gasf_utilities_overview_tab', 1 );
	// NOTE: the FB->MEC sync status (gasf_utilities_events_tab) is no longer a tab here —
	// it is rendered on the Event Calendars > Settings page (GASF Event Calendars plugin),
	// merged with the Eventbrite settings. The function is kept for that plugin to call.
}, 10 );

/* ---------- shared "About this utility" docs panel ----------
 * Every tab calls this first so documentation is consistent:
 *   gasf_utilities_doc_panel( array(
 *     'what'   => 'One-paragraph overview of what the utility does.',
 *     'needs'  => array( 'Requirement 1', 'Requirement 2' ),   // to run at all
 *     'fields' => array( 'Field label' => 'What to enter and why it is needed.' ),
 *     'notes'  => 'Optional extra paragraph (gotchas, related tabs).',
 *   ) );
 * Renders a collapsible panel so the docs never crowd the working UI. */
function gasf_utilities_doc_panel( $args ) {
	$a = wp_parse_args( $args, array( 'what' => '', 'needs' => array(), 'fields' => array(), 'notes' => '' ) );
	echo '<details class="gasf-docs" style="background:#f6f7f7;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:2px;padding:10px 14px;margin:0 0 16px;max-width:960px">';
	echo '<summary style="cursor:pointer;font-weight:600;color:#2271b1">&#128214; About this utility — what it does &amp; how to fill in each field</summary>';
	echo '<div style="margin-top:10px">';
	if ( $a['what'] ) {
		echo '<p style="margin-top:0">' . wp_kses_post( $a['what'] ) . '</p>';
	}
	if ( $a['needs'] ) {
		echo '<p style="margin-bottom:4px"><strong>Needs to run:</strong></p><ul style="list-style:disc;margin:0 0 10px 22px">';
		foreach ( (array) $a['needs'] as $n ) {
			echo '<li>' . wp_kses_post( $n ) . '</li>';
		}
		echo '</ul>';
	}
	if ( $a['fields'] ) {
		echo '<p style="margin-bottom:4px"><strong>Fields &amp; controls:</strong></p>';
		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		foreach ( (array) $a['fields'] as $label => $desc ) {
			echo '<tr><td style="width:220px;font-weight:600">' . wp_kses_post( $label ) . '</td><td>' . wp_kses_post( $desc ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	if ( $a['notes'] ) {
		echo '<p style="color:#646970;margin-bottom:0">' . wp_kses_post( $a['notes'] ) . '</p>';
	}
	echo '</div></details>';
}

/* ---------- page renderer (tabs) ---------- */
function gasf_utilities_render() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	global $gasf_util_tabs;
	$tabs = is_array( $gasf_util_tabs ) ? $gasf_util_tabs : array();
	uasort( $tabs, function ( $a, $b ) { return $a['priority'] <=> $b['priority']; } );
	$first   = $tabs ? array_key_first( $tabs ) : '';
	$req      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$current = ( $req && isset( $tabs[ $req ] ) ) ? $req : $first;

	echo '<div class="wrap">';
	echo '<h1>GASF Utilities</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $t ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $slug ) ),
			$slug === $current ? 'nav-tab-active' : '',
			esc_html( $t['label'] )
		);
	}
	echo '</h2>';
	echo '<div class="gasf-util-tab" style="margin-top:18px">';
	if ( $current && isset( $tabs[ $current ] ) && is_callable( $tabs[ $current ]['callback'] ) ) {
		call_user_func( $tabs[ $current ]['callback'] );
	}
	echo '</div></div>';
}

/* ---------- Overview tab ---------- */
function gasf_utilities_overview_tab() {
	$tab = function ( $slug ) { return esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=' . $slug ) ); };
	echo '<p>All custom germantampabay.com functionality lives in this one must-use plugin — every tab above is one self-contained utility. Each tab opens with a collapsible <strong>&#128214; About this utility</strong> panel explaining what it does, what it needs to run, and what every field is for. This Overview is the map.</p>';

	echo '<h3>Content &amp; home page</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'heroes' ) . '"><strong>Heroes</strong></a> — schedule the home-page hero image (the big banner rendered by the <code>[gas_hero]</code> shortcode). Newest entry whose activation time has passed wins.</li>';
	echo '<li><a href="' . $tab( 'recurring-heroes' ) . '"><strong>Recurring Heroes</strong></a> — auto-heroes for recurring events (Euchre Night, Krampus Meetup…): the hero appears N days before the next occurrence on the events calendar and disappears after it ends. Manual Heroes entries override.</li>';
	echo '</ul>';

	echo '<h3>Search &amp; links</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'seo' ) . '"><strong>SEO</strong></a> — the native replacement for Yoast: titles, meta descriptions, canonical/robots, OpenGraph/Twitter cards, JSON-LD, sitemap redirects, plus the per-page "SEO (GASF)" editor box.</li>';
	echo '<li><a href="' . $tab( 'aiseo' ) . '"><strong>Event SEO (AI)</strong></a> — Claude writes a meta description for every event name that lacks one (all "Biergarten" occurrences share one). Fills blanks only.</li>';
	echo '<li><a href="' . $tab( 'shortlinks' ) . '"><strong>Short Links</strong></a> — branded short URLs (<code>/join</code>, <code>/75th</code>…) with click counts, replacing URL Shortify. Works on all three domains.</li>';
	echo '<li><a href="' . $tab( 'redirects' ) . '"><strong>Redirects</strong></a> — 301/302/410 redirect manager + a live 404 log with one-click "create redirect" fixes.</li>';
	echo '</ul>';

	echo '<h3>Calendars</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'calsync' ) . '"><strong>Calendar Sync</strong></a> — mirrors the public events calendar into the internal Google Calendar via a service account.</li>';
	echo '<li><a href="' . $tab( 'gcal-print' ) . '"><strong>Internal Calendar</strong></a> — a secret-link printable month view of that Google Calendar (includes hand-added private events) for the bulletin board.</li>';
	echo '</ul>';

	echo '<h3>Social &amp; reputation</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . $tab( 'instagram' ) . '"><strong>Instagram</strong></a> — the native feed behind <code>[gasf_instagram]</code> (grid/masonry/carousel + lightbox), replacing Smash Balloon\'s display. Self-refreshing token.</li>';
	echo '<li><a href="' . $tab( 'reviews' ) . '"><strong>Reviews</strong></a> — the <code>[gasf_reviews]</code> wall: live Google + TripAdvisor reviews plus hand-curated entries (Facebook).</li>';
	echo '<li><a href="' . $tab( 'fbhealth' ) . '"><strong>FB Token</strong></a> — watchdog for the GASF-Events Facebook feed token: daily live probe, auto-heal, email alert before it can silently die.</li>';
	echo '</ul>';

	echo '<h3>Runs automatically (no tab)</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><strong>Shortcodes</strong> — <code>[gas_hero]</code>, <code>[gas_parking]</code>, <code>[german_dinner_events]</code>, <code>[bayern_match_events]</code>, <code>[world_cup_schedule]</code>, <code>[bundesliga_table]</code>, <code>[bundesliga_scorers]</code>, <code>[bundesliga_top_scorers]</code>, <code>[gasf_instagram]</code>, <code>[gasf_reviews]</code>.</li>';
	echo '<li><strong>Schema JSON-LD</strong> — Organization + festival Event markup (migrated from the retired HFCM plugin).</li>';
	echo '<li><strong>Site hardening</strong> — REST user-listing &amp; author-enumeration blocks, misc content 301s.</li>';
	echo '</ul>';

	echo '<p style="color:#666;margin-top:18px">Git-backed (repo <code>flinchbot/GASF-Utilities</code>); deploy = <code>git pull</code> in <code>/home4/germanta/gasf-muplugin</code>. Never edit these files on the server. Feature gates are <code>gasf_site_enable_*</code> / <code>gasf_mec_enable_*</code> options (default on; set to <code>0</code> to disable a module). Related but separate: the <strong>GASF-Events</strong> plugin (events calendar, Facebook feed, Eventbrite publishing — see Events &rarr; Feeds and All Events).</p>';
}

/* ---------- Event Calendars tab ---------- */
function gasf_utilities_events_tab() {
	echo '<h3>Facebook sync &amp; Modern Events Calendar fixes</h3>';
	echo '<p>These modules run automatically on the importer\'s schedule. <strong>Facebook is the source of truth</strong> — upcoming FB-imported events have their title, date/time and cover refreshed each sync; vanished FB events are unpublished. Status below.</p>';

	// recent importer audit log
	$log = defined( 'GASF_MEC_LOG' ) ? GASF_MEC_LOG : '/home4/germanta/gasf-mec-importer.log';
	echo '<h4>Recent sync activity</h4>';
	if ( @is_readable( $log ) ) {
		$all   = array_values( array_filter( explode( "\n", (string) @file_get_contents( $log ) ) ) );
		$lines = array_slice( $all, -10 );
		echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;max-height:240px;overflow:auto;font-size:12px">' . esc_html( implode( "\n", $lines ) ) . '</pre>';
	} else {
		echo '<p><em>Audit log not readable.</em></p>';
	}

	// module switches (gasf_mec_enable_* options that have been set)
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'gasf_mec_enable_%' ORDER BY option_name" );
	echo '<h4>Module switches</h4>';
	if ( $rows ) {
		echo '<table class="widefat striped" style="max-width:560px"><thead><tr><th>Gate</th><th>State</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$on = ! ( $r->option_value === '0' || $r->option_value === 'false' );
			echo '<tr><td><code>' . esc_html( $r->option_name ) . '</code></td><td>' . ( $on ? '<span style="color:#1a7f37">● on</span>' : '<span style="color:#b3122b">○ off</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p style="color:#666">Stored as options (default on); set a gate to <code>0</code> to disable its module. Most modules have no UI here by design.</p>';
	} else {
		echo '<p style="color:#666">No gate options set — all modules running on defaults (on).</p>';
	}

	// quick links
	echo '<h4>Quick links</h4><ul style="list-style:disc;margin-left:22px">';
	echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=mec-events' ) ) . '">All events (Modern Events Calendar)</a></li>';
	echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=gasf-utilities&tab=heroes' ) ) . '">Home Page Hero (Heroes tab)</a></li>';
	echo '</ul>';
}
