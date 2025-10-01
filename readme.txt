=== Pexelle for LearnDash ===
Contributors: pexelle
Donate link: https://pexelle.com
Tags: learndash, certificate, qr code, pexelle, share
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect LearnDash with the Pexelle application infrastructure through course QR scanning. Seamlessly transfer your certificates and courses to Pexelle.

== Description ==

**Pexelle for LearnDash** allows you to extend LearnDash with QR-powered certificate sharing.  
Students can scan a course QR code and securely connect their certificates to the **Pexelle app**.  

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
