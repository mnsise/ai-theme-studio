<?php
/**
 * Lightweight latest-posts grid for free Elementor (the Posts widget is Pro).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Posts grid widget.
 */
class PostsGrid extends Widget_Base {

	/**
	 * Name.
	 */
	public function get_name() {
		return 'ats-posts-grid';
	}

	/**
	 * Title.
	 */
	public function get_title() {
		return esc_html__( 'آخرین نوشته‌ها (AI Theme Studio)', 'ai-theme-studio' );
	}

	/**
	 * Icon.
	 */
	public function get_icon() {
		return 'eicon-posts-grid';
	}

	/**
	 * Categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Style dependencies.
	 */
	public function get_style_depends() {
		return array( 'ats-frontend' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section( 'section_query', array( 'label' => esc_html__( 'نوشته‌ها', 'ai-theme-studio' ) ) );
		$this->add_control(
			'posts_per_page',
			array(
				'label'   => esc_html__( 'تعداد', 'ai-theme-studio' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 12,
				'default' => 6,
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'     => esc_html__( 'ستون‌ها', 'ai-theme-studio' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'default'   => '3',
				'selectors' => array( '{{WRAPPER}} .ats-posts' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
		$this->add_control(
			'title_tag',
			array(
				'label'   => esc_html__( 'تگ عنوان', 'ai-theme-studio' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
				),
				'default' => 'h3',
			)
		);
		$this->add_control(
			'show_excerpt',
			array(
				'label'   => esc_html__( 'نمایش خلاصه', 'ai-theme-studio' ),
				'type'    => Controls_Manager::SWITCHER,
				'default' => 'yes',
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
			'title_color',
			array(
				'label'     => esc_html__( 'رنگ عنوان', 'ai-theme-studio' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .ats-post__title a' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'رنگ متن', 'ai-theme-studio' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .ats-post__excerpt, {{WRAPPER}} .ats-post__date' => 'color: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Render.
	 */
	protected function render() {
		$s     = $this->get_settings_for_display();
		$tag   = in_array( $s['title_tag'] ?? 'h3', array( 'h2', 'h3', 'h4' ), true ) ? $s['title_tag'] : 'h3';
		$query = new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, min( 12, (int) ( $s['posts_per_page'] ?? 6 ) ) ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		if ( ! $query->have_posts() ) {
			echo '<p class="ats-posts__empty">' . esc_html__( 'هنوز نوشته‌ای منتشر نشده است.', 'ai-theme-studio' ) . '</p>';
			return;
		}
		echo '<div class="ats-posts">';
		while ( $query->have_posts() ) {
			$query->the_post();
			echo '<article class="ats-post">';
			if ( has_post_thumbnail() ) {
				echo '<a class="ats-post__thumb" href="' . esc_url( get_permalink() ) . '" tabindex="-1" aria-hidden="true">';
				the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) );
				echo '</a>';
			}
			printf(
				'<%1$s class="ats-post__title"><a href="%2$s">%3$s</a></%1$s>',
				esc_html( $tag ),
				esc_url( get_permalink() ),
				esc_html( get_the_title() )
			);
			printf( '<time class="ats-post__date" datetime="%1$s">%2$s</time>', esc_attr( get_the_date( 'c' ) ), esc_html( get_the_date() ) );
			if ( 'yes' === ( $s['show_excerpt'] ?? 'yes' ) ) {
				echo '<p class="ats-post__excerpt">' . esc_html( wp_trim_words( get_the_excerpt(), 24 ) ) . '</p>';
			}
			echo '</article>';
		}
		echo '</div>';
		wp_reset_postdata();
	}
}
