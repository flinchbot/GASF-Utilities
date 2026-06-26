<?php
// Module 25 — URL Shortify Branded Display (cosmetic admin-only rewrite to gtbay.club).
// Gate: gasf_site_enable_us_branded (default ON).
// Task: 260626-7p3.
// Purpose: URL Shortify FREE/lite builds short links as home_url()+slug (no filter hook for
//          custom domains in lite). This module intercepts ONLY the URL Shortify Links admin
//          screen and rewrites displayed links + copy-button values from
//          https://germantampabay.com/<slug> to https://gtbay.club/<slug>.
//          gtbay.club is already a Cloudflare 301 redirect to germantampabay.com, so every
//          rewritten link resolves identically. NO PHP/DB/option/redirect changes are made.
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Only run if gasf_site_enabled() is available (loaded by 00-site-compat.php first).
// Fall back to a default-ON value so this file can never fatal in any load order.
$_gasf_us_enabled = function_exists( 'gasf_site_enabled' )
    ? gasf_site_enabled( 'gasf_site_enable_us_branded' )
    : true; // default ON when gate helper not yet loaded

if ( $_gasf_us_enabled ) {

add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {

    // Guard: only act on URL Shortify admin screens that display short links.
    // Primary target: us_links (Links list). Also cover url_shortify (dashboard)
    // and us_groups (Groups) in case they ever show short-link copy buttons.
    if ( ! isset( $_GET['page'] ) ) {
        return;
    }
    $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
    $allowed_pages = array( 'us_links', 'url_shortify', 'us_groups' );
    if ( ! in_array( $page, $allowed_pages, true ) ) {
        return;
    }

    // Register a no-src script handle so wp_add_inline_script has somewhere to attach.
    // false src is the standard WP pattern for inline-only scripts.
    wp_register_script( 'gasf-us-branded', false, array(), '1.0', true );
    wp_enqueue_script( 'gasf-us-branded' );

    // phpcs:disable
    $js = <<<'INLINEJS'
(function () {
    'use strict';

    var OLD_HOST = 'germantampabay.com';
    var NEW_HOST = 'gtbay.club';

    /**
     * Rewrite a single URL string: host-swap only, path preserved.
     * Tolerates http://, https://, www. prefix.
     * Returns the original string unchanged if it does not contain OLD_HOST.
     */
    function rewriteUrl(url) {
        if (!url || url.indexOf(OLD_HOST) === -1) {
            return url;
        }
        return url.replace(
            /https?:\/\/(?:www\.)?germantampabay\.com\//i,
            'https://' + NEW_HOST + '/'
        );
    }

    /**
     * Rewrite visible text nodes inside an element.
     * Skips SVG subtrees (copy-feedback icons) to avoid mangling icon labels.
     */
    function rewriteTextNodes(el) {
        var walker = document.createTreeWalker(
            el,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function (node) {
                    var parent = node.parentNode;
                    while (parent && parent !== el) {
                        if (parent.nodeName && parent.nodeName.toLowerCase() === 'svg') {
                            return NodeFilter.FILTER_REJECT;
                        }
                        parent = parent.parentNode;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue && node.nodeValue.indexOf(OLD_HOST) !== -1) {
                node.nodeValue = node.nodeValue.replace(
                    /https?:\/\/(?:www\.)?germantampabay\.com\//gi,
                    'https://' + NEW_HOST + '/'
                );
            }
        }
    }

    /**
     * Apply branded rewrite to a single .kc-us-copy-to-clipboard element.
     */
    function applyToElement(el) {
        try {
            // Rewrite the clipboard value (data-clipboard-text attribute)
            var clipVal = el.getAttribute('data-clipboard-text');
            if (clipVal && clipVal.indexOf(NEW_HOST) === -1) {
                el.setAttribute('data-clipboard-text', rewriteUrl(clipVal));
            }

            // If the element itself is an anchor, rewrite its href
            if (el.tagName && el.tagName.toLowerCase() === 'a') {
                var href = el.getAttribute('href');
                if (href && href !== '#' && href.indexOf(OLD_HOST) !== -1) {
                    el.setAttribute('href', rewriteUrl(href));
                }
            }

            // Also rewrite any inner anchor whose href is the short link
            var innerLinks = el.querySelectorAll('a[href]');
            for (var i = 0; i < innerLinks.length; i++) {
                var innerHref = innerLinks[i].getAttribute('href');
                if (innerHref && innerHref.indexOf(OLD_HOST) !== -1) {
                    innerLinks[i].setAttribute('href', rewriteUrl(innerHref));
                }
            }

            // Rewrite visible text nodes (skips SVG children)
            rewriteTextNodes(el);
        } catch (e) {
            // Silently ignore -- never break the admin
        }
    }

    /**
     * Apply rewrite to all .kc-us-copy-to-clipboard elements on the page.
     * If none are found, does nothing (safe no-op).
     */
    function applyAll() {
        try {
            var els = document.querySelectorAll('.kc-us-copy-to-clipboard');
            if (!els || els.length === 0) {
                return;
            }
            for (var i = 0; i < els.length; i++) {
                applyToElement(els[i]);
            }
        } catch (e) {
            // Silently ignore
        }
    }

    // Run on DOMContentLoaded (or immediately if DOM is already ready)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAll);
    } else {
        applyAll();
    }

    // MutationObserver: re-apply if the link list is replaced by AJAX.
    // Guarded with _observing flag so our own DOM writes do not trigger a loop.
    try {
        var listContainer = document.getElementById('the-list') ||
                            document.querySelector('.wp-list-table tbody');
        if (listContainer && typeof MutationObserver !== 'undefined') {
            var _observing = false;
            var observer = new MutationObserver(function (mutations) {
                if (_observing) {
                    return;
                }
                var needsRun = false;
                for (var m = 0; m < mutations.length; m++) {
                    if (mutations[m].addedNodes && mutations[m].addedNodes.length > 0) {
                        needsRun = true;
                        break;
                    }
                }
                if (needsRun) {
                    _observing = true;
                    try {
                        applyAll();
                    } catch (e) {
                        // ignore
                    } finally {
                        _observing = false;
                    }
                }
            });
            observer.observe(listContainer, { childList: true, subtree: true });
        }
    } catch (e) {
        // MutationObserver setup failure is non-fatal
    }

})();
INLINEJS;
    // phpcs:enable

    wp_add_inline_script( 'gasf-us-branded', $js );

} );

} // end if $_gasf_us_enabled
