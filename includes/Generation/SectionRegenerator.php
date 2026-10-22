<?php
/**
 * Regenerates a single section or a single page without rebuilding the site.
 *
 * Only the targeted top-level Elementor element is replaced; every other
 * section (including manual edits) is preserved. If the target itself was
 * edited in Elementor, the job requires explicit overwrite confirmation.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Generation;

use AIThemeStudio\AI\PromptLibrary;
use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DocumentBuilder;
use AIThemeStudio\Elementor\DocumentValidator;
use AIThemeStudio\Validation\PageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Section/page regenerator.
 */
class SectionRegenerator {

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
	 * Pre-flight check run synchronously by the REST endpoint.
	 *
	 * @param array<string, mixed> $project    Project.
	 * @param string               $page_key   Page.
	 * @param string|null          $section_id Section (null = whole page).
	 * @return array{ok: bool, conflict: bool, message: string}
	 */
	public function preflight( array $project, string $page_key, ?string $section_id ): array {
		$def = $this->p->get( 'artifacts' )->latest( (int) $project['id'], 'page', $page_key );
		if ( ! is_array( $def ) ) {
			return array(
				'ok'       => false,
				'conflict' => false,
				'message'  => __( 'این صفحه هنوز تولید نشده است.', 'ai-theme-studio' ),
			);
		}
		if ( null !== $section_id && ! in_array( $section_id, array_column( (array) $def['sections'], 'id' ), true ) ) {
			return array(
				'ok'       => false,
				'conflict' => false,
				'message'  => __( 'بخش موردنظر پیدا نشد.', 'ai-theme-studio' ),
			);
		}
		$post_id = (int) ( $project['pages'][ $page_key ] ?? 0 );
		$writer  = $this->p->get( 'writer' );
		if ( $post_id ) {
			if ( null === $section_id && $writer->is_modified( $post_id ) ) {
				return array(
					'ok'       => true,
					'conflict' => true,
					'message'  => __( 'این صفحه در المنتور ویرایش شده است. بازتولید کامل، ویرایش‌های شما را جایگزین می‌کند (نسخه پشتیبان ذخیره می‌شود).', 'ai-theme-studio' ),
				);
			}
			if ( null !== $section_id ) {
				$map = (array) get_post_meta( $post_id, '_ats_section_map', true );
				if ( isset( $map[ $section_id ] ) && $writer->section_modified( $post_id, (string) $map[ $section_id ] ) ) {
					return array(
						'ok'       => true,
						'conflict' => true,
						'message'  => __( 'این بخش در المنتور ویرایش شده است. بازتولید، ویرایش‌های این بخش را جایگزین می‌کند (نسخه پشتیبان ذخیره می‌شود).', 'ai-theme-studio' ),
					);
				}
			}
		}
		return array(
			'ok'       => true,
			'conflict' => false,
			'message'  => '',
		);
	}

	/**
	 * Job step: regenerate one section.
	 *
	 * @param array<string, mixed> $job     Job (cursor: page_key, section_id, instruction, confirm).
	 * @param array<string, mixed> $project Project.
	 * @throws \RuntimeException On conflict or missing data.
	 */
	public function section( array $job, array $project ): void {
		$c          = (array) $job['cursor'];
		$pid        = (int) $project['id'];
		$page_key   = (string) $c['page_key'];
		$section_id = (string) $c['section_id'];
		$ctx        = new Context( $this->p, $project );
		$artifacts  = $this->p->get( 'artifacts' );
		$def        = $artifacts->latest( $pid, 'page', $page_key );
		$sitemap    = (array) $ctx->artifact( 'sitemap' );
		$check      = $this->preflight( $project, $page_key, $section_id );
		if ( ! $check['ok'] || ( $check['conflict'] && empty( $c['confirm'] ) ) ) {
			throw new \RuntimeException( esc_html( $check['message'] ) );
		}

		$index   = array_search( $section_id, array_column( $def['sections'], 'id' ), true );
		$current = $def['sections'][ $index ];
		$level   = 0 === $index ? 'h1' : 'h2';
		$page    = array();
		foreach ( (array) $sitemap['pages'] as $sp ) {
			if ( $sp['key'] === $page_key ) {
				$page = $sp;
			}
		}

		$keys      = array_column( (array) $sitemap['pages'], 'key' );
		$validator = new PageValidator( $keys, $ctx->asset_refs() );
		$result    = $this->p->get( 'generator' )->generate(
			'section-regeneration',
			array_merge(
				Context::vocabulary(),
				array(
					'section_id'    => $section_id,
					'instruction'   => PromptLibrary::untrusted( (string) $c['instruction'] ),
					'section'       => $current,
					'page'          => array(
						'key'           => $page_key,
						'title'         => $page['title'] ?? '',
						'type'          => $page['type'] ?? '',
						'other_sections' => array_column( $def['sections'], 'id' ),
					),
					'spec'          => $ctx->artifact( 'spec' ),
					'design'        => $ctx->design_summary(),
					'sitemap'       => $ctx->sitemap_summary(),
					'images'        => array_values( array_filter( $ctx->images(), static fn( $i ) => $i['use_as_asset'] && 'logo' !== $i['role'] ) ),
					'heading_level' => strtoupper( $level ),
				)
			),
			'section',
			array(
				'project_id' => $pid,
				'job_id'     => (int) $job['id'],
				'repair'     => 'elementor-validation',
				'validate'   => static function ( array $section ) use ( $def, $index, $section_id, $validator ) {
					$section['id']              = $section_id;
					$page                       = $def;
					$page['sections'][ $index ] = $section;
					$result                     = $validator->validate( $page );
					return array(
						'data'     => $result['page']['sections'][ $index ],
						'errors'   => $result['errors'],
						'warnings' => $result['warnings'],
					);
				},
			)
		);

		$def['sections'][ $index ] = $result['data'];
		$artifacts->save( $pid, 'page', $page_key, $def );
		$artifacts->save(
			$pid,
			'prompt',
			$page_key . '/' . $section_id,
			array(
				'instruction' => (string) $c['instruction'],
				'user'        => get_current_user_id(),
			)
		);

		$post_id = (int) ( $project['pages'][ $page_key ] ?? 0 );
		if ( ! $post_id || ! $this->p->get( 'compat' )->meets_minimum() ) {
			return;
		}
		$writer   = $this->p->get( 'writer' );
		$map      = (array) get_post_meta( $post_id, '_ats_section_map', true );
		$elements = $writer->read( $post_id );
		$builder  = new DocumentBuilder( $ctx->build_context() );
		$built    = $builder->build_section( $result['data'], DocumentValidator::all_ids( $elements ) );

		$element_id = (string) ( $map[ $section_id ] ?? '' );
		if ( ! $element_id || ! $writer->replace_section( $post_id, $element_id, $built['element'], $pid ) ) {
			// The section was deleted in Elementor: rebuild the full page from the definition instead.
			$out = $builder->build_page( $def );
			$writer->write( $post_id, $out['elements'], array( 'project_id' => $pid ) );
			update_post_meta( $post_id, '_ats_section_map', $out['section_map'] );
		}
	}

	/**
	 * Job step: regenerate a full page.
	 *
	 * @param array<string, mixed> $job     Job (cursor: page_key, instruction, confirm).
	 * @param array<string, mixed> $project Project.
	 * @throws \RuntimeException On conflict.
	 */
	public function page( array $job, array $project ): void {
		$c        = (array) $job['cursor'];
		$pid      = (int) $project['id'];
		$page_key = (string) $c['page_key'];
		$ctx      = new Context( $this->p, $project );
		$sitemap  = (array) $ctx->artifact( 'sitemap' );
		$page     = null;
		foreach ( (array) $sitemap['pages'] as $sp ) {
			if ( $sp['key'] === $page_key ) {
				$page = $sp;
			}
		}
		if ( ! $page ) {
			throw new \RuntimeException( esc_html__( 'صفحه در نقشه سایت پیدا نشد.', 'ai-theme-studio' ) );
		}
		$post_id = (int) ( $project['pages'][ $page_key ] ?? 0 );
		$writer  = $this->p->get( 'writer' );
		if ( $post_id && $writer->is_modified( $post_id ) && empty( $c['confirm'] ) ) {
			throw new \RuntimeException( esc_html__( 'این صفحه در المنتور ویرایش شده است؛ برای جایگزینی، بازتولید را با تأیید انجام دهید.', 'ai-theme-studio' ) );
		}

		$def = $this->p->get( 'pipeline' )->generate_page_definition( $job, $ctx, $page, (string) ( $c['instruction'] ?? '' ) );
		$this->p->get( 'artifacts' )->save( $pid, 'page', $page_key, $def );
		if ( ! empty( $c['instruction'] ) ) {
			$this->p->get( 'artifacts' )->save(
				$pid,
				'prompt',
				$page_key,
				array(
					'instruction' => (string) $c['instruction'],
					'user'        => get_current_user_id(),
				)
			);
		}

		if ( $post_id && $this->p->get( 'compat' )->meets_minimum() ) {
			$out = ( new DocumentBuilder( $ctx->build_context() ) )->build_page( $def );
			$writer->write(
				$post_id,
				$out['elements'],
				array(
					'project_id'    => $pid,
					'fallback_html' => $out['fallback_html'],
				)
			);
			update_post_meta( $post_id, '_ats_section_map', $out['section_map'] );
		}
	}
}
