<?php
/**
 * Plugin Name: Pexelle Share for LearnDash
 * Description: Adds a "Share to Pexelle" button next to LearnDash certificate icon, showing a QR modal of the certificate URL.
 * Version: 1.1.5
 * Author: Pexelle
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: psl
 */

if (!defined('ABSPATH')) { exit; }

define('PSL_PLUGIN_FILE', __FILE__);
define('PSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PSL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PSL_VERSION', '1.1.5');

require_once PSL_PLUGIN_DIR . 'inc/class-psl-plugin.php';
require_once PSL_PLUGIN_DIR . 'inc/class-psl-admin.php';
require_once PSL_PLUGIN_DIR . 'inc/class-psl-frontend.php';
require_once PSL_PLUGIN_DIR . 'inc/class-psl-magic-login.php';
require_once PSL_PLUGIN_DIR . 'inc/class-psl-export.php';

add_action('plugins_loaded', static function () {
    if (!class_exists('SFWD_LMS')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Pexelle Share for LearnDash:</strong> LearnDash not detected. Please activate LearnDash.</p></div>';
        });
        return;
    }

    \PSL\Psl_Plugin::init();
    \PSL\Psl_Admin::init();
    \PSL\Psl_Frontend::init();
    \PSL\Psl_Magic_Login::init();
    \PSL\Psl_Export::init();
});
