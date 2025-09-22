<?php
/**
 * Plugin Name: LearnDash Pexelle Button
 * Description: Adds a "Pexelle App" button right next to the Certificate icon on LearnDash profile.
 * Version: 1.0.4
 * Author: You
 */
if (!defined('ABSPATH')) exit;

class LD_Pexelle_Button {
    const OPT_KEY = 'ld_pexelle_btn_options';

    public function __construct() {
        add_action('admin_init',  [$this, 'register_settings']);
        add_action('admin_menu',  [$this, 'add_settings_page']);

        add_action('wp_footer',   [$this, 'print_inline_script'], 99);
        add_action('wp_head',     [$this, 'print_inline_style'],  20);
    }

    public function register_settings() {
        register_setting('ld_pexelle_btn_group', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => function($v){
                return [
                    'label'  => isset($v['label']) ? sanitize_text_field($v['label']) : 'Pexelle App',
                    'url'    => isset($v['url']) ? esc_url_raw($v['url']) : site_url('/app'),
                    'target' => (isset($v['target']) && $v['target']==='_self') ? '_self' : '_blank',
                ];
            },
            'default' => [
                'label'  => 'Pexelle App',
                'url'    => site_url('/app'),
                'target' => '_blank',
            ]
        ]);

        add_settings_section('ld_pexelle_btn_section', 'Pexelle Button', '__return_false', 'ld_pexelle_btn');

        add_settings_field('ld_pexelle_btn_label', 'Button Label', function(){
            $o = get_option(self::OPT_KEY);
            printf('<input type="text" name="%s[label]" value="%s" class="regular-text"/>',
                esc_attr(self::OPT_KEY), esc_attr($o['label'] ?? 'Pexelle App'));
        }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');

        add_settings_field('ld_pexelle_btn_url', 'Button URL', function(){
            $o = get_option(self::OPT_KEY);
            printf('<input type="url" name="%s[url]" value="%s" class="regular-text"/>',
                esc_attr(self::OPT_KEY), esc_attr($o['url'] ?? site_url('/app')));
        }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');

        add_settings_field('ld_pexelle_btn_target', 'Open In', function(){
            $o = get_option(self::OPT_KEY); $t = $o['target'] ?? '_blank'; ?>
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[target]" value="_blank" <?php checked($t,'_blank');?>> New Tab</label>
            &nbsp;&nbsp;
            <label><input type="radio" name="<?php echo esc_attr(self::OPT_KEY); ?>[target]" value="_self"  <?php checked($t,'_self');?>> Same Tab</label>
        <?php }, 'ld_pexelle_btn', 'ld_pexelle_btn_section');
    }

    public function add_settings_page() {
        add_options_page('Pexelle Button', 'Pexelle Button', 'manage_options', 'ld-pexelle-btn', function(){
            echo '<div class="wrap"><h1>Pexelle Button</h1><form method="post" action="options.php">';
            settings_fields('ld_pexelle_btn_group');
            do_settings_sections('ld_pexelle_btn');
            submit_button();

        });
    }

    public function print_inline_style() {
        if (is_admin()) return;
        echo '<style id="ld-pexelle-btn-css">
            .pexelle-app-btn{margin-left:8px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:6px 12px;border-radius:4px;font-weight:600;line-height:1.2}
            .ld-certificate-link+.pexelle-app-btn{vertical-align:middle}
        </style>';
    }

    public function print_inline_script() {
        if (is_admin()) return;
        $o = wp_parse_args(get_option(self::OPT_KEY, []), [
            'label'  => 'Pexelle App',
            'url'    => site_url('/app'),
            'target' => '_blank'
        ]);
        $label  = esc_js($o['label']);
        $url    = esc_url($o['url']);
        $target = ($o['target']==='_self') ? '_self' : '_blank';

        ?>
<script id="ld-pexelle-btn-js">
(function(){
  var CONFIG = {label:"<?php echo $label; ?>", url:"<?php echo $url; ?>", target:"<?php echo $target; ?>"};

  function injectOnce(cert){
    if(!cert || !cert.parentNode) return;
    var next = cert.nextElementSibling;
    if(next && next.classList && next.classList.contains('pexelle-app-btn')) return;

    var a = document.createElement('a');
    a.className = 'ld-button ld-button-primary pexelle-app-btn';
    a.href = CONFIG.url; a.target = CONFIG.target; a.rel = 'noopener';
    a.textContent = CONFIG.label;
    cert.insertAdjacentElement('afterend', a);
  }

  function scan(){
    var nodes = document.querySelectorAll('a.ld-certificate-link, a[aria-label="Certificate"]');
    if(nodes.length){ nodes.forEach(injectOnce); }
  }

  if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
  window.addEventListener('load', scan, {once:false});

  var mo = new MutationObserver(scan);
  mo.observe(document.body, {childList:true, subtree:true});

  var n=0, iv=setInterval(function(){ scan(); if(++n>60) clearInterval(iv); }, 1000);

  window.addEventListener('scroll', scan, {passive:true});
})();
</script>
        <?php
    }
}
new LD_Pexelle_Button();
