<?php
/**
 * Builds prompt variables and the Elementor build context for a project.
 *
 * Only data needed for generation is sent to the AI provider: the owner's
 * description and preferences, uploaded reference images and generated
 * artifacts. No WordPress users, credentials or unrelated site data.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Generation;

use AIThemeStudio\AI\PromptLibrary;
use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\BuildContext;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Validation\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Generation context.
 */
class Context {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $p;

	/**
	 * Project.
	 *
	 * @var array<string, mixed>
	 */
	private array $project;

	/**
	 * Constructor.
	 *
	 * @param Plugin               $p       Plugin.
	 * @param array<string, mixed> $project Project.
	 */
	public function __construct( Plugin $p, array $project ) {
		$this->p       = $p;
		$this->project = $project;
	}

	/**
	 * Artifact shortcut.
	 *
	 * @param string $type Type.
	 * @param string $key  Key.
	 * @return mixed
	 */
	public function artifact( string $type, string $key = '' ) {
		return $this->p->get( 'artifacts' )->latest( (int) $this->project['id'], $type, $key );
	}

	/**
	 * Project input.
	 *
	 * @return array<string, mixed>
	 */
	public function input(): array {
		return (array) $this->project['input'];
	}

	/**
	 * Site language code.
	 */
	public function language(): string {
		$spec = $this->artifact( 'spec' );
		return (string) ( $spec['language'] ?? $this->input()['language'] ?? 'fa' );
	}

	/**
	 * Direction.
	 */
	public function direction(): string {
		$spec = $this->artifact( 'spec' );
		return (string) ( $spec['direction'] ?? $this->input()['direction'] ?? 'rtl' );
	}

	/**
	 * Owner preferences for prompts (the free-text description is fenced separately).
	 *
	 * @return array<string, mixed>
	 */
	public function preferences(): array {
		$in = $this->input();
		return array(
			'category'        => $in['category'] ?? 'auto',
			'language'        => $in['language'] ?? 'fa',
			'direction'       => $in['direction'] ?? 'rtl',
			'colors'          => $in['colors'] ?? array( 'mode' => 'ai' ),
			'fonts'           => $in['fonts'] ?? array(),
			'style_keywords'  => $in['style'] ?? array(),
			'density'         => $in['density'] ?? 'comfortable',
			'corners'         => $in['corners'] ?? 'rounded',
			'responsive'      => $in['responsive'] ?? array(),
			'requested_pages' => $in['pages'] ?? array(),
			'contact_details' => array_filter( (array) ( $in['business'] ?? array() ) ),
		);
	}

	/**
	 * The owner's description fenced as untrusted data.
	 */
	public function user_prompt(): string {
		return PromptLibrary::untrusted( (string) ( $this->input()['prompt'] ?? '' ) );
	}

	/**
	 * Images with analysis (for page prompts).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function images(): array {
		$analysis = (array) ( $this->artifact( 'image_analysis' )['images'] ?? array() );
		$by_ref   = array();
		foreach ( $analysis as $a ) {
			$by_ref[ $a['ref'] ] = $a;
		}
		$out = array();
		foreach ( (array) $this->project['images'] as $img ) {
			$a     = $by_ref[ $img['ref'] ] ?? array();
			$role  = 'auto' !== $img['role'] ? $img['role'] : ( $a['suggested_role'] ?? 'unknown' );
			$asset = isset( $a['use_as_asset'] ) ? (bool) $a['use_as_asset'] : in_array( $role, array( 'logo', 'hero', 'product', 'team', 'gallery', 'background' ), true );
			if ( ! empty( $a['contains_third_party_branding'] ) ) {
				$asset = false;
			}
			$out[] = array(
				'ref'            => $img['ref'],
				'role'           => $role,
				'use_as_asset'   => $asset,
				'target_section' => $a['target_section'] ?? null,
				'description'    => $a['description'] ?? '',
				'alt'            => $a['alt_text'] ?? '',
				'decorative'     => (bool) ( $a['decorative'] ?? false ),
			);
		}
		return $out;
	}

	/**
	 * Image refs that may be placed on pages (assets only, never reference screenshots).
	 *
	 * @return array<int, string>
	 */
	public function asset_refs(): array {
		return array_values(
			array_map(
				static fn( $i ) => $i['ref'],
				array_filter( $this->images(), static fn( $i ) => $i['use_as_asset'] && 'logo' !== $i['role'] )
			)
		);
	}

	/**
	 * Logo ref, if any.
	 */
	public function logo_ref(): ?string {
		foreach ( $this->images() as $img ) {
			if ( 'logo' === $img['role'] ) {
				return $img['ref'];
			}
		}
		return null;
	}

	/**
	 * Compact sitemap for prompts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sitemap_summary(): array {
		$sitemap = (array) $this->artifact( 'sitemap' );
		return array_map(
			static fn( $p ) => array(
				'key'      => $p['key'],
				'type'     => $p['type'],
				'title'    => $p['title'],
				'parent'   => $p['parent'] ?? null,
				'purpose'  => $p['purpose'] ?? '',
				'sections' => array_column( (array) $p['sections'], 'id' ),
			),
			(array) ( $sitemap['pages'] ?? array() )
		);
	}

	/**
	 * Design summary for prompts.
	 *
	 * @return array<string, mixed>
	 */
	public function design_summary(): array {
		$d = (array) $this->artifact( 'design' );
		return array(
			'style'        => $d['rationale'] ?? '',
			'button_style' => $d['button_style'] ?? 'solid',
			'shadow'       => $d['shadow'] ?? 'soft',
			'spacing'      => $d['spacing'] ?? array(),
		);
	}

	/**
	 * Features available to the generator.
	 *
	 * @return array<string, mixed>
	 */
	public function capabilities(): array {
		$compat = $this->p->get( 'compat' );
		$ok     = $compat->meets_minimum();
		$form   = $ok ? $compat->contact_form() : null;
		return array(
			'contact_form'       => $form ? 'available (' . $form['plugin'] . ')' : 'NOT available — contact_form element will show the owner\'s contact details instead',
			'posts_grid'         => 'available',
			'accordion'          => $ok && $compat->containers_active() && $compat->widget_available( 'nested-accordion' ) ? 'available (collapsible)' : 'rendered as headings + text',
			'elementor_pro'      => $compat->has_pro(),
			'real_contact_data'  => array_filter( $this->business() ),
		);
	}

	/**
	 * Owner-supplied business details (form input wins over extracted spec).
	 *
	 * @return array<string, string|null>
	 */
	public function business(): array {
		$spec = (array) ( $this->artifact( 'spec' )['business'] ?? array() );
		$in   = (array) ( $this->input()['business'] ?? array() );
		return array_merge( $spec, array_filter( $in ) );
	}

	/**
	 * Whether the homepage will become the static front page.
	 */
	public function home_is_front(): bool {
		return ! empty( $this->input()['options']['set_front_page'] );
	}

	/**
	 * Elementor build context.
	 */
	public function build_context(): BuildContext {
		$compat = $this->p->get( 'compat' );
		$pages  = $this->p->get( 'pages' );
		$map    = $this->p->get( 'projects' )->pages( (int) $this->project['id'] );
		$ctx    = new BuildContext( new DesignTokens( (array) $this->artifact( 'design' ) ) );

		$ctx->direction   = $this->direction();
		$ctx->language    = $this->language();
		$ctx->project_id  = (int) $this->project['id'];
		$ctx->containers  = $compat->containers_active();
		$ctx->use_globals = (bool) get_post_meta( (int) $this->project['id'], '_ats_globals_registered', true );
		$ctx->business    = $this->business();
		$form             = $compat->contact_form();
		$ctx->contact_form_shortcode = $form['shortcode'] ?? null;
		$ctx->widget_available       = static fn( string $name ): bool => $compat->widget_available( $name );

		$home_front         = $this->home_is_front();
		$ctx->link_resolver = static function ( string $link ) use ( $map, $pages, $home_front ): string {
			if ( 0 === strpos( $link, 'page:' ) ) {
				$key = substr( $link, 5 );
				if ( 'home' === $key && $home_front ) {
					return home_url( '/' );
				}
				return isset( $map[ $key ] ) ? $pages->future_permalink( (int) $map[ $key ] ) : home_url( '/' );
			}
			if ( '' === $link ) {
				return '#';
			}
			if ( '#' === $link[0] ) {
				return '#' . sanitize_html_class( substr( $link, 1 ) );
			}
			$url = esc_url_raw( $link, array( 'http', 'https', 'mailto', 'tel' ) );
			return $url ? $url : '#';
		};

		$images = array();
		foreach ( $this->images() as $img ) {
			$images[ $img['ref'] ] = $img;
		}
		$attachments = array();
		foreach ( (array) $this->project['images'] as $img ) {
			$attachments[ $img['ref'] ] = (int) $img['id'];
		}
		$ctx->image_resolver = static function ( string $ref ) use ( $attachments, $images, $pages ) {
			if ( ! isset( $attachments[ $ref ] ) ) {
				return null;
			}
			$data = $pages->image_data( $attachments[ $ref ] );
			if ( $data && '' === $data['alt'] && ! empty( $images[ $ref ]['alt'] ) ) {
				$data['alt'] = (string) $images[ $ref ]['alt'];
			}
			return $data;
		};
		return $ctx;
	}

	/**
	 * Common vocabulary variables.
	 *
	 * @return array<string, mixed>
	 */
	public static function vocabulary(): array {
		return array(
			'element_types' => implode( ', ', array_diff( Vocabulary::ELEMENT_TYPES, array() ) ),
			'icons'         => implode( ', ', Vocabulary::ICONS ),
			'page_types'    => implode( ', ', Vocabulary::PAGE_TYPES ),
			'section_types' => implode( ', ', Vocabulary::SECTION_TYPES ),
			'site_types'    => implode( ', ', Vocabulary::SITE_TYPES ),
			'roles'         => implode( ', ', Vocabulary::IMAGE_ROLES ),
		);
	}
}
