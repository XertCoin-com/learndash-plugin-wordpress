=== Pexelle for LearnDash ===
Contributors: pexelle
Donate link: https://pexelle.com
Tags: learndash, certificate, qr code, pexelle, share
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect LearnDash with Pexelle via QR codes. Share, verify, and export certificates with secure JSON/PDF handoff.

== Description ==

Connect LearnDash to Pexelle via secure QR handoff. Share and verify certificates, or export clean JSON for integrations.


Key features:
- 🔗 **Seamless integration** with LearnDash courses  
- 📱 **QR code login & sharing** (device handoff support)  
- 🎓 **Certificate transfer** to Pexelle infrastructure  
- 🔒 **Secure one-time login flow** with approval system  

This plugin is ideal for educators and institutions who want to make certificate verification **modern, secure, and easy to share**.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/pexelle-learndash` directory, or install it directly from the WordPress plugin repository.
2. Activate the plugin through the **'Plugins'** screen in WordPress.
3. Navigate to your LearnDash courses and configure QR sharing options.
4. Done! Certificates can now be scanned and shared with Pexelle.

== Frequently Asked Questions ==

= Does this plugin require LearnDash? =
Yes. You must have **LearnDash LMS** installed and active.

= Is the QR secure? =
Yes. The QR codes generate a **short-lived, one-time token** and require approval from the user’s main device before login is completed.

= Can I customize the modal and design? =
Yes. The plugin includes frontend CSS classes (`psl-modal`, `psl-btn`, etc.) that you can override in your theme or child theme.

= Does it work with multisite? =
Currently tested on single-site WordPress. Multisite support is planned.

== Screenshots ==

1. Example QR modal for certificate sharing
2. Device handoff waiting screen
3. Certificate transfer confirmation

== Changelog ==
= 1.2.6 =
* Sanitized all `$_GET['course_id']` lookups with `absint( wp_unslash() )` to resolve PHPCS security warnings.
* Removed remaining usage of `suppress_filters` in WP_Query arguments (not allowed in WordPress.org standards).
* Added targeted `phpcs:ignore` comments for LearnDash-required `meta_query` usage, with explanations.
* Optimized fallback queries with `fields => 'ids'`, disabled caching, and no_found_rows to minimize load.
* Finalized short description under 150 characters for WordPress.org parser compliance.
* General compliance hardening: passed Plugin Check and WordPress.org PHPCS scans without blocking errors.

= 1.2.5 =
* Sanitized all `$_GET` and `$_POST` inputs with `wp_unslash()` + `sanitize_text_field()` / `absint()` for strict security compliance.
* Removed `suppress_filters => true` from WP_Query calls to meet WordPress.org and VIP coding standards.
* Optimized query arguments (`fields => ids`, `no_found_rows`, `update_post_meta_cache` disabled) for performance.
* Shortened plugin short description to be under 150 characters (required for WordPress.org parser).
* Added inline PHPCS ignore comments for unavoidable `meta_query` usage (LearnDash dependency).
* General code cleanup and compliance improvements for plugin repository review.

= 1.2.4 =
* Added full nonce verification and wp_unslash() handling for all AJAX and GET/POST inputs.
* Secured Magic Login flow with bridge nonce to prevent CSRF-style misuse.
* Updated frontend JS (psl-frontend.js) to include and send `ajaxNonce` with AJAX requests.
* Optimized database queries with `fields => ids`, disabled meta/term cache for faster response.
* Improved code compliance with WordPress Plugin Check (PHPCS).
* Shortened plugin description for WordPress.org readme parser (≤150 chars).
* Maintenance release — focused on passing automated + manual plugin review checks.

= 1.2.3 =
* Aligned Text Domain with plugin slug: pexelle-for-learndash.
* Removed discouraged load_plugin_textdomain() (WP ≥ 4.6 auto-loads translations on wp.org).
* Ensured /languages/ directory exists (POT scaffold ready).
* Updated all i18n calls to the new text domain across templates/admin.
* Readme updates: Tested up to: 6.8, Stable tag: 1.2.3.
* No functional changes; maintenance release to pass automated plugin checks.

= 1.2.2 =
* Added secure token bridge for JSON/PDF certificate handoff
* Improved frontend modal styling
* Added countdown timer to waiting page

= 1.2.1 =
* Fixed minor LearnDash course ID parsing bug
* Updated translations

= 1.2.0 =
* Initial public release with QR-based certificate sharing

== Upgrade Notice ==

= 1.2.2 =
Important security and UX improvements. Please update immediately.
