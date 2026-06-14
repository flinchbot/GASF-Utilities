<?php
// Migrated from Code Snippet #5 "Update Yoast/MEC missing Schema" 2026-06-14 (task 260614-gj8).
// Gate: gasf_mec_enable_schema (default ON). Backed up in snippets-backup-20260614.sql.
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( gasf_mec_enabled( 'gasf_mec_enable_schema' ) ) {
/**
 * Fix MEC Event JSON-LD schema — inject missing GSC fields
 * Runs on ALL pages since MEC outputs event schema on list/calendar pages too
 */
add_action('template_redirect', function() {
    ob_start(function($html) {
        $html = preg_replace_callback(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            function($matches) {
                $data = json_decode($matches[1], true);
                if (!$data || !isset($data['@type']) || $data['@type'] !== 'Event') {
                    return $matches[0];
                }

                // Fix organizer
                $data['organizer'] = [
                    '@type' => 'Organization',
                    'name'  => 'German-American Society Friendship of Pinellas County',
                    'url'   => 'https://germantampabay.com'
                ];

                // Fix location address
                $data['location'] = [
                    '@type'   => 'Place',
                    'name'    => 'German-American Society',
                    'address' => [
                        '@type'           => 'PostalAddress',
                        'streetAddress'   => '8098 66th Street North',
                        'addressLocality' => 'Pinellas Park',
                        'addressRegion'   => 'FL',
                        'postalCode'      => '33781',
                        'addressCountry'  => 'US'
                    ]
                ];

                // Add performer if missing or empty
                if (empty($data['performer'])) {
                    $data['performer'] = [
                        '@type' => 'Organization',
                        'name'  => 'German-American Society Friendship of Pinellas County'
                    ];
                }

                return '<script type="application/ld+json">'
                    . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    . '</script>';
            },
            $html
        );
        return $html;
    });
});
}
