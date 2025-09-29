# Pexelle for LearnDash

Connect LearnDash with the **Pexelle application infrastructure** through course QR scanning.  
Seamlessly transfer your **certificates** and **courses** to Pexelle.

![Pexelle QR Modal Example](assets/screenshot-1.png)

---

## ✨ Features

- 🔗 **Seamless integration** with LearnDash courses  
- 📱 **QR code login & sharing** (device handoff support)  
- 🎓 **Certificate transfer** to Pexelle infrastructure  
- 🔒 **Secure one-time login flow** with approval system  

---

## 📥 Installation

1. Upload the plugin files to the `/wp-content/plugins/pexelle-learndash` directory, or install it directly from the WordPress plugin repository.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to your LearnDash courses and configure QR sharing options.
4. Done! Certificates can now be scanned and shared with Pexelle.

---

## ❓ FAQ

### Does this plugin require LearnDash?
Yes. You must have **LearnDash LMS** installed and active.

### Is the QR secure?
Yes. The QR codes generate a **short-lived, one-time token** and require approval from the user’s main device before login is completed.

### Can I customize the modal and design?
Yes. The plugin includes frontend CSS classes (`psl-modal`, `psl-btn`, etc.) that you can override in your theme or child theme.

### Does it work with multisite?
Currently tested on single-site WordPress. Multisite support is planned.

---

## 📸 Screenshots

1. Example QR modal for certificate sharing  
2. Device handoff waiting screen  
3. Certificate transfer confirmation  

---

## 📦 Changelog

### 1.2.2
- Added secure token bridge for JSON/PDF certificate handoff
- Improved frontend modal styling
- Added countdown timer to waiting page

### 1.2.1
- Fixed minor LearnDash course ID parsing bug
- Updated translations

### 1.2.0
- Initial public release with QR-based certificate sharing

---

## ⚖️ License

This plugin is licensed under the **GPLv2 or later**.  
See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

---

## 👤 Author

**Pexelle**  
[https://pexelle.com](https://pexelle.com)

