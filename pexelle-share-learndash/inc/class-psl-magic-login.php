<?php
namespace PSL;

if (!defined('ABSPATH')) { exit; }

final class Psl_Magic_Login {
    const REQ_TTL = 600;
    const K_PREFIX = 'psl_ml_'; // transient key prefix

    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'register_qv']);
        add_action('template_redirect', [__CLASS__, 'route_pages']);

        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        add_action('wp_ajax_psl_magic_create', [__CLASS__, 'ajax_create_request']);
        add_action('init', [__CLASS__, 'maybe_handle_approve_link']);
    }

    private static function tk($id){ return self::K_PREFIX . $id; }

    private static function create_request(int $user_id, string $redirect_url) {
        $id = wp_generate_password(20, false, false); 
        $rec = [
            'user_id'   => $user_id,
            'created'   => time(),
            'expires'   => time() + self::REQ_TTL,
            'approved'  => false,
            'consumed'  => false,
            'redirect'  => esc_url_raw($redirect_url),
        ];
        set_transient(self::tk($id), $rec, self::REQ_TTL);
        return $id;
    }

    private static function get_req($id){ return get_transient(self::tk($id)); }
    private static function set_req($id, $rec){ return set_transient(self::tk($id), $rec, max(1, $rec['expires'] - time())); }
    private static function del_req($id){ return delete_transient(self::tk($id)); }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^psl/magic/([^/]+)/?$', 'index.php?psl_magic=$matches[1]', 'top');
        add_rewrite_rule('^psl/magic/consume/([^/]+)/?$', 'index.php?psl_magic_consume=$matches[1]', 'top');
    }
    public static function register_qv($vars){
        $vars[] = 'psl_magic';
        $vars[] = 'psl_magic_consume';
        $vars[] = 'psl_magic_approve';
        $vars[] = 'psl_magic_nonce';
        return $vars;
    }

    public static function route_pages() {
        $id = get_query_var('psl_magic');
        if ($id) {
            $rec = self::get_req($id);
            status_header(200);
            nocache_headers();
            if (!$rec) {
                echo '<h2>Link expired or invalid.</h2>';
                exit;
            }
            $consume_url = home_url('/psl/magic/consume/' . rawurlencode($id));
            $status_url  = rest_url('psl/v1/magic/status/' . rawurlencode($id));
            ?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pexelle — Device Handoff</title></head><body>
<h2>Waiting for approval…</h2>
<p>Please confirm this login on your other device.</p>
<div id="state">Status: pending</div>
<script>
const STATUS_URL = <?php echo json_encode($status_url); ?>;
const CONSUME_URL = <?php echo json_encode($consume_url); ?>;
let tries = 0;
function poll(){
  fetch(STATUS_URL, {credentials:'omit'}).then(r=>r.json()).then(j=>{
    if(!j || !j.status){ document.getElementById('state').textContent='Status: error'; return; }
    document.getElementById('state').textContent = 'Status: ' + j.status;
    if(j.status==='approved'){ window.location.href = CONSUME_URL; return; }
    if(j.status==='expired'){ return; }
    tries++; if(tries<120) setTimeout(poll, 2000); // تا 4 دقیقه
  }).catch(()=>{ tries++; if(tries<120) setTimeout(poll, 3000); });
}
poll();
</script>
</body></html>
            <?php
            exit;
        }

$idc = get_query_var('psl_magic_consume');
if ($idc) {
    $rec = self::get_req($idc);
    if (!$rec || $rec['expires'] < time()) {
        wp_die('Link expired or invalid.', 'Pexelle', 410);
    }
    if (!$rec['approved']) {
        wp_die('Not approved yet.', 'Pexelle', 403);
    }
    if ($rec['consumed']) {
        wp_die('Already used.', 'Pexelle', 400);
    }

    wp_set_auth_cookie((int)$rec['user_id'], false);
    wp_set_current_user((int)$rec['user_id']);
    do_action('wp_login', get_userdata((int)$rec['user_id'])->user_login, get_userdata((int)$rec['user_id']));

    $dest = $rec['redirect'];
    if (!empty($rec['course_id']) && function_exists('learndash_get_course_certificate_link')) {
        $fresh = learndash_get_course_certificate_link((int)$rec['course_id'], (int)$rec['user_id']);
        if (!empty($fresh)) {
            $dest = $fresh;
        }
    }

    $rec['consumed'] = true;
    self::set_req($idc, $rec);
    self::del_req($idc);

    if (empty($dest)) {
        $dest = home_url('/?ld-profile=1');
    }
    wp_safe_redirect($dest);
    exit;
}
    }

    public static function register_rest() {
        register_rest_route('psl/v1', '/magic/status/(?P<id>[A-Za-z0-9_-]{6,})', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function($req){
                $id = $req['id'];
                $rec = self::get_req($id);
                if (!$rec) return ['status' => 'expired'];
                if ($rec['expires'] < time()) return ['status' => 'expired'];
                return ['status' => $rec['approved'] ? 'approved' : 'pending'];
            }
        ]);
    }

public static function ajax_create_request() {
    if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
    $user_id = get_current_user_id();

    $cert_url = isset($_POST['cert_url']) ? esc_url_raw($_POST['cert_url']) : '';
    if (!$cert_url) wp_send_json_error('missing_cert_url');

    $course_id = 0;
    $parts = wp_parse_url($cert_url);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        if (!empty($q['course_id'])) {
            $course_id = (int) $q['course_id'];
        }
    }

    $id = wp_generate_password(20, false, false);
    $rec = [
        'user_id'   => $user_id,
        'created'   => time(),
        'expires'   => time() + self::REQ_TTL,
        'approved'  => false,
        'consumed'  => false,
        'redirect'  => esc_url_raw($cert_url), 
        'course_id' => $course_id,        
    ];
    set_transient(self::tk($id), $rec, self::REQ_TTL);

    $qr_url = home_url('/psl/magic/' . rawurlencode($id));
    $nonce  = wp_create_nonce('psl_magic_approve_' . $id);
    $approve_url = add_query_arg([
        'psl_magic_approve' => $id,
        'psl_magic_nonce'   => $nonce,
    ], home_url('/'));

    wp_send_json_success([
        'id'          => $id,
        'qr_url'      => $qr_url,
        'approve_url' => $approve_url,
        'expires_in'  => self::REQ_TTL,
    ]);
}

    public static function maybe_handle_approve_link() {
        $id = get_query_var('psl_magic_approve') ?: ($_GET['psl_magic_approve'] ?? '');
        if (!$id) return;
        $nonce = get_query_var('psl_magic_nonce') ?: ($_GET['psl_magic_nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'psl_magic_approve_' . $id)) {
            wp_die('Invalid approval link.', 'Pexelle', 403);
        }
        if (!is_user_logged_in()) {
            auth_redirect();
            return;
        }
        $rec = self::get_req($id);
        if (!$rec) wp_die('Request expired or invalid.', 'Pexelle', 410);
        if ((int)$rec['user_id'] !== get_current_user_id()) wp_die('Not allowed.', 'Pexelle', 403);
        if ($rec['expires'] < time()) wp_die('Request expired.', 'Pexelle', 410);
        if ($rec['approved']) wp_die('Already approved.', 'Pexelle', 200);

        $rec['approved'] = true;
        self::set_req($id, $rec);

        wp_safe_redirect( add_query_arg('approved', '1', home_url('/')) );
        exit;
    }
}
