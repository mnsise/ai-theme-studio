<?php
/**
 * Native WordPress navigation menus.
 *
 * Never deletes menus or menu items. Generated items are tagged with post
 * meta so re-applying updates them instead of creating duplicates.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Menu manager.
 */
class MenuManager {

	/**
	 * Projects.
	 *
	 * @var ProjectRepository
	 */
	private ProjectRepository $projects;

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository $projects Projects.
	 */
	public function __construct( ProjectRepository $projects ) {
		$this->projects = $projects;
	}

	/**
	 * Navigation proposal from a sitemap.
	 *
	 * @param array<string, mixed> $sitemap Sitemap.
	 * @return array<int, array{key: string, label: string, parent: string|null, order: int}>
	 */
	public static function proposal_from_sitemap( array $sitemap ): array {
		$pages = array_filter( (array) $sitemap['pages'], static fn( $p ) => ! empty( $p['in_menu'] ) && 'not_found' !== $p['type'] );
		usort( $pages, static fn( $a, $b ) => ( (int) $a['order'] ) <=> ( (int) $b['order'] ) );
		$keys = array_column( $pages, 'key' );
		$out  = array();
		foreach ( $pages as $p ) {
			$parent = $p['parent'] ?? null;
			$out[]  = array(
				'key'    => (string) $p['key'],
				'label'  => (string) ( $p['menu_label'] ? $p['menu_label'] : $p['title'] ),
				'parent' => $parent && in_array( $parent, $keys, true ) ? (string) $parent : null,
				'order'  => (int) $p['order'],
			);
		}
		return $out;
	}

	/**
	 * Preview: existing menus, theme locations and the proposed structure.
	 *
	 * @param int $project_id Project.
	 * @return array<string, mixed>
	 */
	public function preview( int $project_id ): array {
		$menus    = array();
		$assigned = get_nav_menu_locations();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items   = wp_get_nav_menu_items( $menu->term_id );
			$menus[] = array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'count'     => is_array( $items ) ? count( $items ) : 0,
				'locations' => array_keys( array_filter( $assigned, static fn( $m ) => (int) $m === (int) $menu->term_id ) ),
				'items'     => is_array( $items ) ? array_map(
					static fn( $i ) => array(
						'title'  => $i->title,
						'url'    => $i->url,
						'parent' => (int) $i->menu_item_parent,
					),
					array_slice( $items, 0, 30 )
				) : array(),
			);
		}
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$locations[] = array(
				'slug'     => $slug,
				'label'    => $label,
				'assigned' => (int) ( $assigned[ $slug ] ?? 0 ),
			);
		}
		$proposal   = (array) $this->projects->meta( $project_id, 'nav_proposal' );
		$current_id = (int) $this->projects->meta( $project_id, 'menu_id' );
		$suggested  = $current_id && wp_get_nav_menu_object( $current_id ) ? $current_id : 0;
		if ( ! $suggested ) {
			$name = (string) get_the_title( $project_id );
			foreach ( $menus as $m ) {
				if ( $m['name'] === $name ) {
					$suggested = $m['id'];
				}
			}
		}
		return array(
			'menus'          => $menus,
			'locations'      => $locations,
			'proposal'       => $proposal,
			'project_menu'   => $current_id,
			'suggested_menu' => $suggested,
			'block_theme'    => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		);
	}

	/**
	 * Apply navigation.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $args       mode (create|reuse), menu_id, menu_name, locations[], replace_locations, items (optional edited proposal).
	 * @return array<string, mixed> Result with menu id, created/updated counts and skipped locations.
	 * @throws \RuntimeException On failure.
	 */
	public function apply( int $project_id, array $args ): array {
		$pages    = $this->projects->pages( $project_id );
		$proposal = ! empty( $args['items'] ) && is_array( $args['items'] ) ? $this->sanitize_items( $args['items'] ) : (array) $this->projects->meta( $project_id, 'nav_proposal' );
		if ( ! $proposal ) {
			throw new \RuntimeException( __( 'پیشنهاد منو هنوز ساخته نشده است.', 'ai-theme-studio' ) );
		}

		$menu_id = 0;
		if ( 'reuse' === ( $args['mode'] ?? '' ) ) {
			$menu_id = (int) ( $args['menu_id'] ?? 0 );
			if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
				throw new \RuntimeException( __( 'منوی انتخاب‌شده وجود ندارد.', 'ai-theme-studio' ) );
			}
		} else {
			$existing = (int) $this->projects->meta( $project_id, 'menu_id' );
			if ( $existing && wp_get_nav_menu_object( $existing ) ) {
				$menu_id = $existing;
			} else {
				$name = sanitize_text_field( (string) ( $args['menu_name'] ?? '' ) );
				$name = $name ? $name : get_the_title( $project_id );
				if ( wp_get_nav_menu_object( $name ) ) {
					$name .= ' (' . gmdate( 'Y-m-d H:i' ) . ')';
				}
				$created = wp_create_nav_menu( $name );
				if ( is_wp_error( $created ) ) {
					throw new \RuntimeException( $created->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				$menu_id = (int) $created;
			}
		}

		$existing_items = $this->tagged_items( $menu_id, $project_id );
		$item_ids       = array();
		$created_count  = 0;
		$updated_count  = 0;
		$position       = 1;

		foreach ( $proposal as $item ) {
			$key = (string) $item['key'];
			$url = '';
			if ( isset( $item['url'] ) && $item['url'] ) {
				$url = esc_url_raw( (string) $item['url'], array( 'http', 'https' ) );
			}
			$page_id = (int) ( $pages[ $key ] ?? 0 );
			if ( ! $page_id && ! $url ) {
				continue;
			}
			$data = array(
				'menu-item-title'     => sanitize_text_field( (string) $item['label'] ),
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position++,
				'menu-item-parent-id' => ! empty( $item['parent'] ) && isset( $item_ids[ $item['parent'] ] ) ? $item_ids[ $item['parent'] ] : 0,
			);
			if ( $page_id ) {
				$data['menu-item-object-id'] = $page_id;
				$data['menu-item-object']    = 'page';
				$data['menu-item-type']      = 'post_type';
			} else {
				$data['menu-item-url']  = $url;
				$data['menu-item-type'] = 'custom';
			}
			$existing_item = $existing_items[ $key ] ?? $this->find_page_item( $menu_id, $page_id );
			$result        = wp_update_nav_menu_item( $menu_id, $existing_item, $data );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$existing_item ? ++$updated_count : ++$created_count;
			update_post_meta( (int) $result, '_ats_project_id', $project_id );
			update_post_meta( (int) $result, '_ats_page_key', $key );
			$item_ids[ $key ] = (int) $result;
		}

		$this->projects->set( $project_id, 'menu_id', $menu_id );

		$assigned  = array();
		$skipped   = array();
		$locations = get_nav_menu_locations();
		$valid     = array_keys( get_registered_nav_menus() );
		foreach ( (array) ( $args['locations'] ?? array() ) as $loc ) {
			$loc = sanitize_key( (string) $loc );
			if ( ! in_array( $loc, $valid, true ) ) {
				continue;
			}
			$current = (int) ( $locations[ $loc ] ?? 0 );
			if ( $current && $current !== $menu_id && empty( $args['replace_locations'] ) ) {
				$skipped[] = $loc;
				continue;
			}
			$locations[ $loc ] = $menu_id;
			$assigned[]        = $loc;
		}
		if ( $assigned ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return array(
			'menu_id'           => $menu_id,
			'created'           => $created_count,
			'updated'           => $updated_count,
			'assigned'          => $assigned,
			'skipped_locations' => $skipped,
		);
	}

	/**
	 * Items in a menu created by this project, keyed by page key.
	 *
	 * @param int $menu_id    Menu.
	 * @param int $project_id Project.
	 * @return array<string, int>
	 */
	private function tagged_items( int $menu_id, int $project_id ): array {
		$out   = array();
		$items = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		foreach ( (array) $items as $item ) {
			if ( (int) get_post_meta( $item->ID, '_ats_project_id', true ) === $project_id ) {
				$out[ (string) get_post_meta( $item->ID, '_ats_page_key', true ) ] = (int) $item->ID;
			}
		}
		return $out;
	}

	/**
	 * Existing item pointing to a page (avoids duplicates in reused menus).
	 *
	 * @param int $menu_id Menu.
	 * @param int $page_id Page.
	 */
	private function find_page_item( int $menu_id, int $page_id ): int {
		if ( ! $page_id ) {
			return 0;
		}
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id === $page_id ) {
				return (int) $item->ID;
			}
		}
		return 0;
	}

	/**
	 * Sanitise an edited proposal from the UI.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return array<int, array<string, mixed>>
	 */
	public function sanitize_items( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, 40 ) as $i => $item ) {
			if ( ! is_array( $item ) || empty( $item['label'] ) ) {
				continue;
			}
			$out[] = array(
				'key'    => sanitize_key( (string) ( $item['key'] ?? 'custom_' . $i ) ),
				'label'  => sanitize_text_field( (string) $item['label'] ),
				'parent' => ! empty( $item['parent'] ) ? sanitize_key( (string) $item['parent'] ) : null,
				'order'  => (int) ( $item['order'] ?? $i ),
				'url'    => ! empty( $item['url'] ) ? esc_url_raw( (string) $item['url'], array( 'http', 'https' ) ) : '',
			);
		}
		return $out;
	}
}
