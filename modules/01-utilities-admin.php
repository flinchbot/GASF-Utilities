<?php
/**
 * Unified "GASF Utilities" admin menu.
 *
 * Registers the single top-level "GASF Utilities" menu that every utility with
 * an admin screen hangs a submenu under (e.g. Home Page Hero) — so there's one
 * place for maintainers to find all the custom germantampabay.com tweaks instead
 * of menu sprawl. Modules add their screens with:
 *   add_submenu_page( 'gasf-utilities', ... )
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Priority 9 so the parent menu exists before module submenus (default prio 10) attach.
add_action( 'admin_menu', function () {
	add_menu_page( 'GASF Utilities', 'GASF Utilities', 'manage_options', 'gasf-utilities', 'gasf_utilities_landing', 'dashicons-admin-tools', 26 );
	add_submenu_page( 'gasf-utilities', 'GASF Utilities', 'Overview', 'manage_options', 'gasf-utilities', 'gasf_utilities_landing' );
}, 9 );

if ( ! function_exists( 'gasf_utilities_landing' ) ) {
	function gasf_utilities_landing() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap">
			<h1>GASF Utilities</h1>
			<p>All the custom germantampabay.com tweaks live here, in <strong>one</strong> must-use plugin. Each tool is a self-contained module; most run automatically (shortcodes &amp; filters) and need no settings. This is the place to look for any custom behavior on the site.</p>

			<h2 class="title">Tools with a settings screen</h2>
			<ul style="list-style:disc;margin-left:22px">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=gasf-hero' ) ); ?>">Home Page Hero</a> &mdash; schedule the home-page hero image (image, caption, button, go-live date).</li>
			</ul>

			<h2 class="title">Automatic modules (no settings needed)</h2>
			<ul style="list-style:disc;margin-left:22px">
				<li><strong>Events (MEC):</strong> Facebook sync fixes, branded single-event template, FB cover de-duplication, recurrence expansion, deleted-event handling.</li>
				<li><strong>Shortcodes:</strong> <code>[gas_hero]</code>, <code>[gas_parking]</code>, <code>[german_dinner_events]</code>, <code>[world_cup_schedule]</code>, <code>[bundesliga_table]</code>, <code>[bundesliga_scorers]</code>, <code>[bundesliga_top_scorers]</code>.</li>
				<li><strong>Site hardening / misc:</strong> REST &amp; author-enumeration block, calendar print button, content 301 redirects.</li>
			</ul>

			<p style="color:#666;margin-top:20px">Managed in code (git-backed, deploy with <code>git pull</code>). Feature gates are <code>gasf_mec_enable_*</code> / <code>gasf_site_enable_*</code> options (default on; set to <code>0</code> to disable a module).</p>
		</div>
		<?php
	}
}
