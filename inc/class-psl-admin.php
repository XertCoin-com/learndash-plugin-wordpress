<?php
namespace PSL;

if (!defined('ABSPATH')) { exit; }

final class Psl_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_settings_page() {
        add_options_page(
            __('Pexelle Share', 'psl'),
            __('Pexelle Share', 'psl'),
            'manage_options',
            'psl-share',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(
            'psl_share_group',
            Psl_Plugin::OPTION_KEY,
            ['sanitize_callback' => [__CLASS__, 'sanitize_settings']]
        );

        add_settings_section('psl_main', __('Main Settings', 'psl'), '__return_false', 'psl-share');

        add_settings_field('enabled', __('Enable Feature', 'psl'), [__CLASS__, 'field_enabled'], 'psl-share', 'psl_main');
        add_settings_field('button_text', __('Button Text', 'psl'), [__CLASS__, 'field_button_text'], 'psl-share', 'psl_main');
        add_settings_field('help_install_url', __('"How to install Pexelle?" URL', 'psl'), [__CLASS__, 'field_help_install'], 'psl-share', 'psl_main');
        add_settings_field('help_how_url', __('"How Pexelle works?" URL', 'psl'), [__CLASS__, 'field_help_how'], 'psl-share', 'psl_main');
    }

    public static function sanitize_settings($input) {
        $out = [];
        $out['enabled'] = isset($input['enabled']) ? 1 : 0;
        $out['button_text'] = isset($input['button_text']) ? sanitize_text_field($input['button_text']) : 'Share to Pexelle';
        $out['help_install_url'] = isset($input['help_install_url']) ? esc_url_raw($input['help_install_url']) : '';
        $out['help_how_url'] = isset($input['help_how_url']) ? esc_url_raw($input['help_how_url']) : '';
        return $out;
    }

public static function render_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    $opts = get_option(Psl_Plugin::OPTION_KEY, []);

    $logo_url = plugins_url('assets/images/cropped-unido-logoFS2.png', dirname(__FILE__));
    ?>
    <div class="wrap">
        <div style="margin-bottom:20px;">
            <img src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Pexelle Logo', 'psl'); ?>" style="max-width:100%; height:auto; border-radius:8px;"/>
        </div>

        <h1><?php esc_html_e('Pexelle for LearnDash', 'psl'); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields('psl_share_group'); ?>
            <?php do_settings_sections('psl-share'); ?>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
    
    public static function field_enabled() {
        $opts = get_option(Psl_Plugin::OPTION_KEY, []);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(Psl_Plugin::OPTION_KEY); ?>[enabled]" value="1" <?php checked(1, isset($opts['enabled']) ? $opts['enabled'] : 0); ?> />
            <?php esc_html_e('Enable "Share to Pexelle" button & modal', 'psl'); ?>
        </label>
        <?php
    }

    public static function field_button_text() {
        $opts = get_option(Psl_Plugin::OPTION_KEY, []);
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr(Psl_Plugin::OPTION_KEY); ?>[button_text]" value="<?php echo esc_attr($opts['button_text'] ?? 'Share to Pexelle'); ?>" />
        <?php
    }

    public static function field_help_install() {
        $opts = get_option(Psl_Plugin::OPTION_KEY, []);
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr(Psl_Plugin::OPTION_KEY); ?>[help_install_url]" value="<?php echo esc_attr($opts['help_install_url'] ?? ''); ?>" />
        <?php
    }

    public static function field_help_how() {
        $opts = get_option(Psl_Plugin::OPTION_KEY, []);
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr(Psl_Plugin::OPTION_KEY); ?>[help_how_url]" value="<?php echo esc_attr($opts['help_how_url'] ?? ''); ?>" />
        <?php
    }
}
