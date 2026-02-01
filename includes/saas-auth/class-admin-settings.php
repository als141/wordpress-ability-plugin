<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Admin Settings Page for SaaS Connection.
 *
 * Simplified Japanese UI for one-click SaaS connection.
 *
 * @package WP_MCP
 */

declare( strict_types=1 );

namespace WP_MCP\SaaS_Auth;

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
	 * Connection status option.
	 *
	 * @var string
	 */
	const CONNECTION_OPTION = 'wp_mcp_saas_connection';

	/**
	 * Registration code option.
	 *
	 * @var string
	 */
	const REGISTRATION_CODE_OPTION = 'wp_mcp_registration_code';

	/**
	 * SaaS URL placeholder - replace with actual SaaS URL.
	 *
	 * @var string
	 */
	const SAAS_URL = 'https://your-saas-app.example.com';

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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
	 * Register REST routes for SaaS connection.
	 */
	public function register_routes(): void {
		// Registration code exchange endpoint (called by SaaS).
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

		// Connection callback endpoint (redirected from SaaS).
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
	}

	/**
	 * Handle admin actions (connect/disconnect).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle disconnect action.
		if ( isset( $_POST['wp_mcp_disconnect'] ) && check_admin_referer( 'wp_mcp_disconnect' ) ) {
			$this->disconnect();
			add_settings_error( 'wp_mcp', 'disconnected', '連携を解除しました。', 'updated' );
		}

		// Handle connect action.
		if ( isset( $_POST['wp_mcp_connect'] ) && check_admin_referer( 'wp_mcp_connect' ) ) {
			$saas_url = sanitize_url( $_POST['saas_url'] ?? '' );
			if ( empty( $saas_url ) ) {
				add_settings_error( 'wp_mcp', 'error', 'SaaS の URL を入力してください。', 'error' );
				return;
			}

			// Generate registration code and redirect.
			$registration_code = $this->generate_registration_code();
			$callback_url      = rest_url( 'wp-mcp/v1/connection-callback' );
			$mcp_endpoint      = rest_url( 'mcp/mcp-adapter-default-server' );
			$register_endpoint = rest_url( 'wp-mcp/v1/register' );

			$connect_url = add_query_arg(
				array(
					'action'            => 'wordpress_mcp_connect',
					'site_url'          => rawurlencode( get_site_url() ),
					'site_name'         => rawurlencode( get_bloginfo( 'name' ) ),
					'mcp_endpoint'      => rawurlencode( $mcp_endpoint ),
					'register_endpoint' => rawurlencode( $register_endpoint ),
					'registration_code' => $registration_code,
					'callback_url'      => rawurlencode( $callback_url ),
				),
				trailingslashit( $saas_url ) . 'connect/wordpress'
			);

			// Store SaaS URL for later use.
			update_option( 'wp_mcp_saas_url', $saas_url );

			wp_redirect( $connect_url );
			exit;
		}
	}

	/**
	 * Generate a one-time registration code.
	 *
	 * @return string
	 */
	private function generate_registration_code(): string {
		$code = wp_generate_password( 64, false );
		$data = array(
			'code'       => hash( 'sha256', $code ),
			'created_at' => time(),
			'expires_at' => time() + 600, // 10 minutes.
			'user_id'    => get_current_user_id(),
		);
		update_option( self::REGISTRATION_CODE_OPTION, $data );
		return $code;
	}

	/**
	 * Handle registration request from SaaS.
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

		// Ensure SaaS auth is enabled and create/get credentials.
		$this->ensure_saas_auth_enabled();
		$credentials = $this->get_or_create_saas_credentials( $stored_data['user_id'] );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Generate a permanent access token.
		$access_token = $this->generate_permanent_access_token( $stored_data['user_id'], $saas_identifier ?? 'saas' );

		// Mark as connected.
		$this->mark_connected( $saas_identifier ?? 'Unknown SaaS' );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'mcp_endpoint' => rest_url( 'mcp/mcp-adapter-default-server' ),
				'access_token' => $access_token,
				'api_key'      => $credentials['api_key'],
				'api_secret'   => $credentials['api_secret'],
				'site_url'     => get_site_url(),
				'site_name'    => get_bloginfo( 'name' ),
			),
			200
		);
	}

	/**
	 * Handle connection callback from SaaS.
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
	 * Ensure SaaS authentication is enabled.
	 */
	private function ensure_saas_auth_enabled(): void {
		$auth_provider = SaaS_Auth_Provider::instance();
		$settings      = $auth_provider->get_settings();
		$needs_update  = false;

		if ( ! $settings['enabled'] ) {
			$settings['enabled']           = true;
			$settings['require_https']     = is_ssl();
			$settings['audit_log_enabled'] = true;
			$needs_update                  = true;
		}

		// SaaS経由ではレート制限はSaaS側で管理するため常に無効化
		if ( $settings['rate_limit_enabled'] ) {
			$settings['rate_limit_enabled'] = false;
			$needs_update                   = true;
		}

		if ( $needs_update ) {
			$auth_provider->update_settings( $settings );
		}
	}

	/**
	 * Get or create SaaS credentials for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error
	 */
	private function get_or_create_saas_credentials( int $user_id ) {
		$api_key_manager = API_Key_Manager::instance();

		// Check if SaaS key already exists.
		$existing_keys = get_user_meta( $user_id, API_Key_Manager::API_KEY_DATA_META, true );
		if ( is_array( $existing_keys ) ) {
			foreach ( $existing_keys as $key_id => $key_data ) {
				if ( isset( $key_data['name'] ) && 'SaaS Connection' === $key_data['name'] ) {
					// Regenerate to get the secret.
					return $api_key_manager->regenerate_key_for_saas( $user_id, $key_id );
				}
			}
		}

		// Create new key with all scopes.
		$result = $api_key_manager->create_key(
			$user_id,
			'SaaS Connection',
			array( 'read', 'write', 'admin' )
		);

		return $result;
	}

	/**
	 * Generate a permanent access token.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $client_id Client identifier.
	 * @return string
	 */
	private function generate_permanent_access_token( int $user_id, string $client_id ): string {
		$access_token = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $access_token );

		$tokens                = get_option( 'wp_mcp_access_tokens', array() );
		$tokens[ $token_hash ] = array(
			'user_id'    => $user_id,
			'client_id'  => $client_id,
			'scopes'     => array( 'read', 'write', 'admin' ),
			'created_at' => time(),
			'expires_at' => null, // Never expires.
			'permanent'  => true,
		);
		$updated = update_option( 'wp_mcp_access_tokens', $tokens );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WP MCP Auth] generate_permanent_access_token: token_prefix=%s..., hash=%s..., user_id=%d, update_result=%s, total_tokens=%d',
				substr( $access_token, 0, 8 ),
				substr( $token_hash, 0, 16 ),
				$user_id,
				$updated ? 'success' : 'FAILED',
				count( $tokens )
			) );
		}

		return $access_token;
	}

	/**
	 * Mark the site as connected.
	 *
	 * @param string $saas_name SaaS service name.
	 */
	private function mark_connected( string $saas_name ): void {
		update_option(
			self::CONNECTION_OPTION,
			array(
				'connected'    => true,
				'saas_name'    => sanitize_text_field( $saas_name ),
				'connected_at' => time(),
				'user_id'      => get_current_user_id(),
			)
		);
	}

	/**
	 * Disconnect from SaaS.
	 */
	private function disconnect(): void {
		$connection = get_option( self::CONNECTION_OPTION );

		// Revoke permanent tokens.
		$tokens = get_option( 'wp_mcp_access_tokens', array() );
		foreach ( $tokens as $hash => $data ) {
			if ( ! empty( $data['permanent'] ) ) {
				unset( $tokens[ $hash ] );
			}
		}
		update_option( 'wp_mcp_access_tokens', $tokens );

		// Clean up API keys for the connected user.
		if ( is_array( $connection ) && ! empty( $connection['user_id'] ) ) {
			$user_id = (int) $connection['user_id'];
			delete_user_meta( $user_id, \WP_MCP\SaaS_Auth\API_Key_Manager::API_KEY_META );
			delete_user_meta( $user_id, \WP_MCP\SaaS_Auth\API_Key_Manager::API_KEY_DATA_META );
		}

		// Remove connection.
		delete_option( self::CONNECTION_OPTION );
	}

	/**
	 * Check if connected to SaaS.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		$connection = get_option( self::CONNECTION_OPTION );
		return is_array( $connection ) && ! empty( $connection['connected'] );
	}

	/**
	 * Get connection info.
	 *
	 * @return array|null
	 */
	public function get_connection_info(): ?array {
		$connection = get_option( self::CONNECTION_OPTION );
		return is_array( $connection ) ? $connection : null;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_connected    = $this->is_connected();
		$connection_info = $this->get_connection_info();
		$mcp_endpoint    = rest_url( 'mcp/mcp-adapter-default-server' );
		$saas_url        = get_option( 'wp_mcp_saas_url', self::SAAS_URL );

		// Show notices.
		if ( isset( $_GET['connected'] ) ) {
			echo '<div class="notice notice-success"><p>SaaS との連携が完了しました！</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( urldecode( $_GET['error'] ) ) . '</p></div>';
		}

		settings_errors( 'wp_mcp' );

		?>
		<div class="wrap">
			<h1>MCP 連携設定</h1>

			<div class="wp-mcp-card">
				<h2>接続状態</h2>
				<?php if ( $is_connected ) : ?>
					<div class="wp-mcp-status wp-mcp-status-connected">
						<span class="dashicons dashicons-yes-alt"></span>
						<div>
							<strong>連携中</strong>
							<?php if ( ! empty( $connection_info['saas_name'] ) ) : ?>
								<p><?php echo esc_html( $connection_info['saas_name'] ); ?> と連携しています</p>
							<?php endif; ?>
							<?php if ( ! empty( $connection_info['connected_at'] ) ) : ?>
								<p class="description">
									連携日時: <?php echo esc_html( date_i18n( 'Y年n月j日 H:i', $connection_info['connected_at'] ) ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<form method="post" style="margin-top: 20px;">
						<?php wp_nonce_field( 'wp_mcp_disconnect' ); ?>
						<button type="submit" name="wp_mcp_disconnect" class="button button-secondary"
								onclick="return confirm('連携を解除しますか？\n解除すると、SaaS からこのサイトにアクセスできなくなります。');">
							連携を解除する
						</button>
					</form>

				<?php else : ?>
					<div class="wp-mcp-status wp-mcp-status-disconnected">
						<span class="dashicons dashicons-warning"></span>
						<div>
							<strong>未連携</strong>
							<p>SaaS サービスと連携していません</p>
						</div>
					</div>

					<form method="post" style="margin-top: 20px;">
						<?php wp_nonce_field( 'wp_mcp_connect' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="saas_url">連携先 SaaS の URL</label>
								</th>
								<td>
									<input type="url" id="saas_url" name="saas_url"
										   value="<?php echo esc_attr( $saas_url ); ?>"
										   class="regular-text"
										   placeholder="https://your-saas-app.example.com"
										   required>
									<p class="description">
										連携する SaaS サービスの URL を入力してください。
									</p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<button type="submit" name="wp_mcp_connect" class="button button-primary button-hero">
								SaaS と連携する
							</button>
						</p>
					</form>
				<?php endif; ?>
			</div>

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
				<p>連携が完了すると、SaaS から以下の操作が可能になります：</p>
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
		.wp-mcp-status-connected {
			background: #d4edda;
			border: 1px solid #c3e6cb;
		}
		.wp-mcp-status-connected .dashicons {
			color: #28a745;
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
		</style>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll('.wp-mcp-copy').forEach(function(btn) {
				btn.addEventListener('click', function() {
					const text = this.getAttribute('data-copy');
					navigator.clipboard.writeText(text).then(function() {
						const original = btn.textContent;
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
