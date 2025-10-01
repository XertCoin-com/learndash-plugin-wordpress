<?php
namespace PSL;

if (!defined('ABSPATH')) { exit; }

/**
 * Magic Login (Device Handoff) for LearnDash certificate sharing
 * - Creates one-time, short-lived login requests with manual approval on Device 1
 * - Device 2 lands on /psl/magic/{id} (waiting page) → after approval → /psl/magic/consume/{id}
 * - After login cookie is set on Device 2, we hop to a same-domain Bridge page,
 *   then:
 *     - mode=pdf  → build fresh certificate link (nonce) and redirect
 *     - mode=json → redirect to REST JSON endpoint /wp-json/psl/v1/cert-json?course_id=...
 */
final class Psl_Magic_Login {
    const REQ_TTL  = 600;           // 10 minutes
    const K_PREFIX = 'psl_ml_';     // transient key prefix

    public static function init() {
        add_action('init',          [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars',    [__CLASS__, 'register_qv']);
        add_action('template_redirect', [__CLASS__, 'route_pages']);

        add_action('rest_api_init', [__CLASS__, 'register_rest']);
        add_action('wp_ajax_psl_magic_create', [__CLASS__, 'ajax_create_request']);
        add_action('init',          [__CLASS__, 'maybe_handle_approve_link']);
    }

    /** transient key */
    private static function tk($id){ return self::K_PREFIX . $id; }

    /** Create a pending request (basic shape; course_id/mode set in ajax_create_request) */
    private static function create_request(int $user_id, string $redirect_url) {
        $id  = wp_generate_password(20, false, false);
        $rec = [
            'user_id'   => $user_id,
            'created'   => time(),
            'expires'   => time() + self::REQ_TTL,
            'approved'  => false,
            'consumed'  => false,
            'redirect'  => esc_url_raw($redirect_url),
            // 'course_id' / 'mode' set later
        ];
        set_transient(self::tk($id), $rec, self::REQ_TTL);
        return $id;
    }

    private static function get_req($id){ return get_transient(self::tk($id)); }
    private static function set_req($id, $rec){ return set_transient(self::tk($id), $rec, max(1, ($rec['expires'] ?? time()) - time())); }
    private static function del_req($id){ return delete_transient(self::tk($id)); }

    /** Rewrite rules for waiting & consume routes */
    public static function add_rewrite_rules() {
        add_rewrite_rule('^psl/magic/([^/]+)/?$',          'index.php?psl_magic=$matches[1]',         'top'); // waiting page (device 2)
        add_rewrite_rule('^psl/magic/consume/([^/]+)/?$',  'index.php?psl_magic_consume=$matches[1]',  'top'); // consume & login
    }

    /** Register query vars (include bridge vars) */
    public static function register_qv($vars){
		$vars[] = 'psl_token';
        $vars[] = 'psl_magic';
        $vars[] = 'psl_magic_consume';
        $vars[] = 'psl_magic_approve';
        $vars[] = 'psl_magic_nonce';
		$vars[] = 'psl_bridge_nonce';
        // Bridge:
        $vars[] = 'psl_after_login';
        $vars[] = 'goto_b64';
        $vars[] = 'course_id';
        $vars[] = 'mode';
        return $vars;
    }

    /** Main router: waiting, consume, bridge */
    public static function route_pages() {
        // 1) WAITING PAGE (Device 2 lands here from QR; polls approval; then jumps to consume)
      $id = get_query_var('psl_magic');
if ($id) {
    $rec = self::get_req($id);
    status_header(200);
    nocache_headers();
    if (!$rec || ($rec['expires'] ?? 0) < time()) {
        echo '<h2>Link expired or invalid.</h2>';
        exit;
    }

    $consume_url  = home_url('/psl/magic/consume/' . rawurlencode($id));
    $status_url   = rest_url('psl/v1/magic/status/' . rawurlencode($id));
    $remainingSec = max(0, (int)($rec['expires'] - time())); // countdown
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pexelle — Device Handoff</title>
<style>
  :root{
    --bg:#f7f9fb;
    --card:#fff;
    --text:#0f172a;
    --muted:#475569;
    --primary:#111827;
    --border:#e5e7eb;
    --ok:#059669; /* green-600 */
    --warn:#b45309; /* amber-700 */
    --err:#b91c1c; /* red-700 */
  }
  *{box-sizing:border-box}
  html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
  .wrap{
    min-height:100%;
    display:grid;
    place-items:center;
    padding:24px;
  }
  .card{
    width:100%;
    max-width:520px;
    background:var(--card);
    border:1px solid var(--border);
    border-radius:16px;
    padding:24px 22px;
    box-shadow:0 12px 40px rgba(0,0,0,.08);
    text-align:center;
  }
  .title{margin:4px 0 8px;font-size:22px;font-weight:700;letter-spacing:.2px}
  .desc{margin:0 0 16px;color:var(--muted);font-size:14px}
  .status{
    display:inline-flex;align-items:center;gap:8px;
    padding:8px 12px;border-radius:9999px;
    background:#f8fafc;border:1px solid var(--border);color:var(--muted);
    font-weight:600;font-size:13px;
  }
  .status--ok{background:#ecfdf5;border-color:#bbf7d0;color:var(--ok)}
  .status--warn{background:#fffbeb;border-color:#fde68a;color:var(--warn)}
  .status--err{background:#fef2f2;border-color:#fecaca;color:var(--err)}
  .row{margin-top:14px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .pill{
    min-width:140px;
    display:flex;flex-direction:column;gap:4px;
    padding:10px 12px;border:1px dashed var(--border);border-radius:12px;background:#fafafa;
  }
  .pill .label{font-size:12px;color:var(--muted)}
  .pill .value{font-size:16px;font-weight:700;letter-spacing:.3px}
  /* spinner */
  .spinner{
    margin:12px auto 18px;
    width:36px;height:36px;border-radius:50%;
    border:3px solid #e5e7eb;border-top-color:#111827;
    animation:spin .9s linear infinite;
  }
  @keyframes spin{to{transform:rotate(360deg)}}
  .footer{margin-top:14px;color:#94a3b8;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card" role="status" aria-live="polite">
    <div class="spinner" aria-hidden="true"></div>
    <h2 class="title">Waiting for approval…</h2>
    <p class="desc">Please confirm this login on your other device.</p>

    <div class="row">
      <div class="pill">
        <span class="label">Status</span>
        <span id="state" class="value">pending</span>
      </div>
      <div class="pill">
        <span class="label">Time left</span>
        <span id="countdown" class="value">--:--</span>
      </div>
    </div>

    <div class="row" style="margin-top:10px;">
      <span id="statusBadge" class="status"><span aria-hidden="true">●</span> Pending</span>
    </div>

    <div class="footer">This link will expire for security.</div>
  </div>
</div>

<script>
const STATUS_URL  = <?php echo json_encode($status_url); ?>;
const CONSUME_URL = <?php echo json_encode($consume_url); ?>;
let tries = 0;
let left  = <?php echo (int) $remainingSec; ?>;
let pollTimer = null, tickTimer = null;

function fmt(t){
  t = Math.max(0, t|0);
  const m = Math.floor(t/60), s = t%60;
  return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
function setBadge(className, text){
  const el = document.getElementById('statusBadge');
  el.className = 'status ' + className;
  el.textContent = text;
}

function poll(){
  fetch(STATUS_URL, {credentials:'omit'})
    .then(r=>r.json())
    .then(j=>{
      if(!j || !j.status){
        document.getElementById('state').textContent='error';
        setBadge('status--err','Error');
        return;
      }
      document.getElementById('state').textContent = j.status;
      if(j.status==='approved'){
        setBadge('status--ok','Approved');
        clearInterval(pollTimer);
        clearInterval(tickTimer);
        setTimeout(()=>{ window.location.href = CONSUME_URL; }, 200);
        return;
      }
      if(j.status==='expired'){
        setBadge('status--err','Expired');
        clearInterval(pollTimer);
        clearInterval(tickTimer);
        return;
      }
      tries++;
      if(tries>300){ 
        setBadge('status--warn','Timed out');
        clearInterval(pollTimer);
        clearInterval(tickTimer);
      }
    })
    .catch(()=>{
    });
}

function tick(){
  left--;
  const c = document.getElementById('countdown');
  c.textContent = fmt(left);
  if(left <= 0){
    document.getElementById('state').textContent = 'expired';
    setBadge('status--err','Expired');
    clearInterval(pollTimer);
    clearInterval(tickTimer);
  } else if(left < 30){
    setBadge('status--warn','Pending (soon expiring)');
  }
}

// init
document.getElementById('countdown').textContent = fmt(left);
setBadge('','Pending');
pollTimer = setInterval(poll, 2000);
tickTimer = setInterval(tick, 1000);
</script>
</body>
</html>
    <?php
    exit;
}

        // 2) CONSUME: set login cookie on Device 2, then hop to Bridge on same domain (JS redirect)
        $idc = get_query_var('psl_magic_consume');
        if ($idc) {
            $rec = self::get_req($idc);
            if (!$rec || ($rec['expires'] ?? 0) < time()) wp_die('Link expired or invalid.', 'Pexelle', 410);
            if (!$rec['approved']) wp_die('Not approved yet.', 'Pexelle', 403);
            if (!empty($rec['consumed'])) wp_die('Already used.', 'Pexelle', 400);

            nocache_headers();

            $uid = (int) ($rec['user_id'] ?? 0);
            if ($uid > 0) {
                wp_set_auth_cookie($uid, false);
                wp_set_current_user($uid);
            }

            // one-time
            $rec['consumed'] = true;
            self::set_req($idc, $rec);
            self::del_req($idc);

            $course_id    = !empty($rec['course_id']) ? (int)$rec['course_id'] : 0;
            $mode         = !empty($rec['mode']) ? sanitize_text_field($rec['mode']) : 'pdf';
            $fallback     = !empty($rec['redirect']) ? (string)$rec['redirect'] : '';
            $fallback_b64 = $fallback ? rtrim(strtr(base64_encode($fallback), '+/=', '-_,'), ',') : '';
            $mode = !empty($rec['mode']) ? sanitize_text_field($rec['mode']) : 'pdf';

			$token = wp_generate_password(32, false, false);
			set_transient('psl_json_token_' . $token, [
				'user_id'   => $uid,
				'course_id' => (int) $course_id,
				'created'   => time(),
			], 10 * MINUTE_IN_SECONDS);

			$bridge_nonce = wp_create_nonce('psl_bridge_' . (int) $uid);
            // build Bridge URL (same-domain), pass only compact safe params
            $bridge = add_query_arg(array_filter([
				'psl_after_login' => 1,
				'course_id'       => $course_id ?: null,
				'mode'            => $mode ?: null,
				'goto_b64'        => $fallback_b64 ?: null,
				'psl_token'       => $token,
				'psl_bridge_nonce'=> $bridge_nonce,
			]), home_url('/'));

            status_header(200);
            nocache_headers();
            echo '<!doctype html><meta charset="utf-8"><title>Finalizing login…</title>';
            echo '<p>Finalizing login…</p>';
            echo '<script>setTimeout(function(){ location.href = ' . json_encode($bridge) . ' }, 300);</script>';
            exit;
        }

        // 3) BRIDGE: ensure cookie is visible, then redirect to final destination (PDF or JSON)
        if ( get_query_var('psl_after_login') || isset($_GET['psl_after_login']) ) {
            nocache_headers();

            // Make sure cookie is actually committed
            if ( ! is_user_logged_in() ) {
                echo '<!doctype html><meta charset="utf-8"><title>Finalizing login…</title>';
                echo '<p>Finalizing login…</p>';
                echo '<script>setTimeout(function(){ location.reload(); }, 600);</script>';
                exit;
            }

            $token     = isset($_GET['psl_token']) ? sanitize_text_field((string) $_GET['psl_token']) : '';
			$mode      = isset($_GET['mode']) ? sanitize_text_field((string)$_GET['mode']) : 'pdf';
			$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
			$dest      = '';

				if ($mode === 'json') {
					if ($course_id > 0) {
						$dest = add_query_arg(
							[
								'course_id' => (int) $course_id,
								'token'     => $token,  
							],
							home_url('/wp-json/psl/v1/cert-json')
						);
					}
					if (empty($dest) && !empty($_GET['goto_b64'])) {
						$maybe = base64_decode(strtr((string)$_GET['goto_b64'], '-_,', '+/='));
						if ($maybe && preg_match('#^https?://#i', $maybe)) {
							$dest = $maybe;
						}
					}
				} else {
                // mode=pdf (default): build fresh certificate link (fresh nonce) after cookie present
                if ($course_id > 0 && function_exists('learndash_get_course_certificate_link')) {
                    $fresh = (string) learndash_get_course_certificate_link($course_id, $uid);
                    if (!empty($fresh)) $dest = $fresh;
                }
                // fallback if no course_id/fresh link available
                if (empty($dest) && !empty($_GET['goto_b64'])) {
                    $maybe = base64_decode(strtr((string)$_GET['goto_b64'], '-_,', '+/='));
                    if ($maybe && preg_match('#^https?://#i', $maybe)) {
                        $dest = $maybe;
                    }
                }
            }

            if (empty($dest)) {
                $dest = home_url('/'); // ultimate fallback
            }

            echo '<!doctype html><meta charset="utf-8"><title>Redirecting…</title>';
            echo '<p>Redirecting…</p>';
            echo '<script>setTimeout(function(){ location.href = ' . json_encode($dest) . ' }, 500);</script>';
            exit;
        }
    }

    /** Polling endpoint */
    public static function register_rest() {
        register_rest_route('psl/v1', '/magic/status/(?P<id>[A-Za-z0-9_-]{6,})', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function($req){
                $id  = $req['id'];
                $rec = self::get_req($id);
                if (!$rec) return ['status' => 'expired'];
                if (($rec['expires'] ?? 0) < time()) return ['status' => 'expired'];
                return ['status' => !empty($rec['approved']) ? 'approved' : 'pending'];
            }
        ]);
    }

    /** AJAX: create request (Device 1) */
    public static function ajax_create_request() {
        if (!is_user_logged_in()) wp_send_json_error('not_logged_in');
        $user_id = get_current_user_id();
        $cert_url = isset($_POST['cert_url']) ? esc_url_raw($_POST['cert_url']) : '';
        if (!$cert_url) wp_send_json_error('missing_cert_url');
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'pdf'; // 'pdf' | 'json'
        // Parse course_id from provided certificate URL (for fresh nonce later)
        $course_id = 0;
        $parts = wp_parse_url($cert_url);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            if (!empty($q['course_id'])) {
                $course_id = (int) $q['course_id'];
            }
        }
        // Create record
        $id  = wp_generate_password(20, false, false);
        $rec = [
            'user_id'   => $user_id,
            'created'   => time(),
            'expires'   => time() + self::REQ_TTL,
            'approved'  => false,
            'consumed'  => false,
            'redirect'  => esc_url_raw($cert_url),
            'course_id' => $course_id,
            'mode'      => $mode,
        ];
        set_transient(self::tk($id), $rec, self::REQ_TTL);
        // Build URLs (same site/domain)
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
    /** Approve link (Device 1) */
    public static function maybe_handle_approve_link() {
        $id = get_query_var('psl_magic_approve') ?: ($_GET['psl_magic_approve'] ?? '');
        if (!$id) return;

        $nonce = get_query_var('psl_magic_nonce') ?: ($_GET['psl_magic_nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'psl_magic_approve_' . $id)) {
            wp_die('Invalid approval link.', 'Pexelle', 403);
        }

        if (!is_user_logged_in()) {
            auth_redirect(); // force login
            return;
        }

        $rec = self::get_req($id);
        if (!$rec) wp_die('Request expired or invalid.', 'Pexelle', 410);
        if ((int)($rec['user_id'] ?? 0) !== get_current_user_id()) wp_die('Not allowed.', 'Pexelle', 403);
        if (($rec['expires'] ?? 0) < time()) wp_die('Request expired.', 'Pexelle', 410);
        if (!empty($rec['approved'])) wp_die('Already approved.', 'Pexelle', 200);
        $rec['approved'] = true;
        self::set_req($id, $rec);
        wp_safe_redirect( add_query_arg('approved', '1', home_url('/')) );
        exit;
    }
}
