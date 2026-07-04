<?php
/**
 * Plugin Name: GASF Utilities
 * Description: All custom germantampabay.com functionality in one update-safe plugin: home-page heroes, SEO + AI event descriptions, short links, redirects, Instagram feed, reviews wall, calendar sync, Facebook token watchdog, performance and hardening tweaks, and more. Each module is individually gated — see the GASF Utilities → Settings tab.
 * Version:     1.6.0
 * Author:      GASF
 * License:     GPL-2.0-or-later
 * Update URI:  https://github.com/flinchbot/GASF-Utilities
 *
 * Repo: https://github.com/flinchbot/GASF-Utilities
 *
 * ---------------------------------------------------------------------------
 * SECURITY: This file is tracked in a PUBLIC repo. NEVER hardcode secrets.
 * Every token/key lives in wp_options and is read at runtime.
 * ---------------------------------------------------------------------------
 *
 * This file is named gasf-mec-importer.php for historical reasons — the plugin
 * began life as the MEC Advanced Importer fixes. Renaming it would break the
 * mu-plugin shim on the main site (wp-content/mu-plugins/gasf-mec-importer.php)
 * and the registered plugin path on the Krampus install, so the name stays.
 * The MEC-era modules themselves (importer cron/defaults/window/recurrence/
 * dedup-sweep/single-template, FB refresh, URL Shortify branding) were removed
 * in v1.3.0 — MEC is retired on both sites; see git history for the code.
 *
 * All functionality lives in modules/*.php, loaded by the glob at the bottom.
 * Every module self-gates on a wp_option (gasf_site_enable_* /
 * gasf_mec_enable_*, default ON, '0' = off) — toggle them in
 * wp-admin > GASF Utilities > Settings (modules/02-settings.php), or via
 * `wp option update <gate> 0`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GASF_MEC_VERSION', '1.6.0' );

// Log lives OUTSIDE the web root (parent of ABSPATH), not web-fetchable.
// Falls back silently if unwritable (logging is best-effort).
define( 'GASF_MEC_LOG', dirname( untrailingslashit( ABSPATH ) ) . '/gasf-mec-importer.log' );
define( 'GASF_MEC_LOG_MAX', 1048576 ); // 1 MB, then rotate to .1

/**
 * Feature gate: true unless the option is explicitly '0'/0/false.
 */
function gasf_mec_enabled( $option, $default = '1' ) {
	$v = get_option( $option, $default );
	return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
}

/**
 * Best-effort, size-capped log line. Server-local time.
 */
function gasf_mec_log( $msg ) {
	$f = GASF_MEC_LOG;
	if ( @file_exists( $f ) && @filesize( $f ) > GASF_MEC_LOG_MAX ) {
		@rename( $f, $f . '.1' );
	}
	@file_put_contents( $f, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
}

/* === MODULE LOADER: each file in modules/ self-gates via its gate option === */
foreach ( glob( __DIR__ . "/modules/*.php" ) as $gasf_mec_mod ) {
	require_once $gasf_mec_mod;
}
unset( $gasf_mec_mod );

/* =========================================================================
 * GitHub auto-updates (WP 5.8+ `Update URI` mechanism)
 *
 * Only meaningful where this runs as a REGULAR plugin (e.g. the Krampus
 * site). On the main site it loads as an mu-plugin via the shim, is never in
 * the plugins list, and these hooks simply never match. Public repo -> no
 * credentials. Bump the Version header above for installs to see updates.
 * ========================================================================= */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( basename( $plugin_file ) !== basename( __FILE__ ) ) { return $update; }
	$ver = get_transient( 'gasf_util_update_check' );
	if ( ! is_string( $ver ) || '' === $ver ) {
		$ver  = '';
		$resp = wp_remote_get( 'https://raw.githubusercontent.com/flinchbot/GASF-Utilities/main/gasf-mec-importer.php', array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp )
			&& preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9a-z.\-]*)/mi', (string) wp_remote_retrieve_body( $resp ), $m ) ) {
			$ver = trim( $m[1] );
		}
		set_transient( 'gasf_util_update_check', $ver, 6 * HOUR_IN_SECONDS );
	}
	if ( '' === $ver || version_compare( $ver, (string) $plugin_data['Version'], '<=' ) ) { return $update; }
	return array(
		'id'      => 'https://github.com/flinchbot/GASF-Utilities',
		'slug'    => dirname( $plugin_file ),
		'plugin'  => $plugin_file,
		'version' => $ver,
		'url'     => 'https://github.com/flinchbot/GASF-Utilities',
		'package' => 'https://github.com/flinchbot/GASF-Utilities/archive/refs/heads/main.zip',
	);
}, 10, 3 );

add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || basename( (string) $hook_extra['plugin'] ) !== basename( __FILE__ ) ) { return $source; }
	global $wp_filesystem;
	$want = trailingslashit( $remote_source ) . dirname( (string) $hook_extra['plugin'] ) . '/';
	if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) { return $source; }
	return ( $wp_filesystem && $wp_filesystem->move( $source, $want ) ) ? $want : $source;
}, 10, 4 );
