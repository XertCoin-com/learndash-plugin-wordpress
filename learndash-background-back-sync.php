<?php
/**
 * Plugin Name: LearnDash Background Sync (to External Endpoint)
 * Description: Background-sends LearnDash user events (including points) to your secure endpoint—no UI shown to learners.
 * Version: 1.0.0
 * Author: Pexelle
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License: MIT
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

	private function __construct() {
		// Defaults on first run
		add_action( 'plugins_loaded', [ $this, 'maybe_set_defaults' ] );

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
		?>
		<div class="wrap">
			<h1>LearnDash Background Sync</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ld_bg_sync' ); ?>
				<?php do_settings_sections( 'ld-bg-sync' ); ?>
				<?php submit_button(); ?>
			</form>
			<p><em>No changes are visible to learners. All events are queued and sent in the background.</em></p>
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
		if ( empty( $endpoint ) || empty( $secret ) ) {
			return; // Not configured
		}

		$queue = get_option( self::OPTION_QUEUE, [] );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$updated = [];
		foreach ( $queue as $item ) {
			$body       = wp_json_encode( $item['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$timestamp  = (string) time();
			$signature  = hash_hmac( 'sha256', $timestamp . "\n" . $body, $secret );

			$args = [
				'headers' => [
					'Content-Type' => 'application/json',
					'X-Signature'  => 'v1=' . $signature,
					'X-Timestamp'  => $timestamp,
					'X-Plugin'     => 'ld-bg-sync/' . self::VERSION,
				],
				'body'      => $body,
				'timeout'   => (int) $settings['timeout'],
				'sslverify' => (bool) $settings['sslverify'],
				// Non-blocking is nice, but we process synchronously for retries; keep blocking request
			];

			$response = wp_remote_post( $endpoint, $args );
			$code     = wp_remote_retrieve_response_code( $response );

			if ( is_wp_error( $response ) || $code < 200 || $code >= 300 ) {
				$item['attempts'] = (int) $item['attempts'] + 1;
				$item['next_at']  = time() + $this->backoff_seconds( $item['attempts'] );
				$updated[]        = $item; // Keep for retry
			} else {
				// Delivered — drop
			}
		}

		// Keep only items due for retry in the future
		$retry_queue = array_values( array_filter( $updated, function( $i ) use ( $settings ) {
			return $i['attempts'] < (int) $settings['max_retries'];
		} ) );

		$this->queue_replace( $retry_queue );
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

	/LD_Background_Sync::instance();

// Register REST route after init (callback verifies HMAC internally)
add_action( 'rest_api_init', function() {
	register_rest_route( 'ld-bg-sync/v1', '/intake', [
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => [ LD_Background_Sync::instance(), 'rest_intake' ],
		'permission_callback' => '__return_true',
	] );
} );
ept external events and write into LearnDash ===
	public function rest_intake( WP_REST_Request $req ) {
		$settings = $this->get_settings();
		$raw_body = $req->get_body();

		$signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
		$timestamp = isset($_SERVER['HTTP_X_TIMESTAMP']) ? $_SERVER['HTTP_X_TIMESTAMP'] : '';
		$event_id  = isset($_SERVER['HTTP_X_EVENT_ID']) ? $_SERVER['HTTP_X_EVENT_ID'] : '';

		if ( empty( $settings['secret'] ) || empty( $signature ) || empty( $timestamp ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'missing_auth_headers' ], 401 );
		}

		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'timestamp_out_of_range' ], 401 );
		}

		$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . "\n" . $raw_body, $settings['secret'] );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'bad_signature' ], 401 );
		}

		if ( ! empty( $event_id ) ) {
			$key = 'ld_bg_sync_evt_' . md5( $event_id );
			if ( get_transient( $key ) ) {
				return new WP_REST_Response( [ 'ok' => true, 'skipped' => 'duplicate' ], 200 );
			}
			set_transient( $key, 1, 86400 );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_json' ], 400 );
		}

		switch ( $payload['type'] ?? '' ) {
			case 'points.awarded':
				$result = $this->apply_points_delta_from_payload( $payload );
				break;
			case 'ld.activity':
				$result = $this->apply_generic_activity( $payload );
				break;
			default:
				$result = [ 'ok' => false, 'error' => 'unsupported_type' ];
		}

		$status = ( ! empty( $result['ok'] ) ) ? 200 : 400;
		return new WP_REST_Response( $result, $status );
	}

	private function find_user_from_payload( $payload ) {
		if ( ! empty( $payload['user']['id'] ) ) {
			$user = get_user_by( 'ID', (int) $payload['user']['id'] );
			if ( $user ) return (int) $user->ID;
		}
		if ( ! empty( $payload['user']['email'] ) ) {
			$user = get_user_by( 'email', sanitize_email( $payload['user']['email'] ) );
			if ( $user ) return (int) $user->ID;
		}
		if ( ! empty( $payload['user']['login'] ) ) {
			$user = get_user_by( 'login', sanitize_user( $payload['user']['login'] ) );
			if ( $user ) return (int) $user->ID;
		}
		return 0;
	}

	private function apply_points_delta_from_payload( $payload ) {
		$user_id = $this->find_user_from_payload( $payload );
		if ( $user_id <= 0 ) return [ 'ok' => false, 'error' => 'user_not_found' ];

		$delta  = (float) ( $payload['points_delta'] ?? 0 );
		$reason = isset( $payload['reason'] ) ? sanitize_text_field( $payload['reason'] ) : '';

		$meta_keys = [ 'ld_points', 'learndash_points', 'ld_user_points' ];
		$current  = 0.0; $chosen = null;
		foreach ( $meta_keys as $k ) {
			$val = get_user_meta( $user_id, $k, true );
			if ( $val !== '' ) { $current = (float) $val; $chosen = $k; break; }
		}
		if ( ! $chosen ) { $chosen = 'ld_points'; }

		$new_total = $current + $delta;
		update_user_meta( $user_id, $chosen, $new_total );

		// Signal for other plugins/logging
		do_action( 'ld_bg_sync_points_awarded', $user_id, $delta, $new_total, $reason, $payload );

		return [ 'ok' => true, 'user_id' => $user_id, 'points_meta' => $chosen, 'new_total' => $new_total ];
	}

	private function apply_generic_activity( $payload ) {
		$user_id = $this->find_user_from_payload( $payload );
		if ( $user_id <= 0 ) return [ 'ok' => false, 'error' => 'user_not_found' ];
		$log = get_user_meta( $user_id, 'ld_bg_sync_activity_log', true );
		$log = is_array( $log ) ? $log : [];
		$log[] = [ 'at' => time(), 'payload' => $payload ];
		update_user_meta( $user_id, 'ld_bg_sync_activity_log', $log );
		return [ 'ok' => true, 'user_id' => $user_id ];
	}
}
LD_Background_Sync::instance();

