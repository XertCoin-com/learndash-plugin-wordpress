<?php
/**
 * Plugin Name: LearnDash Background Sync (to External Endpoint)
 * Description: Background-sends LearnDash user events (including points) to your secure endpoint—no UI shown to learners.
 * Version: 1.0.3
 * Author: Pexelle
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License: MITr
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LD_Background_Sync {
	const OPTION_SETTINGS = 'ld_bg_sync_settings';
	const OPTION_QUEUE    = 'ld_bg_sync_queue';
	const CRON_HOOK       = 'ld_bg_sync_process_queue';
	const VERSION         = '1.0.0';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

private function check_admin_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'forbidden', 403 ); }
	check_ajax_referer( 'ldbg_admin', 'nonce' );
}

public function ajax_process_now() {
	$this->check_admin_ajax();
	$this->process_queue();
	wp_send_json_success( [ 'remaining' => count( get_option( self::OPTION_QUEUE, [] ) ) ] );
}

public function ajax_clear_queue() {
	$this->check_admin_ajax();
	update_option( self::OPTION_QUEUE, [], false );
	wp_send_json_success();
}

public function ajax_delete_item() {
	$this->check_admin_ajax();
	$index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
	$q = get_option( self::OPTION_QUEUE, [] );
	if ( $index >= 0 && isset( $q[$index] ) ) {
		array_splice( $q, $index, 1 );
		update_option( self::OPTION_QUEUE, $q, false );
		wp_send_json_success();
	}
	wp_send_json_error( 'not_found', 404 );
}

public function ajax_retry_item() {
	$this->check_admin_ajax();
	$index = isset($_POST['index']) ? (int) $_POST['index'] : -1;
	$q = get_option( self::OPTION_QUEUE, [] );
	if ( $index >= 0 && isset( $q[$index] ) ) {
		$q[$index]['attempts'] = 0;
		unset( $q[$index]['next_at'] );
		update_option( self::OPTION_QUEUE, $q, false );
		wp_send_json_success();
	}
	wp_send_json_error( 'not_found', 404 );
}

public function ajax_export_queue() {
	$this->check_admin_ajax();
	$q = get_option( self::OPTION_QUEUE, [] );
	wp_send_json_success( [ 'json' => $q ] );
}

public function ajax_test_connection() {
	$this->check_admin_ajax();
	$s = $this->get_settings();
	if ( empty( $s['endpoint'] ) || empty( $s['secret'] ) ) {
		wp_send_json_error( 'not_configured', 400 );
	}
	$sample   = $this->build_envelope( 'ld.ping', [ 'user' => [ 'id' => get_current_user_id() ] ] );
	$body     = wp_json_encode( $sample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$ts       = (string) time();
	$sig      = 'v1=' . hash_hmac( 'sha256', $ts . "\n" . $body, $s['secret'] );
	$response = wp_remote_post( $s['endpoint'], [
		'headers' => [
			'Content-Type' => 'application/json',
			'X-Signature'  => $sig,
			'X-Timestamp'  => $ts,
			'X-Plugin'     => 'ld-bg-sync/' . self::VERSION,
		],
		'body'      => $body,
		'timeout'   => (int) $s['timeout'],
		'sslverify' => (bool) $s['sslverify'],
	] );
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 );
	}
	wp_send_json_success( [
		'code' => wp_remote_retrieve_response_code( $response ),
		'body' => wp_remote_retrieve_body( $response ),
	] );
}

public function ajax_rotate_secret() {
	$this->check_admin_ajax();
	$s = $this->get_settings();
	$s['secret'] = wp_generate_password( 48, true, true );
	update_option( self::OPTION_SETTINGS, $s, false );
	wp_send_json_success( [ 'secret' => $s['secret'] ] );
}

public function ajax_enqueue_payload() {
	$this->check_admin_ajax();
	$json = wp_unslash( $_POST['payload'] ?? '' );
	$arr  = json_decode( $json, true );
	if ( ! is_array( $arr ) ) {
		wp_send_json_error( 'invalid_json', 400 );
	}
	$this->queue_push( [ 'payload' => $arr ] );
	wp_send_json_success();
}

public function ajax_schedule_cron() {
	$this->check_admin_ajax();
	wp_clear_scheduled_hook( self::CRON_HOOK );
	wp_schedule_event( time() + 60, 'every_five_minutes', self::CRON_HOOK );
	wp_send_json_success( [ 'next' => wp_next_scheduled( self::CRON_HOOK ) ] );
}
	

	public function admin_assets( $hook ) {
	if ( $hook !== 'settings_page_ld-bg-sync' ) { return; }
	wp_enqueue_style( 'ldbg-admin', plugin_dir_url(__FILE__) . 'assets/ldbg-admin.css', [], self::VERSION );
	wp_enqueue_script( 'ldbg-admin', plugin_dir_url(__FILE__) . 'assets/ldbg-admin.js', [ 'jquery' ], self::VERSION, true );
	wp_localize_script( 'ldbg-admin', 'LDBG', [
		'ajax'   => admin_url( 'admin-ajax.php' ),
		'nonce'  => wp_create_nonce( 'ldbg_admin' ),
			] );
		}
	
	private function __construct() {
		// Defaults on first run
		add_action( 'plugins_loaded', [ $this, 'maybe_set_defaults' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
		add_action( 'wp_ajax_ldbg_process_now', [ $this, 'ajax_process_now' ] );
		add_action( 'wp_ajax_ldbg_clear_queue', [ $this, 'ajax_clear_queue' ] );
		add_action( 'wp_ajax_ldbg_delete_item', [ $this, 'ajax_delete_item' ] );
		add_action( 'wp_ajax_ldbg_retry_item',  [ $this, 'ajax_retry_item' ] );
		add_action( 'wp_ajax_ldbg_export_queue',[ $this, 'ajax_export_queue' ] );
		add_action( 'wp_ajax_ldbg_test_connection', [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_ldbg_rotate_secret', [ $this, 'ajax_rotate_secret' ] );
		add_action( 'wp_ajax_ldbg_enqueue_payload', [ $this, 'ajax_enqueue_payload' ] );
		add_action( 'wp_ajax_ldbg_schedule_cron', [ $this, 'ajax_schedule_cron' ] );

		
		// Admin settings
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
		}

		// Queue processor (WP-Cron)
		add_action( self::CRON_HOOK, [ $this, 'process_queue' ] );

		// Activation / deactivation
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );

		// —— LearnDash & related hooks ——
		// Generic LD activity update (covers many events)
		add_action( 'learndash_update_user_activity', [ $this, 'on_ld_activity' ], 10, 1 );

		// Course/lesson/topic/quiz completion convenience hooks (if present)
		add_action( 'ld_course_completed', [ $this, 'on_ld_course_completed' ], 10, 1 );
		add_action( 'ld_lesson_completed', [ $this, 'on_ld_lesson_completed' ], 10, 1 );
		add_action( 'ld_topic_completed',  [ $this, 'on_ld_topic_completed'  ], 10, 1 );
		add_action( 'ld_quiz_completed',   [ $this, 'on_ld_quiz_completed'   ], 10, 1 );

		// LearnDash Points (several plugins/meta keys exist; support common patterns)
		add_action( 'updated_user_meta', [ $this, 'maybe_capture_points_meta' ], 10, 4 );
		add_action( 'added_user_meta',   [ $this, 'maybe_capture_points_meta' ], 10, 4 );
	}

	public static function activate() {
		// Ensure defaults
		$defaults = [
			'endpoint'   => '', // Set this in Settings → LD Background Sync
			'secret'     => wp_generate_password( 32, true, true ),
			'timeout'    => 5, // seconds
			'sslverify'  => true,
			'max_retries'=> 8,
		];
		$existing = get_option( self::OPTION_SETTINGS );
		if ( ! is_array( $existing ) ) {
			update_option( self::OPTION_SETTINGS, $defaults, false );
		}

		// Create empty queue
		if ( ! is_array( get_option( self::OPTION_QUEUE ) ) ) {
			update_option( self::OPTION_QUEUE, [], false );
		}

		// Schedule cron if not scheduled
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'every_five_minutes', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function maybe_set_defaults() {
		// Register custom schedule (5 minutes)
		add_filter( 'cron_schedules', function( $schedules ) {
			$schedules['every_five_minutes'] = [
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'ld-bg-sync' ),
			];
			return $schedules;
		} );
	}

	// ————— Admin Settings —————
	public function add_settings_page() {
		add_options_page(
			'LD Background Sync',
			'LD Background Sync',
			'manage_options',
			'ld-bg-sync',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'ld_bg_sync', self::OPTION_SETTINGS, [ $this, 'sanitize_settings' ] );

		add_settings_section( 'ld_bg_sync_main', __( 'Connection', 'ld-bg-sync' ), function() {
			echo '<p>Configure the secure destination for background LearnDash events.</p>';
		}, 'ld-bg-sync' );

		add_settings_field( 'endpoint', 'Endpoint URL', [ $this, 'field_endpoint' ], 'ld-bg-sync', 'ld_bg_sync_main' );
		add_settings_field( 'secret', 'Signing Secret', [ $this, 'field_secret' ], 'ld-bg-sync', 'ld_bg_sync_main' );
		add_settings_field( 'timeout', 'HTTP Timeout (s)', [ $this, 'field_timeout' ], 'ld-bg-sync', 'ld_bg_sync_main' );
		add_settings_field( 'sslverify', 'Verify SSL', [ $this, 'field_sslverify' ], 'ld-bg-sync', 'ld_bg_sync_main' );
		add_settings_field( 'max_retries', 'Max Retries', [ $this, 'field_max_retries' ], 'ld-bg-sync', 'ld_bg_sync_main' );
	}

	public function sanitize_settings( $input ) {
		$san = [];
		$san['endpoint']    = isset( $input['endpoint'] ) ? esc_url_raw( $input['endpoint'] ) : '';
		$san['secret']      = isset( $input['secret'] ) ? sanitize_text_field( $input['secret'] ) : '';
		$san['timeout']     = isset( $input['timeout'] ) ? max( 2, (int) $input['timeout'] ) : 5;
		$san['sslverify']   = ! empty( $input['sslverify'] );
		$san['max_retries'] = isset( $input['max_retries'] ) ? max( 1, (int) $input['max_retries'] ) : 8;
		return $san;
	}

	private function get_settings() {
		$defaults = [
			'endpoint'   => '',
			'secret'     => '',
			'timeout'    => 5,
			'sslverify'  => true,
			'max_retries'=> 8,
		];
		return wp_parse_args( get_option( self::OPTION_SETTINGS, [] ), $defaults );
	}

public function render_settings_page() {
	$opts   = $this->get_settings();
	$queue  = get_option( self::OPTION_QUEUE, [] );
	$next   = wp_next_scheduled( self::CRON_HOOK );
	?>
<div class="wrap ldbg-wrap">
    <h1>LearnDash Background Sync</h1>
    <p class="description">Send LearnDash events in the background to your secure endpoint.</p>

    <div class="ldbg-tabs">
        <a class="ldbg-tab active" data-tab="connection">Connection</a>
        <a class="ldbg-tab" data-tab="queue">Queue</a>
        <a class="ldbg-tab" data-tab="diagnostics">Diagnostics</a>
        <a class="ldbg-tab" data-tab="tools">Tools</a>
    </div>

    <!-- Connection -->
    <div class="ldbg-panel" data-panel="connection" style="display:block">
        <form method="post" action="options.php" class="ldbg-card">
            <?php settings_fields( 'ld_bg_sync' ); ?>
            <?php do_settings_sections( 'ld-bg-sync' ); ?>
            <?php submit_button( __( 'Save Settings', 'ld-bg-sync' ) ); ?>
            <p><em>No changes are displayed to users; everything happens in the background..</em></p>
        </form>
        <?php if ( empty( $opts['endpoint'] ) || empty( $opts['secret'] ) ) : ?>
        <div class="notice notice-warning">
            <p>To get started, set the Endpoint and Secret above.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Queue -->
    <div class="ldbg-panel" data-panel="queue">
        <div class="ldbg-card">
            <div class="ldbg-row">
                <button class="button button-secondary" id="ldbg-process-now">Process Now</button>
                <button class="button" id="ldbg-clear-queue">Clear Queue</button>
                <button class="button" id="ldbg-export-queue">Export JSON</button>
                <span class="ldbg-flex-grow"></span>
                <span>Items: <strong><?php echo count( (array) $queue ); ?></strong></span>
            </div>
            <table class="widefat striped ldbg-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>User</th>
                        <th>Attempts</th>
                        <th>Created</th>
                        <th>Next Retry</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $queue ) ) :
							foreach ( $queue as $i => $item ) :
								$p   = $item['payload'] ?? [];
								$u   = $p['data']['user'] ?? $p['user'] ?? [];
								?>
                    <tr data-index="<?php echo esc_attr( $i ); ?>">
                        <td><code><?php echo esc_html( $item['id'] ?? '-' ); ?></code></td>
                        <td><?php echo esc_html( $p['type'] ?? '-' ); ?></td>
                        <td><?php echo esc_html( $u['email'] ?? $u['login'] ?? $u['id'] ?? '-' ); ?></td>
                        <td><?php echo (int) ( $item['attempts'] ?? 0 ); ?></td>
                        <td><?php echo ! empty( $item['created_at'] ) ? esc_html( date_i18n( 'Y-m-d H:i', $item['created_at'] ) ) : '-'; ?>
                        </td>
                        <td><?php
										echo ! empty( $item['next_at'] )
											? esc_html( date_i18n( 'Y-m-d H:i', $item['next_at'] ) )
											: '-';
									?></td>
                        <td>
                            <button class="button ldbg-retry-item">Retry</button>
                            <button class="button link-delete ldbg-delete-item">Delete</button>
                            <button class="button ldbg-view-json">View</button>
                        </td>
                    </tr>
                    <?php endforeach;
						else: ?>
                    <tr>
                        <td colspan="7">No Queue</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Diagnostics -->
    <div class="ldbg-panel" data-panel="diagnostics">
        <div class="ldbg-grid">
            <div class="ldbg-card">
                <h2>WP-Cron</h2>
                <ul>
                    <li>Hook: <code><?php echo esc_html( self::CRON_HOOK ); ?></code></li>
                    <li>Next Run:
                        <strong>
                            <?php echo $next ? esc_html( date_i18n( 'Y-m-d H:i:s', $next ) ) : '— not scheduled —'; ?>
                        </strong>
                    </li>
                </ul>
                <button class="button" id="ldbg-schedule-cron">Schedule/Reschedule</button>
            </div>
            <div class="ldbg-card">
                <h2>Versions</h2>
                <ul>
                    <li>Plugin: <code><?php echo esc_html( self::VERSION ); ?></code></li>
                    <li>WordPress: <code><?php echo esc_html( get_bloginfo('version') ); ?></code></li>
                    <li>PHP: <code><?php echo esc_html( PHP_VERSION ); ?></code></li>
                </ul>
            </div>
            <div class="ldbg-card">
                <h2>Remote Test</h2>
                <p>A sample request is sent to the Endpoint with Sign (no data changes).</p>
                <button class="button button-primary" id="ldbg-test-connection">Test Connection</button>
                <pre class="ldbg-pre" id="ldbg-test-output"></pre>
            </div>
        </div>
    </div>

    <!-- Tools -->
    <div class="ldbg-panel" data-panel="tools">
        <div class="ldbg-card">
            <h2>Rotate Secret</h2>
            <p>A new secure Secret will be generated and replaced. (Update the destination frontend/backend as well)</p>
            <button class="button" id="ldbg-rotate-secret">Rotate</button>
            <code id="ldbg-rotate-output"></code>
        </div>
        <div class="ldbg-card">
            <h2>Manual Enqueue (Dev)</h2>
            <p>write manual Payload.</p>
            <textarea id="ldbg-enqueue-json" class="large-text code"
                rows="6">{ "type":"ld.activity","data":{"user":{"id":1}} }</textarea>
            <button class="button" id="ldbg-enqueue-payload">Enqueue</button>
        </div>
    </div>
</div>
<?php
}

	public function field_endpoint() {
		$opts = $this->get_settings();
		echo '<input type="url" class="regular-text ltr" name="' . esc_attr( self::OPTION_SETTINGS ) . '[endpoint]" value="' . esc_attr( $opts['endpoint'] ) . '" placeholder="https://api.yourdomain.com/ld-events" />';
	}
	public function field_secret() {
		$opts = $this->get_settings();
		echo '<input type="text" class="regular-text ltr" name="' . esc_attr( self::OPTION_SETTINGS ) . '[secret]" value="' . esc_attr( $opts['secret'] ) . '" />';
	}
	public function field_timeout() {
		$opts = $this->get_settings();
		echo '<input type="number" min="2" step="1" class="small-text" name="' . esc_attr( self::OPTION_SETTINGS ) . '[timeout]" value="' . esc_attr( (int) $opts['timeout'] ) . '" />';
	}
	public function field_sslverify() {
		$opts = $this->get_settings();
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_SETTINGS ) . '[sslverify]" ' . checked( $opts['sslverify'], true, false ) . ' /> Verify TLS/SSL certificate</label>';
	}
	public function field_max_retries() {
		$opts = $this->get_settings();
		echo '<input type="number" min="1" step="1" class="small-text" name="' . esc_attr( self::OPTION_SETTINGS ) . '[max_retries]" value="' . esc_attr( (int) $opts['max_retries'] ) . '" />';
	}

	// ————— Queue helpers —————
	private function queue_push( array $item ) {
		$queue = get_option( self::OPTION_QUEUE, [] );
		$item['id']         = wp_generate_uuid4();
		$item['created_at'] = time();
		$item['attempts']   = 0;
		$queue[]            = $item;
		update_option( self::OPTION_QUEUE, $queue, false );

		// Kick the processor soon
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'every_five_minutes', self::CRON_HOOK );
		}
	}

	private function queue_replace( array $queue ) {
		update_option( self::OPTION_QUEUE, $queue, false );
	}

public function process_queue() {
	$settings = $this->get_settings();
	$endpoint = $settings['endpoint'];
	$secret   = $settings['secret'];
	if ( empty( $endpoint ) || empty( $secret ) ) { return; }

	$queue = get_option( self::OPTION_QUEUE, [] );
	if ( empty( $queue ) || ! is_array( $queue ) ) { return; }

	$now     = time();
	$remain  = [];
	foreach ( $queue as $item ) {
		if ( ! empty( $item['next_at'] ) && $item['next_at'] > $now ) {
			$remain[] = $item;
			continue;
		}

		$body      = wp_json_encode( $item['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$timestamp = (string) $now;
		$signature = hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );

		$args = [
			'headers'   => [
				'Content-Type' => 'application/json',
				'X-Signature'  => 'v1=' . $signature,
				'X-Timestamp'  => $timestamp,
				'X-Plugin'     => 'ld-bg-sync/' . self::VERSION,
			],
			'body'      => $body,
			'timeout'   => (int) $settings['timeout'],
			'sslverify' => (bool) $settings['sslverify'],
		];

		$response = wp_remote_post( $endpoint, $args );
		$code     = wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) || $code < 200 || $code >= 300 ) {
			$item['attempts'] = (int) ( $item['attempts'] ?? 0 ) + 1;
			$item['next_at']  = $now + $this->backoff_seconds( $item['attempts'] );
			if ( $item['attempts'] < (int) $settings['max_retries'] ) {
				$remain[] = $item;
			}
		}
	}
	$this->queue_replace( $remain );
}
	
	private function backoff_seconds( $attempt ) {
		// Exponential backoff with jitter, max 1 hour
		$base = min( 3600, pow( 2, min( 10, (int) $attempt ) ) );
		$jitter = random_int( 0, 30 );
		return (int) $base + $jitter;
	}

	// ————— Event builders —————
	private function build_envelope( $type, array $data ) {
		$site  = [
			'home_url'   => home_url( '/' ),
			'site_url'   => site_url( '/' ),
			'site_name'  => get_bloginfo( 'name' ),
			'plugin_ver' => self::VERSION,
			'wp_ver'     => get_bloginfo( 'version' ),
		];
		return [
			'type'      => $type,
			'issued_at' => time(),
			'site'      => $site,
			'data'      => $data,
		];
	}

	private function get_user_brief( $user_id ) {
		$user = get_userdata( $user_id );
		return [
			'id'       => (int) $user_id,
			'email'    => $user ? $user->user_email : null,
			'login'    => $user ? $user->user_login : null,
			'display'  => $user ? $user->display_name : null,
		];
	}

	// ————— Hook handlers —————
	public function on_ld_activity( $activity ) {
		// $activity is an array or object depending on LearnDash version
		$act = is_object( $activity ) ? (array) $activity : (array) $activity;
		$user_id = isset( $act['user_id'] ) ? (int) $act['user_id'] : 0;
		if ( $user_id <= 0 ) { return; }

		$payload = $this->build_envelope( 'ld.activity', [
			'user'     => $this->get_user_brief( $user_id ),
			'activity' => $act,
		] );
		$this->queue_push( [ 'payload' => $payload ] );
	}

	public function on_ld_course_completed( $data ) {
		// $data typically includes: user, course, etc.
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) { return; }
		$payload = $this->build_envelope( 'ld.course_completed', [
			'user' => $this->get_user_brief( $user_id ),
			'data' => $data,
		] );
		$this->queue_push( [ 'payload' => $payload ] );
	}

	public function on_ld_lesson_completed( $data ) {
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) { return; }
		$payload = $this->build_envelope( 'ld.lesson_completed', [
			'user' => $this->get_user_brief( $user_id ),
			'data' => $data,
		] );
		$this->queue_push( [ 'payload' => $payload ] );
	}

	public function on_ld_topic_completed( $data ) {
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) { return; }
		$payload = $this->build_envelope( 'ld.topic_completed', [
			'user' => $this->get_user_brief( $user_id ),
			'data' => $data,
		] );
		$this->queue_push( [ 'payload' => $payload ] );
	}

	public function on_ld_quiz_completed( $data ) {
		$user_id = isset( $data['user']->ID ) ? (int) $data['user']->ID : (int) ( $data['user_id'] ?? 0 );
		if ( $user_id <= 0 ) { return; }
		$payload = $this->build_envelope( 'ld.quiz_completed', [
			'user' => $this->get_user_brief( $user_id ),
			'data' => $data,
		] );
		$this->queue_push( [ 'payload' => $payload ] );
	}

	public function maybe_capture_points_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		// Common meta keys for points used by LearnDash and extensions (varies by setup)
		$keys = [ 'ld_points', 'learndash_points', 'ld_user_points', 'badgeos_learndash_points' ];
		if ( in_array( $meta_key, $keys, true ) ) {
			$payload = $this->build_envelope( 'ld.points_updated', [
				'user'        => $this->get_user_brief( (int) $user_id ),
				'meta_key'    => $meta_key,
				'points_total'=> is_numeric( $meta_value ) ? (float) $meta_value : $meta_value,
			] );
			$this->queue_push( [ 'payload' => $payload ] );
		}
	}
}

LD_Background_Sync::instance();
