<?php
/**
 * Capability map. Every REST route maps to one of these checks.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Capability checks.
 */
final class Capabilities {

	/**
	 * Capability required per action (filterable for custom roles).
	 *
	 * @param string $action Action.
	 */
	public static function cap_for( string $action ): string {
		$map = array(
			'manage_settings' => 'manage_options',
			'use_studio'      => 'edit_pages',
			'upload'          => 'upload_files',
			'publish'         => 'publish_pages',
			'menus'           => 'edit_theme_options',
			'global_styles'   => 'edit_theme_options',
		);
		$cap = $map[ $action ] ?? 'manage_options';
		return (string) apply_filters( 'ats_capability', $cap, $action );
	}

	/**
	 * Whether the current user may perform an action.
	 *
	 * @param string $action Action.
	 */
	public static function can( string $action ): bool {
		return current_user_can( self::cap_for( $action ) );
	}

	/**
	 * Whether the current user may access a project.
	 *
	 * @param int $project_id Project id.
	 */
	public static function can_access_project( int $project_id ): bool {
		$post = get_post( $project_id );
		if ( ! $post || 'ats_project' !== $post->post_type ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return self::can( 'use_studio' ) && (int) $post->post_author === get_current_user_id();
	}
}
