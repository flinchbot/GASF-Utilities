<?php
/**
 * Module 25 — URL Shortify Branded Display (domain-picker approach).
 * Gate: gasf_site_enable_us_branded (default ON).
 * Task: 260626-7p3 (REVISED 2026-06-26).
 * Purpose: (A) Register https://gtbay.club as a selectable domain in URL Shortify's
 *          domain dropdown via the kc_us_custom_domains filter.
 *          (B) Admin-only display/copy rewrite scoped ONLY to links explicitly marked
 *          rules.domain='gtbay' — so existing links (domain='home' or unset) are NEVER
 *          changed. Queries the DB on admin_enqueue_scripts and localizes the id list to JS.
 *          (C) Only enqueued on page=us_links.
 * Safe: no plugin PHP edits, no DB-schema changes, no home/siteurl changes, no redirects.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Gate: fail-safe fall back to ON when gasf_site_enabled() not yet loaded.
$_gasf_us_enabled = function_exists( 'gasf_site_enabled' )
    ? gasf_site_enabled( 'gasf_site_enable_us_branded' )
    : true;

if ( ! $_gasf_us_enabled ) {
    return;
}

// (A) Register gtbay.club as a selectable URL Shortify domain.
// Key MUST be 'gtbay' — that is what gets stored in rules.domain and what the rewrite keys on.
add_filter( 'kc_us_custom_domains', function ( $domains ) {
    if ( ! is_array( $domains ) ) {
        $domains = array();
    }
    $domains['gtbay'] = 'https://gtbay.club';
    return $domains;
} );

// (B) Admin display/copy rewrite — scoped to page=us_links only.
add_action( 'admin_enqueue_scripts', function () {

    // Only act on the URL Shortify Links list/edit screen.
    if ( ! isset( $_GET['page'] ) || 'us_links' !== $_GET['page'] ) {
        return;
    }

    // Query DB once: collect ids whose rules.domain === 'gtbay'.
    // PHP unserialize is safer than a LIKE on serialized bytes.
    // Club-scale table — full scan is fine.
    try {
        global $wpdb;
        $table = $wpdb->prefix . 'kc_us_links';
        $rows  = $wpdb->get_results( "SELECT id, rules FROM `{$table}`", ARRAY_A );
        $gtbay_ids = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $rules = @unserialize( $row['rules'] );
                if ( is_array( $rules ) && isset( $rules['domain'] ) && $rules['domain'] === 'gtbay' ) {
                    $gtbay_ids[] = (int) $row['id'];
                }
            }
        }
    } catch ( \Exception $e ) {
        $gtbay_ids = array();
    }

    // Register a no-src handle (standard WP pattern for inline-only scripts).
    wp_register_script( 'gasf-us-branded', false, array(), '1.1', true );
    wp_enqueue_script( 'gasf-us-branded' );

    // Localize the id list to JS.
    // Using wp_add_inline_script 'before' so the var is available when the IIFE runs.
    $ids_json = wp_json_encode( $gtbay_ids );
    wp_add_inline_script( 'gasf-us-branded', 'var GASF_US_GTBAY_IDS = ' . $ids_json . ';', 'before' );

    // phpcs:disable
    $js = <<<'INLINEJS'
(function () {
    'use strict';

    var OLD_PATTERN = /https?:\/\/(?:www\.)?germantampabay\.com\//i;
    var NEW_PREFIX  = 'https://gtbay.club/';
    var IDS         = (typeof GASF_US_GTBAY_IDS !== 'undefined') ? GASF_US_GTBAY_IDS : [];

    if (!IDS || IDS.length === 0) {
        return; // No gtbay links in DB yet — nothing to do.
    }

    /**
     * Rewrite a URL string: host-swap only, path preserved.
     * Returns null if the URL already is gtbay.club or does not contain germantampabay.com.
     */
    function rewriteUrl(url) {
        if (!url) { return null; }
        if (url.indexOf('gtbay.club') !== -1) { return null; } // already correct, skip
        if (OLD_PATTERN.test(url)) {
            return url.replace(OLD_PATTERN, NEW_PREFIX);
        }
        return null;
    }

    /**
     * Rewrite visible text nodes inside an element (skips SVG subtrees).
     */
    function rewriteTextNodes(el) {
        try {
            var walker = document.createTreeWalker(
                el,
                NodeFilter.SHOW_TEXT,
                {
                    acceptNode: function (node) {
                        var p = node.parentNode;
                        while (p && p !== el) {
                            if (p.nodeName && p.nodeName.toLowerCase() === 'svg') {
                                return NodeFilter.FILTER_REJECT;
                            }
                            p = p.parentNode;
                        }
                        return NodeFilter.FILTER_ACCEPT;
                    }
                }
            );
            var node;
            while ((node = walker.nextNode())) {
                if (node.nodeValue && OLD_PATTERN.test(node.nodeValue)) {
                    node.nodeValue = node.nodeValue.replace(OLD_PATTERN, NEW_PREFIX);
                }
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Rewrite a single copy-to-clipboard element for a given link id.
     * Targets:
     *   #copy-link-<id>  (column_title copy anchor)
     *   #link-<id>       (column_link / create_copy_short_link_html span)
     * Does NOT touch .kc-us-link inputs (they hold only /slug) or Target column.
     */
    function rewriteElement(el) {
        if (!el) { return; }
        try {
            // Rewrite data-clipboard-text (the copy value).
            var clip = el.getAttribute('data-clipboard-text');
            if (clip) {
                var newClip = rewriteUrl(clip);
                if (newClip) { el.setAttribute('data-clipboard-text', newClip); }
            }

            // If the element itself is an anchor, rewrite its href.
            if (el.tagName && el.tagName.toLowerCase() === 'a') {
                var href = el.getAttribute('href');
                if (href && href !== '#') {
                    var newHref = rewriteUrl(href);
                    if (newHref) { el.setAttribute('href', newHref); }
                }
            }

            // Rewrite any inner anchor hrefs.
            var innerLinks = el.querySelectorAll('a[href]');
            for (var i = 0; i < innerLinks.length; i++) {
                var iHref = innerLinks[i].getAttribute('href');
                if (iHref && iHref !== '#') {
                    var niHref = rewriteUrl(iHref);
                    if (niHref) { innerLinks[i].setAttribute('href', niHref); }
                }
            }

            // Rewrite visible text nodes (skips SVG, skips .kc-us-link inputs).
            rewriteTextNodes(el);
        } catch (e) { /* never break admin */ }
    }

    /**
     * Apply rewrites for all gtbay ids.
     */
    function applyAll() {
        try {
            for (var i = 0; i < IDS.length; i++) {
                var id = IDS[i];
                rewriteElement(document.getElementById('copy-link-' + id));
                rewriteElement(document.getElementById('link-' + id));
            }
        } catch (e) { /* ignore */ }
    }

    // Run on DOMContentLoaded (or immediately if DOM already ready).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAll);
    } else {
        applyAll();
    }

    // MutationObserver: re-apply after AJAX row refresh.
    // _observing flag prevents loops on our own DOM writes.
    try {
        var listContainer = document.getElementById('the-list') ||
                            document.querySelector('.wp-list-table tbody');
        if (listContainer && typeof MutationObserver !== 'undefined') {
            var _observing = false;
            var observer = new MutationObserver(function (mutations) {
                if (_observing) { return; }
                var needsRun = false;
                for (var m = 0; m < mutations.length; m++) {
                    if (mutations[m].addedNodes && mutations[m].addedNodes.length > 0) {
                        needsRun = true;
                        break;
                    }
                }
                if (needsRun) {
                    _observing = true;
                    try { applyAll(); } catch (e) { /* ignore */ } finally { _observing = false; }
                }
            });
            observer.observe(listContainer, { childList: true, subtree: true });
        }
    } catch (e) { /* MutationObserver setup failure is non-fatal */ }

})();
INLINEJS;
    // phpcs:enable

    wp_add_inline_script( 'gasf-us-branded', $js );

} );
