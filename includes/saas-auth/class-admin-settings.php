<?php
/**
 * Admin Settings Page for SaaS Authentication.
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
	 * Option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'wp_mcp_saas_options';

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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu(): void {
		add_options_page(
			__( 'MCP SaaS Settings', 'wp-mcp' ),
			__( 'MCP SaaS', 'wp-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-admin',
			plugins_url( 'assets/css/admin.css', dirname( __DIR__ ) . '/readonly-ability-plugin.php' ),
			array(),
			defined( 'WP_MCP_PLUGIN_VERSION' ) ? WP_MCP_PLUGIN_VERSION : '1.0.0'
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			SaaS_Auth_Provider::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// General Settings Section.
		add_settings_section(
			'wp_mcp_general',
			__( 'General Settings', 'wp-mcp' ),
			array( $this, 'render_general_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable SaaS Authentication', 'wp-mcp' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_general',
			array(
				'field'       => 'enabled',
				'description' => __( 'Allow external SaaS services to authenticate via API keys or tokens.', 'wp-mcp' ),
			)
		);

		add_settings_field(
			'require_https',
			__( 'Require HTTPS', 'wp-mcp' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_general',
			array(
				'field'       => 'require_https',
				'description' => __( 'Only allow SaaS authentication over HTTPS connections.', 'wp-mcp' ),
			)
		);

		// JWT Settings Section.
		add_settings_section(
			'wp_mcp_jwt',
			__( 'JWT Settings', 'wp-mcp' ),
			array( $this, 'render_jwt_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'jwt_secret',
			__( 'JWT Secret Key', 'wp-mcp' ),
			array( $this, 'render_password_field' ),
			self::PAGE_SLUG,
			'wp_mcp_jwt',
			array(
				'field'       => 'jwt_secret',
				'description' => __( 'Secret key for signing and verifying JWT tokens. Leave empty to disable JWT authentication.', 'wp-mcp' ),
			)
		);

		add_settings_field(
			'jwt_issuer',
			__( 'JWT Issuer', 'wp-mcp' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'wp_mcp_jwt',
			array(
				'field'       => 'jwt_issuer',
				'description' => __( 'Expected issuer (iss claim) for incoming JWT tokens. Leave empty to skip issuer validation.', 'wp-mcp' ),
				'placeholder' => 'https://your-saas-app.com',
			)
		);

		add_settings_field(
			'token_expiry_hours',
			__( 'Token Expiry (hours)', 'wp-mcp' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'wp_mcp_jwt',
			array(
				'field'       => 'token_expiry_hours',
				'description' => __( 'How long generated access tokens remain valid.', 'wp-mcp' ),
				'min'         => 1,
				'max'         => 720,
			)
		);

		// Rate Limiting Section.
		add_settings_section(
			'wp_mcp_rate_limit',
			__( 'Rate Limiting', 'wp-mcp' ),
			array( $this, 'render_rate_limit_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'rate_limit_enabled',
			__( 'Enable Rate Limiting', 'wp-mcp' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_rate_limit',
			array(
				'field'       => 'rate_limit_enabled',
				'description' => __( 'Limit the number of requests per time window.', 'wp-mcp' ),
			)
		);

		add_settings_field(
			'rate_limit_requests',
			__( 'Max Requests', 'wp-mcp' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'wp_mcp_rate_limit',
			array(
				'field'       => 'rate_limit_requests',
				'description' => __( 'Maximum number of requests allowed per window.', 'wp-mcp' ),
				'min'         => 10,
				'max'         => 10000,
			)
		);

		add_settings_field(
			'rate_limit_window',
			__( 'Time Window (seconds)', 'wp-mcp' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'wp_mcp_rate_limit',
			array(
				'field'       => 'rate_limit_window',
				'description' => __( 'Time window for rate limiting.', 'wp-mcp' ),
				'min'         => 60,
				'max'         => 86400,
			)
		);

		// Audit Logging Section.
		add_settings_section(
			'wp_mcp_audit',
			__( 'Audit Logging', 'wp-mcp' ),
			array( $this, 'render_audit_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'audit_log_enabled',
			__( 'Enable Audit Logging', 'wp-mcp' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'wp_mcp_audit',
			array(
				'field'       => 'audit_log_enabled',
				'description' => __( 'Log all authentication attempts for security monitoring.', 'wp-mcp' ),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input values.
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = array();

		$sanitized['enabled']             = ! empty( $input['enabled'] );
		$sanitized['require_https']       = ! empty( $input['require_https'] );
		$sanitized['jwt_secret']          = sanitize_text_field( $input['jwt_secret'] ?? '' );
		$sanitized['jwt_issuer']          = esc_url_raw( $input['jwt_issuer'] ?? '' );
		$sanitized['token_expiry_hours']  = absint( $input['token_expiry_hours'] ?? 24 );
		$sanitized['rate_limit_enabled']  = ! empty( $input['rate_limit_enabled'] );
		$sanitized['rate_limit_requests'] = absint( $input['rate_limit_requests'] ?? 100 );
		$sanitized['rate_limit_window']   = absint( $input['rate_limit_window'] ?? 3600 );
		$sanitized['audit_log_enabled']   = ! empty( $input['audit_log_enabled'] );

		// Validate ranges.
		$sanitized['token_expiry_hours']  = max( 1, min( 720, $sanitized['token_expiry_hours'] ) );
		$sanitized['rate_limit_requests'] = max( 10, min( 10000, $sanitized['rate_limit_requests'] ) );
		$sanitized['rate_limit_window']   = max( 60, min( 86400, $sanitized['rate_limit_window'] ) );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$auth_provider = SaaS_Auth_Provider::instance();
		$settings      = $auth_provider->get_settings();
		$site_url      = get_site_url();
		$rest_url      = get_rest_url();

		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="wp-mcp-info-box">
				<h3><?php esc_html_e( 'MCP Server Endpoints', 'wp-mcp' ); ?></h3>
				<table class="wp-mcp-endpoints-table">
					<tr>
						<th><?php esc_html_e( 'MCP Endpoint', 'wp-mcp' ); ?></th>
						<td><code><?php echo esc_html( trailingslashit( $rest_url ) . 'mcp/mcp-adapter-default-server' ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Token Endpoint', 'wp-mcp' ); ?></th>
						<td><code><?php echo esc_html( trailingslashit( $rest_url ) . 'wp-mcp/v1/token' ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'OAuth Metadata', 'wp-mcp' ); ?></th>
						<td><code><?php echo esc_html( trailingslashit( $site_url ) . '.well-known/oauth-protected-resource' ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'MCP Metadata', 'wp-mcp' ); ?></th>
						<td><code><?php echo esc_html( trailingslashit( $site_url ) . '.well-known/mcp.json' ); ?></code></td>
					</tr>
				</table>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<div class="wp-mcp-info-box">
				<h3><?php esc_html_e( 'Authentication Methods', 'wp-mcp' ); ?></h3>
				<p><?php esc_html_e( 'The following authentication methods are supported:', 'wp-mcp' ); ?></p>
				<ul>
					<li><strong>Bearer Token:</strong> <code>Authorization: Bearer &lt;token&gt;</code></li>
					<li><strong>API Key:</strong> <code>Authorization: ApiKey &lt;api_key&gt;:&lt;signature&gt;</code></li>
					<li><strong>Basic Auth:</strong> <code>Authorization: Basic base64(api_key:api_secret)</code></li>
				</ul>
			</div>

			<?php $this->render_api_keys_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render API keys management section.
	 */
	private function render_api_keys_section(): void {
		$api_key_manager = API_Key_Manager::instance();
		$user_id         = get_current_user_id();
		?>
		<div class="wp-mcp-api-keys-section">
			<h2><?php esc_html_e( 'Your API Keys', 'wp-mcp' ); ?></h2>
			<p><?php esc_html_e( 'Generate API keys to allow SaaS services to authenticate as you.', 'wp-mcp' ); ?></p>

			<div id="wp-mcp-api-keys-list">
				<p class="loading"><?php esc_html_e( 'Loading...', 'wp-mcp' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Create New API Key', 'wp-mcp' ); ?></h3>
			<form id="wp-mcp-create-api-key-form">
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="api-key-name"><?php esc_html_e( 'Name', 'wp-mcp' ); ?></label>
						</th>
						<td>
							<input type="text" id="api-key-name" name="name" class="regular-text" required
								   placeholder="<?php esc_attr_e( 'My SaaS Application', 'wp-mcp' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'wp-mcp' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="scopes[]" value="read" checked>
									<?php esc_html_e( 'Read', 'wp-mcp' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="scopes[]" value="write">
									<?php esc_html_e( 'Write', 'wp-mcp' ); ?>
								</label><br>
								<label>
									<input type="checkbox" name="scopes[]" value="admin">
									<?php esc_html_e( 'Admin', 'wp-mcp' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php wp_nonce_field( 'wp_mcp_create_api_key', 'wp_mcp_nonce' ); ?>
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Generate API Key', 'wp-mcp' ); ?>
					</button>
				</p>
			</form>

			<div id="wp-mcp-new-key-display" style="display: none;">
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Save these credentials now!', 'wp-mcp' ); ?></strong></p>
					<p><?php esc_html_e( 'The API secret will not be shown again.', 'wp-mcp' ); ?></p>
				</div>
				<table class="wp-mcp-credentials-table">
					<tr>
						<th><?php esc_html_e( 'API Key', 'wp-mcp' ); ?></th>
						<td><code id="new-api-key"></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'API Secret', 'wp-mcp' ); ?></th>
						<td><code id="new-api-secret"></code></td>
					</tr>
				</table>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			const restUrl = '<?php echo esc_js( get_rest_url() ); ?>';
			const nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

			function loadApiKeys() {
				$.ajax({
					url: restUrl + 'wp-mcp/v1/api-keys',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', nonce);
					},
					success: function(keys) {
						renderApiKeys(keys);
					},
					error: function() {
						$('#wp-mcp-api-keys-list').html('<p class="error"><?php esc_html_e( 'Failed to load API keys.', 'wp-mcp' ); ?></p>');
					}
				});
			}

			function renderApiKeys(keys) {
				if (keys.length === 0) {
					$('#wp-mcp-api-keys-list').html('<p><?php esc_html_e( 'No API keys found.', 'wp-mcp' ); ?></p>');
					return;
				}

				let html = '<table class="wp-list-table widefat fixed striped">';
				html += '<thead><tr><th><?php esc_html_e( 'Name', 'wp-mcp' ); ?></th><th><?php esc_html_e( 'Key Prefix', 'wp-mcp' ); ?></th><th><?php esc_html_e( 'Scopes', 'wp-mcp' ); ?></th><th><?php esc_html_e( 'Last Used', 'wp-mcp' ); ?></th><th><?php esc_html_e( 'Actions', 'wp-mcp' ); ?></th></tr></thead>';
				html += '<tbody>';

				keys.forEach(function(key) {
					const lastUsed = key.last_used ? new Date(key.last_used * 1000).toLocaleString() : '<?php esc_html_e( 'Never', 'wp-mcp' ); ?>';
					html += '<tr>';
					html += '<td>' + escapeHtml(key.name) + '</td>';
					html += '<td><code>' + escapeHtml(key.key_prefix) + '</code></td>';
					html += '<td>' + escapeHtml(key.scopes.join(', ')) + '</td>';
					html += '<td>' + lastUsed + '</td>';
					html += '<td><button class="button delete-key" data-key-id="' + escapeHtml(key.key_id) + '"><?php esc_html_e( 'Delete', 'wp-mcp' ); ?></button></td>';
					html += '</tr>';
				});

				html += '</tbody></table>';
				$('#wp-mcp-api-keys-list').html(html);
			}

			function escapeHtml(text) {
				const div = document.createElement('div');
				div.textContent = text;
				return div.innerHTML;
			}

			$('#wp-mcp-create-api-key-form').on('submit', function(e) {
				e.preventDefault();

				const name = $('#api-key-name').val();
				const scopes = [];
				$('input[name="scopes[]"]:checked').each(function() {
					scopes.push($(this).val());
				});

				$.ajax({
					url: restUrl + 'wp-mcp/v1/api-keys',
					method: 'POST',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', nonce);
					},
					contentType: 'application/json',
					data: JSON.stringify({ name: name, scopes: scopes }),
					success: function(response) {
						$('#new-api-key').text(response.api_key);
						$('#new-api-secret').text(response.api_secret);
						$('#wp-mcp-new-key-display').show();
						$('#api-key-name').val('');
						loadApiKeys();
					},
					error: function(xhr) {
						const error = xhr.responseJSON ? xhr.responseJSON.message : '<?php esc_html_e( 'Failed to create API key.', 'wp-mcp' ); ?>';
						alert(error);
					}
				});
			});

			$(document).on('click', '.delete-key', function() {
				if (!confirm('<?php esc_html_e( 'Are you sure you want to delete this API key?', 'wp-mcp' ); ?>')) {
					return;
				}

				const keyId = $(this).data('key-id');

				$.ajax({
					url: restUrl + 'wp-mcp/v1/api-keys/' + keyId,
					method: 'DELETE',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', nonce);
					},
					success: function() {
						loadApiKeys();
					},
					error: function() {
						alert('<?php esc_html_e( 'Failed to delete API key.', 'wp-mcp' ); ?>');
					}
				});
			});

			loadApiKeys();
		});
		</script>

		<style>
		.wp-mcp-info-box {
			background: #fff;
			border: 1px solid #ccd0d4;
			border-left: 4px solid #0073aa;
			padding: 12px 20px;
			margin: 20px 0;
		}
		.wp-mcp-info-box h3 {
			margin-top: 0;
		}
		.wp-mcp-endpoints-table {
			width: 100%;
		}
		.wp-mcp-endpoints-table th {
			width: 150px;
			text-align: left;
			padding: 8px 0;
		}
		.wp-mcp-endpoints-table code {
			background: #f0f0f1;
			padding: 4px 8px;
		}
		.wp-mcp-api-keys-section {
			margin-top: 30px;
			padding-top: 20px;
			border-top: 1px solid #ccd0d4;
		}
		.wp-mcp-credentials-table {
			background: #fffbcc;
			border: 1px solid #e6db55;
			padding: 10px;
			margin: 10px 0;
		}
		.wp-mcp-credentials-table th {
			width: 100px;
			text-align: left;
			padding: 5px;
		}
		.wp-mcp-credentials-table code {
			background: #fff;
			padding: 4px 8px;
			font-size: 13px;
		}
		</style>
		<?php
	}

	/**
	 * Render general section description.
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Configure general settings for SaaS authentication.', 'wp-mcp' ) . '</p>';
	}

	/**
	 * Render JWT section description.
	 */
	public function render_jwt_section(): void {
		echo '<p>' . esc_html__( 'Configure JWT (JSON Web Token) authentication settings.', 'wp-mcp' ) . '</p>';
	}

	/**
	 * Render rate limit section description.
	 */
	public function render_rate_limit_section(): void {
		echo '<p>' . esc_html__( 'Configure rate limiting to prevent abuse.', 'wp-mcp' ) . '</p>';
	}

	/**
	 * Render audit section description.
	 */
	public function render_audit_section(): void {
		echo '<p>' . esc_html__( 'Configure audit logging for security monitoring.', 'wp-mcp' ) . '</p>';
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( array $args ): void {
		$settings = SaaS_Auth_Provider::instance()->get_settings();
		$field    = $args['field'];
		$value    = $settings[ $field ] ?? false;

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
			esc_attr( SaaS_Auth_Provider::OPTION_NAME ),
			esc_attr( $field ),
			checked( $value, true, false ),
			esc_html( $args['description'] ?? '' )
		);
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( array $args ): void {
		$settings    = SaaS_Auth_Provider::instance()->get_settings();
		$field       = $args['field'];
		$value       = $settings[ $field ] ?? '';
		$placeholder = $args['placeholder'] ?? '';

		printf(
			'<input type="text" name="%s[%s]" value="%s" class="regular-text" placeholder="%s">',
			esc_attr( SaaS_Auth_Provider::OPTION_NAME ),
			esc_attr( $field ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render password field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_password_field( array $args ): void {
		$settings = SaaS_Auth_Provider::instance()->get_settings();
		$field    = $args['field'];
		$value    = $settings[ $field ] ?? '';

		printf(
			'<input type="password" name="%s[%s]" value="%s" class="regular-text">',
			esc_attr( SaaS_Auth_Provider::OPTION_NAME ),
			esc_attr( $field ),
			esc_attr( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render number field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number_field( array $args ): void {
		$settings = SaaS_Auth_Provider::instance()->get_settings();
		$field    = $args['field'];
		$value    = $settings[ $field ] ?? 0;
		$min      = $args['min'] ?? 0;
		$max      = $args['max'] ?? 9999;

		printf(
			'<input type="number" name="%s[%s]" value="%d" min="%d" max="%d" class="small-text">',
			esc_attr( SaaS_Auth_Provider::OPTION_NAME ),
			esc_attr( $field ),
			absint( $value ),
			absint( $min ),
			absint( $max )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}
}
