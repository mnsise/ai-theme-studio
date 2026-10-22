<?php
/**
 * Semantic validation and safe auto-repair of AI page definitions.
 *
 * Runs after schema validation. Fixes that cannot change meaning (unknown
 * icons, missing levels, unsafe link schemes) are applied automatically and
 * reported as warnings. Problems that need model judgement are returned as
 * errors so the generator can ask the model to repair its output.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Validation;

use AIThemeStudio\SEO\HeadingValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Page validator.
 */
final class PageValidator {

	public const MAX_DEPTH          = 3;
	public const MAX_PAGE_ELEMENTS  = 160;

	/**
	 * Known page keys.
	 *
	 * @var array<int, string>
	 */
	private array $page_keys;

	/**
	 * Known image refs.
	 *
	 * @var array<int, string>
	 */
	private array $image_refs;

	/**
	 * Warnings.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = array();

	/**
	 * Errors.
	 *
	 * @var array<int, string>
	 */
	private array $errors = array();

	/**
	 * Element counter.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Section ids on the current page.
	 *
	 * @var array<int, string>
	 */
	private array $section_ids = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $page_keys  Page keys in the sitemap.
	 * @param array<int, string> $image_refs Uploaded image refs (img_1...).
	 */
	public function __construct( array $page_keys, array $image_refs ) {
		$this->page_keys  = $page_keys;
		$this->image_refs = $image_refs;
	}

	/**
	 * Validate and repair a page definition.
	 *
	 * @param array<string, mixed> $page Page (schema-validated).
	 * @return array{page: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
	 */
	public function validate( array $page ): array {
		$this->warnings    = array();
		$this->errors      = array();
		$this->count       = 0;
		$this->section_ids = array();

		$seen = array();
		foreach ( $page['sections'] as $i => &$section ) {
			$id = $section['id'];
			if ( isset( $seen[ $id ] ) ) {
				$section['id'] = $id . '-' . ( $i + 1 );
				$this->warnings[] = "Duplicate section id \"{$id}\" renamed to \"{$section['id']}\".";
			}
			$seen[ $section['id'] ] = true;
			$this->section_ids[]    = $section['id'];
		}
		unset( $section );

		foreach ( $page['sections'] as $i => &$section ) {
			if ( 'image' === ( $section['background'] ?? '' ) ) {
				$ref = $section['background_image'] ?? null;
				if ( ! $ref || ! in_array( $ref, $this->image_refs, true ) ) {
					$section['background']       = 'surface';
					$section['background_image'] = null;
					$this->warnings[]            = "Section \"{$section['id']}\": background image reference is not an uploaded image; using a surface color instead.";
				}
			}
			$section['elements'] = $this->elements( $section['elements'], "{$section['id']}", 1 );
			if ( ! $section['elements'] ) {
				$this->errors[] = "Section \"{$section['id']}\" has no valid elements.";
			}
		}
		unset( $section );

		if ( $this->count > self::MAX_PAGE_ELEMENTS ) {
			$this->errors[] = "Page has {$this->count} elements; the maximum is " . self::MAX_PAGE_ELEMENTS . '. Simplify the layout.';
		}

		foreach ( HeadingValidator::fix_page( $page ) as $fix ) {
			$this->warnings[] = $fix;
		}

		return array(
			'page'     => $page,
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Validate a list of elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param string                           $path     Path for messages.
	 * @param int                              $depth    Depth.
	 * @return array<int, array<string, mixed>>
	 */
	private function elements( array $elements, string $path, int $depth ): array {
		$out = array();
		foreach ( $elements as $i => $el ) {
			$el = $this->element( $el, "{$path}/{$i}", $depth );
			if ( null !== $el ) {
				$out[] = $el;
			}
		}
		return $out;
	}

	/**
	 * Validate one element. Returns null to drop it.
	 *
	 * @param array<string, mixed> $el    Element.
	 * @param string               $path  Path.
	 * @param int                  $depth Depth.
	 * @return array<string, mixed>|null
	 */
	private function element( array $el, string $path, int $depth ): ?array {
		++$this->count;
		$type = $el['type'];

		switch ( $type ) {
			case 'heading':
				if ( '' === trim( (string) ( $el['text'] ?? '' ) ) ) {
					$this->warnings[] = "{$path}: empty heading removed.";
					return null;
				}
				$el['level'] = $el['level'] ?? 'h2';
				break;

			case 'text':
				if ( '' === trim( wp_strip_all_tags( (string) ( $el['html'] ?? $el['text'] ?? '' ) ) ) ) {
					$this->warnings[] = "{$path}: empty text removed.";
					return null;
				}
				$el['html'] = (string) ( $el['html'] ?? $el['text'] );
				break;

			case 'button':
				if ( '' === trim( (string) ( $el['text'] ?? '' ) ) ) {
					$this->errors[] = "{$path}: button has no text.";
					return null;
				}
				$el['link']  = $this->link( (string) ( $el['link'] ?? '' ), $path );
				$el['style'] = in_array( $el['style'] ?? '', array( 'primary', 'secondary', 'link' ), true ) ? $el['style'] : 'primary';
				break;

			case 'image':
				$ref = $el['image_ref'] ?? null;
				if ( ! $ref || ! in_array( $ref, $this->image_refs, true ) ) {
					$this->warnings[] = "{$path}: image reference \"" . ( $ref ? $ref : 'none' ) . '" is not an uploaded image; element removed (no images are invented).';
					return null;
				}
				$el['decorative'] = (bool) ( $el['decorative'] ?? false );
				if ( ! $el['decorative'] && '' === trim( (string) ( $el['alt'] ?? '' ) ) ) {
					$el['alt'] = null; // Filled from image analysis by the builder.
				}
				break;

			case 'icon_box':
				if ( '' === trim( (string) ( $el['title'] ?? $el['text'] ?? '' ) ) ) {
					$this->warnings[] = "{$path}: icon box without title removed.";
					return null;
				}
				$el['title'] = (string) ( $el['title'] ?? $el['text'] );
				$el['icon']  = $this->icon( $el['icon'] ?? '' );
				break;

			case 'icon_list':
			case 'accordion':
				$items = array();
				foreach ( (array) ( $el['items'] ?? array() ) as $item ) {
					if ( 'accordion' === $type ) {
						if ( '' === trim( (string) ( $item['title'] ?? '' ) ) || '' === trim( (string) ( $item['content'] ?? '' ) ) ) {
							continue;
						}
					} else {
						$item['text'] = (string) ( $item['text'] ?? $item['title'] ?? '' );
						if ( '' === trim( $item['text'] ) ) {
							continue;
						}
						$item['icon'] = $this->icon( $item['icon'] ?? 'check' );
						if ( ! empty( $item['link'] ) ) {
							$item['link'] = $this->link( (string) $item['link'], $path );
						}
					}
					$items[] = $item;
				}
				if ( ! $items ) {
					$this->warnings[] = "{$path}: {$type} without items removed.";
					return null;
				}
				$el['items'] = $items;
				break;

			case 'testimonial':
				// Testimonials can never be verified, so they are always flagged as placeholders.
				$el['placeholder'] = true;
				if ( '' === trim( (string) ( $el['text'] ?? '' ) ) ) {
					return null;
				}
				break;

			case 'group':
				if ( $depth >= self::MAX_DEPTH ) {
					$this->errors[] = "{$path}: groups nested deeper than " . self::MAX_DEPTH . ' levels.';
					return null;
				}
				$el['direction'] = $el['direction'] ?? 'column';
				$el['elements']  = $this->elements( (array) ( $el['elements'] ?? array() ), $path, $depth + 1 );
				if ( ! $el['elements'] ) {
					$this->warnings[] = "{$path}: empty group removed.";
					return null;
				}
				break;

			case 'posts_grid':
				$el['count'] = $el['count'] ?? 6;
				break;
		}
		return $el;
	}

	/**
	 * Normalise a link reference.
	 *
	 * Allowed: page:<key>, #<section-id>, http(s) URLs, mailto:, tel:.
	 *
	 * @param string $link Link.
	 * @param string $path Path.
	 */
	public function link( string $link, string $path ): string {
		$link = trim( $link );
		if ( '' === $link ) {
			return in_array( 'contact', $this->page_keys, true ) ? 'page:contact' : 'page:' . ( $this->page_keys[0] ?? 'home' );
		}
		if ( 0 === strpos( $link, 'page:' ) ) {
			$key = substr( $link, 5 );
			if ( in_array( $key, $this->page_keys, true ) ) {
				return $link;
			}
			$this->warnings[] = "{$path}: link to unknown page \"{$key}\" replaced with the homepage.";
			return 'page:' . ( $this->page_keys[0] ?? 'home' );
		}
		if ( '#' === $link[0] ) {
			return '#' . preg_replace( '/[^a-z0-9\-]/', '', strtolower( substr( $link, 1 ) ) );
		}
		if ( preg_match( '#^(https?://|mailto:|tel:)#i', $link ) ) {
			return $link;
		}
		$this->warnings[] = "{$path}: unsupported link \"{$link}\" replaced with \"#\".";
		return '#';
	}

	/**
	 * Map an icon name to the allowlist.
	 *
	 * @param string $icon Icon.
	 */
	private function icon( string $icon ): string {
		$icon = preg_replace( '/^(fa[srb]?\s+)?fa-/', '', strtolower( trim( $icon ) ) );
		return in_array( $icon, Vocabulary::ICONS, true ) ? $icon : 'check';
	}
}
