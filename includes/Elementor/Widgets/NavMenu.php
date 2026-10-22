<?php
/**
 * Accessible, crawlable navigation menu widget for free Elementor (Elementor's
 * own Nav Menu widget requires Pro). Renders a native WordPress menu with
 * plain <a href> links; a tiny script collapses it on small screens.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Nav menu widget.
 */
class NavMenu extends Widget_Base {

	/**
	 * Name.
	 */
	public function get_name() {
		return 'ats-nav-menu';
	}

	/**
	 * Title.
	 */
	public function get_title() {
		return esc_html__( 'منوی سایت (AI Theme Studio)', 'ai-theme-studio' );
	}

	/**
	 * Icon.
	 */
	public function get_icon() {
		return 'eicon-nav-menu';
	}

	/**
	 * Categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords.
	 */
	public function get_keywords() {
		return array( 'menu', 'nav', 'منو' );
	}

	/**
	 * Style dependencies.
	 */
	public function get_style_depends() {
		return array( 'ats-frontend' );
	}

	/**
	 * Script dependencies.
	 */
	public function get_script_depends() {
		return array( 'ats-nav' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$menus   = array( '' => esc_html__( 'خودکار (منوی پروژه)', 'ai-theme-studio' ) );
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[ (string) $menu->term_id ] = $menu->name;
		}

		$this->start_controls_section( 'section_menu', array( 'label' => esc_html__( 'منو', 'ai-theme-studio' ) ) );
		$this->add_control(
			'menu',
			array(
				'label'       => esc_html__( 'منوی وردپرس', 'ai-theme-studio' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $menus,
				'default'     => '',
				'description' => esc_html__( 'منوها را از نمایش > فهرست‌ها مدیریت کنید.', 'ai-theme-studio' ),
			)
		);
		$this->add_control(
			'project_id',
			array(
				'type'    => Controls_Manager::HIDDEN,
				'default' => 0,
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			array(
				'label' => esc_html__( 'استایل', 'ai-theme-studio' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'link_color',
			array(
				'label'     => esc_html__( 'رنگ پیوند', 'ai-theme-studio' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .ats-nav a, {{WRAPPER}} .ats-nav__toggle' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'link_hover_color',
			array(
				'label'     => esc_html__( 'رنگ پیوند (هاور/فعال)', 'ai-theme-studio' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .ats-nav a:hover, {{WRAPPER}} .ats-nav a:focus-visible, {{WRAPPER}} .ats-nav .current-menu-item > a' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .ats-nav a',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$menu_id  = (int) ( $settings['menu'] ?? 0 );
		$project  = (int) ( $settings['project_id'] ?? 0 );
		if ( ! $menu_id && $project ) {
			$menu_id = (int) get_post_meta( $project, '_ats_menu_id', true );
		}
		$list_id = 'ats-nav-' . $this->get_id();

		$items = '';
		if ( $menu_id && wp_get_nav_menu_object( $menu_id ) ) {
			$items = (string) wp_nav_menu(
				array(
					'menu'        => $menu_id,
					'container'   => false,
					'menu_class'  => 'ats-nav__list',
					'menu_id'     => $list_id,
					'depth'       => 2,
					'fallback_cb' => false,
					'echo'        => false,
				)
			);
		} elseif ( $project ) {
			$items = self::project_fallback( $project, $list_id );
		}
		if ( '' === $items ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p>' . esc_html__( 'هنوز منویی انتخاب یا ساخته نشده است.', 'ai-theme-studio' ) . '</p>';
			}
			return;
		}
		?>
		<nav class="ats-nav" aria-label="<?php esc_attr_e( 'منوی اصلی', 'ai-theme-studio' ); ?>">
			<button type="button" class="ats-nav__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $list_id ); ?>" hidden>
				<span class="ats-nav__bars" aria-hidden="true"></span>
				<span class="ats-nav__label"><?php esc_html_e( 'منو', 'ai-theme-studio' ); ?></span>
			</button>
			<?php echo $items; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nav_menu output / escaped below. ?>
		</nav>
		<?php
	}

	/**
	 * Links to the project's pages (before a WordPress menu is created).
	 *
	 * @param int    $project Project id.
	 * @param string $list_id List id.
	 */
	public static function project_fallback( int $project, string $list_id ): string {
		$pages   = get_post_meta( $project, '_ats_pages', true );
		$sitemap = get_post_meta( $project, '_ats_nav_proposal', true );
		if ( ! is_array( $pages ) || ! is_array( $sitemap ) ) {
			return '';
		}
		$current = (int) get_queried_object_id();
		$out     = '<ul id="' . esc_attr( $list_id ) . '" class="ats-nav__list">';
		foreach ( $sitemap as $item ) {
			if ( ! empty( $item['parent'] ) ) {
				continue;
			}
			$id = (int) ( $pages[ $item['key'] ] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			$is_front = (int) get_option( 'page_on_front' ) === $id;
			$url      = $is_front ? home_url( '/' ) : get_permalink( $id );
			$out     .= sprintf(
				'<li class="menu-item%1$s"><a href="%2$s"%3$s>%4$s</a></li>',
				$current === $id ? ' current-menu-item' : '',
				esc_url( (string) $url ),
				$current === $id ? ' aria-current="page"' : '',
				esc_html( (string) $item['label'] )
			);
		}
		return $out . '</ul>';
	}
}
