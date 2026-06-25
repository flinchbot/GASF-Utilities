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
	gasf_utilities_add_tab( 'events', 'Event Calendars', 'gasf_utilities_events_tab', 10 );
}, 10 );

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
	echo '<p>All custom germantampabay.com tweaks live in this one must-use plugin. Most run automatically (shortcodes &amp; filters); the tabs above hold anything with controls or status. This is the place to look for any custom behavior on the site.</p>';
	echo '<h3>What\'s in here</h3><ul style="list-style:disc;margin-left:22px">';
	echo '<li><strong>Event Calendars</strong> — Facebook sync fixes for Modern Events Calendar, branded single-event template, cover de-duplication, recurrence, deleted-event handling. <em>(See the Event Calendars tab for status.)</em></li>';
	echo '<li><strong>Heroes</strong> — schedule the home-page hero image. <em>(See the Heroes tab.)</em></li>';
	echo '<li><strong>Shortcodes</strong> — <code>[gas_hero]</code>, <code>[gas_parking]</code>, <code>[german_dinner_events]</code>, <code>[world_cup_schedule]</code>, <code>[bundesliga_table]</code>, <code>[bundesliga_scorers]</code>, <code>[bundesliga_top_scorers]</code>.</li>';
	echo '<li><strong>Site hardening / misc</strong> — REST &amp; author-enumeration block, calendar print button, content 301 redirects.</li>';
	echo '</ul>';
	echo '<p style="color:#666;margin-top:18px">Git-backed (repo <code>GASF-Utilities</code>); deploy with <code>git pull</code>. Feature gates are <code>gasf_mec_enable_*</code> / <code>gasf_site_enable_*</code> options (default on; set to <code>0</code> to disable a module).</p>';
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
