<?php

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

function wp_mcp_register_categories() {
	$categories = array(
		'analysis'   => array(
			'label'       => 'Content Analysis',
			'description' => 'Analyze existing posts, blocks, and content patterns.',
		),
		'style'      => array(
			'label'       => 'Style & Regulations',
			'description' => 'Theme styles, block patterns, and content regulations.',
		),
		'content'    => array(
			'label'       => 'Content Management',
			'description' => 'Create, update, publish, and delete content.',
		),
		'media'      => array(
			'label'       => 'Media',
			'description' => 'Search and manage media assets.',
		),
		'taxonomy'   => array(
			'label'       => 'Taxonomy',
			'description' => 'Categories, tags, and term management.',
		),
		'site'       => array(
			'label'       => 'Site Info',
			'description' => 'Site-level information and metadata.',
		),
		'resource'   => array(
			'label'       => 'Resources',
			'description' => 'Static or semi-static MCP resources.',
		),
		'prompt'     => array(
			'label'       => 'Prompts',
			'description' => 'Structured prompt templates for MCP.',
		),
	);

	foreach ( $categories as $slug => $data ) {
		wp_register_ability_category( $slug, $data );
	}
}

add_action( 'wp_abilities_api_categories_init', 'wp_mcp_register_categories' );

if ( did_action( 'wp_abilities_api_categories_init' ) ) {
	wp_mcp_register_categories();
}
