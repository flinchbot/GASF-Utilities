<?php
// Migrated from Code Snippet #23 "Redirect: USA v TBD -> USA v Türkiye (World Cup)" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_redirects (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_redirects' ) ) {
/**
 * GASF: 301 redirect for the renamed World Cup event slug.
 * Old: /events/world-cup-watch-party-usa-v-tbd/  ->  new: .../usa-v-turkiye/
 * Keeps any existing shared links (Facebook, calendar) from 404ing.
 */
add_action('template_redirect', function() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'world-cup-watch-party-usa-v-tbd') !== false) {
        wp_redirect('https://germantampabay.com/events/world-cup-watch-party-usa-v-turkiye/', 301);
        exit;
    }
}, 1);
}
