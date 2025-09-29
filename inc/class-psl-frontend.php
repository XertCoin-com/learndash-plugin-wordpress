<?php
namespace PSL;

if (!defined('ABSPATH')) { exit; }

final class Psl_Frontend {

    public static function init() {
        add_action('wp', [__CLASS__, 'maybe_hook_assets']);
        add_action('wp_footer', [__CLASS__, 'inject_modal'], 20);
    }
  
    public static function maybe_hook_assets() {
        if (!self::is_enabled()) { return; }

        global $post;
        $has_ld_profile = false;

        if (is_a($post, 'WP_Post')) {
            $has_ld_profile = has_shortcode($post->post_content ?? '', 'ld_profile');
        }
        if ($has_ld_profile || is_user_logged_in()) {
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }
    }

    public static function enqueue_assets() {
        wp_register_style('psl-frontend', PSL_PLUGIN_URL . 'assets/css/psl-frontend.css', [], PSL_VERSION);
        wp_enqueue_style('psl-frontend');
        wp_register_script('psl-qrcode', PSL_PLUGIN_URL . 'assets/js/qrcode.min.js', [], PSL_VERSION, true);
        wp_enqueue_script('psl-qrcode');
        wp_register_script('psl-frontend', PSL_PLUGIN_URL . 'assets/js/psl-frontend.js', ['psl-qrcode'], PSL_VERSION, true);

        $data = [
            'buttonText'  => Psl_Plugin::get_option('button_text', 'Share to Pexelle'),
            'helpInstall' => Psl_Plugin::get_option('help_install_url', ''),
            'helpHow'     => Psl_Plugin::get_option('help_how_url', ''),
            'certSelector'=> '.ld-certificate-link',
            'ajax_url'    => admin_url('admin-ajax.php'),
            'site_url'    => home_url('/'),
        ];
        wp_localize_script('psl-frontend', 'PSL_SETTINGS', $data);
        wp_enqueue_script('psl-frontend');
    }

    public static function inject_modal() {
        if (!self::is_enabled()) { return; }
        if (!is_admin()) {
            include PSL_PLUGIN_DIR . 'templates/modal.php';
        }
    }

    private static function is_enabled(): bool {
        return (bool) Psl_Plugin::get_option('enabled', 1);
    }
}
