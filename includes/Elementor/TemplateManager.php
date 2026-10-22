<?php
/**
 * Header/footer templates.
 *
 * Free Elementor: templates are stored in the Elementor Library (type
 * "section") and rendered around generated pages that use the Elementor
 * Canvas template (see CanvasLayout). They are fully editable in Elementor.
 *
 * Elementor Pro (optional, at publish time with explicit authorization):
 * the same data is saved as Theme Builder "header"/"footer" documents with
 * an "Entire Site" display condition.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Template manager.
 */
class TemplateManager {

	/**
	 * Compatibility.
	 *
	 * @var Compatibility
	 */
	private Compatibility $compat;

	/**
	 * Writer.
	 *
	 * @var DocumentWriter
	 */
	private DocumentWriter $writer;

	/**
	 * Constructor.
	 *
	 * @param Compatibility  $compat Compatibility.
	 * @param DocumentWriter $writer Writer.
	 */
	public function __construct( Compatibility $compat, DocumentWriter $writer ) {
		$this->compat = $compat;
		$this->writer = $writer;
	}

	/**
	 * Header section definition (plugin-authored, not AI-authored).
	 *
	 * @param array<string, mixed> $spec     Site spec.
	 * @param array<string, mixed> $sitemap  Sitemap.
	 * @param string|null          $logo_ref Logo image ref.
	 * @return array<string, mixed>
	 */
	public static function header_definition( array $spec, array $sitemap, ?string $logo_ref ): array {
		$cta      = $sitemap['header_cta'] ?? null;
		$elements = array(
			array(
				'type'      => 'group',
				'direction' => 'column',
				'width'     => 22,
				'elements'  => array(
					array(
						'type'      => 'site_logo',
						'image_ref' => $logo_ref,
						'text'      => (string) ( $spec['site_name'] ?? '' ),
					),
				),
			),
			array(
				'type'      => 'group',
				'direction' => 'column',
				'width'     => $cta && ! empty( $cta['text'] ) ? 56 : 78,
				'align'     => 'center',
				'elements'  => array( array( 'type' => 'nav_menu' ) ),
			),
		);
		if ( $cta && ! empty( $cta['text'] ) ) {
			$elements[] = array(
				'type'      => 'group',
				'direction' => 'column',
				'width'     => 22,
				'align'     => 'end',
				'elements'  => array(
					array(
						'type'  => 'button',
						'text'  => (string) $cta['text'],
						'link'  => 'page:' . ( $cta['page_key'] ? $cta['page_key'] : 'contact' ),
						'style' => 'primary',
						'align' => 'end',
					),
				),
			);
		}
		return array(
			'id'         => 'site-header',
			'type'       => 'custom',
			'label'      => 'Header',
			'tag'        => 'header',
			'background' => 'default',
			'layout'     => array(
				'direction' => 'row',
				'align'     => 'center',
				'padding'   => 'sm',
				'gap'       => 'md',
				'width'     => 'boxed',
			),
			'elements'   => $elements,
		);
	}

	/**
	 * Footer section definitions.
	 *
	 * @param array<string, mixed> $spec    Site spec.
	 * @param array<string, mixed> $sitemap Sitemap.
	 * @return array<int, array<string, mixed>>
	 */
	public static function footer_definitions( array $spec, array $sitemap ): array {
		$fa    = 0 === strpos( (string) ( $spec['language'] ?? 'fa' ), 'fa' );
		$links = array();
		$legal = array();
		foreach ( (array) $sitemap['pages'] as $p ) {
			if ( 'not_found' === $p['type'] ) {
				continue;
			}
			$item = array(
				'text' => (string) ( $p['menu_label'] ? $p['menu_label'] : $p['title'] ),
				'icon' => $fa ? 'arrow-left' : 'arrow-right',
				'link' => 'page:' . $p['key'],
			);
			if ( ! empty( $p['in_footer'] ) || in_array( $p['type'], array( 'privacy', 'terms' ), true ) ) {
				$legal[] = $item;
			} elseif ( ! empty( $p['in_menu'] ) && empty( $p['parent'] ) ) {
				$links[] = $item;
			}
		}

		$columns = array(
			array(
				'type'      => 'group',
				'direction' => 'column',
				'elements'  => array_values(
					array_filter(
						array(
							array(
								'type' => 'site_logo',
								'text' => (string) ( $spec['site_name'] ?? '' ),
							),
							! empty( $sitemap['footer']['about_text'] ) ? array(
								'type' => 'text',
								'html' => '<p>' . esc_html( (string) $sitemap['footer']['about_text'] ) . '</p>',
							) : null,
						)
					)
				),
			),
		);
		if ( $links ) {
			$columns[] = array(
				'type'      => 'group',
				'direction' => 'column',
				'elements'  => array(
					array(
						'type'  => 'heading',
						'level' => 'p',
						'text'  => $fa ? 'دسترسی سریع' : 'Quick links',
					),
					array(
						'type'  => 'icon_list',
						'items' => $links,
					),
				),
			);
		}
		$contact = array();
		$b       = (array) ( $spec['business'] ?? array() );
		if ( ! empty( $b['email'] ) ) {
			$contact[] = array(
				'text' => $b['email'],
				'icon' => 'envelope',
				'link' => 'mailto:' . $b['email'],
			);
		}
		if ( ! empty( $b['phone'] ) ) {
			$contact[] = array(
				'text' => $b['phone'],
				'icon' => 'phone',
				'link' => 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $b['phone'] ),
			);
		}
		if ( ! empty( $b['address'] ) ) {
			$contact[] = array(
				'text' => $b['address'],
				'icon' => 'map-marker-alt',
			);
		}
		if ( $contact ) {
			$columns[] = array(
				'type'      => 'group',
				'direction' => 'column',
				'elements'  => array(
					array(
						'type'  => 'heading',
						'level' => 'p',
						'text'  => $fa ? 'تماس با ما' : 'Contact',
					),
					array(
						'type'  => 'icon_list',
						'items' => $contact,
					),
				),
			);
		}

		$bottom = array(
			array(
				'type'  => 'text',
				'html'  => '<p>© ' . esc_html( (string) ( $spec['site_name'] ?? '' ) ) . '</p>',
				'align' => 'start',
			),
		);
		if ( $legal ) {
			$bottom[] = array(
				'type'  => 'icon_list',
				'items' => $legal,
			);
		}

		return array(
			array(
				'id'         => 'site-footer',
				'type'       => 'custom',
				'label'      => 'Footer',
				'tag'        => 'footer',
				'background' => 'dark',
				'layout'     => array(
					'direction' => 'row',
					'align'     => 'start',
					'padding'   => 'md',
					'gap'       => 'lg',
				),
				'elements'   => $columns,
			),
			array(
				'id'         => 'site-footer-bottom',
				'type'       => 'custom',
				'label'      => 'Footer bottom',
				'tag'        => 'div',
				'background' => 'dark',
				'layout'     => array(
					'direction' => 'row',
					'align'     => 'center',
					'padding'   => 'sm',
				),
				'elements'   => $bottom,
			),
		);
	}

	/**
	 * Create or update a library template from section definitions.
	 *
	 * @param int                              $existing_id Existing template id (0 to create).
	 * @param string                           $title       Title.
	 * @param array<int, array<string, mixed>> $sections    Section definitions.
	 * @param DocumentBuilder                  $builder     Builder.
	 * @param int                              $project_id  Project.
	 * @param bool                             $force       Overwrite manual edits.
	 * @return array{id: int, skipped: bool}
	 * @throws \RuntimeException On failure.
	 */
	public function save_template( int $existing_id, string $title, array $sections, DocumentBuilder $builder, int $project_id, bool $force = false ): array {
		$id = $existing_id && get_post( $existing_id ) ? $existing_id : 0;
		if ( $id && ! $force && $this->writer->is_modified( $id ) ) {
			return array(
				'id'      => $id,
				'skipped' => true,
			);
		}
		if ( ! $id ) {
			$id = $this->create_library_post( $title, 'section' );
		}

		$built = $builder->build_page( array( 'sections' => $sections ) );
		$this->writer->write(
			$id,
			$built['elements'],
			array(
				'template_type' => (string) get_post_meta( $id, '_elementor_template_type', true ) ? (string) get_post_meta( $id, '_elementor_template_type', true ) : 'section',
				'project_id'    => $project_id,
			)
		);
		update_post_meta( $id, '_ats_project_id', $project_id );
		return array(
			'id'      => $id,
			'skipped' => false,
		);
	}

	/**
	 * Create an Elementor library post.
	 *
	 * @param string $title Title.
	 * @param string $type  Document type.
	 * @param string $status Post status.
	 * @throws \RuntimeException On failure.
	 */
	private function create_library_post( string $title, string $type, string $status = 'publish' ): int {
		if ( $this->compat->meets_minimum() ) {
			$document = \Elementor\Plugin::$instance->documents->create(
				$type,
				array(
					'post_title'  => $title,
					'post_status' => $status,
				)
			);
			if ( $document && ! is_wp_error( $document ) ) {
				return (int) $document->get_main_id();
			}
		}
		$id = wp_insert_post(
			array(
				'post_type'   => 'elementor_library',
				'post_title'  => $title,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		update_post_meta( $id, '_elementor_template_type', $type );
		wp_set_object_terms( $id, $type, 'elementor_library_type' );
		return (int) $id;
	}

	/**
	 * Copy a library template into a Pro Theme Builder header/footer with an "Entire Site" condition.
	 *
	 * @param int    $source_id   Library template id.
	 * @param string $type        header|footer.
	 * @param int    $existing_id Existing theme template (0 to create).
	 * @param int    $project_id  Project.
	 * @return int Theme template id, 0 if unavailable.
	 */
	public function activate_theme_builder( int $source_id, string $type, int $existing_id, int $project_id ): int {
		if ( ! $this->compat->theme_builder_available() || ! in_array( $type, array( 'header', 'footer' ), true ) ) {
			return 0;
		}
		$id = $existing_id && get_post( $existing_id ) ? $existing_id : $this->create_library_post( get_the_title( $source_id ), $type, 'publish' );
		$this->writer->write(
			$id,
			$this->writer->read( $source_id ),
			array(
				'template_type' => $type,
				'project_id'    => $project_id,
			)
		);
		update_post_meta( $id, '_elementor_conditions', array( 'include/general' ) );
		update_post_meta( $id, '_ats_project_id', $project_id );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			$module->get_conditions_manager()->get_cache()->regenerate();
		} catch ( \Throwable $e ) {
			return $id;
		}
		return $id;
	}
}
