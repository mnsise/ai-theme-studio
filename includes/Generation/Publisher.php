<?php
/**
 * Publishing (explicitly authorised) and rollback.
 *
 * Every site-level change (front page, 404 handling, Elementor site styles,
 * Theme Builder header/footer) is opt-in and recorded so it can be undone.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Generation;

use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Security\Capabilities;
use AIThemeStudio\SEO\LinkExtractor;
use AIThemeStudio\WordPress\NotFoundHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Publisher.
 */
class Publisher {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $p;

	/**
	 * Constructor.
	 *
	 * @param Plugin $p Plugin.
	 */
	public function __construct( Plugin $p ) {
		$this->p = $p;
	}

	/**
	 * Publish.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $opts       pages (keys|null), set_front_page, use_404, apply_site_styles, theme_builder.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function publish( int $project_id, array $opts ) {
		$projects = $this->p->get( 'projects' );
		$project  = $projects->get( $project_id );
		if ( ! $project ) {
			return new \WP_Error( 'ats_not_found', __( 'پروژه پیدا نشد.', 'ai-theme-studio' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $project['status'], array( 'ready', 'published' ), true ) ) {
			return new \WP_Error( 'ats_not_ready', __( 'پیش از انتشار، تولید باید کامل شده باشد.', 'ai-theme-studio' ), array( 'status' => 409 ) );
		}
		$site_level = ! empty( $opts['set_front_page'] ) || ! empty( $opts['use_404'] ) || ! empty( $opts['apply_site_styles'] ) || ! empty( $opts['theme_builder'] );
		if ( $site_level && ! Capabilities::can( 'global_styles' ) ) {
			return new \WP_Error( 'ats_forbidden', __( 'برای تغییر تنظیمات سراسری سایت دسترسی کافی ندارید.', 'ai-theme-studio' ), array( 'status' => 403 ) );
		}

		$pages     = $project['pages'];
		$selected  = isset( $opts['pages'] ) && is_array( $opts['pages'] ) ? array_intersect_key( $pages, array_flip( $opts['pages'] ) ) : $pages;
		$published = array();
		$failed    = array();
		$backup    = array(
			'show_on_front'  => get_option( 'show_on_front' ),
			'page_on_front'  => (int) get_option( 'page_on_front' ),
			'ats_404'        => (int) get_option( NotFoundHandler::OPTION, 0 ),
			'drafts'         => array(),
		);

		foreach ( $selected as $key => $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				$backup['drafts'][] = (int) $id;
			}
			if ( $this->p->get( 'pages' )->publish( (int) $id ) ) {
				$published[] = $key;
			} else {
				$failed[] = $key;
			}
		}

		$notes = array();
		$front = (int) ( $pages['home'] ?? 0 );
		if ( ! empty( $opts['set_front_page'] ) && $front && 'publish' === get_post_status( $front ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $front );
			$notes[] = __( 'صفحه اصلی به‌عنوان صفحه نخست سایت تنظیم شد.', 'ai-theme-studio' );
		} elseif ( ! empty( $project['input']['options']['set_front_page'] ) && $front ) {
			$this->relink_home( $project, (string) get_permalink( $front ) );
			$notes[] = __( 'صفحه نخست سایت تغییر نکرد؛ پیوندهای «خانه» به نشانی صفحه اصلی پروژه اصلاح شدند.', 'ai-theme-studio' );
		}

		$not_found = (int) ( $pages['not_found'] ?? 0 );
		if ( ! empty( $opts['use_404'] ) && $not_found && 'publish' === get_post_status( $not_found ) ) {
			update_option( NotFoundHandler::OPTION, $not_found, false );
			$notes[] = __( 'صفحه ۴۰۴ سفارشی فعال شد.', 'ai-theme-studio' );
		}

		if ( ! empty( $opts['apply_site_styles'] ) ) {
			$design = (array) $this->p->get( 'artifacts' )->latest( $project_id, 'design' );
			if ( $this->p->get( 'kit' )->apply_site_styles( new DesignTokens( $design ), $project_id ) ) {
				$notes[] = __( 'رنگ‌ها و تایپوگرافی سراسری المنتور (Site Settings) به‌روزرسانی شد؛ نسخه قبلی ذخیره شده است.', 'ai-theme-studio' );
			}
		}

		if ( ! empty( $opts['theme_builder'] ) ) {
			$tm     = $this->p->get( 'templates' );
			$header = $tm->activate_theme_builder( (int) $project['header_id'], 'header', (int) $projects->meta( $project_id, 'theme_header_id' ), $project_id );
			$footer = $tm->activate_theme_builder( (int) $project['footer_id'], 'footer', (int) $projects->meta( $project_id, 'theme_footer_id' ), $project_id );
			if ( $header && $footer ) {
				$projects->set( $project_id, 'theme_header_id', $header );
				$projects->set( $project_id, 'theme_footer_id', $footer );
				$projects->set( $project_id, 'theme_builder', true );
				foreach ( $pages as $id ) {
					update_post_meta( (int) $id, '_wp_page_template', 'elementor_header_footer' );
				}
				$notes[] = __( 'هدر و فوتر به‌عنوان قالب‌های Theme Builder المنتور پرو روی کل سایت فعال شدند.', 'ai-theme-studio' );
			} else {
				$notes[] = __( 'Theme Builder در دسترس نیست (المنتور پرو لازم است)؛ هدر و فوتر فقط روی صفحات تولیدشده نمایش داده می‌شوند.', 'ai-theme-studio' );
			}
		}

		$this->p->get( 'artifacts' )->save( $project_id, 'publish_backup', '', $backup );
		$projects->set_status( $project_id, 'published' );
		$projects->set( $project_id, 'published_at', gmdate( 'c' ) );

		return array(
			'published' => $published,
			'failed'    => $failed,
			'notes'     => $notes,
		);
	}

	/**
	 * Undo publishing: pages back to draft, restore front page/404/kit.
	 *
	 * @param int $project_id Project.
	 * @return array<string, mixed>
	 */
	public function unpublish( int $project_id ): array {
		$backup = (array) $this->p->get( 'artifacts' )->latest( $project_id, 'publish_backup' );
		foreach ( (array) ( $backup['drafts'] ?? array() ) as $id ) {
			if ( current_user_can( 'edit_post', $id ) ) {
				wp_update_post(
					array(
						'ID'          => (int) $id,
						'post_status' => 'draft',
					)
				);
			}
		}
		if ( Capabilities::can( 'global_styles' ) ) {
			if ( isset( $backup['show_on_front'] ) ) {
				update_option( 'show_on_front', $backup['show_on_front'] );
				update_option( 'page_on_front', (int) $backup['page_on_front'] );
			}
			update_option( NotFoundHandler::OPTION, (int) ( $backup['ats_404'] ?? 0 ), false );
			$this->p->get( 'kit' )->restore( $project_id, 'kit_backup_site' );
			foreach ( array( 'theme_header_id', 'theme_footer_id' ) as $k ) {
				$tid = (int) $this->p->get( 'projects' )->meta( $project_id, $k );
				if ( $tid ) {
					wp_update_post(
						array(
							'ID'          => $tid,
							'post_status' => 'draft',
						)
					);
				}
			}
			$this->p->get( 'projects' )->set( $project_id, 'theme_builder', false );
		}
		$this->p->get( 'projects' )->set_status( $project_id, 'ready' );
		return array( 'restored' => true );
	}

	/**
	 * Replace links to the site root with the project's home page URL.
	 *
	 * @param array<string, mixed> $project Project.
	 * @param string               $url     New URL.
	 */
	private function relink_home( array $project, string $url ): void {
		$writer = $this->p->get( 'writer' );
		$root   = home_url( '/' );
		$ids    = array_merge( array_values( $project['pages'] ), array_filter( array( (int) $project['header_id'], (int) $project['footer_id'] ) ) );
		foreach ( $ids as $id ) {
			$elements = $writer->read( (int) $id );
			$changed  = false;
			$elements = $this->replace_links( $elements, $root, $url, $changed );
			if ( $changed && ! $writer->is_modified( (int) $id ) ) {
				$writer->write(
					(int) $id,
					$elements,
					array(
						'project_id'    => (int) $project['id'],
						'template_type' => (string) get_post_meta( (int) $id, '_elementor_template_type', true ),
					)
				);
			}
		}
		unset( $root );
	}

	/**
	 * Recursive link replacement.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param string                           $from     From URL.
	 * @param string                           $to       To URL.
	 * @param bool                             $changed  Changed flag.
	 * @return array<int, array<string, mixed>>
	 */
	private function replace_links( array $elements, string $from, string $to, bool &$changed ): array {
		foreach ( $elements as &$el ) {
			if ( isset( $el['settings']['link']['url'] ) && untrailingslashit( $el['settings']['link']['url'] ) === untrailingslashit( $from ) ) {
				$el['settings']['link']['url'] = $to;
				$changed                       = true;
			}
			if ( isset( $el['settings']['icon_list'] ) && is_array( $el['settings']['icon_list'] ) ) {
				foreach ( $el['settings']['icon_list'] as &$item ) {
					if ( isset( $item['link']['url'] ) && untrailingslashit( $item['link']['url'] ) === untrailingslashit( $from ) ) {
						$item['link']['url'] = $to;
						$changed             = true;
					}
				}
				unset( $item );
			}
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->replace_links( $el['elements'], $from, $to, $changed );
			}
		}
		unset( $el );
		return $elements;
	}

	/**
	 * Preview data for each page.
	 *
	 * @param array<string, mixed> $project Project.
	 * @return array<int, array<string, mixed>>
	 */
	public function page_list( array $project ): array {
		$sitemap = (array) $this->p->get( 'artifacts' )->latest( (int) $project['id'], 'sitemap' );
		$writer  = $this->p->get( 'writer' );
		$out     = array();
		foreach ( (array) ( $sitemap['pages'] ?? array() ) as $page ) {
			$id   = (int) ( $project['pages'][ $page['key'] ] ?? 0 );
			$def  = $this->p->get( 'artifacts' )->latest( (int) $project['id'], 'page', (string) $page['key'] );
			$out[] = array(
				'key'         => $page['key'],
				'type'        => $page['type'],
				'title'       => $page['title'],
				'slug'        => $page['slug'],
				'post_id'     => $id,
				'status'      => $id ? get_post_status( $id ) : null,
				'generated'   => is_array( $def ),
				'built'       => $id && (bool) $writer->read( $id ),
				'modified'    => $id ? $writer->is_modified( $id ) : false,
				'preview_url' => $id ? get_preview_post_link( $id ) : null,
				'view_url'    => $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : null,
				'edit_url'    => $id ? admin_url( 'post.php?post=' . $id . '&action=elementor' ) : null,
				'seo'         => $page['seo'],
				'sections'    => is_array( $def ) ? array_map(
					static fn( $s ) => array(
						'id'    => $s['id'],
						'type'  => $s['type'],
						'label' => $s['label'] ?? $s['id'],
					),
					(array) $def['sections']
				) : array(),
				'links'       => $id ? count( LinkExtractor::links( $writer->read( $id ) ) ) : 0,
			);
		}
		return $out;
	}
}
