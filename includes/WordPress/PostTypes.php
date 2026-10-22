<?php
/**
 * Private project post type.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Post types.
 */
final class PostTypes {

	/**
	 * Register.
	 */
	public static function register(): void {
		register_post_type(
			'ats_project',
			array(
				'labels'              => array(
					'name'          => __( 'پروژه‌های AI Theme Studio', 'ai-theme-studio' ),
					'singular_name' => __( 'پروژه', 'ai-theme-studio' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'author' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
			)
		);
	}
}
