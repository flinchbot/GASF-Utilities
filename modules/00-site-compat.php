<?php
/**
 * Site compatibility shim — provides the gate helper that the migrated
 * GASF_Site modules (rest-enum, bundesliga, print button, redirects, parking)
 * rely on, now that they live in this unified "GASF Utilities" plugin.
 * Guarded so it can never collide if the old gasf-site.php loader is present.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'GASF_SITE_VERSION' ) ) { define( 'GASF_SITE_VERSION', '1.0.0' ); }

if ( ! function_exists( 'gasf_site_enabled' ) ) {
	function gasf_site_enabled( $option, $default = '1' ) {
		$v = get_option( $option, $default );
		return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
	}
}
