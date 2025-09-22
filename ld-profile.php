<?php
/**
 * Plugin Name: LearnDash Pexelle Button
 * Description:  LearnDash Pexelle Button
 * Version: 1.0.0
 * Author: Pexelle
 */

if ( ! defined('ABSPATH') ) exit;

class LD_Pexelle_Button {
    const OPT_KEY = 'ld_pexelle_btn_options';

    public function __construct() {
        // settings
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);

        // enqueue on pages that contain [ld_profile]
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('the_content', [$this, 'flag_profile_page'], 5); // detect shortcode in content
    }

    /** detect [ld_profile] to enqueue only when needed */
    private $should_enqueue = false;
    public function flag_profile_page($content) {
        if ( has_shortcode($content, 'ld_profile') ) {
            $this->should_enqueue = true;
        }
        return $content;
    }

    public function enqueue_assets() {
        if ( ! $this->should_enqueue && ! is_page() ) return;

        wp_register_script('ld-pexelle-btn', plugins_url('pexelle-btn.js', __FILE__), [], '1.0.0', true);
        wp_register_style('ld-pexelle-btn', plugins_url('pexelle-btn.css', __FILE__), [], '1.0.0');

        $opts = wp_parse_args( get_option(self::OPT_KEY, []), [
            'label' => 'Pexelle App',
            'url'   => site_url('/app'),
            'target'=> '_blank'
        ]);

        wp_localize_script('ld-pexelle-btn', 'LD_PEXELLE_BTN', [
            'label' => $opts['label'],
            'url'   => $opts['url'],
            'target'=> $opts['target'],
        ]);

        wp_enqueue_script('ld-pexelle-btn');
        wp_enqueue_style('ld-pexelle-btn');
    }

    /** settings */
    public function register_settings() {
        register_setting('ld_pexelle_btn_group', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => function($v){
                return [
                    'label' => isset($v['label']) ? sanitize_text_field($v['label']) : 'Pexelle App',
                    'url'   => isset($v['url']) ? esc_url_raw($v['url']) : site_url('/app'),
                    'target'=> (isset($v['target']) && $v['target']==='_self') ? '_self' : '_blank',
                ];
            },
            'default' => [
                'label' => 'Pexelle App',
                'url'   => site_url('/app'),
                'target'=> '_blank',
            ]
        ]);

        add_settings_section('ld_pexelle_btn_section', 'Pexelle Button', '__return_false', 'ld_pexelle_btn');

        add_settings_field('ld_pexelle_btn_label', 'Button Label', function(){
            $o = get_option(self::OPT_KEY);
            printf('<input type="text" name="%s[label]" value="%s" class="regular-text" />', esc_attr(self::OPT_KEY), esc_attr($o['label'] ?? 'Pexelle App'));
        }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');

        add_settings_field('ld_pexelle_btn_url', 'Button URL', function(){
            $o = get_option(self::OPT_KEY);
            printf('<input type="url" name="%s[url]" value="%s" class="regular-text" />', esc_attr(self::OPT_KEY), esc_attr($o['url'] ?? site_url('/app')));
        }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');

        add_settings_field('ld_pexelle_btn_target', 'Open In', function(){
            $o = get_option(self::OPT_KEY);
            $target = $o['target'] ?? '_blank';
            ?>
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[target]" value="_blank" <?php checked($target, '_blank'); ?> /> New Tab</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[target]" value="_self" <?php checked($target, '_self'); ?> /> Same Tab</label>
            <?php
        }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');
    }

    public function add_settings_page() {
        add_options_page('Pexelle Button', 'Pexelle Button', 'manage_options', 'ld-pexelle-btn', function(){
            echo '<div class="wrap"><h1>Pexelle Button</h1><form method="post" action="options.php">';
            settings_fields('ld_pexelle_btn_group');
            do_settings_sections('ld_pexelle_btn');
            submit_button();
            echo '</form><p> This button added in LearnDash  Certificate Section in Profile .</p></div>';
        });
    }
}
new LD_Pexelle_Button();
