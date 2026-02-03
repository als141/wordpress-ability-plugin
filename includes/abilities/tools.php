<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

add_action( 'wp_abilities_api_init', 'wp_mcp_register_tools' );

function wp_mcp_register_tools() {
	$prefix         = defined( 'WP_MCP_ABILITY_PREFIX' ) ? WP_MCP_ABILITY_PREFIX : 'wp-mcp';
	$meta_readonly  = wp_mcp_meta( array( 'readonly' => true ) );
	$meta_write     = wp_mcp_meta( array( 'destructive' => true ) );
	$meta_idempotent = wp_mcp_meta( array( 'destructive' => true, 'idempotent' => true ) );

	// 1. Content analysis tools.
	wp_register_ability( "{$prefix}/get-posts-by-category", array(
		'label'       => 'Get Posts by Category',
		'description' => '指定カテゴリの記事一覧を取得します。',
		'category'    => 'analysis',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 10,
				),
				'order' => array(
					'type'    => 'string',
					'enum'    => array( 'DESC', 'ASC' ),
					'default' => 'DESC',
				),
				'orderby' => array(
					'type'    => 'string',
					'enum'    => array( 'date', 'title', 'modified' ),
					'default' => 'date',
				),
			),
			'required' => array( 'category_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'   => array( 'type' => 'integer' ),
							'title'     => array( 'type' => 'string' ),
							'date'      => array( 'type' => 'string' ),
							'modified'  => array( 'type' => 'string' ),
							'word_count'=> array( 'type' => 'integer' ),
							'status'    => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			if ( ! $category_id ) {
				return new WP_Error( 'invalid_category', 'category_id is required.' );
			}
			$category = get_category( $category_id );
			if ( ! $category || is_wp_error( $category ) ) {
				return new WP_Error( 'invalid_category', 'Category not found.' );
			}

			$limit   = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
			$limit   = max( 1, min( 100, $limit ) );
			$order   = isset( $input['order'] ) && in_array( strtoupper( $input['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $input['order'] ) : 'DESC';
			$orderby = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'title', 'modified' ), true ) ? $input['orderby'] : 'date';

			$q = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'cat'            => $category_id,
					'orderby'        => $orderby,
					'order'          => $order,
					'no_found_rows'  => true,
				)
			);

			$items = array();
			while ( $q->have_posts() ) {
				$q->the_post();
				$post = get_post();
				$date = get_the_date( DATE_ATOM, $post );
				$modified = get_the_modified_date( DATE_ATOM, $post );
				$items[] = array(
					'post_id'    => (int) $post->ID,
					'title'      => wp_mcp_string_value( get_the_title( $post ) ),
					'date'       => $date ? $date : '',
					'modified'   => $modified ? $modified : '',
					'word_count' => (int) wp_mcp_content_word_count( $post->post_content ),
					'status'     => wp_mcp_string_value( $post->post_status ),
				);
			}
			wp_reset_postdata();

			return array( 'items' => $items );
		},
	) );

	wp_register_ability( "{$prefix}/get-post-block-structure", array(
		'label'       => 'Get Post Block Structure',
		'description' => '記事のGutenbergブロック構造をJSON形式で取得します。',
		'category'    => 'analysis',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required' => array( 'post_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'blockName'   => array( 'type' => array( 'string', 'null' ) ),
							'attrs'       => array( 'type' => 'object' ),
							'innerBlocks' => array( 'type' => 'array' ),
							'innerHTML'   => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'invalid_post', 'post_id is required.' );
			}
			$post = wp_mcp_get_post_or_error( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$blocks = parse_blocks( $post->post_content );
			return array( 'items' => wp_mcp_map_block_structure( $blocks ) );
		},
	) );

	wp_register_ability( "{$prefix}/analyze-category-format-patterns", array(
		'label'       => 'Analyze Category Format Patterns',
		'description' => 'カテゴリ内の記事から共通フォーマットパターンを抽出します。',
		'category'    => 'analysis',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'sample_count' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 5,
				),
			),
			'required' => array( 'category_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_name'   => array( 'type' => 'string' ),
				'common_blocks'   => array( 'type' => 'array' ),
				'common_classes'  => array( 'type' => 'array' ),
				'typical_structure' => array( 'type' => 'array' ),
				'heading_patterns'  => array( 'type' => 'object' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			if ( ! $category_id ) {
				return new WP_Error( 'invalid_category', 'category_id is required.' );
			}
			$category = get_category( $category_id );
			if ( ! $category || is_wp_error( $category ) ) {
				return new WP_Error( 'invalid_category', 'Category not found.' );
			}

			$sample_count = isset( $input['sample_count'] ) ? (int) $input['sample_count'] : 5;
			$sample_count = max( 1, min( 20, $sample_count ) );

			$posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'numberposts'    => $sample_count,
					'category'       => $category_id,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$block_counts  = array();
			$class_counts  = array();
			$heading_levels = array();
			$structures    = array();

			foreach ( $posts as $post ) {
				$blocks = parse_blocks( $post->post_content );
				wp_mcp_collect_block_stats( $blocks, $block_counts, $class_counts, $heading_levels );

				$structure = array();
			foreach ( $blocks as $block ) {
				$structure[] = isset( $block['blockName'] ) ? $block['blockName'] : 'core/freeform';
			}
				$structures[] = $structure;
			}

			arsort( $block_counts );
			arsort( $class_counts );

			$threshold = max( 1, (int) ceil( count( $posts ) * 0.6 ) );
			$common_blocks = array();
			foreach ( $block_counts as $block_name => $count ) {
				if ( $count >= $threshold ) {
					$common_blocks[] = $block_name;
				}
			}

			$common_classes = array();
			foreach ( $class_counts as $class_name => $count ) {
				if ( $count >= $threshold ) {
					$common_classes[] = $class_name;
				}
			}

			return array(
				'category_name'     => $category->name,
				'common_blocks'     => array_values( $common_blocks ),
				'common_classes'    => array_values( $common_classes ),
				'typical_structure' => isset( $structures[0] ) ? $structures[0] : array(),
				'heading_patterns'  => array(
					'levels' => $heading_levels,
					'total'  => array_sum( $heading_levels ),
				),
			);
		},
	) );

	wp_register_ability( "{$prefix}/get-post-raw-content", array(
		'label'       => 'Get Post Raw Content',
		'description' => '記事の生コンテンツ（ブロックHTML）を取得します。',
		'category'    => 'analysis',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required' => array( 'post_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'         => array( 'type' => 'integer' ),
				'raw_content'     => array( 'type' => 'string' ),
				'rendered_content'=> array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'invalid_post', 'post_id is required.' );
			}
			$post = wp_mcp_get_post_or_error( $post_id );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			return array(
				'post_id'         => (int) $post->ID,
				'raw_content'     => wp_mcp_string_value( $post->post_content ),
				'rendered_content'=> wp_mcp_string_value( apply_filters( 'the_content', $post->post_content ) ),
			);
		},
	) );

	wp_register_ability( "{$prefix}/extract-used-blocks", array(
		'label'       => 'Extract Used Blocks',
		'description' => '指定範囲の記事から使用ブロックの頻度を抽出します。',
		'category'    => 'analysis',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array(
					'type'    => 'string',
					'default' => 'post',
				),
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 100,
				),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'block_name' => array( 'type' => 'string' ),
							'count'      => array( 'type' => 'integer' ),
							'percentage' => array( 'type' => 'number' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'post';
			$limit     = isset( $input['limit'] ) ? (int) $input['limit'] : 100;
			$limit     = max( 1, min( 500, $limit ) );

			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'numberposts'    => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$counts = array();
			$total  = 0;
			foreach ( $posts as $post ) {
				$blocks = parse_blocks( $post->post_content );
				$names  = array();
				wp_mcp_flatten_block_names( $blocks, $names );
				foreach ( $names as $name ) {
					$counts[ $name ] = ( isset( $counts[ $name ] ) ? $counts[ $name ] : 0 ) + 1;
					$total++;
				}
			}

			arsort( $counts );
			$results = array();
			foreach ( $counts as $name => $count ) {
				$results[] = array(
					'block_name' => $name,
					'count'      => $count,
					'percentage' => $total > 0 ? round( ( $count / $total ) * 100, 2 ) : 0,
				);
			}

			return array( 'items' => $results );
		},
	) );

	// 2. Style / regulation tools.
	wp_register_ability( "{$prefix}/get-theme-styles", array(
		'label'       => 'Get Theme Styles',
		'description' => 'テーマのグローバルスタイル設定を取得します。',
		'category'    => 'style',
		'input_schema' => array(
			'type' => 'object',
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'colors'     => array( 'type' => 'object' ),
				'typography' => array( 'type' => 'object' ),
				'spacing'    => array( 'type' => 'object' ),
				'layout'     => array( 'type' => 'object' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$settings = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings() : array();
			$styles   = function_exists( 'wp_get_global_styles' ) ? wp_get_global_styles() : array();

			return array(
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
				'spacing' => wp_mcp_object_value( isset( $settings['spacing'] ) ? $settings['spacing'] : array() ),
				'layout'  => wp_mcp_object_value( isset( $settings['layout'] ) ? $settings['layout'] : array() ),
			);
		},
	) );

	wp_register_ability( "{$prefix}/get-block-patterns", array(
		'label'       => 'Get Block Patterns',
		'description' => '登録済みのブロックパターン一覧を取得します。',
		'category'    => 'style',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category' => array( 'type' => 'string' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'content'     => array( 'type' => 'string' ),
							'categories'  => array( 'type' => 'array' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
				return array( 'items' => array() );
			}

			$category = isset( $input['category'] ) ? sanitize_key( $input['category'] ) : '';
			$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
			$results  = array();

			foreach ( $patterns as $pattern ) {
				if ( $category && ( empty( $pattern['categories'] ) || ! in_array( $category, $pattern['categories'], true ) ) ) {
					continue;
				}

				$results[] = array(
					'name'        => isset( $pattern['name'] ) ? $pattern['name'] : '',
					'title'       => isset( $pattern['title'] ) ? $pattern['title'] : '',
					'description' => isset( $pattern['description'] ) ? $pattern['description'] : '',
					'content'     => isset( $pattern['content'] ) ? $pattern['content'] : '',
					'categories'  => isset( $pattern['categories'] ) ? $pattern['categories'] : array(),
				);
			}

			return array( 'items' => $results );
		},
	) );

	wp_register_ability( "{$prefix}/get-reusable-blocks", array(
		'label'       => 'Get Reusable Blocks',
		'description' => '再利用ブロック一覧を取得します。',
		'category'    => 'style',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 100,
				),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'integer' ),
							'title'   => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
							'status'  => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 100;
			$per_page = max( 1, min( 200, $per_page ) );

			$posts = get_posts(
				array(
					'post_type'      => 'wp_block',
					'post_status'    => array( 'publish', 'draft' ),
					'numberposts'    => $per_page,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			$items = array();
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'content' => $post->post_content,
					'status'  => $post->post_status,
				);
			}

			return array( 'items' => $items );
		},
	) );

	wp_register_ability( "{$prefix}/get-article-regulations", array(
		'label'       => 'Get Article Regulations',
		'description' => 'カテゴリ別のレギュレーション設定を取得します。',
		'category'    => 'style',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'category_id' => array( 'type' => 'integer' ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'heading_rules'     => array( 'type' => 'object' ),
				'required_sections' => array( 'type' => 'array' ),
				'allowed_boxes'     => array( 'type' => 'array' ),
				'color_scheme'      => array( 'type' => 'object' ),
				'formatting_rules'  => array( 'type' => 'object' ),
				'configured'        => array( 'type' => 'boolean' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			$regulations = get_option( 'mcp_article_regulations', array() );
			$configured  = ! empty( $regulations );

			if ( $category_id && is_array( $regulations ) && isset( $regulations[ $category_id ] ) ) {
				$data = $regulations[ $category_id ];
			} else {
				$data = $regulations;
			}

			$heading_rules = ( isset( $data['heading_rules'] ) && is_array( $data['heading_rules'] ) ) ? $data['heading_rules'] : array();
			$required_sections = ( isset( $data['required_sections'] ) && is_array( $data['required_sections'] ) ) ? $data['required_sections'] : array();
			$allowed_boxes = ( isset( $data['allowed_boxes'] ) && is_array( $data['allowed_boxes'] ) ) ? $data['allowed_boxes'] : array();
			$color_scheme = ( isset( $data['color_scheme'] ) && is_array( $data['color_scheme'] ) ) ? $data['color_scheme'] : array();
			$formatting_rules = ( isset( $data['formatting_rules'] ) && is_array( $data['formatting_rules'] ) ) ? $data['formatting_rules'] : array();

			return array(
				'heading_rules'     => wp_mcp_object_value( $heading_rules ),
				'required_sections' => array_values( $required_sections ),
				'allowed_boxes'     => array_values( $allowed_boxes ),
				'color_scheme'      => wp_mcp_object_value( $color_scheme ),
				'formatting_rules'  => wp_mcp_object_value( $formatting_rules ),
				'configured'        => (bool) $configured,
			);
		},
	) );

	// 3. Post creation & editing tools.
	wp_register_ability( "{$prefix}/create-draft-post", array(
		'label'       => 'Create Draft Post',
		'description' => 'GutenbergブロックHTMLで新規下書きを作成します。',
		'category'    => 'content',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'title' => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'category_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'tag_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'excerpt' => array( 'type' => 'string' ),
				'meta'    => array( 'type' => 'object' ),
			),
			'required' => array( 'title', 'content' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'edit_url'   => array( 'type' => 'string' ),
				'preview_url'=> array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'edit_posts' );
		},
		'execute_callback' => static function ( $input ) {
			$title   = isset( $input['title'] ) ? wp_strip_all_tags( (string) $input['title'] ) : '';
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$excerpt = isset( $input['excerpt'] ) ? (string) $input['excerpt'] : '';

			if ( '' === $title || '' === $content ) {
				return new WP_Error( 'invalid_input', 'title and content are required.' );
			}

			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
					'post_status'  => 'draft',
					'post_type'    => 'post',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$category_ids = isset( $input['category_ids'] ) ? wp_mcp_sanitize_int_array( $input['category_ids'] ) : array();
			$tag_ids      = isset( $input['tag_ids'] ) ? wp_mcp_sanitize_int_array( $input['tag_ids'] ) : array();

			if ( ! empty( $category_ids ) ) {
				wp_set_post_terms( $post_id, $category_ids, 'category', false );
			}
			if ( ! empty( $tag_ids ) ) {
				wp_set_post_terms( $post_id, $tag_ids, 'post_tag', false );
			}

			if ( ! empty( $input['meta'] ) && is_array( $input['meta'] ) ) {
				foreach ( $input['meta'] as $meta_key => $meta_value ) {
					$meta_key = sanitize_key( $meta_key );
					if ( is_protected_meta( $meta_key, 'post' ) ) {
						continue;
					}
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

			$edit_url    = get_edit_post_link( $post_id, 'raw' );
			$preview_url = function_exists( 'get_preview_post_link' ) ? get_preview_post_link( $post_id ) : get_permalink( $post_id );

			return array(
				'post_id'    => (int) $post_id,
				'edit_url'   => $edit_url ? wp_mcp_string_value( $edit_url ) : '',
				'preview_url'=> $preview_url ? wp_mcp_string_value( $preview_url ) : '',
			);
		},
	) );

	wp_register_ability( "{$prefix}/update-post-content", array(
		'label'       => 'Update Post Content',
		'description' => '既存記事のコンテンツを更新します。',
		'category'    => 'content',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'content' => array( 'type' => 'string' ),
				'title'   => array( 'type' => 'string' ),
			),
			'required' => array( 'post_id', 'content' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'     => array( 'type' => 'boolean' ),
				'post_id'     => array( 'type' => 'integer' ),
				'modified_at' => array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_idempotent,
		'permission_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$title   = isset( $input['title'] ) ? (string) $input['title'] : '';

			if ( ! $post_id ) {
				return new WP_Error( 'invalid_post', 'post_id is required.' );
			}

			$update = array(
				'ID'           => $post_id,
				'post_content' => $content,
			);

			if ( '' !== $title ) {
				$update['post_title'] = wp_strip_all_tags( $title );
			}

			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'     => true,
				'post_id'     => (int) $post_id,
				'modified_at' => wp_mcp_string_value( get_post_modified_time( DATE_ATOM, true, $post_id ) ),
			);
		},
	) );

	wp_register_ability( "{$prefix}/update-post-meta", array(
		'label'       => 'Update Post Meta',
		'description' => '記事のメタ情報を更新します。',
		'category'    => 'content',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'    => array( 'type' => 'integer' ),
				'meta_key'   => array( 'type' => 'string' ),
				'meta_value' => array(
					'type' => array( 'string', 'number', 'boolean', 'object', 'array', 'null' ),
					'items' => array( 'type' => array( 'string', 'number', 'boolean', 'object', 'null' ) ),
				),
			),
			'required' => array( 'post_id', 'meta_key', 'meta_value' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success' => array( 'type' => 'boolean' ),
				'meta_id' => array( 'type' => array( 'integer', 'null' ) ),
			),
		),
		'meta' => $meta_idempotent,
		'permission_callback' => static function ( $input ) {
			$post_id  = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$meta_key = isset( $input['meta_key'] ) ? sanitize_key( $input['meta_key'] ) : '';
			if ( ! $post_id || '' === $meta_key ) {
				return current_user_can( 'edit_posts' );
			}
			return current_user_can( 'edit_post', $post_id ) && current_user_can( 'edit_post_meta', $post_id, $meta_key );
		},
		'execute_callback' => static function ( $input ) {
			$post_id  = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$meta_key = isset( $input['meta_key'] ) ? sanitize_key( $input['meta_key'] ) : '';
			if ( ! $post_id || '' === $meta_key ) {
				return new WP_Error( 'invalid_input', 'post_id and meta_key are required.' );
			}

			$meta_value = $input['meta_value'];
			$updated    = update_post_meta( $post_id, $meta_key, $meta_value );

			// update_post_meta returns false when the value is unchanged, which is still a success.
			$success = false !== $updated;

			return array(
				'success' => $success,
				'meta_id' => is_int( $updated ) ? $updated : null,
			);
		},
	) );

	wp_register_ability( "{$prefix}/publish-post", array(
		'label'       => 'Publish Post',
		'description' => '下書き記事を公開または予約投稿します。',
		'category'    => 'content',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'scheduled_time' => array( 'type' => 'string' ),
			),
			'required' => array( 'post_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'published_url' => array( 'type' => 'string' ),
				'published_at'  => array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			return $post_id ? current_user_can( 'publish_post', $post_id ) : current_user_can( 'publish_posts' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'invalid_post', 'post_id is required.' );
			}

			$scheduled_time = isset( $input['scheduled_time'] ) ? (string) $input['scheduled_time'] : '';
			$update = array( 'ID' => $post_id );

			if ( '' !== $scheduled_time ) {
				$timestamp = strtotime( $scheduled_time );
				if ( ! $timestamp ) {
					return new WP_Error( 'invalid_datetime', 'scheduled_time must be a valid ISO 8601 datetime.' );
				}
				$post_date_gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
				$update['post_status'] = 'future';
				$update['post_date_gmt'] = $post_date_gmt;
				$update['post_date'] = get_date_from_gmt( $post_date_gmt );
			} else {
				$update['post_status'] = 'publish';
			}

			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success'       => true,
				'published_url' => wp_mcp_string_value( get_permalink( $post_id ) ),
				'published_at'  => wp_mcp_string_value( get_post_time( DATE_ATOM, true, $post_id ) ),
			);
		},
	) );

	wp_register_ability( "{$prefix}/delete-post", array(
		'label'       => 'Delete Post',
		'description' => '記事を削除（ゴミ箱移動または完全削除）します。',
		'category'    => 'content',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'force'   => array( 'type' => 'boolean', 'default' => false ),
			),
			'required' => array( 'post_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'          => array( 'type' => 'boolean' ),
				'deleted_post_id'  => array( 'type' => 'integer' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			return $post_id ? current_user_can( 'delete_post', $post_id ) : current_user_can( 'delete_posts' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( ! $post_id ) {
				return new WP_Error( 'invalid_post', 'post_id is required.' );
			}
			$force = ! empty( $input['force'] );
			$deleted = $force ? wp_delete_post( $post_id, true ) : wp_trash_post( $post_id );
			if ( ! $deleted ) {
				return new WP_Error( 'delete_failed', 'Failed to delete post.' );
			}

			return array(
				'success'         => true,
				'deleted_post_id' => $post_id,
			);
		},
	) );

	// 4. Validation tools.
	wp_register_ability( "{$prefix}/validate-block-content", array(
		'label'       => 'Validate Block Content',
		'description' => 'ブロックコンテンツの構文・形式チェックを行います。',
		'category'    => 'validation',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'content' => array( 'type' => 'string' ),
			),
			'required' => array( 'content' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'is_valid'   => array( 'type' => 'boolean' ),
				'block_count'=> array( 'type' => 'integer' ),
				'errors'     => array( 'type' => 'array' ),
				'warnings'   => array( 'type' => 'array' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$errors = array();
			$warnings = array();

			if ( '' === trim( $content ) ) {
				$errors[] = 'Content is empty.';
			}

			$has_blocks = has_blocks( $content );
			$blocks = $has_blocks ? parse_blocks( $content ) : array();
			$block_count = 0;
			if ( $has_blocks ) {
				$names = array();
				wp_mcp_flatten_block_names( $blocks, $names );
				$block_count = count( $names );
			} else {
				$warnings[] = 'No block markers detected.';
			}

			return array(
				'is_valid'    => empty( $errors ),
				'block_count' => $block_count,
				'errors'      => $errors,
				'warnings'    => $warnings,
			);
		},
	) );

	wp_register_ability( "{$prefix}/check-regulation-compliance", array(
		'label'       => 'Check Regulation Compliance',
		'description' => 'カテゴリ別レギュレーションへの準拠を検証します。',
		'category'    => 'validation',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'content' => array( 'type' => 'string' ),
				'category_id' => array( 'type' => 'integer' ),
			),
			'required' => array( 'content', 'category_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'is_compliant' => array( 'type' => 'boolean' ),
				'violations'   => array( 'type' => 'array' ),
				'suggestions'  => array( 'type' => 'array' ),
				'score'        => array( 'type' => 'number' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$category_id = isset( $input['category_id'] ) ? absint( $input['category_id'] ) : 0;
			$violations = array();
			$suggestions = array();

			$regulations = get_option( 'mcp_article_regulations', array() );
			$rules = array();
			if ( $category_id && isset( $regulations[ $category_id ] ) ) {
				$rules = $regulations[ $category_id ];
			}

			if ( ! empty( $rules['required_sections'] ) && is_array( $rules['required_sections'] ) ) {
				preg_match_all( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $matches );
				$heading_list = isset( $matches[1] ) ? $matches[1] : array();
				$headings = array_map( static function ( $text ) {
					return trim( wp_strip_all_tags( $text ) );
				}, $heading_list );

				foreach ( $rules['required_sections'] as $required ) {
					$required = trim( (string) $required );
					if ( '' === $required ) {
						continue;
					}
					if ( ! in_array( $required, $headings, true ) ) {
						$violations[] = 'Missing required section: ' . $required;
					}
				}
			}

			if ( empty( $rules ) ) {
				$suggestions[] = 'No regulation rules configured; compliance checks were limited.';
			}

			$score = max( 0, 100 - ( count( $violations ) * 10 ) );

			return array(
				'is_compliant' => empty( $violations ),
				'violations'   => $violations,
				'suggestions'  => $suggestions,
				'score'        => $score,
			);
		},
	) );

	wp_register_ability( "{$prefix}/check-seo-requirements", array(
		'label'       => 'Check SEO Requirements',
		'description' => 'SEO要件チェックを行います。',
		'category'    => 'validation',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'content' => array( 'type' => 'string' ),
				'target_keywords' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'title' => array( 'type' => 'string' ),
			),
			'required' => array( 'content' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'score'             => array( 'type' => 'number' ),
				'keyword_density'   => array( 'type' => 'object' ),
				'heading_structure' => array( 'type' => 'object' ),
				'issues'            => array( 'type' => 'array' ),
				'recommendations'   => array( 'type' => 'array' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$content = isset( $input['content'] ) ? (string) $input['content'] : '';
			$title   = isset( $input['title'] ) ? (string) $input['title'] : '';
			$keywords = isset( $input['target_keywords'] ) && is_array( $input['target_keywords'] ) ? $input['target_keywords'] : array();

			$issues = array();
			$recommendations = array();
			$score = 100;

			$word_count = wp_mcp_content_word_count( $content );
			if ( $word_count < 300 ) {
				$issues[] = 'Content is shorter than 300 words.';
				$recommendations[] = 'Expand the content to at least 300 words.';
				$score -= 10;
			}

			if ( '' !== $title ) {
				$title_text   = wp_strip_all_tags( $title );
				$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title_text ) : strlen( $title_text );
				if ( $title_length < 30 || $title_length > 60 ) {
					$issues[] = 'Title length should be between 30 and 60 characters.';
					$recommendations[] = 'Adjust title length to 30-60 characters.';
					$score -= 5;
				}
			}

			preg_match_all( '/<h([1-6])\b[^>]*>/i', $content, $heading_matches );
			$heading_counts = array();
			foreach ( $heading_matches[1] as $level ) {
				$level_key = 'h' . $level;
				$heading_counts[ $level_key ] = ( isset( $heading_counts[ $level_key ] ) ? $heading_counts[ $level_key ] : 0 ) + 1;
			}
			if ( ! empty( $heading_counts['h1'] ) ) {
				$issues[] = 'H1 heading found in content. WordPress themes typically output H1 from the post title, causing duplicate H1 tags.';
				$recommendations[] = 'Use H2 and below for content headings. The post title serves as H1.';
				$score -= 5;
			}

			$plain_text = wp_strip_all_tags( $content );
			$plain = function_exists( 'mb_strtolower' ) ? mb_strtolower( $plain_text ) : strtolower( $plain_text );
			// For CJK text, use character count as the denominator for density.
			$is_cjk       = (bool) preg_match( '/[\x{3000}-\x{9FFF}\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}]/u', $plain );
			$density_base = $is_cjk && function_exists( 'mb_strlen' )
				? mb_strlen( preg_replace( '/\s+/u', '', $plain ) )
				: $word_count;
			$keyword_density = array();
			foreach ( $keywords as $keyword ) {
				$keyword = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $keyword ) : strtolower( (string) $keyword ) );
				if ( '' === $keyword ) {
					continue;
				}
				$occurrences = function_exists( 'mb_substr_count' ) ? mb_substr_count( $plain, $keyword ) : substr_count( $plain, $keyword );
				$kw_len      = $is_cjk && function_exists( 'mb_strlen' ) ? mb_strlen( $keyword ) : 1;
				$density     = $density_base > 0 ? ( $occurrences * $kw_len ) / $density_base : 0;
				$keyword_density[ $keyword ] = round( $density, 4 );
				if ( $occurrences === 0 ) {
					$issues[] = 'Keyword not found: ' . $keyword;
					$recommendations[] = 'Add the keyword to the content: ' . $keyword;
					$score -= 5;
				}
			}

			return array(
				'score'             => max( 0, $score ),
				'keyword_density'   => wp_mcp_object_value( $keyword_density ),
				'heading_structure' => wp_mcp_object_value( $heading_counts ),
				'issues'            => $issues,
				'recommendations'   => $recommendations,
			);
		},
	) );

	// 5. Media tools.
	wp_register_ability( "{$prefix}/get-media-library", array(
		'label'       => 'Get Media Library',
		'description' => 'メディアライブラリから画像・ファイル一覧を取得します。',
		'category'    => 'media',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'search' => array( 'type' => 'string' ),
				'mime_type' => array( 'type' => 'string' ),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array( 'type' => 'integer' ),
							'url'       => array( 'type' => 'string' ),
							'title'     => array( 'type' => 'string' ),
							'alt'       => array( 'type' => 'string' ),
							'caption'   => array( 'type' => 'string' ),
							'width'     => array( 'type' => 'integer' ),
							'height'    => array( 'type' => 'integer' ),
							'mime_type' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'upload_files' );
		},
		'execute_callback' => static function ( $input ) {
			$search    = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
			$mime_type = isset( $input['mime_type'] ) ? sanitize_text_field( $input['mime_type'] ) : '';
			$per_page  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
			$per_page  = max( 1, min( 100, $per_page ) );

			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'numberposts'    => $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);

			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			if ( '' !== $mime_type ) {
				$args['post_mime_type'] = $mime_type;
			}

			$attachments = get_posts( $args );
			$items = array();
			foreach ( $attachments as $attachment ) {
				$meta = wp_get_attachment_metadata( $attachment->ID );
				$url = wp_get_attachment_url( $attachment->ID );
				$items[] = array(
					'id'        => (int) $attachment->ID,
					'url'       => $url ? wp_mcp_string_value( $url ) : '',
					'title'     => wp_mcp_string_value( $attachment->post_title ),
					'alt'       => wp_mcp_string_value( get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ),
					'caption'   => wp_mcp_string_value( $attachment->post_excerpt ),
					'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
					'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
					'mime_type' => wp_mcp_string_value( $attachment->post_mime_type ),
				);
			}

			return array( 'items' => $items );
		},
	) );

	wp_register_ability( "{$prefix}/upload-media", array(
		'label'       => 'Upload Media',
		'description' => 'URLまたはBase64からメディアをアップロードします。',
		'category'    => 'media',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'source' => array( 'type' => 'string' ),
				'filename' => array( 'type' => 'string' ),
				'title' => array( 'type' => 'string' ),
				'alt' => array( 'type' => 'string' ),
				'caption' => array( 'type' => 'string' ),
			),
			'required' => array( 'source', 'filename' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'media_id' => array( 'type' => 'integer' ),
				'url'      => array( 'type' => 'string' ),
				'width'    => array( 'type' => 'integer' ),
				'height'   => array( 'type' => 'integer' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'upload_files' );
		},
		'execute_callback' => static function ( $input ) {
			$source   = isset( $input['source'] ) ? (string) $input['source'] : '';
			$filename = isset( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : '';
			$title    = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
			$alt      = isset( $input['alt'] ) ? sanitize_text_field( $input['alt'] ) : '';
			$caption  = isset( $input['caption'] ) ? sanitize_text_field( $input['caption'] ) : '';

			if ( '' === $source || '' === $filename ) {
				return new WP_Error( 'invalid_input', 'source and filename are required.' );
			}

			wp_mcp_ensure_media_includes();

			$tmp_file = null;
			$cleanup  = static function ( $path ) {
				if ( $path && file_exists( $path ) ) {
					@unlink( $path );
				}
			};

			if ( preg_match( '#^https?://#i', $source ) ) {
				if ( ! wp_http_validate_url( $source ) ) {
					return new WP_Error( 'invalid_url', 'source must be a valid URL.' );
				}
				$tmp_file = download_url( $source );
				if ( is_wp_error( $tmp_file ) ) {
					return $tmp_file;
				}
			} else {
				if ( preg_match( '#^data:#', $source ) ) {
					$source = preg_replace( '#^data:.*;base64,#', '', $source );
				}
				$decoded = base64_decode( $source, true );
				if ( false === $decoded ) {
					return new WP_Error( 'invalid_base64', 'source must be a valid base64 string.' );
				}
				$tmp_file = wp_tempnam( $filename );
				if ( ! $tmp_file ) {
					return new WP_Error( 'tmp_file_failed', 'Failed to create temp file.' );
				}
				file_put_contents( $tmp_file, $decoded );
			}

			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			);

			$attachment_id = media_handle_sideload(
				$file_array,
				0,
				$title,
				array(
					'post_title'   => $title,
					'post_excerpt' => $caption,
				)
			);

			$cleanup( $tmp_file );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}

			$meta = wp_get_attachment_metadata( $attachment_id );
			$url = wp_get_attachment_url( $attachment_id );

			return array(
				'media_id' => (int) $attachment_id,
				'url'      => $url ? wp_mcp_string_value( $url ) : '',
				'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			);
		},
	) );

	wp_register_ability( "{$prefix}/set-featured-image", array(
		'label'       => 'Set Featured Image',
		'description' => '記事のアイキャッチ画像を設定します。',
		'category'    => 'media',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'  => array( 'type' => 'integer' ),
				'media_id' => array( 'type' => 'integer' ),
			),
			'required' => array( 'post_id', 'media_id' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'success'       => array( 'type' => 'boolean' ),
				'thumbnail_url' => array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		},
		'execute_callback' => static function ( $input ) {
			$post_id  = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			$media_id = isset( $input['media_id'] ) ? absint( $input['media_id'] ) : 0;
			if ( ! $post_id || ! $media_id ) {
				return new WP_Error( 'invalid_input', 'post_id and media_id are required.' );
			}
			if ( 'attachment' !== get_post_type( $media_id ) ) {
				return new WP_Error( 'invalid_media', 'media_id must reference an attachment.' );
			}

			$set = set_post_thumbnail( $post_id, $media_id );
			$thumb_url = wp_get_attachment_image_url( $media_id, 'full' );

			return array(
				'success'       => (bool) $set,
				'thumbnail_url' => $thumb_url ? $thumb_url : '',
			);
		},
	) );

	// 6. Taxonomy tools.
	wp_register_ability( "{$prefix}/get-categories", array(
		'label'       => 'Get Categories',
		'description' => 'カテゴリ一覧を取得します。',
		'category'    => 'taxonomy',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'parent' => array( 'type' => 'integer' ),
				'hide_empty' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array( 'type' => 'integer' ),
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'parent'      => array( 'type' => 'integer' ),
							'count'       => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$args = array(
				'hide_empty' => ! empty( $input['hide_empty'] ),
			);
			if ( isset( $input['parent'] ) ) {
				$args['parent'] = absint( $input['parent'] );
			}

			$categories = get_categories( $args );
			if ( is_wp_error( $categories ) ) {
				return $categories;
			}
			$items = array();
			foreach ( $categories as $category ) {
				$items[] = array(
					'id'          => (int) $category->term_id,
					'name'        => wp_mcp_string_value( $category->name ),
					'slug'        => wp_mcp_string_value( $category->slug ),
					'description' => wp_mcp_string_value( $category->description ),
					'parent'      => (int) $category->parent,
					'count'       => (int) $category->count,
				);
			}

			return array( 'items' => $items );
		},
	) );

	wp_register_ability( "{$prefix}/get-tags", array(
		'label'       => 'Get Tags',
		'description' => 'タグ一覧を取得します。',
		'category'    => 'taxonomy',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'search' => array( 'type' => 'string' ),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 100,
				),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array( 'type' => 'integer' ),
							'name'  => array( 'type' => 'string' ),
							'slug'  => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 100;
			$per_page = max( 1, min( 200, $per_page ) );
			$search   = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

			$tags = get_tags(
				array(
					'number'     => $per_page,
					'search'     => $search,
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $tags ) ) {
				return $tags;
			}

			$items = array();
			foreach ( $tags as $tag ) {
				$items[] = array(
					'id'    => (int) $tag->term_id,
					'name'  => wp_mcp_string_value( $tag->name ),
					'slug'  => wp_mcp_string_value( $tag->slug ),
					'count' => (int) $tag->count,
				);
			}

			return array( 'items' => $items );
		},
	) );

	wp_register_ability( "{$prefix}/create-term", array(
		'label'       => 'Create Term',
		'description' => 'カテゴリまたはタグを新規作成します。',
		'category'    => 'taxonomy',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy' => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag' ),
				),
				'name' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
				'parent' => array( 'type' => 'integer' ),
			),
			'required' => array( 'taxonomy', 'name' ),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'term_id' => array( 'type' => 'integer' ),
				'name'    => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
			),
		),
		'meta' => $meta_write,
		'permission_callback' => static function ( $input ) {
			$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
			$tax_obj = $taxonomy ? get_taxonomy( $taxonomy ) : null;
			if ( $tax_obj && ! empty( $tax_obj->cap->manage_terms ) ) {
				return current_user_can( $tax_obj->cap->manage_terms );
			}
			return current_user_can( 'manage_categories' );
		},
		'execute_callback' => static function ( $input ) {
			$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
			$name     = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
			$slug     = isset( $input['slug'] ) ? sanitize_title( $input['slug'] ) : '';
			$parent   = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;

			if ( '' === $taxonomy || '' === $name ) {
				return new WP_Error( 'invalid_input', 'taxonomy and name are required.' );
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'invalid_taxonomy', 'Taxonomy not found.' );
			}

			$args = array();
			if ( '' !== $slug ) {
				$args['slug'] = $slug;
			}
			if ( 'category' === $taxonomy && $parent ) {
				$args['parent'] = $parent;
			}

			$result = wp_insert_term( $name, $taxonomy, $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$term = get_term( $result['term_id'], $taxonomy );

			return array(
				'term_id' => (int) $result['term_id'],
				'name'    => wp_mcp_string_value( $term ? $term->name : $name ),
				'slug'    => wp_mcp_string_value( $term ? $term->slug : $slug ),
			);
		},
	) );

	// 7. Site info tools.
	wp_register_ability( "{$prefix}/get-site-info", array(
		'label'       => 'Get Site Info',
		'description' => 'サイトの基本情報を取得します。',
		'category'    => 'site',
		'input_schema' => array(
			'type' => 'object',
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'admin_email' => array( 'type' => 'string' ),
				'language'    => array( 'type' => 'string' ),
				'timezone'    => array( 'type' => 'string' ),
				'gmt_offset'  => array( 'type' => 'number' ),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function () {
			$info = array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => get_site_url(),
				'language'    => get_locale(),
				'timezone'    => get_option( 'timezone_string' ),
				'gmt_offset'  => (float) get_option( 'gmt_offset' ),
			);

			if ( current_user_can( 'manage_options' ) ) {
				$info['admin_email'] = get_option( 'admin_email' );
			}

			return $info;
		},
	) );

	wp_register_ability( "{$prefix}/get-post-types", array(
		'label'       => 'Get Post Types',
		'description' => '利用可能な投稿タイプ一覧を取得します。',
		'category'    => 'site',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'public_only' => array( 'type' => 'boolean', 'default' => true ),
			),
		),
		'output_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'         => array( 'type' => 'string' ),
							'label'        => array( 'type' => 'string' ),
							'description'  => array( 'type' => 'string' ),
							'rest_base'    => array( 'type' => 'string' ),
							'hierarchical' => array( 'type' => 'boolean' ),
							'has_archive'  => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		),
		'meta' => $meta_readonly,
		'permission_callback' => static function ( $input ) {
			return current_user_can( 'read' );
		},
		'execute_callback' => static function ( $input ) {
			$public_only = ! isset( $input['public_only'] ) || ! empty( $input['public_only'] );
			$args = $public_only ? array( 'public' => true ) : array();
			$post_types = get_post_types( $args, 'objects' );

			$items = array();
			foreach ( $post_types as $post_type ) {
				$rest_base = is_string( $post_type->rest_base ) ? $post_type->rest_base : '';
				$items[] = array(
					'name'         => wp_mcp_string_value( $post_type->name ),
					'label'        => wp_mcp_string_value( $post_type->label ),
					'description'  => wp_mcp_string_value( $post_type->description ),
					'rest_base'    => $rest_base,
					'hierarchical' => (bool) $post_type->hierarchical,
					'has_archive'  => (bool) $post_type->has_archive,
				);
			}

			return array( 'items' => $items );
		},
	) );
}

if ( did_action( 'wp_abilities_api_init' ) ) {
	wp_mcp_register_tools();
}
