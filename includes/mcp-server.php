<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * MCP Server initialization with SaaS authentication support.
 *
 * @package WP_MCP
 */

use WP_MCP\SaaS_Auth\SaaS_Auth_Provider;

add_action( 'mcp_adapter_init', static function ( $adapter ) {
	if ( ! $adapter || ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
		return;
	}

	$prefix = defined( 'WP_MCP_ABILITY_PREFIX' ) ? WP_MCP_ABILITY_PREFIX : 'wp-mcp';
	$server_id = apply_filters( 'wp_mcp_server_id', 'mcp-adapter-default-server' );
	$server_namespace = apply_filters( 'wp_mcp_server_namespace', 'mcp' );
	$server_route = apply_filters( 'wp_mcp_server_route', 'mcp-adapter-default-server' );
	$transport_class = null;

	// Determine transport class.
	if ( class_exists( 'WP\\MCP\\Transport\\HttpTransport' ) ) {
		$transport_class = 'WP\\MCP\\Transport\\HttpTransport';
	} elseif ( class_exists( 'WP\\MCP\\Transport\\Http\\RestTransport' ) ) {
		$transport_class = 'WP\\MCP\\Transport\\Http\\RestTransport';
	}

	// Configure SaaS authentication permission callback.
	$transport_permission_callback = null;
	if ( class_exists( SaaS_Auth_Provider::class ) ) {
		$auth_provider = SaaS_Auth_Provider::instance();
		$transport_permission_callback = array( $auth_provider, 'check_permission' );
	}

	$tools = array(
		"{$prefix}/get-posts-by-category",
		"{$prefix}/get-post-block-structure",
		"{$prefix}/analyze-category-format-patterns",
		"{$prefix}/get-post-raw-content",
		"{$prefix}/extract-used-blocks",
		"{$prefix}/get-theme-styles",
		"{$prefix}/get-block-patterns",
		"{$prefix}/get-reusable-blocks",
		"{$prefix}/get-article-regulations",
		"{$prefix}/create-draft-post",
		"{$prefix}/update-post-content",
		"{$prefix}/update-post-meta",
		"{$prefix}/publish-post",
		"{$prefix}/delete-post",
		"{$prefix}/validate-block-content",
		"{$prefix}/check-regulation-compliance",
		"{$prefix}/check-seo-requirements",
		"{$prefix}/get-media-library",
		"{$prefix}/upload-media",
		"{$prefix}/set-featured-image",
		"{$prefix}/get-categories",
		"{$prefix}/get-tags",
		"{$prefix}/create-term",
		"{$prefix}/get-site-info",
		"{$prefix}/get-post-types",
	);

	$resources = array(
		"{$prefix}/block-schemas",
		"{$prefix}/style-guide",
		"{$prefix}/category-templates",
		"{$prefix}/writing-regulations",
	);

	$prompts = array(
		"{$prefix}/article-generation",
		"{$prefix}/format-conversion",
		"{$prefix}/seo-optimization",
		"{$prefix}/regulation-learning",
	);

	$tools = apply_filters( 'wp_mcp_tools', $tools );
	$resources = apply_filters( 'wp_mcp_resources', $resources );
	$prompts = apply_filters( 'wp_mcp_prompts', $prompts );

	try {
		if ( ! $transport_class ) {
			error_log( 'WP MCP: transport class not found. MCP server was not created.' );
			return;
		}

		$server = $adapter->get_server( $server_id );
		if ( $server ) {
			$server->register_tools( $tools );
			$server->register_resources( $resources );
			$server->register_prompts( $prompts );
			return;
		}

		$result = $adapter->create_server(
			$server_id,
			$server_namespace,
			$server_route,
			'WordPress MCP Suite',
			'All-in-one MCP server for WordPress content operations.',
			defined( 'WP_MCP_PLUGIN_VERSION' ) ? WP_MCP_PLUGIN_VERSION : '1.0.0',
			array( $transport_class ),
			'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
			null,
			$tools,
			$resources,
			$prompts,
			$transport_permission_callback
		);
		if ( is_wp_error( $result ) ) {
			error_log( 'WP MCP: server creation failed: ' . $result->get_error_message() );
		}
	} catch ( Exception $exception ) {
		error_log( 'WP MCP: server creation exception: ' . $exception->getMessage() );
	}
} );
