<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * SaaS Authentication Provider for MCP Server.
 *
 * Provides OAuth 2.0 / Bearer Token authentication for external SaaS connections.
 *
 * @package WP_MCP
 */

declare( strict_types=1 );

namespace WP_MCP\SaaS_Auth;

use WP_Error;
use WP_User;

/**
 * SaaS Authentication Provider class.
 *
 * Handles authentication for external SaaS services connecting to the MCP server.
 * Supports multiple authentication methods:
 * - API Key + Secret (HMAC signature)
 * - Bearer Token (JWT)
 * - OAuth 2.0 Access Token
 */
class SaaS_Auth_Provider {

	/**
	 * Singleton instance.
	 *
	 * @var SaaS_Auth_Provider|null
	 */
	private static ?SaaS_Auth_Provider $instance = null;

	/**
	 * Option name for storing SaaS settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_saas_settings';

	/**
	 * Option name for storing API keys.
	 *
	 * @var string
	 */
	const API_KEYS_OPTION = 'wp_mcp_saas_api_keys';

	/**
	 * Meta key for user API keys.
	 *
	 * @var string
	 */
	const USER_API_KEY_META = '_wp_mcp_api_key';

	/**
	 * Authentication result cache for current request.
	 *
	 * @var array|null
	 */
	private ?array $auth_cache = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SaaS_Auth_Provider
	 */
	public static function instance(): SaaS_Auth_Provider {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		// Initialize hooks.
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Initialize the provider.
	 */
	public function init(): void {
		// Register REST routes for token management.
		add_action( 'rest_api_init', array( $this, 'register_auth_routes' ) );
	}

	/**
	 * Get SaaS settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$defaults = array(
			'enabled'                  => false,
			'allowed_saas_domains'     => array(),
			'jwt_secret'               => '',
			'jwt_algorithm'            => 'HS256',
			'jwt_issuer'               => '',
			'token_expiry_hours'       => 24,
			'require_https'            => true,
			'rate_limit_enabled'       => false,
			'rate_limit_requests'      => 100,
			'rate_limit_window'        => 3600,
			'audit_log_enabled'        => true,
			'allowed_scopes'           => array( 'read', 'write', 'admin' ),
		);

		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Update SaaS settings.
	 *
	 * @param array $settings Settings to update.
	 * @return bool
	 */
	public function update_settings( array $settings ): bool {
		$current = $this->get_settings();
		$updated = wp_parse_args( $settings, $current );
		return update_option( self::OPTION_NAME, $updated );
	}

	/**
	 * Transport permission callback for MCP server.
	 *
	 * This is the main entry point for authentication.
	 *
	 * @param \WP_REST_Request|null $request Optional request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request = null ): bool|WP_Error {
		$settings = $this->get_settings();

		// If SaaS auth is not enabled, fall back to default WordPress auth.
		if ( ! $settings['enabled'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WP MCP Auth] SaaS auth is NOT enabled, falling back to is_user_logged_in()' );
			}
			return is_user_logged_in();
		}

		// Check HTTPS requirement.
		if ( $settings['require_https'] && ! is_ssl() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WP MCP Auth] HTTPS required but request is not SSL' );
			}
			return new WP_Error(
				'https_required',
				__( 'HTTPS is required for SaaS authentication.', 'wp-mcp' ),
				array( 'status' => 403 )
			);
		}

		// Get authorization header.
		$auth_header = $this->get_authorization_header();

		if ( empty( $auth_header ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WP MCP Auth] No Authorization header found. HTTP_AUTHORIZATION=' . ( $_SERVER['HTTP_AUTHORIZATION'] ?? '(not set)' ) . ', REDIRECT_HTTP_AUTHORIZATION=' . ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '(not set)' ) );
			}
			// No auth header - check if user is logged in (backward compatibility).
			if ( is_user_logged_in() ) {
				return true;
			}
			return new WP_Error(
				'missing_authorization',
				__( 'Authorization header is required.', 'wp-mcp' ),
				array( 'status' => 401 )
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[WP MCP Auth] Authorization header found: ' . substr( $auth_header, 0, 20 ) . '...' );
		}

		// Parse authorization header.
		$auth_result = $this->authenticate( $auth_header, $request );

		if ( is_wp_error( $auth_result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[WP MCP Auth] Authentication failed: ' . $auth_result->get_error_code() . ' - ' . $auth_result->get_error_message() );
			}
			$this->log_auth_attempt( false, $auth_result->get_error_message() );
			return $auth_result;
		}

		// Set current user.
		if ( isset( $auth_result['user_id'] ) ) {
			wp_set_current_user( $auth_result['user_id'] );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$user = wp_get_current_user();
				error_log( '[WP MCP Auth] User set: ID=' . $auth_result['user_id'] . ', login=' . $user->user_login . ', roles=' . implode( ',', $user->roles ) . ', can_read=' . ( current_user_can( 'read' ) ? 'yes' : 'no' ) );
			}
		}

		// Check rate limiting (skip for SaaS access_token auth - rate limiting is managed by SaaS).
		$auth_type = $auth_result['auth_type'] ?? '';
		if ( $settings['rate_limit_enabled'] && 'access_token' !== $auth_type ) {
			$rate_check = $this->check_rate_limit( $auth_result );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		// Cache the authentication result.
		$this->auth_cache = $auth_result;

		$this->log_auth_attempt( true, '', $auth_result );
		return true;
	}

	/**
	 * Authenticate based on authorization header.
	 *
	 * @param string                $auth_header The authorization header value.
	 * @param \WP_REST_Request|null $request     Optional request object.
	 * @return array|WP_Error Authentication result or error.
	 */
	public function authenticate( string $auth_header, $request = null ): array|WP_Error {
		// Bearer token authentication.
		if ( preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return $this->authenticate_bearer_token( $matches[1] );
		}

		// API Key authentication (X-API-Key: key:signature format).
		if ( preg_match( '/^ApiKey\s+(.+)$/i', $auth_header, $matches ) ) {
			return $this->authenticate_api_key( $matches[1], $request );
		}

		// Basic auth with API key (for simpler integrations).
		if ( preg_match( '/^Basic\s+(.+)$/i', $auth_header, $matches ) ) {
			$decoded = base64_decode( $matches[1], true );
			if ( false !== $decoded && str_contains( $decoded, ':' ) ) {
				return $this->authenticate_basic( $decoded );
			}
		}

		return new WP_Error(
			'invalid_auth_scheme',
			__( 'Unsupported authorization scheme. Use Bearer, ApiKey, or Basic.', 'wp-mcp' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Authenticate using Bearer token (JWT).
	 *
	 * @param string $token The bearer token.
	 * @return array|WP_Error
	 */
	private function authenticate_bearer_token( string $token ): array|WP_Error {
		$settings = $this->get_settings();

		// First, check if it's a stored access token.
		$token_data = $this->validate_access_token( $token );
		if ( ! is_wp_error( $token_data ) ) {
			return $token_data;
		}

		// Try JWT validation.
		if ( ! empty( $settings['jwt_secret'] ) ) {
			$jwt_result = $this->validate_jwt( $token );
			if ( ! is_wp_error( $jwt_result ) ) {
				return $jwt_result;
			}
		}

		return new WP_Error(
			'invalid_token',
			__( 'Invalid or expired bearer token.', 'wp-mcp' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Validate stored access token.
	 *
	 * @param string $token The access token.
	 * @return array|WP_Error
	 */
	private function validate_access_token( string $token ): array|WP_Error {
		$token_hash = hash( 'sha256', $token );
		$tokens     = get_option( 'wp_mcp_access_tokens', array() );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$stored_hashes = array_keys( $tokens );
			$stored_prefixes = array_map( function( $h ) { return substr( $h, 0, 16 ); }, $stored_hashes );
			error_log( sprintf(
				'[WP MCP Auth] validate_access_token: received_hash=%s..., token_prefix=%s..., stored_hashes=[%s], stored_count=%d',
				substr( $token_hash, 0, 16 ),
				substr( $token, 0, 8 ),
				implode( ', ', $stored_prefixes ),
				count( $tokens )
			) );
		}

		if ( ! isset( $tokens[ $token_hash ] ) ) {
			return new WP_Error( 'token_not_found', 'Token not found.' );
		}

		$token_data = $tokens[ $token_hash ];

		// Check expiry (permanent tokens have null expires_at).
		$is_permanent = ! empty( $token_data['permanent'] ) || null === ( $token_data['expires_at'] ?? null );
		if ( ! $is_permanent && ! empty( $token_data['expires_at'] ) && time() > $token_data['expires_at'] ) {
			// Remove expired token.
			unset( $tokens[ $token_hash ] );
			update_option( 'wp_mcp_access_tokens', $tokens );
			return new WP_Error( 'token_expired', 'Token has expired.' );
		}

		// Update last used.
		$tokens[ $token_hash ]['last_used'] = time();
		update_option( 'wp_mcp_access_tokens', $tokens );

		return array(
			'user_id'    => $token_data['user_id'],
			'scopes'     => $token_data['scopes'] ?? array( 'read' ),
			'client_id'  => $token_data['client_id'] ?? '',
			'auth_type'  => 'access_token',
		);
	}

	/**
	 * Validate JWT token.
	 *
	 * @param string $token The JWT token.
	 * @return array|WP_Error
	 */
	private function validate_jwt( string $token ): array|WP_Error {
		$settings = $this->get_settings();
		$secret   = $settings['jwt_secret'];

		if ( empty( $secret ) ) {
			return new WP_Error( 'jwt_not_configured', 'JWT validation is not configured.' );
		}

		// Split token.
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return new WP_Error( 'invalid_jwt_format', 'Invalid JWT format.' );
		}

		list( $header_b64, $payload_b64, $signature_b64 ) = $parts;

		// Verify signature.
		$expected_signature = $this->base64url_encode(
			hash_hmac( 'sha256', "{$header_b64}.{$payload_b64}", $secret, true )
		);

		if ( ! hash_equals( $expected_signature, $signature_b64 ) ) {
			return new WP_Error( 'invalid_signature', 'JWT signature verification failed.' );
		}

		// Decode payload.
		$payload = json_decode( $this->base64url_decode( $payload_b64 ), true );
		if ( ! $payload ) {
			return new WP_Error( 'invalid_payload', 'Unable to decode JWT payload.' );
		}

		// Verify expiration.
		if ( isset( $payload['exp'] ) && time() > $payload['exp'] ) {
			return new WP_Error( 'jwt_expired', 'JWT has expired.' );
		}

		// Verify issuer if configured.
		if ( ! empty( $settings['jwt_issuer'] ) ) {
			if ( ! isset( $payload['iss'] ) || $payload['iss'] !== $settings['jwt_issuer'] ) {
				return new WP_Error( 'invalid_issuer', 'JWT issuer mismatch.' );
			}
		}

		// Get user from subject.
		$user_id = 0;
		if ( isset( $payload['sub'] ) ) {
			if ( is_numeric( $payload['sub'] ) ) {
				$user_id = absint( $payload['sub'] );
			} else {
				// Try to find user by email or login.
				$user = get_user_by( 'email', $payload['sub'] );
				if ( ! $user ) {
					$user = get_user_by( 'login', $payload['sub'] );
				}
				if ( $user ) {
					$user_id = $user->ID;
				}
			}
		}

		if ( ! $user_id ) {
			return new WP_Error( 'user_not_found', 'User not found in JWT.' );
		}

		return array(
			'user_id'   => $user_id,
			'scopes'    => $payload['scopes'] ?? array( 'read' ),
			'client_id' => $payload['client_id'] ?? '',
			'auth_type' => 'jwt',
			'jwt_data'  => $payload,
		);
	}

	/**
	 * Authenticate using API key.
	 *
	 * @param string                $credentials The API key credentials (key:signature or just key).
	 * @param \WP_REST_Request|null $request     Request object.
	 * @return array|WP_Error
	 */
	private function authenticate_api_key( string $credentials, $request = null ): array|WP_Error {
		// Parse credentials (format: api_key:signature or just api_key).
		if ( str_contains( $credentials, ':' ) ) {
			list( $api_key, $signature ) = explode( ':', $credentials, 2 );
		} else {
			$api_key   = $credentials;
			$signature = null;
		}

		$api_key_hash = hash( 'sha256', $api_key );

		// Find user by API key (keys are stored as arrays).
		$users = get_users( array(
			'meta_key'     => self::USER_API_KEY_META,
			'meta_compare' => 'EXISTS',
		) );

		$found_user     = null;
		$found_key_id   = null;
		$found_key_data = null;

		foreach ( $users as $user ) {
			$key_hashes = get_user_meta( $user->ID, self::USER_API_KEY_META, true );

			if ( ! is_array( $key_hashes ) ) {
				continue;
			}

			foreach ( $key_hashes as $key_id => $stored_hash ) {
				if ( hash_equals( $stored_hash, $api_key_hash ) ) {
					$found_user   = $user;
					$found_key_id = $key_id;
					break 2;
				}
			}
		}

		if ( ! $found_user ) {
			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'wp-mcp' ),
				array( 'status' => 401 )
			);
		}

		$all_key_data = get_user_meta( $found_user->ID, self::USER_API_KEY_META . '_data', true );

		if ( ! is_array( $all_key_data ) || ! isset( $all_key_data[ $found_key_id ] ) ) {
			return new WP_Error( 'api_key_data_missing', 'API key data not found.' );
		}

		$found_key_data = $all_key_data[ $found_key_id ];

		// Check if key is active.
		if ( isset( $found_key_data['active'] ) && ! $found_key_data['active'] ) {
			return new WP_Error( 'api_key_inactive', 'API key is inactive.' );
		}

		// If signature is provided, verify HMAC.
		if ( null !== $signature && $request ) {
			$secret = $found_key_data['secret'] ?? '';
			if ( ! $this->verify_hmac_signature( $request, $secret, $signature ) ) {
				return new WP_Error( 'invalid_signature', 'HMAC signature verification failed.' );
			}
		}

		// Update last used timestamp.
		$all_key_data[ $found_key_id ]['last_used'] = time();
		update_user_meta( $found_user->ID, self::USER_API_KEY_META . '_data', $all_key_data );

		return array(
			'user_id'   => $found_user->ID,
			'scopes'    => $found_key_data['scopes'] ?? array( 'read' ),
			'client_id' => $found_key_data['client_id'] ?? $found_key_id,
			'auth_type' => 'api_key',
		);
	}

	/**
	 * Authenticate using Basic auth (api_key:secret).
	 *
	 * @param string $decoded The decoded basic auth string.
	 * @return array|WP_Error
	 */
	private function authenticate_basic( string $decoded ): array|WP_Error {
		list( $api_key, $secret ) = explode( ':', $decoded, 2 );

		$api_key_hash = hash( 'sha256', $api_key );

		// Find user by API key (keys are stored as arrays).
		$users = get_users( array(
			'meta_key'     => self::USER_API_KEY_META,
			'meta_compare' => 'EXISTS',
		) );

		$found_user    = null;
		$found_key_id  = null;
		$found_key_data = null;

		foreach ( $users as $user ) {
			$key_hashes = get_user_meta( $user->ID, self::USER_API_KEY_META, true );

			if ( ! is_array( $key_hashes ) ) {
				continue;
			}

			// Check if any of the user's API key hashes match.
			foreach ( $key_hashes as $key_id => $stored_hash ) {
				if ( hash_equals( $stored_hash, $api_key_hash ) ) {
					$found_user   = $user;
					$found_key_id = $key_id;
					break 2;
				}
			}
		}

		if ( ! $found_user ) {
			return new WP_Error( 'invalid_credentials', 'Invalid API credentials.' );
		}

		// Get key data.
		$all_key_data = get_user_meta( $found_user->ID, self::USER_API_KEY_META . '_data', true );

		if ( ! is_array( $all_key_data ) || ! isset( $all_key_data[ $found_key_id ] ) ) {
			return new WP_Error( 'key_data_missing', 'API key data not found.' );
		}

		$found_key_data = $all_key_data[ $found_key_id ];

		// Check if key is active.
		if ( isset( $found_key_data['active'] ) && ! $found_key_data['active'] ) {
			return new WP_Error( 'api_key_inactive', 'API key is inactive.' );
		}

		// Verify secret.
		if ( ! isset( $found_key_data['secret'] ) || ! hash_equals( $found_key_data['secret'], $secret ) ) {
			return new WP_Error( 'invalid_secret', 'Invalid API secret.' );
		}

		// Update last used timestamp.
		$all_key_data[ $found_key_id ]['last_used'] = time();
		update_user_meta( $found_user->ID, self::USER_API_KEY_META . '_data', $all_key_data );

		return array(
			'user_id'   => $found_user->ID,
			'scopes'    => $found_key_data['scopes'] ?? array( 'read' ),
			'client_id' => $found_key_data['client_id'] ?? $found_key_id,
			'auth_type' => 'basic',
		);
	}

	/**
	 * Verify HMAC signature.
	 *
	 * @param \WP_REST_Request $request   Request object.
	 * @param string           $secret    The secret key.
	 * @param string           $signature The provided signature.
	 * @return bool
	 */
	private function verify_hmac_signature( $request, string $secret, string $signature ): bool {
		// Build string to sign.
		$method    = $request->get_method();
		$path      = $request->get_route();
		$timestamp = $request->get_header( 'X-Timestamp' ) ?? '';
		$body      = $request->get_body();

		$string_to_sign  = "{$method}\n{$path}\n{$timestamp}\n{$body}";
		$expected_sig    = base64_encode( hash_hmac( 'sha256', $string_to_sign, $secret, true ) );

		return hash_equals( $expected_sig, $signature );
	}

	/**
	 * Check rate limiting.
	 *
	 * @param array $auth_result Authentication result.
	 * @return bool|WP_Error True on success, WP_Error on rate limit exceeded.
	 */
	private function check_rate_limit( array $auth_result ): bool|WP_Error {
		$settings   = $this->get_settings();
		$user_id    = $auth_result['user_id'] ?? 0;
		$client_id  = $auth_result['client_id'] ?? 'default';
		$cache_key  = "wp_mcp_rate_limit_{$user_id}_{$client_id}";

		$rate_data = get_transient( $cache_key );
		if ( false === $rate_data ) {
			$rate_data = array(
				'count'      => 0,
				'window_start' => time(),
			);
		}

		// Check if window has expired.
		if ( time() - $rate_data['window_start'] > $settings['rate_limit_window'] ) {
			$rate_data = array(
				'count'        => 0,
				'window_start' => time(),
			);
		}

		// Increment count.
		$rate_data['count']++;

		// Check limit.
		if ( $rate_data['count'] > $settings['rate_limit_requests'] ) {
			$retry_after = $settings['rate_limit_window'] - ( time() - $rate_data['window_start'] );
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Rate limit exceeded. Please try again later.', 'wp-mcp' ),
				array(
					'status'       => 429,
					'retry_after'  => $retry_after,
				)
			);
		}

		// Save rate data.
		set_transient( $cache_key, $rate_data, $settings['rate_limit_window'] );

		return true;
	}

	/**
	 * Log authentication attempt.
	 *
	 * @param bool   $success Whether authentication succeeded.
	 * @param string $error   Error message if failed.
	 * @param array  $context Additional context.
	 */
	private function log_auth_attempt( bool $success, string $error = '', array $context = array() ): void {
		$settings = $this->get_settings();

		if ( ! $settings['audit_log_enabled'] ) {
			return;
		}

		$log_entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'success'    => $success,
			'ip_address' => $this->get_client_ip(),
			'user_agent' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'error'      => $error,
			'user_id'    => $context['user_id'] ?? 0,
			'auth_type'  => $context['auth_type'] ?? '',
			'client_id'  => $context['client_id'] ?? '',
		);

		// Store in option (in production, use a proper logging system).
		$logs   = get_option( 'wp_mcp_auth_logs', array() );
		$logs[] = $log_entry;

		// Keep only last 1000 entries.
		if ( count( $logs ) > 1000 ) {
			$logs = array_slice( $logs, -1000 );
		}

		update_option( 'wp_mcp_auth_logs', $logs, false );

		// Also log to error_log for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[WP MCP Auth] %s - User: %d, Type: %s, Client: %s, IP: %s %s',
				$success ? 'SUCCESS' : 'FAILED',
				$log_entry['user_id'],
				$log_entry['auth_type'],
				$log_entry['client_id'],
				$log_entry['ip_address'],
				$error ? "- Error: {$error}" : ''
			) );
		}
	}

	/**
	 * Register authentication-related REST routes.
	 */
	public function register_auth_routes(): void {
		// Token endpoint (OAuth 2.0 compatible).
		register_rest_route(
			'wp-mcp/v1',
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token_request' ),
				'permission_callback' => '__return_true',
			)
		);

		// Token revocation.
		register_rest_route(
			'wp-mcp/v1',
			'/token/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token_revoke' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Token introspection.
		register_rest_route(
			'wp-mcp/v1',
			'/token/introspect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token_introspect' ),
				'permission_callback' => '__return_true',
			)
		);

		// Debug endpoint to test authentication.
		register_rest_route(
			'wp-mcp/v1',
			'/auth/test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_auth_test' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle authentication test request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_auth_test( $request ): \WP_REST_Response {
		$auth_header = $this->get_authorization_header();
		$settings    = $this->get_settings();

		$result = array(
			'auth_header_received' => ! empty( $auth_header ),
			'auth_header_type'     => '',
			'saas_auth_enabled'    => $settings['enabled'],
			'authentication'       => null,
			'current_user_id'      => get_current_user_id(),
		);

		if ( ! empty( $auth_header ) ) {
			if ( preg_match( '/^Bearer\s+/i', $auth_header ) ) {
				$result['auth_header_type'] = 'Bearer';
			} elseif ( preg_match( '/^Basic\s+/i', $auth_header ) ) {
				$result['auth_header_type'] = 'Basic';
			} elseif ( preg_match( '/^ApiKey\s+/i', $auth_header ) ) {
				$result['auth_header_type'] = 'ApiKey';
			} else {
				$result['auth_header_type'] = 'Unknown';
			}

			// Try to authenticate.
			$auth_result = $this->authenticate( $auth_header, $request );

			if ( is_wp_error( $auth_result ) ) {
				$result['authentication'] = array(
					'success' => false,
					'error'   => $auth_result->get_error_code(),
					'message' => $auth_result->get_error_message(),
				);
			} else {
				$result['authentication'] = array(
					'success'   => true,
					'user_id'   => $auth_result['user_id'] ?? 0,
					'auth_type' => $auth_result['auth_type'] ?? '',
					'scopes'    => $auth_result['scopes'] ?? array(),
				);
			}
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle token request (OAuth 2.0 token endpoint).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function handle_token_request( $request ): \WP_REST_Response|WP_Error {
		$grant_type = $request->get_param( 'grant_type' );

		switch ( $grant_type ) {
			case 'client_credentials':
				return $this->handle_client_credentials_grant( $request );

			case 'refresh_token':
				return $this->handle_refresh_token_grant( $request );

			default:
				return new WP_Error(
					'unsupported_grant_type',
					__( 'Unsupported grant type.', 'wp-mcp' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle client credentials grant.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function handle_client_credentials_grant( $request ): \WP_REST_Response|WP_Error {
		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );
		$scopes        = $request->get_param( 'scope' ) ?? 'read';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'invalid_request', 'client_id and client_secret are required.' );
		}

		// Authenticate client.
		$auth_result = $this->authenticate_basic( "{$client_id}:{$client_secret}" );
		if ( is_wp_error( $auth_result ) ) {
			return new WP_Error( 'invalid_client', 'Client authentication failed.', array( 'status' => 401 ) );
		}

		// Generate access token.
		$settings     = $this->get_settings();
		$access_token = $this->generate_access_token();
		$expires_in   = $settings['token_expiry_hours'] * 3600;

		// Store token.
		$tokens                              = get_option( 'wp_mcp_access_tokens', array() );
		$token_hash                          = hash( 'sha256', $access_token );
		$tokens[ $token_hash ]               = array(
			'user_id'    => $auth_result['user_id'],
			'client_id'  => $client_id,
			'scopes'     => explode( ' ', $scopes ),
			'created_at' => time(),
			'expires_at' => time() + $expires_in,
		);
		update_option( 'wp_mcp_access_tokens', $tokens );

		return new \WP_REST_Response(
			array(
				'access_token' => $access_token,
				'token_type'   => 'Bearer',
				'expires_in'   => $expires_in,
				'scope'        => $scopes,
			),
			200
		);
	}

	/**
	 * Handle refresh token grant.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function handle_refresh_token_grant( $request ): \WP_REST_Response|WP_Error {
		// Refresh token implementation would go here.
		return new WP_Error( 'not_implemented', 'Refresh token grant is not yet implemented.' );
	}

	/**
	 * Handle token revocation.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_token_revoke( $request ): \WP_REST_Response {
		$token      = $request->get_param( 'token' );
		$token_hash = hash( 'sha256', $token );

		$tokens = get_option( 'wp_mcp_access_tokens', array() );
		if ( isset( $tokens[ $token_hash ] ) ) {
			unset( $tokens[ $token_hash ] );
			update_option( 'wp_mcp_access_tokens', $tokens );
		}

		return new \WP_REST_Response( array( 'revoked' => true ), 200 );
	}

	/**
	 * Handle token introspection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_token_introspect( $request ): \WP_REST_Response {
		$token      = $request->get_param( 'token' );
		$token_hash = hash( 'sha256', $token );

		$tokens = get_option( 'wp_mcp_access_tokens', array() );
		if ( ! isset( $tokens[ $token_hash ] ) ) {
			return new \WP_REST_Response( array( 'active' => false ), 200 );
		}

		$token_data = $tokens[ $token_hash ];
		$is_active  = time() < ( $token_data['expires_at'] ?? 0 );

		$response = array(
			'active'    => $is_active,
			'scope'     => implode( ' ', $token_data['scopes'] ?? array() ),
			'client_id' => $token_data['client_id'] ?? '',
			'exp'       => $token_data['expires_at'] ?? 0,
			'iat'       => $token_data['created_at'] ?? 0,
		);

		if ( $is_active && isset( $token_data['user_id'] ) ) {
			$user = get_user_by( 'id', $token_data['user_id'] );
			if ( $user ) {
				$response['sub']      = (string) $user->ID;
				$response['username'] = $user->user_login;
			}
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Generate a secure access token.
	 *
	 * @return string
	 */
	private function generate_access_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Get the authorization header.
	 *
	 * @return string
	 */
	private function get_authorization_header(): string {
		// Check various header locations.
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Apache specific.
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( isset( $headers['Authorization'] ) ) {
				return sanitize_text_field( $headers['Authorization'] );
			}
		}

		return '';
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs.
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '';
	}

	/**
	 * Base64 URL encode.
	 *
	 * @param string $data Data to encode.
	 * @return string
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64 URL decode.
	 *
	 * @param string $data Data to decode.
	 * @return string
	 */
	private function base64url_decode( string $data ): string {
		return base64_decode( strtr( $data, '-_', '+/' ) );
	}

	/**
	 * Get current authentication info.
	 *
	 * @return array|null
	 */
	public function get_current_auth(): ?array {
		return $this->auth_cache;
	}
}
