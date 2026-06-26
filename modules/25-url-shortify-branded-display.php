<?php
/**
 * Module 25 — URL Shortify Branded Display (per-link domain store, decoupled from lite).
 * Gate: gasf_site_enable_us_branded (default ON).
 * Task: 260626-7p3 v3 (2026-06-26).
 * Purpose: Per-link gtbay.club branding for URL Shortify — own store, kc_us_link_created
 *          default, list-row dropdown via admin-ajax, scoped display rewrite.
 * Safe: no plugin PHP edits, no DB-schema changes, no home/siteurl changes, no redirects.
 * Approach: OWN wp_option `gasf_us_link_domains` stores the set of link ids branded
 *           gtbay.club (absence = germantampabay.com, default for existing links).
 *           New links auto-default to gtbay via the kc_us_link_created hook.
 *           Per-row dropdown in the Links list persists per-link choice via wp_ajax endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Gate: fail-safe fall back to ON when gasf_site_enabled() not yet loaded.
$_gasf_us_enabled = function_exists( 'gasf_site_enabled' )
    ? gasf_site_enabled( 'gasf_site_enable_us_branded' )
    : true;

if ( ! $_gasf_us_enabled ) {
    return;
}

// ---------------------------------------------------------------------------
// STORE HELPERS
// ---------------------------------------------------------------------------

/**
 * Return the array of link ids (int) that are branded gtbay.club.
 * Absence from the set means germantampabay.com (default for existing links).
 *
 * @return int[]
 */
function gasf_us_get_branded_ids() {
    $opt = get_option( 'gasf_us_link_domains', array() );
    if ( ! is_array( $opt ) ) {
        return array();
    }
    return array_map( 'intval', array_keys( $opt ) );
}

/**
 * Add or remove a link id from the branded-gtbay set.
 *
 * @param int    $id     Link id.
 * @param string $domain 'gtbay' to brand as gtbay.club, 'home' to remove branding.
 * @return bool  True if now branded gtbay; false if now germantampabay (home).
 */
function gasf_us_set_link_domain( $id, $domain ) {
    $id  = absint( $id );
    $opt = get_option( 'gasf_us_link_domains', array() );
    if ( ! is_array( $opt ) ) {
        $opt = array();
    }
    if ( 'gtbay' === $domain ) {
        $opt[ $id ] = 'gtbay';
        update_option( 'gasf_us_link_domains', $opt, false );
        return true;
    } else {
        unset( $opt[ $id ] );
        update_option( 'gasf_us_link_domains', $opt, false );
        return false;
    }
}

// ---------------------------------------------------------------------------
// DEFAULT NEW LINKS TO GTBAY (server hook)
// ---------------------------------------------------------------------------

/**
 * When URL Shortify creates a new link, default it to gtbay.club in our store.
 * $saved = $wpdb->insert_id (confirmed from BaseDB::insert) — positive int on success.
 */
add_action( 'kc_us_link_created', function ( $saved ) {
    try {
        $id = absint( $saved );

        // Primary path: $saved is the new link id.
        if ( $id > 0 ) {
            // Verify the id exists in the links table (belt + suspenders).
            global $wpdb;
            $table  = $wpdb->prefix . 'kc_us_links';
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id = %d LIMIT 1", $id ) );
            if ( $exists ) {
                gasf_us_set_link_domain( $id, 'gtbay' );
                return;
            }
        }

        // Fallback: resolve by latest id in the links table.
        global $wpdb;
        $table      = $wpdb->prefix . 'kc_us_links';
        $latest_id  = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" );
        if ( $latest_id > 0 ) {
            gasf_us_set_link_domain( $latest_id, 'gtbay' );
        }
    } catch ( \Exception $e ) {
        // Silent no-op — never break admin.
    }
} );

// ---------------------------------------------------------------------------
// ADMIN-AJAX: per-row domain change
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_gasf_us_set_link_domain', function () {
    try {
        // Nonce + capability check.
        if ( ! check_ajax_referer( 'gasf_us_branded', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Bad nonce' ), 403 );
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
            return;
        }

        // Sanitize inputs.
        $id     = absint( $_POST['id'] ?? 0 );
        $domain = sanitize_key( $_POST['domain'] ?? '' );

        if ( $id <= 0 || ! in_array( $domain, array( 'gtbay', 'home' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid input' ), 400 );
            return;
        }

        gasf_us_set_link_domain( $id, $domain );

        wp_send_json_success( array( 'id' => $id, 'domain' => $domain ) );
    } catch ( \Exception $e ) {
        wp_send_json_error( array( 'message' => 'Server error' ), 500 );
    }
} );

// ---------------------------------------------------------------------------
// ADMIN ENQUEUE + JS — scoped to page=us_links ONLY
// ---------------------------------------------------------------------------

add_action( 'admin_enqueue_scripts', function () {

    // Only act on the URL Shortify Links screen.
    if ( ! isset( $_GET['page'] ) || 'us_links' !== $_GET['page'] ) {
        return;
    }

    try {
        $branded_ids = gasf_us_get_branded_ids();
    } catch ( \Exception $e ) {
        $branded_ids = array();
    }

    // Register a no-src handle (standard WP pattern for inline-only scripts).
    wp_register_script( 'gasf-us-branded', false, array(), '2.0', true );
    wp_enqueue_script( 'gasf-us-branded' );

    // Localize: branded ids + ajax url + nonce + canonical hosts.
    // Uses wp_add_inline_script 'before' so the var exists when the IIFE runs.
    $data = array(
        'ids'     => array_values( $branded_ids ), // int[]
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'gasf_us_branded' ),
        'home'    => 'https://germantampabay.com',
        'gtbay'   => 'https://gtbay.club',
    );
    wp_add_inline_script(
        'gasf-us-branded',
        'var GASF_US = ' . wp_json_encode( $data ) . ';',
        'before'
    );

    // phpcs:disable
    $js = <<<'INLINEJS'
(function () {
    'use strict';

    var CFG     = (typeof GASF_US !== 'undefined') ? GASF_US : {};
    var IDS     = CFG.ids     || [];
    var AJAXURL = CFG.ajaxurl || '';
    var NONCE   = CFG.nonce   || '';
    var HOME    = CFG.home    || 'https://germantampabay.com';
    var GTBAY   = CFG.gtbay  || 'https://gtbay.club';

    var HOME_PATTERN  = /https?:\/\/(?:www\.)?germantampabay\.com\//i;
    var GTBAY_PATTERN = /https?:\/\/(?:www\.)?gtbay\.club\//i;

    // -----------------------------------------------------------------------
    // URL helpers
    // -----------------------------------------------------------------------

    /** Swap from germantampabay.com -> gtbay.club. Returns null if no change needed. */
    function toGtbay(url) {
        if (!url) { return null; }
        if (GTBAY_PATTERN.test(url)) { return null; }     // already gtbay
        if (HOME_PATTERN.test(url))  { return url.replace(HOME_PATTERN, GTBAY + '/'); }
        return null;
    }

    /** Swap from gtbay.club -> germantampabay.com. Returns null if no change needed. */
    function toHome(url) {
        if (!url) { return null; }
        if (HOME_PATTERN.test(url))  { return null; }     // already home
        if (GTBAY_PATTERN.test(url)) { return url.replace(GTBAY_PATTERN, HOME + '/'); }
        return null;
    }

    /**
     * Rewrite visible text nodes inside el (skips SVG subtrees and .kc-us-link inputs).
     */
    function rewriteTextNodes(el, transformFn) {
        try {
            var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
                acceptNode: function (node) {
                    var p = node.parentNode;
                    // Skip SVG subtrees
                    while (p && p !== el) {
                        if (p.nodeName && p.nodeName.toLowerCase() === 'svg') {
                            return NodeFilter.FILTER_REJECT;
                        }
                        // Skip .kc-us-link inputs (they show only /slug)
                        if (p.classList && p.classList.contains('kc-us-link')) {
                            return NodeFilter.FILTER_REJECT;
                        }
                        p = p.parentNode;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            });
            var node;
            while ((node = walker.nextNode())) {
                if (node.nodeValue) {
                    var val = node.nodeValue;
                    var newVal = null;
                    if (HOME_PATTERN.test(val) || GTBAY_PATTERN.test(val)) {
                        newVal = transformFn(val);
                    }
                    if (newVal !== null) { node.nodeValue = newVal; }
                }
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Apply host-swap to a .kc-us-copy-to-clipboard element.
     * Rewrites: data-clipboard-text, any inner anchor hrefs, visible text nodes.
     * Does NOT touch .kc-us-link inputs.
     */
    function rewriteEl(el, transformFn) {
        if (!el) { return; }
        try {
            var clip = el.getAttribute('data-clipboard-text');
            if (clip) {
                var nc = transformFn(clip);
                if (nc !== null) { el.setAttribute('data-clipboard-text', nc); }
            }

            // Inner anchors (copy anchor is the element itself in column_title)
            if (el.tagName && el.tagName.toLowerCase() === 'a') {
                var href = el.getAttribute('href');
                if (href && href !== '#') {
                    var nh = transformFn(href);
                    if (nh !== null) { el.setAttribute('href', nh); }
                }
            }
            var links = el.querySelectorAll('a[href]');
            for (var i = 0; i < links.length; i++) {
                var h = links[i].getAttribute('href');
                if (h && h !== '#') {
                    var nnh = transformFn(h);
                    if (nnh !== null) { links[i].setAttribute('href', nnh); }
                }
            }

            rewriteTextNodes(el, transformFn);
        } catch (e) { /* never break admin */ }
    }

    /** Rewrite both copy elements for a link id to gtbay. */
    function rewriteToGtbay(id) {
        rewriteEl(document.getElementById('copy-link-' + id), toGtbay);
        rewriteEl(document.getElementById('link-'      + id), toGtbay);
    }

    /** Rewrite both copy elements for a link id back to home. */
    function rewriteToHome(id) {
        rewriteEl(document.getElementById('copy-link-' + id), toHome);
        rewriteEl(document.getElementById('link-'      + id), toHome);
    }

    // -----------------------------------------------------------------------
    // Per-row domain dropdown
    // -----------------------------------------------------------------------

    /**
     * Inject a domain <select> into the row that contains #copy-link-<id>.
     * Placed immediately after the #link-<id> span inside the Link column cell,
     * or appended to the cell that holds #copy-link-<id> if #link-<id> is absent.
     * Guarded against double-injection.
     */
    function injectDropdown(id) {
        try {
            var copyEl = document.getElementById('copy-link-' + id);
            var linkEl = document.getElementById('link-'      + id);

            // Find the row
            var row = copyEl ? copyEl.closest('tr') : null;
            if (!row) { return; }

            // Guard: skip if dropdown already injected in this row
            if (row.querySelector('.gasf-us-domain')) { return; }

            var isBranded = IDS.indexOf(id) !== -1;

            var sel = document.createElement('select');
            sel.className      = 'gasf-us-domain';
            sel.dataset.id     = id;
            sel.style.cssText  = 'margin-left:6px;font-size:11px;padding:2px 4px;border:1px solid #ccc;border-radius:3px;cursor:pointer;';
            sel.title          = 'Short URL domain for this link';

            var optHome  = document.createElement('option');
            optHome.value = 'home';
            optHome.textContent = 'germantampabay.com';
            if (!isBranded) { optHome.selected = true; }

            var optGtbay = document.createElement('option');
            optGtbay.value = 'gtbay';
            optGtbay.textContent = 'gtbay.club';
            if (isBranded) { optGtbay.selected = true; }

            sel.appendChild(optHome);
            sel.appendChild(optGtbay);

            sel.addEventListener('change', function () {
                var chosenDomain = this.value;
                var linkId       = parseInt(this.dataset.id, 10);

                // Optimistic UI update
                if (chosenDomain === 'gtbay') {
                    // Add to local ids
                    if (IDS.indexOf(linkId) === -1) { IDS.push(linkId); }
                    rewriteToGtbay(linkId);
                } else {
                    // Remove from local ids
                    var idx = IDS.indexOf(linkId);
                    if (idx !== -1) { IDS.splice(idx, 1); }
                    rewriteToHome(linkId);
                }

                // Persist via our own ajax endpoint
                try {
                    var body = new URLSearchParams();
                    body.append('action', 'gasf_us_set_link_domain');
                    body.append('id',     linkId);
                    body.append('domain', chosenDomain);
                    body.append('nonce',  NONCE);

                    fetch(AJAXURL, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) {
                                // Roll back on server error
                                if (chosenDomain === 'gtbay') {
                                    var ridx = IDS.indexOf(linkId);
                                    if (ridx !== -1) { IDS.splice(ridx, 1); }
                                    rewriteToHome(linkId);
                                } else {
                                    if (IDS.indexOf(linkId) === -1) { IDS.push(linkId); }
                                    rewriteToGtbay(linkId);
                                }
                                // Reset the dropdown visual state
                                var el = row.querySelector('.gasf-us-domain');
                                if (el) { el.value = chosenDomain === 'gtbay' ? 'home' : 'gtbay'; }
                            }
                        })
                        .catch(function () { /* network error — state may be inconsistent; next reload corrects */ });
                } catch (e) { /* fetch not available — no-op */ }
            });

            // Place the dropdown: after #link-<id> if present, else after #copy-link-<id>
            var anchor = linkEl || copyEl;
            if (anchor && anchor.parentNode) {
                anchor.parentNode.insertBefore(sel, anchor.nextSibling);
            }
        } catch (e) { /* silent */ }
    }

    // -----------------------------------------------------------------------
    // LIST VIEW: apply rewrites + inject dropdowns
    // -----------------------------------------------------------------------

    /**
     * Discover all link ids present in the rendered table (via #copy-link- ids)
     * then inject dropdowns for all, and apply rewrites for branded ids.
     */
    function applyAll() {
        try {
            // Apply gtbay rewrite for all currently branded ids
            for (var i = 0; i < IDS.length; i++) {
                rewriteToGtbay(IDS[i]);
            }

            // Inject per-row dropdown for every link in the table
            var copyEls = document.querySelectorAll('[id^="copy-link-"]');
            for (var j = 0; j < copyEls.length; j++) {
                var idStr = copyEls[j].id.replace('copy-link-', '');
                var lid   = parseInt(idStr, 10);
                if (!isNaN(lid)) {
                    injectDropdown(lid);
                }
            }
        } catch (e) { /* ignore */ }
    }

    // -----------------------------------------------------------------------
    // NEW/EDIT FORM VIEW: rewrite the static Short URL prefix span
    // -----------------------------------------------------------------------

    function applyFormPrefix() {
        try {
            // The prefix span has a distinctive class combo:
            //   "inline-flex items-center px-3 text-gray-500 bg-gray-200 border border-r-0
            //    border-gray-400 rounded-l-md sm:text-sm"
            // It contains the $blog_url text and nothing else of substance.
            var prefixSpans = document.querySelectorAll(
                'span.inline-flex.items-center.px-3.text-gray-500.bg-gray-200'
            );
            for (var i = 0; i < prefixSpans.length; i++) {
                var span = prefixSpans[i];
                var txt  = span.textContent || '';
                // Only act on spans that clearly show germantampabay.com
                if (HOME_PATTERN.test(txt)) {
                    // Determine which form we're on from the page action.
                    var urlParams  = new URLSearchParams(window.location.search);
                    var pageAction = urlParams.get('action') || '';
                    var editId     = parseInt(urlParams.get('id') || '0', 10);

                    if (pageAction === 'new') {
                        // New link => default gtbay; show gtbay prefix
                        span.textContent = txt.replace(HOME_PATTERN, GTBAY + '/');
                    } else if (pageAction === 'edit' && editId > 0) {
                        // Edit: show gtbay prefix only if this id is in our branded set
                        if (IDS.indexOf(editId) !== -1) {
                            span.textContent = txt.replace(HOME_PATTERN, GTBAY + '/');
                        }
                        // else leave as germantampabay.com
                    }
                }
            }
        } catch (e) { /* silent */ }
    }

    // -----------------------------------------------------------------------
    // INIT
    // -----------------------------------------------------------------------

    function init() {
        try {
            applyAll();
            applyFormPrefix();
        } catch (e) { /* ignore */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // MutationObserver: re-apply after AJAX table re-render (debounced).
    try {
        var listContainer = document.getElementById('the-list') ||
                            document.querySelector('.wp-list-table tbody');
        if (listContainer && typeof MutationObserver !== 'undefined') {
            var _busy = false;
            var _timer;
            var observer = new MutationObserver(function (mutations) {
                if (_busy) { return; }
                var hasAdded = false;
                for (var m = 0; m < mutations.length; m++) {
                    if (mutations[m].addedNodes && mutations[m].addedNodes.length > 0) {
                        hasAdded = true;
                        break;
                    }
                }
                if (!hasAdded) { return; }
                clearTimeout(_timer);
                _timer = setTimeout(function () {
                    _busy = true;
                    try { applyAll(); } catch (e) { /* ignore */ } finally { _busy = false; }
                }, 50);
            });
            observer.observe(listContainer, { childList: true, subtree: true });
        }
    } catch (e) { /* MutationObserver setup failure is non-fatal */ }

})();
INLINEJS;
    // phpcs:enable

    wp_add_inline_script( 'gasf-us-branded', $js );

} );
