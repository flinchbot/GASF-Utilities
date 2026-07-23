<?php
/**
 * Friendly 404 template — served by modules/31-redirects.php via a
 * `template_include` filter (only when the file exists + feature is on).
 *
 * Lives in modules/templates/ (NOT modules/ root) on purpose: the plugin's
 * module loader globs `modules/*.php` and require_once's each match, so a
 * template placed directly in modules/ would be EXECUTED at load time. The
 * subdirectory keeps it out of that glob; it's included only as a template.
 *
 * Keeps the real 404 status (good SEO — a "not found" must stay 404, never
 * 200/redirect), wraps the theme's own header/footer, and shows the upcoming
 * events list so a visitor who hit a gone/mistyped URL still lands somewhere
 * useful. The events CSS is force-enqueued in 31-redirects.php on is_404().
 *
 * @package GASF_Utilities
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

status_header( 404 );
nocache_headers();

get_header();
?>
<div class="gasf-404" style="max-width:860px;margin:48px auto 64px;padding:0 22px;text-align:center">
	<p style="font-size:14px;letter-spacing:.14em;text-transform:uppercase;color:#a4161a;font-weight:700;margin:0 0 8px">Hoppla! &mdash; Page not found</p>
	<h1 style="font-size:2.1rem;line-height:1.2;margin:0 0 14px">That page isn&rsquo;t here</h1>
	<p style="font-size:1.12rem;line-height:1.6;color:#444;max-width:640px;margin:0 auto 26px">
		We couldn&rsquo;t find what you were looking for &mdash; it may have moved, or the event may have already happened.
		But there&rsquo;s always something good coming up at the German-American Society. Take a look!
	</p>
	<p style="margin:0 0 40px">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display:inline-block;padding:11px 28px;margin:5px;border-radius:6px;background:#a4161a;color:#fff;text-decoration:none;font-weight:600">Home</a>
		<a href="<?php echo esc_url( home_url( '/calendar-of-events/' ) ); ?>" style="display:inline-block;padding:11px 28px;margin:5px;border-radius:6px;background:#2f3c7e;color:#fff;text-decoration:none;font-weight:600">Full calendar</a>
	</p>
	<h2 style="font-size:1.45rem;margin:0 0 18px">Upcoming events</h2>
	<div style="text-align:left">
		<?php
		// do_shortcode returns the events list HTML; the CSS is enqueued on
		// is_404() by the module (mark_needed) so this renders styled.
		echo do_shortcode( '[gasf_events view=list limit=6 filter=off]' ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</div>
</div>
<?php
get_footer();
