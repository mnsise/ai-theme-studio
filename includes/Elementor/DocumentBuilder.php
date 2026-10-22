<?php
/**
 * Converts validated page/section definitions into native Elementor element
 * data (Flexbox Containers + core widgets). Output is plain arrays matching
 * the structure Elementor stores in `_elementor_data`.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Document builder.
 */
class DocumentBuilder {

	/**
	 * Context.
	 *
	 * @var BuildContext
	 */
	private BuildContext $ctx;

	/**
	 * Used element ids.
	 *
	 * @var array<string, bool>
	 */
	private array $ids = array();

	/**
	 * Fallback HTML buffer.
	 *
	 * @var array<int, string>
	 */
	private array $html = array();

	/**
	 * Build warnings.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = array();

	/**
	 * Allowed HTML in text widgets.
	 */
	private const TEXT_TAGS = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
	);

	/**
	 * Constructor.
	 *
	 * @param BuildContext $ctx Context.
	 */
	public function __construct( BuildContext $ctx ) {
		$this->ctx = $ctx;
	}

	/**
	 * Build a page.
	 *
	 * @param array<string, mixed> $page Validated page definition.
	 * @return array{elements: array<int, array<string, mixed>>, section_map: array<string, string>, fallback_html: string, warnings: array<int, string>}
	 */
	public function build_page( array $page ): array {
		$this->html     = array();
		$this->warnings = array();
		$elements       = array();
		$map            = array();
		foreach ( $page['sections'] as $section ) {
			$el                     = $this->section( $section );
			$elements[]             = $el;
			$map[ $section['id'] ] = $el['id'];
		}
		if ( ! $this->ctx->containers ) {
			$elements = ( new LegacyConverter( fn() => $this->id() ) )->convert( $elements );
		}
		return array(
			'elements'      => $elements,
			'section_map'   => $map,
			'fallback_html' => implode( "\n", $this->html ),
			'warnings'      => $this->warnings,
		);
	}

	/**
	 * Build a single top-level section (used for section regeneration and templates).
	 *
	 * @param array<string, mixed> $section Section definition.
	 * @param array<int, string>   $reserved_ids Ids already used in the document.
	 * @return array{element: array<string, mixed>, warnings: array<int, string>}
	 */
	public function build_section( array $section, array $reserved_ids = array() ): array {
		$this->ids      = array_fill_keys( $reserved_ids, true );
		$this->warnings = array();
		$el             = $this->section( $section );
		if ( ! $this->ctx->containers ) {
			$converted = ( new LegacyConverter( fn() => $this->id() ) )->convert( array( $el ) );
			$el        = $converted[0];
		}
		return array(
			'element'  => $el,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Unique 7-char hex element id.
	 */
	public function id(): string {
		do {
			$id = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} while ( isset( $this->ids[ $id ] ) || ctype_digit( $id ) );
		$this->ids[ $id ] = true;
		return $id;
	}

	/**
	 * Section -> top-level container.
	 *
	 * @param array<string, mixed> $s Section.
	 * @return array<string, mixed>
	 */
	private function section( array $s ): array {
		$t      = $this->ctx->tokens;
		$bg     = (string) ( $s['background'] ?? 'default' );
		$layout = (array) ( $s['layout'] ?? array() );
		$dir    = 'row' === ( $layout['direction'] ?? 'column' ) ? 'row' : 'column';
		$align  = (string) ( $layout['align'] ?? 'start' );
		$pal    = $this->palette( $bg );
		$pad    = $t->padding( $layout['padding'] ?? null );
		$gap    = $t->gap( $layout['gap'] ?? null );
		$tag    = (string) ( $s['tag'] ?? 'section' );
		$tag    = in_array( $tag, array( 'section', 'div', 'article', 'aside', 'header', 'footer', 'nav' ), true ) ? $tag : 'section';

		$settings = array(
			'_title'                => (string) ( $s['label'] ?? $s['id'] ),
			'html_tag'              => $tag,
			'_element_id'           => (string) $s['id'],
			'content_width'         => 'full' === ( $layout['width'] ?? 'boxed' ) ? 'full' : 'boxed',
			'boxed_width'           => DesignTokens::px( $t->container_width() ),
			'flex_direction'        => $dir,
			'flex_direction_mobile' => 'column',
			'flex_align_items'      => 'row' === $dir ? 'center' : $this->flex_align( $align ),
			'flex_justify_content'  => 'row' === $dir ? 'space-between' : 'flex-start',
			'flex_gap'              => $this->gap_value( $gap ),
			'flex_gap_mobile'       => $this->gap_value( (int) round( $gap * 0.75 ) ),
			'padding'               => DesignTokens::dims( $pad[0], 24, $pad[0], 24 ),
			'padding_tablet'        => DesignTokens::dims( $pad[1], 24, $pad[1], 24 ),
			'padding_mobile'        => DesignTokens::dims( $pad[2], 16, $pad[2], 16 ),
			'__globals__'           => array(),
		);
		if ( ! empty( $layout['min_height'] ) ) {
			$settings['min_height']           = array(
				'unit'  => 'vh',
				'size'  => (int) $layout['min_height'],
				'sizes' => array(),
			);
			$settings['min_height_mobile']    = array(
				'unit'  => 'vh',
				'size'  => 0,
				'sizes' => array(),
			);
			$settings['flex_justify_content'] = 'row' === $dir ? 'space-between' : 'center';
		}
		$this->background( $settings, $bg, $s['background_image'] ?? null );

		$this->html[] = sprintf( '<%1$s id="%2$s">', $tag, esc_attr( (string) $s['id'] ) );
		$children     = $this->children( (array) $s['elements'], $dir, $pal, $align, 1 );
		$this->html[] = '</' . $tag . '>';

		return $this->container( $settings, $children, false );
	}

	/**
	 * Convert a list of child elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param string                           $dir      Parent direction.
	 * @param array<string, string>            $pal      Palette.
	 * @param string                           $align    Logical alignment.
	 * @param int                              $depth    Depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function children( array $elements, string $dir, array $pal, string $align, int $depth ): array {
		$out = array();

		if ( 'row' === $dir ) {
			$n = max( 1, count( $elements ) );
			foreach ( $elements as $el ) {
				if ( 'group' !== $el['type'] ) {
					$el = array(
						'type'      => 'group',
						'direction' => 'column',
						'style'     => 'plain',
						'elements'  => array( $el ),
					);
				}
				$width = isset( $el['width'] ) ? (int) $el['width'] : (int) floor( 100 / $n );
				$out[] = $this->group( $el, $pal, $align, $depth, $width, $n );
			}
			return $out;
		}

		$buttons = array();
		$flush   = function () use ( &$buttons, &$out, $pal, $align ) {
			if ( ! $buttons ) {
				return;
			}
			if ( 1 === count( $buttons ) ) {
				$out[] = $this->button( $buttons[0], $pal, $align );
			} else {
				$widgets = array();
				foreach ( $buttons as $b ) {
					$widgets[] = $this->button( $b, $pal, $align, true );
				}
				$out[] = $this->container(
					array(
						'_title'               => 'Buttons',
						'content_width'        => 'full',
						'flex_direction'       => 'row',
						'flex_wrap'            => 'wrap',
						'flex_justify_content' => $this->flex_align( $align ),
						'flex_align_items'     => 'center',
						'flex_gap'             => $this->gap_value( 12 ),
						'padding'              => DesignTokens::dims( 0, 0, 0, 0 ),
					),
					$widgets,
					true
				);
			}
			$buttons = array();
		};

		foreach ( $elements as $el ) {
			if ( 'button' === $el['type'] ) {
				$buttons[] = $el;
				continue;
			}
			$flush();
			$converted = $this->element( $el, $pal, $align, $depth );
			foreach ( $converted as $c ) {
				$out[] = $c;
			}
		}
		$flush();
		return $out;
	}

	/**
	 * Convert one element into zero or more Elementor elements.
	 *
	 * @param array<string, mixed>  $el    Element.
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @param int                   $depth Depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function element( array $el, array $pal, string $align, int $depth ): array {
		$align = (string) ( $el['align'] ?? $align );
		switch ( $el['type'] ) {
			case 'heading':
				return array( $this->heading( (string) $el['text'], (string) ( $el['level'] ?? 'h2' ), $pal, $align ) );
			case 'text':
				return array( $this->text( (string) ( $el['html'] ?? '' ), $pal, $align ) );
			case 'button':
				return array( $this->button( $el, $pal, $align ) );
			case 'image':
				$img = $this->image( $el, $align );
				return $img ? array( $img ) : array();
			case 'icon_box':
				return array( $this->icon_box( $el, $pal, $align ) );
			case 'icon_list':
				return array( $this->icon_list( (array) $el['items'], $pal ) );
			case 'testimonial':
				return array( $this->testimonial( $el, $pal ) );
			case 'accordion':
				return $this->accordion( $el, $pal, $align );
			case 'spacer':
				return array(
					$this->widget(
						'spacer',
						array( 'space' => DesignTokens::px( (int) ( $el['size'] ?? 24 ) ) )
					),
				);
			case 'divider':
				$s = array(
					'style'  => 'solid',
					'weight' => DesignTokens::px( 1 ),
				);
				$this->color( $s, 'color', 'border' );
				return array( $this->widget( 'divider', $s ) );
			case 'posts_grid':
				return array( $this->posts_grid( $el, $pal ) );
			case 'contact_form':
				return $this->contact_form( $pal, $align );
			case 'nav_menu':
				return array( $this->nav_menu( $pal ) );
			case 'site_logo':
				return $this->site_logo( $el, $pal, $align );
			case 'group':
				return array( $this->group( $el, $pal, $align, $depth, null, 1 ) );
		}
		return array();
	}

	/**
	 * Group -> nested container.
	 *
	 * @param array<string, mixed>  $el       Group.
	 * @param array<string, string> $pal      Palette.
	 * @param string                $align    Alignment.
	 * @param int                   $depth    Depth.
	 * @param int|null              $width    Width in % when inside a row.
	 * @param int                   $siblings Number of siblings in the row.
	 * @return array<string, mixed>
	 */
	private function group( array $el, array $pal, string $align, int $depth, ?int $width, int $siblings ): array {
		$t     = $this->ctx->tokens;
		$dir   = 'row' === ( $el['direction'] ?? 'column' ) ? 'row' : 'column';
		$card  = 'card' === ( $el['style'] ?? 'plain' );
		$align = (string) ( $el['align'] ?? $align );
		$n     = count( (array) $el['elements'] );

		$settings = array(
			'_title'                => $card ? 'Card' : ( 'row' === $dir ? 'Row' : 'Group' ),
			'content_width'         => 'full',
			'flex_direction'        => $dir,
			'flex_direction_mobile' => 'column',
			'flex_gap'              => $this->gap_value( 'row' === $dir ? $t->gap() : 16 ),
			'flex_align_items'      => 'row' === $dir ? 'stretch' : $this->flex_align( $align ),
			'padding'               => DesignTokens::dims( 0, 0, 0, 0 ),
		);
		if ( 'row' === $dir && $n >= 3 ) {
			$settings['flex_wrap_tablet'] = 'wrap';
		}
		if ( null !== $width ) {
			$settings['width']        = array(
				'unit'  => '%',
				'size'  => $width,
				'sizes' => array(),
			);
			$settings['width_tablet'] = array(
				'unit'  => '%',
				'size'  => $siblings >= 3 ? 47 : $width,
				'sizes' => array(),
			);
			$settings['width_mobile'] = array(
				'unit'  => '%',
				'size'  => 100,
				'sizes' => array(),
			);
		}

		$child_pal = $pal;
		if ( $card ) {
			$settings['padding']        = DesignTokens::dims( 28, 24, 28, 24 );
			$settings['padding_mobile'] = DesignTokens::dims( 22, 18, 22, 18 );
			$settings['border_radius']  = DesignTokens::dims( $t->radius( 'medium' ), $t->radius( 'medium' ), $t->radius( 'medium' ), $t->radius( 'medium' ) );
			$settings['border_border']  = 'solid';
			$settings['border_width']   = DesignTokens::dims( 1, 1, 1, 1 );
			$settings['background_background'] = 'classic';
			if ( $pal['dark'] ) {
				$settings['background_color'] = 'rgba(255,255,255,0.06)';
				$settings['border_color']     = 'rgba(255,255,255,0.18)';
			} else {
				$this->color( $settings, 'background_color', $pal['card_bg'] );
				$this->color( $settings, 'border_color', 'border' );
				if ( 'none' !== $t->shadow() ) {
					$settings['box_shadow_box_shadow_type'] = 'yes';
					$settings['box_shadow_box_shadow']      = array(
						'horizontal' => 0,
						'vertical'   => 'medium' === $t->shadow() ? 10 : 4,
						'blur'       => 'medium' === $t->shadow() ? 30 : 18,
						'spread'     => 0,
						'color'      => 'rgba(15,23,42,0.08)',
					);
				}
			}
		}

		$children = $this->children( (array) $el['elements'], $dir, $child_pal, $align, $depth + 1 );
		return $this->container( $settings, $children, true );
	}

	/**
	 * Heading widget.
	 *
	 * @param string                $text  Text.
	 * @param string                $level h1..h6|p.
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<string, mixed>
	 */
	private function heading( string $text, string $level, array $pal, string $align ): array {
		$level = preg_match( '/^(h[1-6]|p)$/', $level ) ? $level : 'h2';
		$text  = sanitize_text_field( $text );
		$s     = array(
			'title'       => $text,
			'header_size' => $level,
			'align'       => $this->phys( $align ),
		);
		$this->color( $s, 'title_color', 'p' === $level ? $pal['accent'] : $pal['heading'] );
		$this->typo( $s, 'p' === $level ? 'small' : $level, 'typography' );
		$this->html[] = sprintf( '<%1$s>%2$s</%1$s>', $level, esc_html( $text ) );
		return $this->widget( 'heading', $s );
	}

	/**
	 * Text editor widget.
	 *
	 * @param string                $html  HTML.
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<string, mixed>
	 */
	private function text( string $html, array $pal, string $align ): array {
		$html = $this->safe_html( $html );
		$s    = array(
			'editor' => $html,
			'align'  => $this->phys( $align ),
		);
		$this->color( $s, 'text_color', $pal['text'] );
		$this->typo( $s, 'body', 'typography' );
		$this->html[] = $html;
		return $this->widget( 'text-editor', $s );
	}

	/**
	 * Resolve page links then restrict HTML to the safe subset.
	 *
	 * @param string $html HTML.
	 */
	public function safe_html( string $html ): string {
		if ( false === strpos( $html, '<' ) ) {
			$html = '<p>' . $html . '</p>';
		}
		$resolver = $this->ctx->link_resolver;
		$html     = (string) preg_replace_callback(
			'/href\s*=\s*(["\'])(.*?)\1/i',
			static fn( $m ) => 'href="' . esc_attr( $resolver( html_entity_decode( $m[2] ) ) ) . '"',
			$html
		);
		return wp_kses( $html, self::TEXT_TAGS, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Button widget.
	 *
	 * @param array<string, mixed>  $el     Element.
	 * @param array<string, string> $pal    Palette.
	 * @param string                $align  Alignment.
	 * @param bool                  $inline Inside a button row.
	 * @return array<string, mixed>
	 */
	private function button( array $el, array $pal, string $align, bool $inline = false ): array {
		$t     = $this->ctx->tokens;
		$style = (string) ( $el['style'] ?? 'primary' );
		$url   = ( $this->ctx->link_resolver )( (string) ( $el['link'] ?? '#' ) );
		$text  = sanitize_text_field( (string) $el['text'] );
		$r     = $t->radius( 'button' );

		$s = array(
			'text'                  => $text,
			'link'                  => array(
				'url'         => $url,
				'is_external' => '',
				'nofollow'    => '',
			),
			'size'                  => 'md',
			'text_padding'          => DesignTokens::dims( 14, 28, 14, 28 ),
			'border_radius'         => DesignTokens::dims( $r, $r, $r, $r ),
			'background_background' => 'classic',
		);
		if ( ! $inline ) {
			$s['align'] = $this->phys( (string) ( $el['align'] ?? $align ) );
		}
		$this->typo( $s, 'button', 'typography' );

		$inverse = 'primary' === $pal['surface'] || 'accent' === $pal['surface'];
		if ( 'link' === $style ) {
			$s['background_color'] = 'rgba(0,0,0,0)';
			$s['text_padding']     = DesignTokens::dims( 6, 0, 6, 0 );
			$this->color( $s, 'button_text_color', $pal['dark'] ? $pal['heading'] : 'primary' );
		} elseif ( 'secondary' === $style || 'outline' === $t->button_style() ) {
			$s['background_color'] = 'rgba(0,0,0,0)';
			$s['border_border']    = 'solid';
			$s['border_width']     = DesignTokens::dims( 2, 2, 2, 2 );
			$this->color( $s, 'button_text_color', $pal['dark'] ? $pal['heading'] : 'primary' );
			$this->color( $s, 'border_color', $pal['dark'] ? $pal['heading'] : 'primary' );
		} elseif ( $inverse ) {
			$this->color( $s, 'background_color', 'on_primary' );
			$this->color( $s, 'button_text_color', 'primary' );
		} else {
			$this->color( $s, 'background_color', 'primary' );
			$this->color( $s, 'button_text_color', 'on_primary' );
			$s['button_background_hover_color'] = DesignTokens::shade( $t->color( 'primary' ), -12 );
			$this->color( $s, 'hover_color', 'on_primary' );
		}
		$this->html[] = sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $url ), esc_html( $text ) );
		return $this->widget( 'button', $s );
	}

	/**
	 * Image widget.
	 *
	 * @param array<string, mixed> $el    Element.
	 * @param string               $align Alignment.
	 * @return array<string, mixed>|null
	 */
	private function image( array $el, string $align ): ?array {
		$img = ( $this->ctx->image_resolver )( (string) ( $el['image_ref'] ?? '' ) );
		if ( ! $img ) {
			$this->warnings[] = sprintf( 'Image %s could not be resolved and was skipped.', (string) ( $el['image_ref'] ?? '' ) );
			return null;
		}
		$decorative = ! empty( $el['decorative'] );
		$alt        = $decorative ? '' : sanitize_text_field( (string) ( $el['alt'] ?? $img['alt'] ?? '' ) );
		$r          = $this->ctx->tokens->radius( 'medium' );
		$s          = array(
			'image'               => array(
				'id'     => (int) $img['id'],
				'url'    => esc_url_raw( (string) $img['url'] ),
				'alt'    => $alt,
				'source' => 'library',
				'size'   => '',
			),
			'image_size'          => 'large',
			'align'               => $this->phys( $align ),
			'image_border_radius' => DesignTokens::dims( $r, $r, $r, $r ),
		);
		$this->html[] = sprintf(
			'<img src="%1$s" alt="%2$s" width="%3$d" height="%4$d">',
			esc_url( (string) $img['url'] ),
			esc_attr( $alt ),
			(int) ( $img['width'] ?? 0 ),
			(int) ( $img['height'] ?? 0 )
		);
		return $this->widget( 'image', $s );
	}

	/**
	 * Icon box widget.
	 *
	 * @param array<string, mixed>  $el    Element.
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<string, mixed>
	 */
	private function icon_box( array $el, array $pal, string $align ): array {
		$level = (string) ( $el['level'] ?? 'h3' );
		$title = sanitize_text_field( (string) $el['title'] );
		$desc  = sanitize_textarea_field( (string) ( $el['description'] ?? '' ) );
		$s     = array(
			'selected_icon'    => $this->icon( (string) ( $el['icon'] ?? 'check' ) ),
			'view'             => 'default',
			'title_text'       => $title,
			'description_text' => $desc,
			'title_size'       => preg_match( '/^h[2-6]$/', $level ) ? $level : 'h3',
			'position'         => 'top',
			'text_align'       => $this->phys( $align ),
			'icon_size'        => DesignTokens::px( 32 ),
			'icon_space'       => DesignTokens::px( 12 ),
		);
		$this->color( $s, 'primary_color', $pal['accent'] );
		$this->color( $s, 'title_color', $pal['heading'] );
		$this->color( $s, 'description_color', $pal['text'] );
		$this->typo( $s, 'h4', 'title_typography' );
		$this->typo( $s, 'body', 'description_typography' );
		$this->html[] = sprintf( '<%1$s>%2$s</%1$s><p>%3$s</p>', $s['title_size'], esc_html( $title ), esc_html( $desc ) );
		return $this->widget( 'icon-box', $s );
	}

	/**
	 * Icon list widget.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @param array<string, string>            $pal   Palette.
	 * @return array<string, mixed>
	 */
	private function icon_list( array $items, array $pal ): array {
		$list = array();
		$html = '<ul>';
		foreach ( $items as $item ) {
			$text = sanitize_text_field( (string) $item['text'] );
			$row  = array(
				'_id'           => $this->id(),
				'text'          => $text,
				'selected_icon' => $this->icon( (string) ( $item['icon'] ?? 'check' ) ),
			);
			if ( ! empty( $item['link'] ) ) {
				$url         = ( $this->ctx->link_resolver )( (string) $item['link'] );
				$row['link'] = array(
					'url'         => $url,
					'is_external' => '',
					'nofollow'    => '',
				);
				$html       .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $url ), esc_html( $text ) );
			} else {
				$html .= '<li>' . esc_html( $text ) . '</li>';
			}
			$list[] = $row;
		}
		$this->html[] = $html . '</ul>';
		$s            = array(
			'icon_list'     => $list,
			'space_between' => DesignTokens::px( 10 ),
			'icon_size'     => DesignTokens::px( 16 ),
		);
		$this->color( $s, 'icon_color', $pal['accent'] );
		$this->color( $s, 'text_color', $pal['text'] );
		$this->typo( $s, 'body', 'icon_typography' );
		return $this->widget( 'icon-list', $s );
	}

	/**
	 * Testimonial widget (always a marked placeholder unless the owner supplied it).
	 *
	 * @param array<string, mixed>  $el  Element.
	 * @param array<string, string> $pal Palette.
	 * @return array<string, mixed>
	 */
	private function testimonial( array $el, array $pal ): array {
		$name   = sanitize_text_field( (string) ( $el['name'] ?? '' ) );
		$marker = 0 === strpos( $this->ctx->language, 'fa' ) ? 'نمونه' : 'Sample';
		if ( ! empty( $el['placeholder'] ) && false === mb_strpos( $name, $marker ) && false === mb_strpos( $name, '[' ) ) {
			$name = trim( $name . ' (' . $marker . ')' );
		}
		$s = array(
			'_title'                => 'Testimonial (placeholder — replace with a real review)',
			'testimonial_content'   => sanitize_textarea_field( (string) $el['text'] ),
			'testimonial_name'      => $name,
			'testimonial_job'       => sanitize_text_field( (string) ( $el['role'] ?? '' ) ),
			'testimonial_alignment' => 'center',
			// An empty image prevents Elementor's default placeholder photo.
			'testimonial_image'     => array(
				'url' => '',
				'id'  => '',
			),
		);
		$this->color( $s, 'content_content_color', $pal['text'] );
		$this->color( $s, 'name_text_color', $pal['heading'] );
		$this->color( $s, 'job_text_color', $pal['muted'] );
		$this->html[] = '<blockquote>' . esc_html( $s['testimonial_content'] ) . '</blockquote>';
		return $this->widget( 'testimonial', $s );
	}

	/**
	 * Nested Accordion (native <details>/<summary>), or plain headings + text.
	 *
	 * The legacy "accordion" widget is not used: its markup (role="button" with
	 * aria-selected on a heading, anchors without href) fails Lighthouse
	 * accessibility and SEO audits.
	 *
	 * @param array<string, mixed>  $el    Element.
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<int, array<string, mixed>>
	 */
	private function accordion( array $el, array $pal, string $align ): array {
		$level = preg_match( '/^h[2-6]$/', (string) ( $el['level'] ?? '' ) ) ? $el['level'] : 'h3';
		if ( ! $this->ctx->containers || ! ( $this->ctx->widget_available )( 'nested-accordion' ) ) {
			$out = array();
			foreach ( (array) $el['items'] as $item ) {
				$out[] = $this->heading( (string) $item['title'], $level, $pal, $align );
				$out[] = $this->text( (string) $item['content'], $pal, $align );
			}
			return $out;
		}
		$items    = array();
		$children = array();
		foreach ( (array) $el['items'] as $item ) {
			$title      = sanitize_text_field( (string) $item['title'] );
			$items[]    = array(
				'_id'        => $this->id(),
				'item_title' => $title,
			);
			$this->html[] = sprintf( '<%1$s>%2$s</%1$s>', $level, esc_html( $title ) );
			$children[] = $this->container(
				array(
					'_title'        => $title,
					'content_width' => 'full',
				),
				array( $this->text( (string) $item['content'], $pal, $align ) ),
				true
			);
		}
		$s = array(
			'items'         => $items,
			'title_tag'     => $level,
			'default_state' => 'all_collapsed',
		);
		$this->color( $s, 'normal_title_color', $pal['heading'] );
		$this->color( $s, 'hover_title_color', $pal['accent'] );
		$this->color( $s, 'active_title_color', $pal['accent'] );
		$this->typo( $s, 'h5', 'title_typography' );
		$widget             = $this->widget( 'nested-accordion', $s );
		$widget['elements'] = $children;
		return array( $widget );
	}

	/**
	 * Latest posts (custom lightweight widget registered by this plugin).
	 *
	 * @param array<string, mixed>  $el  Element.
	 * @param array<string, string> $pal Palette.
	 * @return array<string, mixed>
	 */
	private function posts_grid( array $el, array $pal ): array {
		$s = array(
			'posts_per_page' => (int) ( $el['count'] ?? 6 ),
			'columns'        => '3',
			'columns_mobile' => '1',
			'title_tag'      => 'h3',
		);
		$this->color( $s, 'title_color', $pal['heading'] );
		$this->color( $s, 'text_color', $pal['text'] );
		$this->html[] = '<!-- latest posts -->';
		return $this->widget( 'ats-posts-grid', $s );
	}

	/**
	 * Contact form or an honest fallback with real contact details only.
	 *
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<int, array<string, mixed>>
	 */
	private function contact_form( array $pal, string $align ): array {
		if ( $this->ctx->contact_form_shortcode ) {
			$this->html[] = '<!-- contact form -->';
			return array( $this->widget( 'shortcode', array( 'shortcode' => $this->ctx->contact_form_shortcode ) ) );
		}
		$items = array();
		$b     = $this->ctx->business;
		if ( ! empty( $b['email'] ) && is_email( $b['email'] ) ) {
			$items[] = array(
				'text' => $b['email'],
				'icon' => 'envelope',
				'link' => 'mailto:' . $b['email'],
			);
		}
		if ( ! empty( $b['phone'] ) ) {
			$items[] = array(
				'text' => $b['phone'],
				'icon' => 'phone',
				'link' => 'tel:' . preg_replace( '/[^0-9+]/', '', $b['phone'] ),
			);
		}
		if ( ! empty( $b['address'] ) ) {
			$items[] = array(
				'text' => $b['address'],
				'icon' => 'map-marker-alt',
			);
		}
		$this->warnings[] = 'No contact form plugin detected; contact details are shown instead of a form.';
		if ( $items ) {
			return array( $this->icon_list( $items, $pal ) );
		}
		$placeholder = 0 === strpos( $this->ctx->language, 'fa' )
			? '[نمونه — ایمیل، تلفن و نشانی واقعی خود را اینجا وارد کنید]'
			: '[Sample — add your real email, phone and address here]';
		return array( $this->text( '<p>' . $placeholder . '</p>', $pal, $align ) );
	}

	/**
	 * Navigation menu: Elementor Pro widget when available, else this plugin's free widget.
	 *
	 * @param array<string, string> $pal Palette.
	 * @return array<string, mixed>
	 */
	private function nav_menu( array $pal ): array {
		$s = array( 'project_id' => $this->ctx->project_id );
		$this->color( $s, 'link_color', $pal['heading'] );
		$this->color( $s, 'link_hover_color', $pal['accent'] );
		$this->typo( $s, 'button', 'typography' );
		$this->html[] = '<nav><!-- menu --></nav>';
		return $this->widget( 'ats-nav-menu', $s );
	}

	/**
	 * Logo image or the site name as text (never a heading level, to keep one H1 per page).
	 *
	 * @param array<string, mixed>  $el    Element (image_ref, text).
	 * @param array<string, string> $pal   Palette.
	 * @param string                $align Alignment.
	 * @return array<int, array<string, mixed>>
	 */
	private function site_logo( array $el, array $pal, string $align ): array {
		$img = ! empty( $el['image_ref'] ) ? ( $this->ctx->image_resolver )( (string) $el['image_ref'] ) : null;
		$url = ( $this->ctx->link_resolver )( 'page:home' );
		if ( $img ) {
			$s = array(
				'image'      => array(
					'id'     => (int) $img['id'],
					'url'    => esc_url_raw( (string) $img['url'] ),
					'alt'    => sanitize_text_field( (string) ( $el['text'] ?? '' ) ),
					'source' => 'library',
				),
				'image_size' => 'medium',
				'link_to'    => 'custom',
				'link'       => array( 'url' => $url ),
				'width'      => DesignTokens::px( 160 ),
				'align'      => $this->phys( $align ),
			);
			return array( $this->widget( 'image', $s ) );
		}
		$s = array(
			'title'       => sanitize_text_field( (string) ( $el['text'] ?? '' ) ),
			'header_size' => 'p',
			'link'        => array( 'url' => $url ),
			'align'       => $this->phys( $align ),
		);
		$this->color( $s, 'title_color', $pal['heading'] );
		$this->typo( $s, 'h4', 'typography' );
		return array( $this->widget( 'heading', $s ) );
	}

	/**
	 * Section background settings.
	 *
	 * @param array<string, mixed> $s   Settings (modified).
	 * @param string               $bg  Background token.
	 * @param string|null          $ref Image ref.
	 */
	private function background( array &$s, string $bg, ?string $ref ): void {
		$map = array(
			'default' => 'background',
			'surface' => 'surface',
			'primary' => 'primary',
			'dark'    => 'dark',
			'accent'  => 'accent',
		);
		if ( 'image' === $bg && $ref ) {
			$img = ( $this->ctx->image_resolver )( $ref );
			if ( $img ) {
				$s['background_background']          = 'classic';
				$s['background_image']               = array(
					'id'  => (int) $img['id'],
					'url' => esc_url_raw( (string) $img['url'] ),
				);
				$s['background_size']                = 'cover';
				$s['background_position']            = 'center center';
				$s['background_overlay_background']  = 'classic';
				$s['background_overlay_opacity']     = array(
					'unit'  => 'px',
					'size'  => 0.65,
					'sizes' => array(),
				);
				$this->color( $s, 'background_overlay_color', 'dark' );
				return;
			}
			$bg = 'dark';
		}
		if ( isset( $map[ $bg ] ) ) {
			$s['background_background'] = 'classic';
			$this->color( $s, 'background_color', $map[ $bg ] );
		}
	}

	/**
	 * Palette (token names) for a background.
	 *
	 * @param string $bg Background.
	 * @return array<string, mixed>
	 */
	private function palette( string $bg ): array {
		if ( in_array( $bg, array( 'primary', 'accent' ), true ) ) {
			return array(
				'heading' => 'on_primary',
				'text'    => 'on_primary',
				'muted'   => 'on_primary',
				'accent'  => 'on_primary',
				'card_bg' => 'background',
				'surface' => $bg,
				'dark'    => true,
			);
		}
		if ( in_array( $bg, array( 'dark', 'image' ), true ) ) {
			return array(
				'heading' => 'on_dark',
				'text'    => 'on_dark',
				'muted'   => 'on_dark',
				'accent'  => 'on_dark',
				'card_bg' => 'dark',
				'surface' => 'dark',
				'dark'    => true,
			);
		}
		return array(
			'heading' => 'heading',
			'text'    => 'text',
			'muted'   => 'muted',
			'accent'  => 'primary',
			'card_bg' => 'surface' === $bg ? 'background' : 'surface',
			'surface' => 'surface' === $bg ? 'surface' : 'background',
			'dark'    => false,
		);
	}

	/**
	 * Set a color with its global reference.
	 *
	 * @param array<string, mixed> $s     Settings (modified).
	 * @param string               $key   Control key.
	 * @param string               $token Token name.
	 */
	private function color( array &$s, string $key, string $token ): void {
		$s[ $key ] = $this->ctx->tokens->color( $token );
		if ( ! isset( $s['__globals__'] ) ) {
			$s['__globals__'] = array();
		}
		$s['__globals__'][ $key ] = $this->ctx->use_globals ? $this->ctx->tokens->color_ref( $token ) : '';
	}

	/**
	 * Set typography with its global reference.
	 *
	 * @param array<string, mixed> $s      Settings (modified).
	 * @param string               $token  Typography token.
	 * @param string               $prefix Control prefix.
	 */
	private function typo( array &$s, string $token, string $prefix ): void {
		$s = array_merge( $s, $this->ctx->tokens->typography( $token, $prefix ) );
		if ( ! isset( $s['__globals__'] ) ) {
			$s['__globals__'] = array();
		}
		$s['__globals__'][ $prefix . '_typography' ] = $this->ctx->use_globals ? $this->ctx->tokens->typo_ref( $token ) : '';
	}

	/**
	 * Font Awesome icon value.
	 *
	 * @param string $name Icon name from the allowlist.
	 * @return array<string, string>
	 */
	private function icon( string $name ): array {
		return array(
			'value'   => 'fas fa-' . preg_replace( '/[^a-z0-9\-]/', '', $name ),
			'library' => 'fa-solid',
		);
	}

	/**
	 * Gap value.
	 *
	 * @param int $px Pixels.
	 * @return array<string, mixed>
	 */
	private function gap_value( int $px ): array {
		return array(
			'unit'     => 'px',
			'size'     => $px,
			'column'   => (string) $px,
			'row'      => (string) $px,
			'isLinked' => true,
		);
	}

	/**
	 * Logical alignment -> flex value.
	 *
	 * @param string $align start|center|end.
	 */
	private function flex_align( string $align ): string {
		return array(
			'start'  => 'flex-start',
			'center' => 'center',
			'end'    => 'flex-end',
		)[ $align ] ?? 'flex-start';
	}

	/**
	 * Logical alignment -> physical text alignment respecting direction.
	 *
	 * @param string $align start|center|end.
	 */
	private function phys( string $align ): string {
		if ( 'center' === $align ) {
			return 'center';
		}
		$start = $this->ctx->rtl() ? 'right' : 'left';
		$end   = $this->ctx->rtl() ? 'left' : 'right';
		return 'end' === $align ? $end : $start;
	}

	/**
	 * Container element.
	 *
	 * @param array<string, mixed>             $settings Settings.
	 * @param array<int, array<string, mixed>> $children Children.
	 * @param bool                             $inner    Nested.
	 * @return array<string, mixed>
	 */
	private function container( array $settings, array $children, bool $inner ): array {
		if ( isset( $settings['__globals__'] ) && ! $settings['__globals__'] ) {
			unset( $settings['__globals__'] );
		}
		return array(
			'id'       => $this->id(),
			'elType'   => 'container',
			'isInner'  => $inner,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	/**
	 * Widget element.
	 *
	 * @param string               $type     Widget type.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function widget( string $type, array $settings ): array {
		return array(
			'id'         => $this->id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'isInner'    => false,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}
}
