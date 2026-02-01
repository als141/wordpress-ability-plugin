<?php
/**
 * Admin Settings Page for App Connections.
 *
 * Japanese UI for managing multiple app connections via MCP.
 *
 * @package WP_MCP
 */

declare( strict_types=1 );

namespace WP_MCP\SaaS_Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings class.
 */
class Admin_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var Admin_Settings|null
	 */
	private static ?Admin_Settings $instance = null;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-saas-settings';

	/**
	 * Connections option name.
	 *
	 * @var string
	 */
	const CONNECTIONS_OPTION = 'wp_mcp_connections';

	/**
	 * Registration code option.
	 *
	 * @var string
	 */
	const REGISTRATION_CODE_OPTION = 'wp_mcp_registration_code';

	/**
	 * Get singleton instance.
	 *
	 * @return Admin_Settings
	 */
	public static function instance(): Admin_Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->migrate_legacy_connection();
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Migrate legacy single-connection data to new multi-connection format.
	 */
	private function migrate_legacy_connection(): void {
		// Only migrate if new option does not exist yet.
		$connections = get_option( self::CONNECTIONS_OPTION );
		if ( false !== $connections ) {
			return;
		}

		$old_connection = get_option( 'wp_mcp_saas_connection' );
		$old_url        = get_option( 'wp_mcp_saas_url', '' );

		if ( ! is_array( $old_connection ) || empty( $old_connection['connected'] ) ) {
			// No legacy connection — initialise empty.
			update_option( self::CONNECTIONS_OPTION, array() );
			return;
		}

		$user_id       = (int) ( $old_connection['user_id'] ?? 0 );
		$connection_id = wp_generate_uuid4();

		// Find the permanent access token for this user.
		$tokens     = get_option( 'wp_mcp_access_tokens', array() );
		$token_hash = '';
		foreach ( $tokens as $hash => $data ) {
			if ( ! empty( $data['permanent'] ) && (int) ( $data['user_id'] ?? 0 ) === $user_id ) {
				$token_hash = $hash;
				// Update client_id to new connection_id.
				$tokens[ $hash ]['client_id'] = $connection_id;
				break;
			}
		}
		if ( '' !== $token_hash ) {
			update_option( 'wp_mcp_access_tokens', $tokens );
		}

		// Find API key named "SaaS Connection".
		$api_key_id    = '';
		$existing_keys = get_user_meta( $user_id, API_Key_Manager::API_KEY_DATA_META, true );
		if ( is_array( $existing_keys ) ) {
			foreach ( $existing_keys as $k_id => $k_data ) {
				if ( isset( $k_data['name'] ) && 'SaaS Connection' === $k_data['name'] ) {
					$api_key_id = $k_id;
					break;
				}
			}
		}

		$new_connections = array(
			$connection_id => array(
				'connection_id'      => $connection_id,
				'name'               => $old_connection['saas_name'] ?? '既存の連携',
				'app_name'           => $old_connection['saas_name'] ?? 'Unknown',
				'connected_at'       => $old_connection['connected_at'] ?? time(),
				'user_id'            => $user_id,
				'access_token_hash'  => $token_hash,
				'api_key_id'         => $api_key_id,
			),
		);

		update_option( self::CONNECTIONS_OPTION, $new_connections );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu(): void {
		add_options_page(
			'MCP 連携設定',
			'MCP 連携',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register REST routes for app connection.
	 */
	public function register_routes(): void {
		// Registration code exchange endpoint (called by app).
		register_rest_route(
			'wp-mcp/v1',
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_registration' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'registration_code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && strlen( $value ) > 0;
						},
					),
					'saas_identifier'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);

		// Connection callback endpoint (kept for backward compatibility with app-initiated redirects).
		register_rest_route(
			'wp-mcp/v1',
			'/connection-callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_connection_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'error'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Connection status polling endpoint (for admin UI).
		register_rest_route(
			'wp-mcp/v1',
			'/connection-status/(?P<connection_id>[a-f0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_connection_status' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'connection_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle admin actions (generate connection URL / disconnect).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle disconnect action.
		if ( isset( $_POST['wp_mcp_disconnect'] ) && check_admin_referer( 'wp_mcp_disconnect' ) ) {
			$connection_id = sanitize_text_field( wp_unslash( $_POST['connection_id'] ?? '' ) );
			if ( empty( $connection_id ) ) {
				add_settings_error( 'wp_mcp', 'error', '無効な連携IDです。', 'error' );
				return;
			}
			$this->disconnect( $connection_id );
			add_settings_error( 'wp_mcp', 'disconnected', '連携を解除しました。', 'updated' );
		}

		// Handle cancel pending connection.
		if ( isset( $_POST['wp_mcp_cancel_pending'] ) && check_admin_referer( 'wp_mcp_cancel_pending' ) ) {
			delete_option( self::REGISTRATION_CODE_OPTION );
			add_settings_error( 'wp_mcp', 'cancelled', '接続URLを無効化しました。', 'updated' );
		}

		// Handle generate connection URL action.
		if ( isset( $_POST['wp_mcp_generate'] ) && check_admin_referer( 'wp_mcp_generate' ) ) {
			$connection_name = sanitize_text_field( wp_unslash( $_POST['connection_name'] ?? '' ) );

			if ( empty( $connection_name ) ) {
				add_settings_error( 'wp_mcp', 'error', '連携ネームを入力してください。', 'error' );
				return;
			}

			// Ensure auth is enabled before generating.
			$this->ensure_auth_enabled();

			// Generate registration code.
			$this->generate_registration_code( $connection_name );

			// Redirect back to settings page to show the connection URL.
			wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&pending=1' ) );
			exit;
		}
	}

	/**
	 * Generate a one-time registration code for a new connection.
	 *
	 * @param string $connection_name User-assigned connection name.
	 */
	private function generate_registration_code( string $connection_name ): void {
		$code          = wp_generate_password( 64, false );
		$connection_id = wp_generate_uuid4();

		$data = array(
			'code'            => hash( 'sha256', $code ),
			'raw_code'        => $code, // Kept temporarily for display; deleted after registration or expiry.
			'created_at'      => time(),
			'expires_at'      => time() + 600, // 10 minutes.
			'user_id'         => get_current_user_id(),
			'connection_id'   => $connection_id,
			'connection_name' => sanitize_text_field( $connection_name ),
		);
		update_option( self::REGISTRATION_CODE_OPTION, $data );
	}

	/**
	 * Handle registration request from app.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_registration( $request ) {
		$registration_code = $request->get_param( 'registration_code' );
		$saas_identifier   = $request->get_param( 'saas_identifier' );

		if ( empty( $registration_code ) ) {
			return new \WP_Error( 'missing_code', '登録コードが必要です。', array( 'status' => 400 ) );
		}

		// Validate registration code.
		$stored_data = get_option( self::REGISTRATION_CODE_OPTION );
		if ( ! $stored_data ) {
			return new \WP_Error( 'invalid_code', '無効な登録コードです。', array( 'status' => 401 ) );
		}

		$code_hash = hash( 'sha256', $registration_code );
		if ( ! hash_equals( $stored_data['code'], $code_hash ) ) {
			return new \WP_Error( 'invalid_code', '無効な登録コードです。', array( 'status' => 401 ) );
		}

		if ( time() > $stored_data['expires_at'] ) {
			delete_option( self::REGISTRATION_CODE_OPTION );
			return new \WP_Error( 'expired_code', '登録コードの有効期限が切れています。', array( 'status' => 401 ) );
		}

		// Delete the registration code (one-time use).
		delete_option( self::REGISTRATION_CODE_OPTION );

		$connection_id   = $stored_data['connection_id'] ?? wp_generate_uuid4();
		$connection_name = $stored_data['connection_name'] ?? ( $saas_identifier ?? 'Unknown' );
		$user_id         = (int) ( $stored_data['user_id'] ?? 0 );

		// Ensure auth is enabled and create credentials.
		$this->ensure_auth_enabled();

		$api_key_name = $connection_name;
		if ( ! empty( $saas_identifier ) ) {
			$api_key_name = sprintf( '%s (%s)', $connection_name, $saas_identifier );
		}
		$credentials = $this->create_connection_credentials( $user_id, $connection_id, $api_key_name );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Generate a permanent access token.
		$access_token = $this->generate_permanent_access_token( $user_id, $connection_id );

		// Mark as connected.
		$this->mark_connected(
			array(
				'connection_id'      => $connection_id,
				'name'               => $connection_name,
				'app_name'           => $saas_identifier ?? '',
				'user_id'            => $user_id,
				'access_token_hash'  => hash( 'sha256', $access_token ),
				'api_key_id'         => $credentials['key_id'],
			)
		);

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'mcp_endpoint'  => rest_url( 'mcp/mcp-adapter-default-server' ),
				'access_token'  => $access_token,
				'api_key'       => $credentials['api_key'],
				'api_secret'    => $credentials['api_secret'],
				'site_url'      => get_site_url(),
				'site_name'     => get_bloginfo( 'name' ),
				'connection_id' => $connection_id,
			),
			200
		);
	}

	/**
	 * Handle connection callback from app (backward compatibility).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return void
	 */
	public function handle_connection_callback( $request ) {
		$status = $request->get_param( 'status' );
		$error  = $request->get_param( 'error' );

		$redirect_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		if ( 'success' === $status ) {
			$redirect_url = add_query_arg( 'connected', '1', $redirect_url );
		} else {
			$redirect_url = add_query_arg( 'error', rawurlencode( $error ?? '連携に失敗しました。' ), $redirect_url );
		}

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle connection status polling (AJAX from admin UI).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_connection_status( $request ): \WP_REST_Response {
		$connection_id = $request->get_param( 'connection_id' );
		$connections   = get_option( self::CONNECTIONS_OPTION, array() );

		if ( isset( $connections[ $connection_id ] ) ) {
			return new \WP_REST_Response( array( 'status' => 'connected' ), 200 );
		}

		// Check if registration code still exists (pending).
		$pending = get_option( self::REGISTRATION_CODE_OPTION );
		if ( is_array( $pending ) && ( $pending['connection_id'] ?? '' ) === $connection_id ) {
			if ( time() > ( $pending['expires_at'] ?? 0 ) ) {
				delete_option( self::REGISTRATION_CODE_OPTION );
				return new \WP_REST_Response( array( 'status' => 'expired' ), 200 );
			}
			return new \WP_REST_Response(
				array(
					'status'     => 'pending',
					'expires_at' => $pending['expires_at'],
				),
				200
			);
		}

		return new \WP_REST_Response( array( 'status' => 'not_found' ), 200 );
	}

	/**
	 * Ensure authentication is enabled.
	 */
	private function ensure_auth_enabled(): void {
		$auth_provider = SaaS_Auth_Provider::instance();
		$settings      = $auth_provider->get_settings();
		$needs_update  = false;

		if ( ! $settings['enabled'] ) {
			$settings['enabled']           = true;
			$settings['require_https']     = is_ssl();
			$settings['audit_log_enabled'] = true;
			$needs_update                  = true;
		}

		// Rate limiting is managed by each app, so disable it here.
		if ( $settings['rate_limit_enabled'] ) {
			$settings['rate_limit_enabled'] = false;
			$needs_update                   = true;
		}

		if ( $needs_update ) {
			$auth_provider->update_settings( $settings );
		}
	}

	/**
	 * Create API key credentials for a specific connection.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $connection_id Connection UUID.
	 * @param string $key_name      API key name.
	 * @return array|\WP_Error
	 */
	private function create_connection_credentials( int $user_id, string $connection_id, string $key_name ) {
		$api_key_manager = API_Key_Manager::instance();

		return $api_key_manager->create_key(
			$user_id,
			$key_name,
			array( 'read', 'write', 'admin' ),
			$connection_id
		);
	}

	/**
	 * Generate a permanent access token for a connection.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $connection_id Connection UUID (used as client_id).
	 * @return string
	 */
	private function generate_permanent_access_token( int $user_id, string $connection_id ): string {
		$access_token = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $access_token );

		$tokens                = get_option( 'wp_mcp_access_tokens', array() );
		$tokens[ $token_hash ] = array(
			'user_id'    => $user_id,
			'client_id'  => $connection_id,
			'scopes'     => array( 'read', 'write', 'admin' ),
			'created_at' => time(),
			'expires_at' => null, // Never expires.
			'permanent'  => true,
		);
		$updated = update_option( 'wp_mcp_access_tokens', $tokens );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WP MCP Auth] generate_permanent_access_token: token_prefix=%s..., hash=%s..., user_id=%d, connection_id=%s, update_result=%s, total_tokens=%d',
				substr( $access_token, 0, 8 ),
				substr( $token_hash, 0, 16 ),
				$user_id,
				$connection_id,
				$updated ? 'success' : 'FAILED',
				count( $tokens )
			) );
		}

		return $access_token;
	}

	/**
	 * Store a new connection.
	 *
	 * @param array $connection_data Connection data.
	 */
	private function mark_connected( array $connection_data ): void {
		$connections   = get_option( self::CONNECTIONS_OPTION, array() );
		$connection_id = $connection_data['connection_id'];

		$connections[ $connection_id ] = array(
			'connection_id'      => $connection_id,
			'name'               => sanitize_text_field( $connection_data['name'] ),
			'app_name'           => sanitize_text_field( $connection_data['app_name'] ?? '' ),
			'connected_at'       => time(),
			'user_id'            => $connection_data['user_id'],
			'access_token_hash'  => $connection_data['access_token_hash'],
			'api_key_id'         => $connection_data['api_key_id'],
		);

		update_option( self::CONNECTIONS_OPTION, $connections );
	}

	/**
	 * Disconnect a specific connection.
	 *
	 * @param string $connection_id Connection UUID.
	 */
	private function disconnect( string $connection_id ): void {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! isset( $connections[ $connection_id ] ) ) {
			return;
		}

		$connection = $connections[ $connection_id ];

		// Revoke the specific access token for this connection.
		$token_hash = $connection['access_token_hash'] ?? '';
		if ( ! empty( $token_hash ) ) {
			$tokens = get_option( 'wp_mcp_access_tokens', array() );
			if ( isset( $tokens[ $token_hash ] ) ) {
				unset( $tokens[ $token_hash ] );
				update_option( 'wp_mcp_access_tokens', $tokens );
			}
		}

		// Delete the specific API key for this connection.
		$user_id = (int) ( $connection['user_id'] ?? 0 );
		$key_id  = $connection['api_key_id'] ?? '';
		if ( $user_id && ! empty( $key_id ) ) {
			API_Key_Manager::instance()->delete_key_internal( $user_id, $key_id );
		}

		// Remove connection entry.
		unset( $connections[ $connection_id ] );
		update_option( self::CONNECTIONS_OPTION, $connections );
	}

	/**
	 * Check if any connections exist.
	 *
	 * @return bool
	 */
	public function has_connections(): bool {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );
		return ! empty( $connections );
	}

	/**
	 * Get all connections.
	 *
	 * @return array
	 */
	public function get_all_connections(): array {
		return get_option( self::CONNECTIONS_OPTION, array() );
	}

	/**
	 * Get a specific connection.
	 *
	 * @param string $connection_id Connection UUID.
	 * @return array|null
	 */
	public function get_connection( string $connection_id ): ?array {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );
		return $connections[ $connection_id ] ?? null;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connections  = $this->get_all_connections();
		$mcp_endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
		$pending      = get_option( self::REGISTRATION_CODE_OPTION );

		// Check if pending code has expired.
		if ( is_array( $pending ) && time() > ( $pending['expires_at'] ?? 0 ) ) {
			delete_option( self::REGISTRATION_CODE_OPTION );
			$pending = null;
		}

		$has_pending = is_array( $pending ) && ! empty( $pending['raw_code'] );

		// Show notices.
		if ( isset( $_GET['connected'] ) ) {
			echo '<div class="notice notice-success"><p>アプリとの連携が完了しました！</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( urldecode( $_GET['error'] ?? '' ) ) . '</p></div>';
		}

		settings_errors( 'wp_mcp' );

		?>
		<div class="wrap">
			<h1>MCP 連携設定</h1>

			<div class="wp-mcp-card">
				<h2>連携リスト</h2>

				<?php if ( ! empty( $connections ) ) : ?>
					<table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
						<thead>
							<tr>
								<th style="width: 30%;">連携ネーム</th>
								<th style="width: 25%;">アプリ名</th>
								<th style="width: 25%;">連携日時</th>
								<th style="width: 20%;">操作</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $connections as $conn ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $conn['name'] ?? '' ); ?></strong></td>
									<td><?php echo esc_html( $conn['app_name'] ?: '—' ); ?></td>
									<td>
										<?php
										if ( ! empty( $conn['connected_at'] ) ) {
											echo esc_html( date_i18n( 'Y/n/j H:i', $conn['connected_at'] ) );
										}
										?>
									</td>
									<td>
										<form method="post" style="display: inline;">
											<?php wp_nonce_field( 'wp_mcp_disconnect' ); ?>
											<input type="hidden" name="connection_id" value="<?php echo esc_attr( $conn['connection_id'] ?? '' ); ?>">
											<button type="submit" name="wp_mcp_disconnect" class="button button-small"
													onclick="return confirm('「<?php echo esc_js( $conn['name'] ?? '' ); ?>」の連携を解除しますか？\n解除すると、このアプリからサイトにアクセスできなくなります。');">
												解除
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<div class="wp-mcp-status wp-mcp-status-disconnected">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong>未連携</strong>
							<p>連携しているアプリはありません</p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $has_pending ) : ?>
				<?php
				$register_endpoint = rest_url( 'wp-mcp/v1/register' );
				$connection_url    = add_query_arg( 'code', $pending['raw_code'], $register_endpoint );
				$connection_id     = $pending['connection_id'] ?? '';
				$expires_at        = $pending['expires_at'] ?? 0;
				$status_endpoint   = rest_url( 'wp-mcp/v1/connection-status/' . $connection_id );
				?>
				<div class="wp-mcp-card">
					<h2>接続URL — <?php echo esc_html( $pending['connection_name'] ?? '' ); ?></h2>

					<div class="wp-mcp-pending-box">
						<p class="wp-mcp-pending-label">この接続URLをアプリに貼り付けてください:</p>
						<div class="wp-mcp-url-display">
							<code id="wp-mcp-connection-url"><?php echo esc_html( $connection_url ); ?></code>
							<button type="button" class="button wp-mcp-copy" data-copy="<?php echo esc_attr( $connection_url ); ?>">
								コピー
							</button>
						</div>

						<div class="wp-mcp-pending-info">
							<table class="wp-mcp-info-table">
								<tr>
									<th>サイト URL</th>
									<td><code><?php echo esc_html( get_site_url() ); ?></code></td>
								</tr>
								<tr>
									<th>MCP エンドポイント</th>
									<td><code><?php echo esc_html( $mcp_endpoint ); ?></code></td>
								</tr>
								<tr>
									<th>登録エンドポイント</th>
									<td><code><?php echo esc_html( $register_endpoint ); ?></code></td>
								</tr>
							</table>
						</div>

						<div class="wp-mcp-pending-status" id="wp-mcp-pending-status">
							<span class="dashicons dashicons-update wp-mcp-spin"></span>
							<span>アプリからの接続を待機中...</span>
							<span class="wp-mcp-timer" id="wp-mcp-timer"></span>
						</div>
					</div>

					<form method="post" style="margin-top: 16px;">
						<?php wp_nonce_field( 'wp_mcp_cancel_pending' ); ?>
						<button type="submit" name="wp_mcp_cancel_pending" class="button button-secondary">
							キャンセル
						</button>
					</form>
				</div>

				<script>
				(function() {
					var expiresAt = <?php echo (int) $expires_at; ?>;
					var statusUrl = <?php echo wp_json_encode( $status_endpoint ); ?>;
					var settingsUrl = <?php echo wp_json_encode( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&connected=1' ) ); ?>;
					var timerEl = document.getElementById('wp-mcp-timer');
					var statusEl = document.getElementById('wp-mcp-pending-status');

					function updateTimer() {
						var remaining = expiresAt - Math.floor(Date.now() / 1000);
						if (remaining <= 0) {
							timerEl.textContent = '（有効期限切れ）';
							statusEl.innerHTML = '<span class="dashicons dashicons-no" style="color:#dc3232;"></span> <span>接続URLの有効期限が切れました。新しいURLを生成してください。</span>';
							return;
						}
						var min = Math.floor(remaining / 60);
						var sec = remaining % 60;
						timerEl.textContent = '（残り ' + min + '分' + (sec < 10 ? '0' : '') + sec + '秒）';
						setTimeout(updateTimer, 1000);
					}
					updateTimer();

					function pollStatus() {
						fetch(statusUrl, {
							credentials: 'same-origin',
							headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> }
						})
						.then(function(res) { return res.json(); })
						.then(function(data) {
							if (data.status === 'connected') {
								statusEl.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <span>連携が完了しました！ページを更新しています...</span>';
								setTimeout(function() { window.location.href = settingsUrl; }, 1000);
							} else if (data.status === 'expired' || data.status === 'not_found') {
								statusEl.innerHTML = '<span class="dashicons dashicons-no" style="color:#dc3232;"></span> <span>接続URLの有効期限が切れました。新しいURLを生成してください。</span>';
							} else {
								setTimeout(pollStatus, 3000);
							}
						})
						.catch(function() {
							setTimeout(pollStatus, 5000);
						});
					}
					setTimeout(pollStatus, 3000);
				})();
				</script>

			<?php else : ?>
				<div class="wp-mcp-card">
					<h2>新しい連携を追加</h2>
					<form method="post">
						<?php wp_nonce_field( 'wp_mcp_generate' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="connection_name">連携ネーム</label>
								</th>
								<td>
									<input type="text" id="connection_name" name="connection_name"
										   class="regular-text"
										   placeholder="例: マーケティング連携"
										   required>
									<p class="description">
										この連携を識別するための名前を入力してください。
									</p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" name="wp_mcp_generate" class="button button-primary button-hero">
								接続URLを生成する
							</button>
						</p>
					</form>
				</div>
			<?php endif; ?>

			<div class="wp-mcp-card">
				<h2>MCP サーバー情報</h2>
				<table class="wp-mcp-info-table">
					<tr>
						<th>MCP エンドポイント</th>
						<td>
							<code><?php echo esc_html( $mcp_endpoint ); ?></code>
							<button type="button" class="button button-small wp-mcp-copy" data-copy="<?php echo esc_attr( $mcp_endpoint ); ?>">
								コピー
							</button>
						</td>
					</tr>
					<tr>
						<th>サイト URL</th>
						<td><code><?php echo esc_html( get_site_url() ); ?></code></td>
					</tr>
					<tr>
						<th>サイト名</th>
						<td><?php echo esc_html( get_bloginfo( 'name' ) ); ?></td>
					</tr>
				</table>
			</div>

			<div class="wp-mcp-card">
				<h2>利用可能な機能</h2>
				<p>連携が完了すると、アプリから以下の操作が可能になります：</p>
				<ul class="wp-mcp-features">
					<li><span class="dashicons dashicons-admin-post"></span> 記事の作成・編集・削除</li>
					<li><span class="dashicons dashicons-admin-media"></span> メディアのアップロード・管理</li>
					<li><span class="dashicons dashicons-category"></span> カテゴリ・タグの管理</li>
					<li><span class="dashicons dashicons-admin-appearance"></span> テーマスタイル・ブロックパターンの取得</li>
					<li><span class="dashicons dashicons-chart-line"></span> SEO チェック・コンテンツ検証</li>
				</ul>
			</div>
		</div>

		<style>
		.wp-mcp-card {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			padding: 20px 24px;
			margin: 20px 0;
			max-width: 800px;
		}
		.wp-mcp-card h2 {
			margin-top: 0;
			padding-bottom: 12px;
			border-bottom: 1px solid #eee;
		}
		.wp-mcp-status {
			display: flex;
			align-items: flex-start;
			padding: 16px;
			border-radius: 4px;
			gap: 12px;
		}
		.wp-mcp-status .dashicons {
			font-size: 32px;
			width: 32px;
			height: 32px;
		}
		.wp-mcp-status-disconnected {
			background: #fff3cd;
			border: 1px solid #ffeeba;
		}
		.wp-mcp-status-disconnected .dashicons {
			color: #856404;
		}
		.wp-mcp-status strong {
			font-size: 16px;
		}
		.wp-mcp-status p {
			margin: 4px 0 0;
		}
		.wp-mcp-info-table {
			width: 100%;
			border-collapse: collapse;
		}
		.wp-mcp-info-table th {
			text-align: left;
			padding: 12px 0;
			width: 180px;
			vertical-align: top;
		}
		.wp-mcp-info-table td {
			padding: 12px 0;
		}
		.wp-mcp-info-table code {
			background: #f0f0f1;
			padding: 4px 8px;
			font-size: 13px;
		}
		.wp-mcp-copy {
			margin-left: 8px !important;
		}
		.wp-mcp-features {
			list-style: none;
			padding: 0;
			margin: 0;
		}
		.wp-mcp-features li {
			padding: 8px 0;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.wp-mcp-features .dashicons {
			color: #0073aa;
		}
		.button-hero {
			padding: 12px 24px !important;
			height: auto !important;
			font-size: 16px !important;
		}
		.wp-list-table code {
			background: #f0f0f1;
			padding: 2px 6px;
		}
		.wp-mcp-pending-box {
			background: #f0f6fc;
			border: 1px solid #c3d1e0;
			border-radius: 4px;
			padding: 20px;
		}
		.wp-mcp-pending-label {
			font-weight: 600;
			margin-top: 0;
			margin-bottom: 12px;
		}
		.wp-mcp-url-display {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 16px;
		}
		.wp-mcp-url-display code {
			background: #fff;
			border: 1px solid #ccd0d4;
			padding: 10px 14px;
			font-size: 13px;
			flex: 1;
			word-break: break-all;
			display: block;
		}
		.wp-mcp-pending-info {
			margin-bottom: 16px;
			padding-top: 12px;
			border-top: 1px solid #c3d1e0;
		}
		.wp-mcp-pending-status {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 12px 16px;
			background: #fff;
			border: 1px solid #ccd0d4;
			border-radius: 4px;
			font-size: 14px;
		}
		.wp-mcp-spin {
			animation: wp-mcp-rotation 1.5s linear infinite;
		}
		@keyframes wp-mcp-rotation {
			from { transform: rotate(0deg); }
			to   { transform: rotate(360deg); }
		}
		.wp-mcp-timer {
			color: #666;
			font-size: 13px;
		}
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll('.wp-mcp-copy').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var text = this.getAttribute('data-copy');
					navigator.clipboard.writeText(text).then(function() {
						var original = btn.textContent;
						btn.textContent = 'コピーしました！';
						setTimeout(function() {
							btn.textContent = original;
						}, 2000);
					});
				});
			});
		});
		</script>
		<?php
	}
}
