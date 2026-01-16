<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( 'wp_mcp_meta' ) ) {
	function wp_mcp_meta( array $annotations = array(), array $extra = array() ) {
		$meta = array(
			'show_in_rest' => true,
		);

		if ( ! empty( $annotations ) ) {
			$meta['annotations'] = $annotations;
		}

		return array_merge( $meta, $extra );
	}
}

if ( ! function_exists( 'wp_mcp_sanitize_int_array' ) ) {
	function wp_mcp_sanitize_int_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ints = array_map( 'absint', $value );
		$ints = array_filter( $ints, static function ( $item ) {
			return $item > 0;
		} );

		return array_values( $ints );
	}
}

if ( ! function_exists( 'wp_mcp_get_post_or_error' ) ) {
	function wp_mcp_get_post_or_error( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'You do not have permission to access this post.' );
		}

		return $post;
	}
}

if ( ! function_exists( 'wp_mcp_map_block_structure' ) ) {
	function wp_mcp_map_block_structure( array $blocks ) {
		$mapped = array();
		foreach ( $blocks as $block ) {
			$mapped[] = array(
				'blockName'   => isset( $block['blockName'] ) ? $block['blockName'] : null,
				'attrs'       => wp_mcp_object_value( isset( $block['attrs'] ) ? $block['attrs'] : array() ),
				'innerBlocks' => wp_mcp_map_block_structure( isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : array() ),
				'innerHTML'   => wp_mcp_string_value( isset( $block['innerHTML'] ) ? $block['innerHTML'] : '' ),
			);
		}

		return $mapped;
	}
}

if ( ! function_exists( 'wp_mcp_collect_block_stats' ) ) {
	function wp_mcp_collect_block_stats( array $blocks, array &$block_counts, array &$class_counts, array &$heading_levels ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : 'core/freeform';
			$block_counts[ $name ] = ( isset( $block_counts[ $name ] ) ? $block_counts[ $name ] : 0 ) + 1;

			if ( ! empty( $block['attrs']['className'] ) ) {
				$classes = preg_split( '/\s+/', (string) $block['attrs']['className'] );
				foreach ( $classes as $class ) {
					$class = trim( $class );
					if ( '' === $class ) {
						continue;
					}
					$class_counts[ $class ] = ( isset( $class_counts[ $class ] ) ? $class_counts[ $class ] : 0 ) + 1;
				}
			}

			if ( 'core/heading' === $name ) {
				$level = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;
				$level_key = 'h' . max( 1, min( 6, $level ) );
				$heading_levels[ $level_key ] = ( isset( $heading_levels[ $level_key ] ) ? $heading_levels[ $level_key ] : 0 ) + 1;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				wp_mcp_collect_block_stats( $block['innerBlocks'], $block_counts, $class_counts, $heading_levels );
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_flatten_block_names' ) ) {
	function wp_mcp_flatten_block_names( array $blocks, array &$names ) {
		foreach ( $blocks as $block ) {
			$names[] = isset( $block['blockName'] ) ? $block['blockName'] : 'core/freeform';
			if ( ! empty( $block['innerBlocks'] ) ) {
				wp_mcp_flatten_block_names( $block['innerBlocks'], $names );
			}
		}
	}
}

if ( ! function_exists( 'wp_mcp_content_word_count' ) ) {
	function wp_mcp_content_word_count( $content ) {
		$plain = trim( wp_strip_all_tags( $content ) );
		if ( '' === $plain ) {
			return 0;
		}

		$count = str_word_count( $plain );
		if ( 0 === $count && function_exists( 'mb_strlen' ) ) {
			$count = (int) mb_strlen( preg_replace( '/\s+/u', '', $plain ) );
		}

		return $count;
	}
}

if ( ! function_exists( 'wp_mcp_ensure_media_includes' ) ) {
	function wp_mcp_ensure_media_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}

if ( ! function_exists( 'wp_mcp_string_value' ) ) {
	function wp_mcp_string_value( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}
}

if ( ! function_exists( 'wp_mcp_int_value' ) ) {
	function wp_mcp_int_value( $value ) {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}

if ( ! function_exists( 'wp_mcp_object_value' ) ) {
	function wp_mcp_object_value( $value ) {
		if ( $value instanceof stdClass ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return new stdClass();
			}
			return $value;
		}

		return new stdClass();
	}
}
