<?php
namespace PSL;

if (!defined('ABSPATH')) { exit; }

final class Psl_Plugin {

    const OPTION_KEY = 'psl_share_settings';
    const NONCE_KEY  = 'psl_share_nonce';

    public static function init() {
        add_action('init', [__CLASS__, 'maybe_set_defaults']);
        add_action('init', [__CLASS__, 'load_textdomain']);
    }

    public static function load_textdomain() {
        load_plugin_textdomain('psl', false, dirname(plugin_basename(PSL_PLUGIN_FILE)) . '/languages/');
    }

    public static function maybe_set_defaults() {
        $opts = get_option(self::OPTION_KEY);
        if (!$opts || !is_array($opts)) {
            $defaults = [
                'enabled'         => 1,
                'button_text'     => 'Share to Pexelle',
                'help_install_url'=> 'https://pexelle.com/install',
                'help_how_url'    => 'https://pexelle.com/how-it-works',
            ];
            add_option(self::OPTION_KEY, $defaults, '', false);
        }
    }

    public static function get_option($key, $default = null) {
        $opts = get_option(self::OPTION_KEY, []);
        return isset($opts[$key]) ? $opts[$key] : $default;
    }

    public static function update_options($data) {
        update_option(self::OPTION_KEY, $data);
    }
}
