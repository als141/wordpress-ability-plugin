<?php
/**
 * API Key Manager for SaaS Authentication.
 *
 * Handles generation, storage, and management of API keys for users.
 *
 * @package WP_MCP
 */

declare( strict_types=1 );

namespace WP_MCP\SaaS_Auth;

use WP_Error;
use WP_User;

/**
 * API Key Manager class.
 */
class API_Key_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var API_Key_Manager|null
	 */
	private static ?API_Key_Manager $instance = null;

	/**
	 * Meta key for storing API key hash.
	 *
	 * @var string
	 */
	const API_KEY_META = '_wp_mcp_api_key';

	/**
	 * Meta key for storing API key data.
	 *
	 * @var string
	 */
	const API_KEY_DATA_META = '_wp_mcp_api_key_data';

	/**
	 * Get singleton instance.
	 *
	 * @return API_Key_Manager
	 */
	public static function instance(): API_Key_Manager {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// List user's API keys.
		register_rest_route(
			'wp-mcp/v1',
			'/api-keys',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_api_keys' ),
					'permission_callback' => array( $this, 'check_user_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_api_key' ),
					'permission_callback' => array( $this, 'check_user_permission' ),
					'args'                => array(
						'name'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'scopes' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array( 'read' ),
						),
					),
				),
			)
		);

		// Delete API key.
		register_rest_route(
			'wp-mcp/v1',
			'/api-keys/(?P<key_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_api_key' ),
				'permission_callback' => array( $this, 'check_user_permission' ),
			)
		);

		// Regenerate API key secret.
		register_rest_route(
			'wp-mcp/v1',
			'/api-keys/(?P<key_id>[a-zA-Z0-9_-]+)/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_api_key' ),
				'permission_callback' => array( $this, 'check_user_permission' ),
			)
		);
	}

	/**
	 * Check if user has permission to manage API keys.
	 *
	 * @return bool
	 */
	public function check_user_permission(): bool {
		return is_user_logged_in();
	}

	/**
	 * Create a new API key for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    A friendly name for the key.
	 * @param array  $scopes  Allowed scopes for this key.
	 * @return array|WP_Error
	 */
	public function create_key( int $user_id, string $name, array $scopes = array( 'read' ) ): array|WP_Error {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', 'User not found.' );
		}

		// Generate API key and secret.
		$api_key    = $this->generate_api_key();
		$api_secret = $this->generate_secret();
		$key_id     = wp_generate_uuid4();

		// Get existing keys.
		$existing_keys = get_user_meta( $user_id, self::API_KEY_DATA_META, true );
		if ( ! is_array( $existing_keys ) ) {
			$existing_keys = array();
		}

		// Limit number of keys per user.
		if ( count( $existing_keys ) >= 5 ) {
			return new WP_Error(
				'key_limit_exceeded',
				__( 'Maximum number of API keys reached (5). Please delete an existing key first.', 'wp-mcp' )
			);
		}

		// Store key data.
		$key_data = array(
			'key_id'     => $key_id,
			'name'       => sanitize_text_field( $name ),
			'key_hash'   => hash( 'sha256', $api_key ),
			'secret'     => $api_secret,
			'scopes'     => $this->validate_scopes( $scopes ),
			'created_at' => time(),
			'last_used'  => null,
			'active'     => true,
			'client_id'  => $key_id, // Use key_id as client_id.
		);

		$existing_keys[ $key_id ] = $key_data;
		update_user_meta( $user_id, self::API_KEY_DATA_META, $existing_keys );

		// Also store the hash for quick lookup.
		$key_hashes = get_user_meta( $user_id, self::API_KEY_META, true );
		if ( ! is_array( $key_hashes ) ) {
			$key_hashes = array();
		}
		$key_hashes[ $key_id ] = $key_data['key_hash'];
		update_user_meta( $user_id, self::API_KEY_META, $key_hashes );

		// Return the key and secret (only shown once).
		return array(
			'key_id'     => $key_id,
			'api_key'    => $api_key,
			'api_secret' => $api_secret,
			'name'       => $key_data['name'],
			'scopes'     => $key_data['scopes'],
			'created_at' => $key_data['created_at'],
			'message'    => __( 'Save these credentials now. The API secret will not be shown again.', 'wp-mcp' ),
		);
	}

	/**
	 * REST handler: Create API key.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create_api_key( $request ): \WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$name    = $request->get_param( 'name' );
		$scopes  = $request->get_param( 'scopes' ) ?? array( 'read' );

		$result = $this->create_key( $user_id, $name, $scopes );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * List API keys for a user (without secrets).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function list_api_keys( $request ): \WP_REST_Response {
		$user_id = get_current_user_id();
		$keys    = get_user_meta( $user_id, self::API_KEY_DATA_META, true );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		// Remove sensitive data.
		$safe_keys = array();
		foreach ( $keys as $key_id => $key_data ) {
			$safe_keys[] = array(
				'key_id'     => $key_id,
				'name'       => $key_data['name'] ?? '',
				'scopes'     => $key_data['scopes'] ?? array(),
				'created_at' => $key_data['created_at'] ?? 0,
				'last_used'  => $key_data['last_used'] ?? null,
				'active'     => $key_data['active'] ?? true,
				'key_prefix' => substr( $key_data['key_hash'] ?? '', 0, 8 ) . '...',
			);
		}

		return new \WP_REST_Response( $safe_keys, 200 );
	}

	/**
	 * Delete an API key.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_api_key( $request ): \WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$key_id  = $request->get_param( 'key_id' );

		$keys = get_user_meta( $user_id, self::API_KEY_DATA_META, true );
		if ( ! is_array( $keys ) || ! isset( $keys[ $key_id ] ) ) {
			return new WP_Error( 'key_not_found', 'API key not found.', array( 'status' => 404 ) );
		}

		// Remove from keys data.
		unset( $keys[ $key_id ] );
		update_user_meta( $user_id, self::API_KEY_DATA_META, $keys );

		// Remove from hash lookup.
		$key_hashes = get_user_meta( $user_id, self::API_KEY_META, true );
		if ( is_array( $key_hashes ) && isset( $key_hashes[ $key_id ] ) ) {
			unset( $key_hashes[ $key_id ] );
			update_user_meta( $user_id, self::API_KEY_META, $key_hashes );
		}

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Regenerate API key secret.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function regenerate_api_key( $request ): \WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$key_id  = $request->get_param( 'key_id' );

		$keys = get_user_meta( $user_id, self::API_KEY_DATA_META, true );
		if ( ! is_array( $keys ) || ! isset( $keys[ $key_id ] ) ) {
			return new WP_Error( 'key_not_found', 'API key not found.', array( 'status' => 404 ) );
		}

		// Generate new key and secret.
		$new_api_key    = $this->generate_api_key();
		$new_api_secret = $this->generate_secret();
		$new_key_hash   = hash( 'sha256', $new_api_key );

		// Update key data.
		$keys[ $key_id ]['key_hash'] = $new_key_hash;
		$keys[ $key_id ]['secret']   = $new_api_secret;
		update_user_meta( $user_id, self::API_KEY_DATA_META, $keys );

		// Update hash lookup.
		$key_hashes             = get_user_meta( $user_id, self::API_KEY_META, true );
		$key_hashes[ $key_id ]  = $new_key_hash;
		update_user_meta( $user_id, self::API_KEY_META, $key_hashes );

		return new \WP_REST_Response(
			array(
				'key_id'     => $key_id,
				'api_key'    => $new_api_key,
				'api_secret' => $new_api_secret,
				'message'    => __( 'New credentials generated. Save them now.', 'wp-mcp' ),
			),
			200
		);
	}

	/**
	 * Validate API key credentials.
	 *
	 * @param string $api_key    The API key.
	 * @param string $api_secret The API secret (optional).
	 * @return array|WP_Error User data or error.
	 */
	public function validate_credentials( string $api_key, string $api_secret = '' ): array|WP_Error {
		$key_hash = hash( 'sha256', $api_key );

		// Search for user with this key.
		$users = get_users( array(
			'meta_query' => array(
				array(
					'key'     => self::API_KEY_META,
					'value'   => $key_hash,
					'compare' => 'LIKE',
				),
			),
			'number'     => 1,
		) );

		if ( empty( $users ) ) {
			return new WP_Error( 'invalid_key', 'Invalid API key.' );
		}

		$user     = $users[0];
		$keys     = get_user_meta( $user->ID, self::API_KEY_DATA_META, true );

		// Find the specific key.
		$matched_key = null;
		foreach ( $keys as $key_id => $key_data ) {
			if ( isset( $key_data['key_hash'] ) && $key_data['key_hash'] === $key_hash ) {
				$matched_key = $key_data;
				$matched_key['key_id'] = $key_id;
				break;
			}
		}

		if ( ! $matched_key ) {
			return new WP_Error( 'key_not_found', 'API key not found.' );
		}

		// Check if key is active.
		if ( isset( $matched_key['active'] ) && ! $matched_key['active'] ) {
			return new WP_Error( 'key_inactive', 'API key is inactive.' );
		}

		// Validate secret if provided.
		if ( ! empty( $api_secret ) ) {
			if ( ! isset( $matched_key['secret'] ) || ! hash_equals( $matched_key['secret'], $api_secret ) ) {
				return new WP_Error( 'invalid_secret', 'Invalid API secret.' );
			}
		}

		// Update last used timestamp.
		$keys[ $matched_key['key_id'] ]['last_used'] = time();
		update_user_meta( $user->ID, self::API_KEY_DATA_META, $keys );

		return array(
			'user_id'   => $user->ID,
			'key_id'    => $matched_key['key_id'],
			'scopes'    => $matched_key['scopes'] ?? array( 'read' ),
			'client_id' => $matched_key['client_id'] ?? $matched_key['key_id'],
		);
	}

	/**
	 * Generate a new API key.
	 *
	 * @return string
	 */
	private function generate_api_key(): string {
		$prefix = 'mcp_';
		$random = bin2hex( random_bytes( 24 ) );
		return $prefix . $random;
	}

	/**
	 * Generate a new secret.
	 *
	 * @return string
	 */
	private function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Validate and filter scopes.
	 *
	 * @param array $scopes Requested scopes.
	 * @return array Valid scopes.
	 */
	private function validate_scopes( array $scopes ): array {
		$allowed_scopes = array( 'read', 'write', 'admin' );
		return array_values( array_intersect( $scopes, $allowed_scopes ) );
	}

	/**
	 * Regenerate API key for SaaS connection (internal use).
	 *
	 * @param int    $user_id User ID.
	 * @param string $key_id  Key ID.
	 * @return array|WP_Error New credentials or error.
	 */
	public function regenerate_key_for_saas( int $user_id, string $key_id ): array|WP_Error {
		$keys = get_user_meta( $user_id, self::API_KEY_DATA_META, true );
		if ( ! is_array( $keys ) || ! isset( $keys[ $key_id ] ) ) {
			return new WP_Error( 'key_not_found', 'API key not found.' );
		}

		// Generate new key and secret.
		$new_api_key    = $this->generate_api_key();
		$new_api_secret = $this->generate_secret();
		$new_key_hash   = hash( 'sha256', $new_api_key );

		// Update key data.
		$keys[ $key_id ]['key_hash'] = $new_key_hash;
		$keys[ $key_id ]['secret']   = $new_api_secret;
		$keys[ $key_id ]['scopes']   = array( 'read', 'write', 'admin' ); // Ensure full access.
		update_user_meta( $user_id, self::API_KEY_DATA_META, $keys );

		// Update hash lookup.
		$key_hashes            = get_user_meta( $user_id, self::API_KEY_META, true );
		$key_hashes[ $key_id ] = $new_key_hash;
		update_user_meta( $user_id, self::API_KEY_META, $key_hashes );

		return array(
			'api_key'    => $new_api_key,
			'api_secret' => $new_api_secret,
		);
	}
}
