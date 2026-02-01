<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'wp_abilities_api_init', 'wp_mcp_register_prompts' );

function wp_mcp_register_prompts() {
	$prefix = defined( 'WP_MCP_ABILITY_PREFIX' ) ? WP_MCP_ABILITY_PREFIX : 'wp-mcp';

	wp_register_ability( "{$prefix}/article-generation", array(
		'label'       => 'Article Generation Prompt',
		'description' => '記事生成用のベースプロンプトを返します。',
		'category'    => 'prompt',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_id' => array( 'type' => 'integer' ),
				'keywords' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'word_count' => array( 'type' => 'integer' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array( 'type' => 'array' ),
			),
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array(
				'arguments' => array(
					array(
						'name'        => 'category_id',
						'description' => 'Target category ID',
						'required'    => false,
					),
					array(
						'name'        => 'keywords',
						'description' => 'Target keywords list',
						'required'    => false,
					),
					array(
						'name'        => 'word_count',
						'description' => 'Target word count',
						'required'    => false,
					),
				),
			)
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			$category_name = '';
			if ( $category_id ) {
				$category = get_category( $category_id );
				if ( $category && ! is_wp_error( $category ) ) {
					$category_name = $category->name;
				}
			}

			$keywords = isset( $input['keywords'] ) && is_array( $input['keywords'] ) ? array_filter( $input['keywords'] ) : array();
			$word_count = isset( $input['word_count'] ) ? (int) $input['word_count'] : 0;

			$system = "You are an assistant writing a WordPress article in Gutenberg block HTML. Follow the site's style and regulations. Use block comments and semantic headings.";
			$user_parts = array();
			if ( $category_name ) {
				$user_parts[] = "Category: {$category_name}";
			}
			if ( ! empty( $keywords ) ) {
				$user_parts[] = 'Keywords: ' . implode( ', ', $keywords );
			}
			if ( $word_count > 0 ) {
				$user_parts[] = "Target length: {$word_count} words";
			}
			$user_parts[] = 'Output Gutenberg block HTML only.';

			return array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $system,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => implode( "\n", $user_parts ),
							),
						),
					),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/format-conversion", array(
		'label'       => 'Format Conversion Prompt',
		'description' => 'プレーンテキストをGutenbergブロック形式へ変換するプロンプトを返します。',
		'category'    => 'prompt',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'plain_text' => array( 'type' => 'string' ),
				'target_format' => array( 'type' => 'string' ),
			),
			'required' => array( 'plain_text' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array( 'type' => 'array' ),
			),
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array(
				'arguments' => array(
					array(
						'name'        => 'plain_text',
						'description' => 'Plain text to convert',
						'required'    => true,
					),
					array(
						'name'        => 'target_format',
						'description' => 'Target block format (optional)',
						'required'    => false,
					),
				),
			)
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$plain_text = isset( $input['plain_text'] ) ? (string) $input['plain_text'] : '';
			$target_format = isset( $input['target_format'] ) ? (string) $input['target_format'] : 'Gutenberg block HTML';

			$system = 'You are an assistant converting content into Gutenberg block HTML.';
			$user = "Convert the following content into {$target_format}. Preserve meaning and structure. Output only block HTML.\n\n" . $plain_text;

			return array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $system,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $user,
							),
						),
					),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/seo-optimization", array(
		'label'       => 'SEO Optimization Prompt',
		'description' => 'SEO最適化提案生成のためのプロンプトを返します。',
		'category'    => 'prompt',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'target_keywords' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required' => array( 'post_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array( 'type' => 'array' ),
			),
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array(
				'arguments' => array(
					array(
						'name'        => 'post_id',
						'description' => 'Target post ID',
						'required'    => true,
					),
					array(
						'name'        => 'target_keywords',
						'description' => 'SEO target keywords',
						'required'    => false,
					),
				),
			)
		),
		'permission_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			return $post_id ? current_user_can( 'read_post', $post_id ) : current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new \WP_Error( 'invalid_input', 'post_id is required.' );
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'not_found', 'Post not found.' );
			}
			$keywords = isset( $input['target_keywords'] ) && is_array( $input['target_keywords'] ) ? array_filter( $input['target_keywords'] ) : array();

			$title = $post->post_title;
			$content = wp_strip_all_tags( $post->post_content );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $content ) > 2000 ) {
				$content = mb_substr( $content, 0, 2000 ) . '...';
			}

			$system = 'You are an SEO advisor for WordPress content. Provide actionable optimizations.';
			$user_parts = array(
				"Post title: {$title}",
				"Excerpt/content: {$content}",
			);
			if ( ! empty( $keywords ) ) {
				$user_parts[] = 'Target keywords: ' . implode( ', ', $keywords );
			}

			return array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $system,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => implode( "\n", $user_parts ),
							),
						),
					),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/regulation-learning", array(
		'label'       => 'Regulation Learning Prompt',
		'description' => '既存記事からレギュレーション学習を行うプロンプトを返します。',
		'category'    => 'prompt',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_id' => array( 'type' => 'integer' ),
				'sample_count' => array( 'type' => 'integer' ),
			),
			'required' => array( 'category_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'messages' => array( 'type' => 'array' ),
			),
		),
		'meta' => wp_mcp_meta(
			array( 'readonly' => true ),
			array(
				'arguments' => array(
					array(
						'name'        => 'category_id',
						'description' => 'Target category ID',
						'required'    => true,
					),
					array(
						'name'        => 'sample_count',
						'description' => 'Number of sample posts',
						'required'    => false,
					),
				),
			)
		),
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			$sample_count = isset( $input['sample_count'] ) ? (int) $input['sample_count'] : 5;

			if ( ! $category_id ) {
				return new \WP_Error( 'invalid_input', 'category_id is required.' );
			}

			$category = get_category( $category_id );
			if ( ! $category || is_wp_error( $category ) ) {
				return new \WP_Error( 'not_found', 'Category not found.' );
			}
			$category_name = $category->name;

			$system = 'You are an assistant extracting editorial regulations from existing WordPress articles.';
			$user = "Analyze category '{$category_name}' using {$sample_count} sample posts. Summarize heading rules, required sections, tone, formatting, and block usage.";

			return array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $system,
							),
						),
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => $user,
							),
						),
					),
				),
			);
		},
	) );
}

if ( did_action( 'wp_abilities_api_init' ) ) {
	wp_mcp_register_prompts();
}
