<?php
/**
 * Project persistence (private CPT + post meta for small, structured fields).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\WordPress;

use AIThemeStudio\Validation\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Project repository.
 */
class ProjectRepository {

	public const STATUSES = array( 'draft', 'generating', 'review_sitemap', 'ready', 'failed', 'cancelled', 'published' );

	/**
	 * Create a project.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $input Sanitised input.
	 */
	public function create( string $name, array $input ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => 'ats_project',
				'post_status' => 'private',
				'post_title'  => $name,
				'post_author' => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_ats_input', $input );
		update_post_meta( $id, '_ats_status', 'draft' );
		update_post_meta( $id, '_ats_images', array() );
		update_post_meta( $id, '_ats_pages', array() );
		return (int) $id;
	}

	/**
	 * Load a project as an array.
	 *
	 * @param int $id Project id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'ats_project' !== $post->post_type ) {
			return null;
		}
		$input = get_post_meta( $id, '_ats_input', true );
		return array(
			'id'            => $id,
			'name'          => $post->post_title,
			'author'        => (int) $post->post_author,
			'status'        => (string) get_post_meta( $id, '_ats_status', true ),
			'input'         => is_array( $input ) ? $input : array(),
			'images'        => $this->images( $id ),
			'pages'         => $this->pages( $id ),
			'menu_id'       => (int) get_post_meta( $id, '_ats_menu_id', true ),
			'header_id'     => (int) get_post_meta( $id, '_ats_header_id', true ),
			'footer_id'     => (int) get_post_meta( $id, '_ats_footer_id', true ),
			'theme_builder' => (bool) get_post_meta( $id, '_ats_theme_builder', true ),
			'notices'       => (array) get_post_meta( $id, '_ats_notices', true ),
			'created'       => $post->post_date_gmt,
			'modified'      => $post->post_modified_gmt,
		);
	}

	/**
	 * Projects visible to the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_current_user(): array {
		$args = array(
			'post_type'      => 'ats_project',
			'post_status'    => 'private',
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( ! current_user_can( 'manage_options' ) ) {
			$args['author'] = get_current_user_id();
		}
		$out = array();
		foreach ( get_posts( $args ) as $id ) {
			$p = $this->get( (int) $id );
			if ( $p ) {
				$out[] = array(
					'id'       => $p['id'],
					'name'     => $p['name'],
					'status'   => $p['status'],
					'pages'    => count( $p['pages'] ),
					'images'   => count( $p['images'] ),
					'modified' => $p['modified'],
				);
			}
		}
		return $out;
	}

	/**
	 * Set status.
	 *
	 * @param int    $id     Project.
	 * @param string $status Status.
	 */
	public function set_status( int $id, string $status ): void {
		if ( in_array( $status, self::STATUSES, true ) ) {
			update_post_meta( $id, '_ats_status', $status );
			wp_update_post( array( 'ID' => $id ) );
		}
	}

	/**
	 * Update input.
	 *
	 * @param int                  $id    Project.
	 * @param array<string, mixed> $input Input.
	 */
	public function set_input( int $id, array $input ): void {
		update_post_meta( $id, '_ats_input', $input );
	}

	/**
	 * Image refs.
	 *
	 * @param int $id Project.
	 * @return array<int, array{ref: string, id: int, role: string}>
	 */
	public function images( int $id ): array {
		$images = get_post_meta( $id, '_ats_images', true );
		return is_array( $images ) ? array_values( $images ) : array();
	}

	/**
	 * Add an image reference.
	 *
	 * @param int    $id            Project.
	 * @param int    $attachment_id Attachment.
	 * @param string $role          Role.
	 * @return string The new ref.
	 */
	public function add_image( int $id, int $attachment_id, string $role ): string {
		$images = $this->images( $id );
		$seq    = (int) get_post_meta( $id, '_ats_image_seq', true ) + 1;
		update_post_meta( $id, '_ats_image_seq', $seq );
		$ref      = 'img_' . $seq;
		$images[] = array(
			'ref'  => $ref,
			'id'   => $attachment_id,
			'role' => $this->role( $role ),
		);
		update_post_meta( $id, '_ats_images', $images );
		return $ref;
	}

	/**
	 * Update an image role.
	 *
	 * @param int    $id   Project.
	 * @param string $ref  Ref.
	 * @param string $role Role.
	 */
	public function set_image_role( int $id, string $ref, string $role ): bool {
		$images = $this->images( $id );
		foreach ( $images as &$img ) {
			if ( $img['ref'] === $ref ) {
				$img['role'] = $this->role( $role );
				update_post_meta( $id, '_ats_images', $images );
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove an image reference (the media attachment itself is kept).
	 *
	 * @param int    $id  Project.
	 * @param string $ref Ref.
	 */
	public function remove_image( int $id, string $ref ): bool {
		$images = $this->images( $id );
		$kept   = array_values( array_filter( $images, static fn( $i ) => $i['ref'] !== $ref ) );
		if ( count( $kept ) === count( $images ) ) {
			return false;
		}
		update_post_meta( $id, '_ats_images', $kept );
		return true;
	}

	/**
	 * Generated page map key => post id.
	 *
	 * @param int $id Project.
	 * @return array<string, int>
	 */
	public function pages( int $id ): array {
		$pages = get_post_meta( $id, '_ats_pages', true );
		return is_array( $pages ) ? array_map( 'intval', $pages ) : array();
	}

	/**
	 * Set a page mapping.
	 *
	 * @param int    $id      Project.
	 * @param string $key     Page key.
	 * @param int    $post_id Page id.
	 */
	public function set_page( int $id, string $key, int $post_id ): void {
		$pages         = $this->pages( $id );
		$pages[ $key ] = $post_id;
		update_post_meta( $id, '_ats_pages', $pages );
	}

	/**
	 * Generic meta setter for whitelisted keys.
	 *
	 * @param int    $id    Project.
	 * @param string $key   Key without prefix.
	 * @param mixed  $value Value.
	 */
	public function set( int $id, string $key, $value ): void {
		$allowed = array( 'menu_id', 'header_id', 'footer_id', 'theme_builder', 'theme_header_id', 'theme_footer_id', 'nav_proposal', 'notices', 'published_at' );
		if ( in_array( $key, $allowed, true ) ) {
			update_post_meta( $id, '_ats_' . $key, $value );
		}
	}

	/**
	 * Generic meta getter.
	 *
	 * @param int    $id  Project.
	 * @param string $key Key without prefix.
	 * @return mixed
	 */
	public function meta( int $id, string $key ) {
		return get_post_meta( $id, '_ats_' . $key, true );
	}

	/**
	 * Append a user-visible notice (deduplicated).
	 *
	 * @param int    $id      Project.
	 * @param string $message Message.
	 */
	public function add_notice( int $id, string $message ): void {
		$notices = (array) get_post_meta( $id, '_ats_notices', true );
		$notices = array_values( array_filter( $notices ) );
		if ( ! in_array( $message, $notices, true ) ) {
			$notices[] = $message;
			update_post_meta( $id, '_ats_notices', array_slice( $notices, -30 ) );
		}
	}

	/**
	 * Delete a project record (generated pages are user content and are kept).
	 *
	 * @param int $id Project.
	 */
	public function delete( int $id ): void {
		wp_delete_post( $id, true );
	}

	/**
	 * Normalise an image role.
	 *
	 * @param string $role Role.
	 */
	private function role( string $role ): string {
		return in_array( $role, Vocabulary::IMAGE_ROLES, true ) || 'auto' === $role ? $role : 'auto';
	}
}
