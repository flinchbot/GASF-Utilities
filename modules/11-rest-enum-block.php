<?php
// Migrated from Code Snippet #11 "Block REST API user enumeration" 2026-06-14 (task 260614-gj8).
// Gate: gasf_site_enable_restenum (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_site_enabled( 'gasf_site_enable_restenum' ) ) {
// Block REST API user enumeration
// Controlled by GASF_BLOCK_REST_USERS constant in wp-config.php
if ( defined('GASF_BLOCK_REST_USERS') && GASF_BLOCK_REST_USERS ) {
    add_filter('rest_endpoints', function($endpoints) {
        unset($endpoints['/wp/v2/users']);
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
        return $endpoints;
    });
}
}
