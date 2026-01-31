<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * OAuth 2.0 Metadata Endpoints for MCP Specification Compliance.
 *
 * Implements RFC 8414 (Authorization Server Metadata) and RFC 9728 (Protected Resource Metadata).
 *
 * @package WP_MCP
 */

declare( strict_types=1 );

namespace WP_MCP\SaaS_Auth;

/**
 * OAuth Metadata class.
 *
 * Provides .well-known endpoints required by MCP specification:
 * - /.well-known/oauth-protected-resource (RFC 9728)
 * - /.well-known/oauth-authorization-server (RFC 8414)
 */
class OAuth_Metadata {

	/**
	 * Singleton instance.
	 *
	 * @var OAuth_Metadata|null
	 */
	private static ?OAuth_Metadata $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return OAuth_Metadata
	 */
	public static function instance(): OAuth_Metadata {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_well_known_routes' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register .well-known rewrite rules.
	 */
	public function register_well_known_routes(): void {
		// Add rewrite rules for .well-known endpoints.
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?wp_mcp_wellknown=protected-resource',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?wp_mcp_wellknown=authorization-server',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/mcp\.json/?$',
			'index.php?wp_mcp_wellknown=mcp-metadata',
			'top'
		);

		// Add query var.
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'wp_mcp_wellknown';
			return $vars;
		} );

		// Handle the request.
		add_action( 'template_redirect', array( $this, 'handle_well_known_request' ) );
	}

	/**
	 * Handle .well-known requests.
	 */
	public function handle_well_known_request(): void {
		$wellknown = get_query_var( 'wp_mcp_wellknown' );

		if ( empty( $wellknown ) ) {
			return;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Cache-Control: public, max-age=3600' );

		switch ( $wellknown ) {
			case 'protected-resource':
				echo wp_json_encode( $this->get_protected_resource_metadata() );
				break;

			case 'authorization-server':
				echo wp_json_encode( $this->get_authorization_server_metadata() );
				break;

			case 'mcp-metadata':
				echo wp_json_encode( $this->get_mcp_metadata() );
				break;

			default:
				status_header( 404 );
				echo wp_json_encode( array( 'error' => 'not_found' ) );
				break;
		}

		exit;
	}

	/**
	 * Register REST API routes for OAuth metadata.
	 */
	public function register_rest_routes(): void {
		// Protected Resource Metadata (RFC 9728).
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_protected_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);

		// Authorization Server Metadata (RFC 8414).
		register_rest_route(
			'wp-mcp/v1',
			'/oauth/authorization-server',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_authorization_server_metadata' ),
				'permission_callback' => '__return_true',
			)
		);

		// MCP Server Metadata.
		register_rest_route(
			'wp-mcp/v1',
			'/mcp/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_mcp_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Get Protected Resource Metadata (RFC 9728).
	 *
	 * This metadata tells MCP clients where to get authorization.
	 *
	 * @return array
	 */
	public function get_protected_resource_metadata(): array {
		$site_url = get_site_url();
		$rest_url = get_rest_url();

		return array(
			// REQUIRED: Resource identifier (the MCP server URL).
			'resource'                       => trailingslashit( $site_url ) . 'wp-json/mcp/mcp-adapter-default-server',

			// REQUIRED: Authorization server(s) that can issue tokens for this resource.
			'authorization_servers'          => array(
				trailingslashit( $site_url ),
			),

			// OPTIONAL: Scopes supported by this resource.
			'scopes_supported'               => array(
				'read',
				'write',
				'admin',
			),

			// OPTIONAL: Bearer token types accepted.
			'bearer_methods_supported'       => array(
				'header',
			),

			// OPTIONAL: Resource documentation.
			'resource_documentation'         => trailingslashit( $site_url ) . 'wp-json/wp-mcp/v1/docs',

			// OPTIONAL: Resource signing algorithms.
			'resource_signing_alg_values_supported' => array(
				'HS256',
				'RS256',
			),

			// MCP-specific metadata.
			'mcp_server_version'             => defined( 'WP_MCP_PLUGIN_VERSION' ) ? WP_MCP_PLUGIN_VERSION : '1.0.0',
			'mcp_protocol_version'           => '2025-03-26',
			'mcp_transport_types'            => array(
				'rest',
				'streamable-http',
			),
		);
	}

	/**
	 * Get Authorization Server Metadata (RFC 8414).
	 *
	 * This metadata tells MCP clients how to authenticate.
	 *
	 * @return array
	 */
	public function get_authorization_server_metadata(): array {
		$site_url = get_site_url();
		$rest_url = get_rest_url();

		return array(
			// REQUIRED: Authorization server identifier.
			'issuer'                                     => trailingslashit( $site_url ),

			// OPTIONAL: Authorization endpoint (for authorization code flow).
			// Note: We're using a simplified flow, but include for compatibility.
			'authorization_endpoint'                     => trailingslashit( $rest_url ) . 'wp-mcp/v1/oauth/authorize',

			// REQUIRED: Token endpoint.
			'token_endpoint'                             => trailingslashit( $rest_url ) . 'wp-mcp/v1/token',

			// OPTIONAL: Token introspection endpoint (RFC 7662).
			'introspection_endpoint'                     => trailingslashit( $rest_url ) . 'wp-mcp/v1/token/introspect',

			// OPTIONAL: Token revocation endpoint (RFC 7009).
			'revocation_endpoint'                        => trailingslashit( $rest_url ) . 'wp-mcp/v1/token/revoke',

			// OPTIONAL: Dynamic client registration endpoint (RFC 7591).
			'registration_endpoint'                      => trailingslashit( $rest_url ) . 'wp-mcp/v1/oauth/register',

			// OPTIONAL: JWK Set URI (for JWT verification).
			'jwks_uri'                                   => trailingslashit( $rest_url ) . 'wp-mcp/v1/oauth/jwks',

			// REQUIRED: Supported response types.
			'response_types_supported'                   => array(
				'code',
				'token',
			),

			// OPTIONAL: Supported grant types.
			'grant_types_supported'                      => array(
				'client_credentials',
				'authorization_code',
				'refresh_token',
			),

			// OPTIONAL: Supported token endpoint auth methods.
			'token_endpoint_auth_methods_supported'      => array(
				'client_secret_basic',
				'client_secret_post',
			),

			// OPTIONAL: Supported scopes.
			'scopes_supported'                           => array(
				'read',
				'write',
				'admin',
			),

			// OPTIONAL: Supported code challenge methods (PKCE).
			'code_challenge_methods_supported'           => array(
				'S256',
				'plain',
			),

			// OPTIONAL: Service documentation.
			'service_documentation'                      => trailingslashit( $site_url ) . 'wp-json/wp-mcp/v1/docs',

			// OPTIONAL: UI locales supported.
			'ui_locales_supported'                       => array(
				'en',
				'ja',
			),

			// OPTIONAL: Claims supported.
			'claims_supported'                           => array(
				'sub',
				'iss',
				'aud',
				'exp',
				'iat',
				'scope',
				'client_id',
			),

			// OPTIONAL: Response modes supported.
			'response_modes_supported'                   => array(
				'query',
				'fragment',
			),

			// MCP-specific additions.
			'mcp_resource_indicators_supported'          => true,
			'mcp_session_management_supported'           => true,
		);
	}

	/**
	 * Get MCP-specific metadata.
	 *
	 * @return array
	 */
	public function get_mcp_metadata(): array {
		$site_url = get_site_url();
		$rest_url = get_rest_url();

		$server_info = array(
			'name'        => get_bloginfo( 'name' ) . ' MCP Server',
			'version'     => defined( 'WP_MCP_PLUGIN_VERSION' ) ? WP_MCP_PLUGIN_VERSION : '1.0.0',
			'description' => 'WordPress MCP Server for content operations.',
		);

		return array(
			// Server information.
			'server'                => $server_info,

			// Protocol version.
			'protocol_version'      => '2025-03-26',

			// Endpoints.
			'endpoints'             => array(
				'mcp'              => trailingslashit( $rest_url ) . 'mcp/mcp-adapter-default-server',
				'mcp_streamable'   => trailingslashit( $rest_url ) . 'mcp/mcp-adapter-default-server/streamable',
				'token'            => trailingslashit( $rest_url ) . 'wp-mcp/v1/token',
				'api_keys'         => trailingslashit( $rest_url ) . 'wp-mcp/v1/api-keys',
			),

			// Authentication methods.
			'auth_methods'          => array(
				'bearer_token',
				'api_key',
				'basic',
			),

			// Supported transports.
			'transports'            => array(
				array(
					'type'     => 'rest',
					'endpoint' => trailingslashit( $rest_url ) . 'mcp/mcp-adapter-default-server',
				),
				array(
					'type'     => 'streamable-http',
					'endpoint' => trailingslashit( $rest_url ) . 'mcp/mcp-adapter-default-server/streamable',
				),
			),

			// Capabilities.
			'capabilities'          => array(
				'tools'     => true,
				'resources' => true,
				'prompts'   => true,
			),

			// OAuth metadata locations.
			'oauth'                 => array(
				'protected_resource_metadata' => trailingslashit( $site_url ) . '.well-known/oauth-protected-resource',
				'authorization_server_metadata' => trailingslashit( $site_url ) . '.well-known/oauth-authorization-server',
			),

			// Links.
			'links'                 => array(
				'documentation' => 'https://modelcontextprotocol.io/specification/2025-03-26',
				'wordpress'     => $site_url,
			),
		);
	}

	/**
	 * REST callback for protected resource metadata.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_protected_resource_metadata(): \WP_REST_Response {
		return new \WP_REST_Response( $this->get_protected_resource_metadata(), 200 );
	}

	/**
	 * REST callback for authorization server metadata.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_authorization_server_metadata(): \WP_REST_Response {
		return new \WP_REST_Response( $this->get_authorization_server_metadata(), 200 );
	}

	/**
	 * REST callback for MCP metadata.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_mcp_metadata(): \WP_REST_Response {
		return new \WP_REST_Response( $this->get_mcp_metadata(), 200 );
	}

	/**
	 * Flush rewrite rules.
	 *
	 * Should be called on plugin activation.
	 */
	public static function flush_rewrite_rules(): void {
		self::instance()->register_well_known_routes();
		flush_rewrite_rules();
	}
}
