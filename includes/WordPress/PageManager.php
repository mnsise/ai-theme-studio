<?php
/**
 * WordPress page creation and lookup. Pages are always created as drafts;
 * existing pages are never overwritten (slug conflicts get a unique slug).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Page manager.
 */
class PageManager {

	/**
	 * Find an existing generated page for a project/key (idempotency).
	 *
	 * @param int    $project_id Project.
	 * @param string $key        Page key.
	 */
	public function find( int $project_id, string $key ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'publish', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_ats_project_id',
						'value' => $project_id,
					),
					array(
						'key'   => '_ats_page_key',
						'value' => $key,
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Create a draft page shell, or return the existing one.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $page       Sitemap page.
	 * @param int                  $parent_id  Parent page id.
	 * @param int                  $known_id   Id from the project map.
	 * @return int Page id.
	 * @throws \RuntimeException On failure.
	 */
	public function ensure( int $project_id, array $page, int $parent_id = 0, int $known_id = 0 ): int {
		if ( $known_id && 'page' === get_post_type( $known_id ) && 'trash' !== get_post_status( $known_id ) ) {
			return $known_id;
		}
		$existing = $this->find( $project_id, (string) $page['key'] );
		if ( $existing ) {
			return $existing;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( (string) $page['title'] ),
				'post_name'    => sanitize_title( (string) $page['slug'] ),
				'post_parent'  => $parent_id,
				'menu_order'   => (int) ( $page['order'] ?? 0 ),
				'post_content' => '',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		update_post_meta( $id, '_ats_project_id', $project_id );
		update_post_meta( $id, '_ats_page_key', (string) $page['key'] );
		return (int) $id;
	}

	/**
	 * Final URL of a page, also for drafts (uses WordPress' sample permalink so links
	 * stay correct after publishing).
	 *
	 * @param int  $post_id  Page id.
	 * @param bool $is_front Whether it will be the static front page.
	 */
	public function future_permalink( int $post_id, bool $is_front = false ): string {
		if ( $is_front ) {
			return home_url( '/' );
		}
		if ( 'publish' === get_post_status( $post_id ) ) {
			return (string) get_permalink( $post_id );
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'page_id', $post_id, home_url( '/' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$sample = get_sample_permalink( $post_id );
		if ( is_array( $sample ) && 2 === count( $sample ) ) {
			return str_replace( array( '%pagename%', '%postname%' ), $sample[1], $sample[0] );
		}
		return (string) get_permalink( $post_id );
	}

	/**
	 * Attachment data for the builder.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{id: int, url: string, alt: string, width: int, height: int}|null
	 */
	public function image_data( int $attachment_id ): ?array {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'large' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'id'     => $attachment_id,
			'url'    => (string) $src[0],
			'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}

	/**
	 * Publish a page (requires capability).
	 *
	 * @param int $post_id Page.
	 */
	public function publish( int $post_id ): bool {
		if ( ! current_user_can( 'publish_post', $post_id ) ) {
			return false;
		}
		if ( 'publish' === get_post_status( $post_id ) ) {
			return true;
		}
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		return ! is_wp_error( $result );
	}
}
