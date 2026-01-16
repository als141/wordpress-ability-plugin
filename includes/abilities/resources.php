<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'wp_abilities_api_init', 'wp_mcp_register_resources' );

function wp_mcp_register_resources() {
	$prefix = defined( 'WP_MCP_ABILITY_PREFIX' ) ? WP_MCP_ABILITY_PREFIX : 'wp-mcp';
	$meta_readonly = wp_mcp_meta( array( 'readonly' => true ) );

	$resource_input_schema = array(
		'type'       => 'object',
		'properties' => array(
			'uri' => array( 'type' => 'string' ),
		),
	);

	wp_register_ability( "{$prefix}/block-schemas", array(
		'label'       => 'Block Schemas',
		'description' => '利用可能なブロックタイプのスキーマ定義を取得します。',
		'category'    => 'resource',
		'input_schema' => $resource_input_schema,
		'output_schema' => array(
			'type' => 'array',
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array( 'uri' => 'wordpress://mcp/block_schemas' )
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$registry = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance() : null;
			$schemas = array();

			if ( $registry ) {
				foreach ( $registry->get_all_registered() as $block ) {
					$schemas[] = array(
						'name'        => $block->name,
						'title'       => $block->title,
						'description' => $block->description,
						'attributes'  => $block->attributes,
						'supports'    => $block->supports,
					);
				}
			}

			return array(
				array(
					'uri'      => 'wordpress://mcp/block_schemas',
					'mimeType' => 'application/json',
					'text'     => wp_json_encode( $schemas ),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/style-guide", array(
		'label'       => 'Style Guide',
		'description' => 'サイトのスタイルガイドラインを取得します。',
		'category'    => 'resource',
		'input_schema' => $resource_input_schema,
		'output_schema' => array(
			'type' => 'array',
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array( 'uri' => 'wordpress://mcp/style_guide' )
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
			$styles   = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();
			$guidelines = get_option( 'mcp_style_guidelines', array() );

			$data = array(
				'theme_styles' => array(
					'colors' => array(
						'palette'   => isset( $settings['color']['palette'] ) ? $settings['color']['palette'] : array(),
						'gradients' => isset( $settings['color']['gradients'] ) ? $settings['color']['gradients'] : array(),
						'custom'    => isset( $styles['color'] ) ? $styles['color'] : array(),
					),
					'typography' => array(
						'fontFamilies' => isset( $settings['typography']['fontFamilies'] ) ? $settings['typography']['fontFamilies'] : array(),
						'fontSizes'    => isset( $settings['typography']['fontSizes'] ) ? $settings['typography']['fontSizes'] : array(),
						'custom'       => isset( $styles['typography'] ) ? $styles['typography'] : array(),
					),
					'spacing' => isset( $settings['spacing'] ) ? $settings['spacing'] : array(),
					'layout'  => isset( $settings['layout'] ) ? $settings['layout'] : array(),
				),
				'custom_guidelines' => $guidelines,
			);

			return array(
				array(
					'uri'      => 'wordpress://mcp/style_guide',
					'mimeType' => 'application/json',
					'text'     => wp_json_encode( $data ),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/category-templates", array(
		'label'       => 'Category Templates',
		'description' => 'カテゴリ別記事テンプレートを取得します。',
		'category'    => 'resource',
		'input_schema' => $resource_input_schema,
		'output_schema' => array(
			'type' => 'array',
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array( 'uri' => 'wordpress://mcp/category_templates' )
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$templates = get_option( 'mcp_block_templates', array() );

			return array(
				array(
					'uri'      => 'wordpress://mcp/category_templates',
					'mimeType' => 'application/json',
					'text'     => wp_json_encode( $templates ),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/writing-regulations", array(
		'label'       => 'Writing Regulations',
		'description' => 'ライティングレギュレーションを取得します。',
		'category'    => 'resource',
		'input_schema' => $resource_input_schema,
		'output_schema' => array(
			'type' => 'array',
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array( 'uri' => 'wordpress://mcp/writing_regulations' )
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$regulations = get_option( 'mcp_article_regulations', array() );

			return array(
				array(
					'uri'      => 'wordpress://mcp/writing_regulations',
					'mimeType' => 'application/json',
					'text'     => wp_json_encode( $regulations ),
				),
			);
		},
	) );
}

if ( did_action( 'wp_abilities_api_init' ) ) {
	wp_mcp_register_resources();
}
