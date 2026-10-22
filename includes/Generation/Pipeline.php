<?php
/**
 * Step-based generation pipeline. Each call to run() performs one bounded
 * unit of work (at most one AI request) and returns the next step, so long
 * generations never depend on a single HTTP request. All intermediate
 * results are stored as versioned artifacts, which makes steps resumable and
 * lets retries skip completed work.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Generation;

use AIThemeStudio\AI\AIException;
use AIThemeStudio\Core\Plugin;
use AIThemeStudio\Elementor\DesignTokens;
use AIThemeStudio\Elementor\DocumentBuilder;
use AIThemeStudio\Elementor\TemplateManager;
use AIThemeStudio\Media\ImagePreparer;
use AIThemeStudio\Validation\Contrast;
use AIThemeStudio\Validation\PageValidator;
use AIThemeStudio\Validation\Vocabulary;
use AIThemeStudio\WordPress\MenuManager;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline.
 */
class Pipeline {

	public const STEPS = array( 'analyze_requirements', 'analyze_images', 'create_sitemap', 'design_system', 'generate_pages', 'build_elementor', 'quality_checks' );

	/**
	 * Step labels (UI).
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'analyze_requirements' => __( 'تحلیل نیازمندی‌ها', 'ai-theme-studio' ),
			'analyze_images'       => __( 'تحلیل تصاویر', 'ai-theme-studio' ),
			'create_sitemap'       => __( 'ساخت نقشه سایت', 'ai-theme-studio' ),
			'design_system'        => __( 'تولید سیستم طراحی', 'ai-theme-studio' ),
			'generate_pages'       => __( 'تولید صفحات', 'ai-theme-studio' ),
			'build_elementor'      => __( 'ساخت و اعتبارسنجی ساختار المنتور', 'ai-theme-studio' ),
			'quality_checks'       => __( 'بررسی‌های کیفیت', 'ai-theme-studio' ),
			'done'                 => __( 'آماده بررسی', 'ai-theme-studio' ),
			'regenerate_section'   => __( 'بازتولید بخش', 'ai-theme-studio' ),
			'regenerate_page'      => __( 'بازتولید صفحه', 'ai-theme-studio' ),
		);
	}

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
	 * Run one unit of work.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 * @throws \RuntimeException On non-retryable errors.
	 */
	public function run( array $job ): array {
		$project = $this->p->get( 'projects' )->get( (int) $job['project_id'] );
		if ( ! $project ) {
			throw new \RuntimeException( __( 'پروژه پیدا نشد.', 'ai-theme-studio' ) );
		}
		$ctx = new Context( $this->p, $project );

		switch ( $job['step'] ) {
			case 'analyze_requirements':
				return $this->analyze_requirements( $job, $project, $ctx );
			case 'analyze_images':
				return $this->analyze_images( $job, $project, $ctx );
			case 'create_sitemap':
				return $this->create_sitemap( $job, $project, $ctx );
			case 'design_system':
				return $this->design_system( $job, $project, $ctx );
			case 'generate_pages':
				return $this->generate_pages( $job, $project, $ctx );
			case 'build_elementor':
				return $this->build_elementor( $job, $project, $ctx );
			case 'quality_checks':
				return $this->quality_checks( $job, $project, $ctx );
			case 'regenerate_section':
				$this->p->get( 'regenerator' )->section( $job, $project );
				return $this->finish( $job, $project );
			case 'regenerate_page':
				$this->p->get( 'regenerator' )->page( $job, $project );
				return $this->finish( $job, $project );
		}
		throw new \RuntimeException( 'Unknown step ' . esc_html( (string) $job['step'] ) );
	}

	/**
	 * Transition helper.
	 *
	 * @param array<string, mixed> $job    Job.
	 * @param string               $step   Next step.
	 * @param array<string, mixed> $cursor Cursor additions.
	 * @param string               $status Status.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function next( array $job, string $step, array $cursor = array(), string $status = 'queued' ): array {
		return array(
			'step'   => $step,
			'cursor' => array_merge( (array) $job['cursor'], $cursor ),
			'status' => $status,
		);
	}

	/**
	 * Finish a regeneration job.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function finish( array $job, array $project ): array {
		$restore = (string) ( $job['cursor']['restore_status'] ?? 'ready' );
		$this->p->get( 'projects' )->set_status( (int) $project['id'], $restore );
		return $this->next( $job, 'done', array(), 'completed' );
	}

	/**
	 * Common generator options.
	 *
	 * @param array<string, mixed> $job   Job.
	 * @param array<string, mixed> $extra Extra.
	 * @return array<string, mixed>
	 */
	private function opts( array $job, array $extra = array() ): array {
		return array_merge(
			array(
				'project_id' => (int) $job['project_id'],
				'job_id'     => (int) $job['id'],
			),
			$extra
		);
	}

	/**
	 * Record warnings as project notices/log.
	 *
	 * @param array<string, mixed> $job      Job.
	 * @param array<int, string>   $warnings Warnings.
	 * @param string               $context  Context.
	 */
	private function warn( array $job, array $warnings, string $context ): void {
		foreach ( array_slice( $warnings, 0, 20 ) as $w ) {
			$this->p->get( 'logger' )->warning( $context . ': ' . $w, (int) $job['project_id'], (int) $job['id'] );
		}
	}

	/**
	 * 1. Requirements -> website specification.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function analyze_requirements( array $job, array $project, Context $ctx ): array {
		$in     = $ctx->input();
		$lang   = (string) ( $in['language'] ?? 'fa' );
		$dir    = (string) ( $in['direction'] ?? ( Vocabulary::is_rtl_language( $lang ) ? 'rtl' : 'ltr' ) );
		$images = array_map(
			static fn( $i ) => array(
				'ref'      => $i['ref'],
				'role'     => $i['role'],
				'filename' => wp_basename( (string) get_attached_file( (int) $i['id'] ) ),
			),
			(array) $project['images']
		);

		$result = $this->p->get( 'generator' )->generate(
			'planning',
			array_merge(
				Context::vocabulary(),
				array(
					'user_prompt'   => $ctx->user_prompt(),
					'preferences'   => $ctx->preferences(),
					'image_summary' => $images ? $images : 'none',
					'site_language' => $lang,
					'direction'     => $dir,
				)
			),
			'site_spec',
			$this->opts(
				$job,
				array(
					'validate' => static function ( array $data ) use ( $lang, $dir, $in ) {
						$data['language']  = $lang;
						$data['direction'] = $dir;
						if ( ! empty( $in['category'] ) && in_array( $in['category'], Vocabulary::SITE_TYPES, true ) ) {
							$data['site_type'] = $in['category'];
						}
						$pages = array_values( array_unique( array_merge( array( 'home' ), (array) $data['required_pages'], (array) ( $in['pages'] ?? array() ) ) ) );
						if ( ! in_array( 'not_found', $pages, true ) ) {
							$pages[] = 'not_found';
						}
						$data['required_pages'] = array_values( array_intersect( $pages, Vocabulary::PAGE_TYPES ) );
						return array(
							'data'     => $data,
							'errors'   => array(),
							'warnings' => array(),
						);
					},
				)
			)
		);
		$this->p->get( 'artifacts' )->save( (int) $project['id'], 'spec', '', $result['data'] );
		foreach ( (array) ( $result['data']['warnings'] ?? array() ) as $w ) {
			$this->p->get( 'projects' )->add_notice( (int) $project['id'], (string) $w );
		}
		return $this->next( $job, 'analyze_images' );
	}

	/**
	 * 2. Reference image analysis (vision).
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function analyze_images( array $job, array $project, Context $ctx ): array {
		$images = (array) $project['images'];
		$pid    = (int) $project['id'];
		if ( ! $images ) {
			$this->p->get( 'artifacts' )->save( $pid, 'image_analysis', '', array( 'images' => array(), 'overall' => array(), 'status' => 'no_images' ) );
			return $this->next( $job, 'create_sitemap' );
		}

		$provider = $this->p->get( 'generator' )->provider();
		if ( ! $provider->supports_vision() ) {
			$msg = sprintf(
				/* translators: 1: provider, 2: model */
				__( 'مدل %2$s در سرویس %1$s از تحلیل تصویر پشتیبانی نمی‌کند (یا در تنظیمات غیرفعال است). تصاویر تحلیل نشدند؛ فقط بر اساس نقشی که شما تعیین کرده‌اید استفاده می‌شوند و متن جایگزین باید دستی تکمیل شود.', 'ai-theme-studio' ),
				$provider->id(),
				$provider->model()
			);
			$this->p->get( 'projects' )->add_notice( $pid, $msg );
			$this->p->get( 'logger' )->warning( $msg, $pid, (int) $job['id'] );
			$this->p->get( 'artifacts' )->save( $pid, 'image_analysis', '', array( 'images' => array(), 'overall' => array(), 'status' => 'vision_unavailable' ) );
			return $this->next( $job, 'create_sitemap' );
		}

		$payload = array();
		$list    = array();
		foreach ( $images as $img ) {
			$prepared = ImagePreparer::prepare( (int) $img['id'], $img['ref'] );
			if ( ! $prepared ) {
				/* translators: %s: image ref */
				$this->p->get( 'projects' )->add_notice( $pid, sprintf( __( 'تصویر %s قابل آماده‌سازی برای تحلیل نبود و نادیده گرفته شد.', 'ai-theme-studio' ), $img['ref'] ) );
				continue;
			}
			$payload[] = $prepared;
			$list[]    = array(
				'ref'        => $img['ref'],
				'owner_role' => $img['role'],
				'filename'   => wp_basename( (string) get_attached_file( (int) $img['id'] ) ),
			);
		}

		$refs   = array_column( $list, 'ref' );
		$result = $this->p->get( 'generator' )->generate(
			'image-analysis',
			array_merge(
				Context::vocabulary(),
				array(
					'image_count'   => count( $payload ),
					'images'        => $list,
					'user_prompt'   => $ctx->user_prompt(),
					'site_language' => $ctx->language(),
				)
			),
			'image_analysis',
			$this->opts(
				$job,
				array(
					'images'   => $payload,
					'validate' => static function ( array $data ) use ( $refs, $list ) {
						$roles  = array_column( $list, 'owner_role', 'ref' );
						$kept   = array();
						foreach ( (array) $data['images'] as $img ) {
							if ( in_array( $img['ref'], $refs, true ) ) {
								if ( isset( $roles[ $img['ref'] ] ) && 'auto' !== $roles[ $img['ref'] ] ) {
									$img['suggested_role'] = $roles[ $img['ref'] ];
								}
								$kept[ $img['ref'] ] = $img;
							}
						}
						$missing = array_diff( $refs, array_keys( $kept ) );
						$data['images'] = array_values( $kept );
						return array(
							'data'     => $data,
							'errors'   => $missing ? array( 'Missing analysis for: ' . implode( ', ', $missing ) ) : array(),
							'warnings' => array(),
						);
					},
				)
			)
		);
		$data           = $result['data'];
		$data['status'] = 'analyzed';
		$this->p->get( 'artifacts' )->save( $pid, 'image_analysis', '', $data );

		$by_ref = array_column( (array) $project['images'], 'id', 'ref' );
		foreach ( $data['images'] as $img ) {
			$att = (int) ( $by_ref[ $img['ref'] ] ?? 0 );
			if ( $att && empty( $img['decorative'] ) && '' !== $img['alt_text'] && '' === (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ) {
				update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( $img['alt_text'] ) );
			}
			if ( ! empty( $img['contains_third_party_branding'] ) ) {
				/* translators: %s: image ref */
				$this->p->get( 'projects' )->add_notice( $pid, sprintf( __( 'تصویر %s شامل نشان تجاری دیگران است؛ فقط به‌عنوان الهام طراحی استفاده شد و در سایت قرار نگرفت.', 'ai-theme-studio' ), $img['ref'] ) );
			}
		}
		return $this->next( $job, 'create_sitemap' );
	}

	/**
	 * 3. Sitemap and navigation proposal.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function create_sitemap( array $job, array $project, Context $ctx ): array {
		$spec     = (array) $ctx->artifact( 'spec' );
		$analysis = (array) $ctx->artifact( 'image_analysis' );
		$allowed  = array_merge( (array) $spec['required_pages'], array( 'service', 'product', 'case_study' ) );

		$result = $this->p->get( 'generator' )->generate(
			'sitemap',
			array_merge(
				Context::vocabulary(),
				array(
					'spec'           => $spec,
					'image_analysis' => array(
						'overall' => $analysis['overall'] ?? array(),
						'images'  => $ctx->images(),
					),
					'preferences'    => $ctx->preferences(),
				)
			),
			'sitemap',
			$this->opts(
				$job,
				array( 'validate' => static fn( array $data ) => self::validate_sitemap( $data, $allowed ) )
			)
		);
		$pid = (int) $project['id'];
		$this->p->get( 'artifacts' )->save( $pid, 'sitemap', '', $result['data'] );
		$this->p->get( 'projects' )->set( $pid, 'nav_proposal', MenuManager::proposal_from_sitemap( $result['data'] ) );
		$this->warn( $job, $result['warnings'], 'sitemap' );

		if ( ! empty( $ctx->input()['options']['review_sitemap'] ) ) {
			$this->p->get( 'projects' )->set_status( $pid, 'review_sitemap' );
			return $this->next( $job, 'design_system', array(), 'waiting' );
		}
		return $this->next( $job, 'design_system' );
	}

	/**
	 * Sitemap semantic validation (public for the sitemap editor endpoint).
	 *
	 * @param array<string, mixed> $data    Sitemap.
	 * @param array<int, string>   $allowed Allowed page types.
	 * @return array{data: array<string, mixed>, errors: array<int, string>, warnings: array<int, string>}
	 */
	public static function validate_sitemap( array $data, array $allowed ): array {
		$errors   = array();
		$warnings = array();
		$keys     = array();
		$slugs    = array();
		$pages    = array();
		foreach ( (array) $data['pages'] as $page ) {
			if ( isset( $keys[ $page['key'] ] ) ) {
				$warnings[] = "Duplicate page key {$page['key']} removed.";
				continue;
			}
			if ( $allowed && ! in_array( $page['type'], $allowed, true ) && 'custom' !== $page['type'] ) {
				$warnings[] = "Page {$page['key']} of type {$page['type']} was not requested and was removed.";
				continue;
			}
			$slug = sanitize_title( (string) $page['slug'] );
			$base = $slug;
			$n    = 2;
			while ( isset( $slugs[ $slug ] ) ) {
				$slug = $base . '-' . $n++;
			}
			$slugs[ $slug ] = true;
			$page['slug']   = $slug;
			if ( in_array( $page['type'], array( 'not_found', 'privacy', 'terms' ), true ) ) {
				$page['in_menu'] = false;
			}
			if ( 'not_found' === $page['type'] ) {
				$page['noindex'] = true;
				$page['key']     = 'not_found';
			}
			$sids = array();
			foreach ( $page['sections'] as $i => $s ) {
				if ( isset( $sids[ $s['id'] ] ) ) {
					$page['sections'][ $i ]['id'] = $s['id'] . '-' . ( $i + 1 );
				}
				$sids[ $page['sections'][ $i ]['id'] ] = true;
			}
			$keys[ $page['key'] ] = true;
			$pages[]              = $page;
		}
		if ( ! isset( $keys['home'] ) ) {
			$errors[] = 'The sitemap must contain the homepage with key "home".';
		}
		foreach ( $pages as &$page ) {
			if ( ! empty( $page['parent'] ) && ( ! isset( $keys[ $page['parent'] ] ) || $page['parent'] === $page['key'] ) ) {
				$page['parent'] = null;
			}
			if ( 'home' === $page['key'] ) {
				$page['type']   = 'home';
				$page['parent'] = null;
				$page['order']  = 0;
			}
		}
		unset( $page );
		$data['pages'] = $pages;
		if ( ! empty( $data['header_cta']['page_key'] ) && ! isset( $keys[ $data['header_cta']['page_key'] ] ) ) {
			$data['header_cta']['page_key'] = isset( $keys['contact'] ) ? 'contact' : 'home';
		}
		return array(
			'data'     => $data,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * 4. Global design system.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function design_system( array $job, array $project, Context $ctx ): array {
		$this->p->get( 'projects' )->set_status( (int) $project['id'], 'generating' );
		$lang    = $ctx->language();
		$fonts   = Vocabulary::fonts_for( $lang );
		$in      = $ctx->input();
		$attempt = 0;

		$validate = static function ( array $data ) use ( &$attempt, $fonts, $in ) {
			++$attempt;
			$warnings = array();
			foreach ( array( 'heading_font', 'body_font' ) as $f ) {
				$wanted = (string) ( $in['fonts'][ 'heading_font' === $f ? 'heading' : 'body' ] ?? '' );
				if ( $wanted && 'auto' !== $wanted && in_array( $wanted, $fonts, true ) ) {
					$data['typography'][ $f ] = $wanted;
				}
				if ( ! in_array( $data['typography'][ $f ], $fonts, true ) ) {
					$warnings[]               = "Font {$data['typography'][$f]} is not supported for this language; using {$fonts[0]}.";
					$data['typography'][ $f ] = $fonts[0];
				}
			}
			if ( 'custom' === ( $in['colors']['mode'] ?? '' ) ) {
				foreach ( array( 'primary', 'secondary', 'background' ) as $c ) {
					$hex = (string) ( $in['colors'][ $c ] ?? '' );
					if ( preg_match( '/^#[0-9A-Fa-f]{6}$/', $hex ) ) {
						$data['colors'][ $c ] = strtoupper( $hex );
					}
				}
			}
			$fails = Contrast::failures( $data['colors'] );
			if ( $fails && 1 === $attempt ) {
				return array(
					'data'     => $data,
					'errors'   => array_map( static fn( $f ) => sprintf( 'contrast: %s on %s is %.2f:1 (needs %s:1)', $f['fg'], $f['bg'], $f['ratio'], 'primary' === $f['fg'] ? '3' : '4.5' ), $fails ),
					'warnings' => $warnings,
				);
			}
			if ( $fails ) {
				$fixed          = Contrast::fix_all( $data['colors'] );
				$data['colors'] = $fixed['colors'];
				$warnings       = array_merge( $warnings, $fixed['changes'] );
			}
			return array(
				'data'     => $data,
				'errors'   => array(),
				'warnings' => $warnings,
			);
		};

		$result = $this->p->get( 'generator' )->generate(
			'design-system',
			array(
				'spec'          => $ctx->artifact( 'spec' ),
				'image_overall' => $ctx->artifact( 'image_analysis' )['overall'] ?? array(),
				'preferences'   => $ctx->preferences(),
				'fonts'         => implode( ', ', $fonts ),
			),
			'design_system',
			$this->opts(
				$job,
				array(
					'validate' => $validate,
					'repair'   => static fn( array $errors ) => preg_grep( '/^contrast:/', $errors ) ? 'accessibility-validation' : 'repair',
				)
			)
		);
		$pid = (int) $project['id'];
		$this->p->get( 'artifacts' )->save( $pid, 'design', '', $result['data'] );
		$this->warn( $job, $result['warnings'], 'design' );

		if ( $this->p->get( 'compat' )->meets_minimum() && ! empty( $in['options']['apply_globals'] ) ) {
			if ( $this->p->get( 'kit' )->register_globals( new DesignTokens( $result['data'] ), $pid ) ) {
				update_post_meta( $pid, '_ats_globals_registered', 1 );
			}
		}
		return $this->next( $job, 'generate_pages' );
	}

	/**
	 * 5. Page definitions (one page per run).
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function generate_pages( array $job, array $project, Context $ctx ): array {
		$sitemap = (array) $ctx->artifact( 'sitemap' );
		$done    = (array) ( $job['cursor']['pages_done'] ?? array() );
		$next    = null;
		foreach ( (array) $sitemap['pages'] as $page ) {
			if ( ! in_array( $page['key'], $done, true ) ) {
				$next = $page;
				break;
			}
		}
		if ( ! $next ) {
			return $this->next( $job, 'build_elementor' );
		}

		// Pages edited by the user keep their definition so it stays in sync with the preserved document.
		$post_id = (int) ( $project['pages'][ $next['key'] ] ?? 0 );
		if ( $post_id && empty( $job['cursor']['force'] ) && $this->p->get( 'writer' )->is_modified( $post_id )
			&& null !== $this->p->get( 'artifacts' )->latest( (int) $project['id'], 'page', (string) $next['key'] ) ) {
			$this->p->get( 'logger' )->info( 'Skipped generation of user-edited page: ' . $next['key'], (int) $project['id'], (int) $job['id'] );
			$done[] = $next['key'];
			return $this->next( $job, 'generate_pages', array( 'pages_done' => $done ) );
		}

		$def = $this->generate_page_definition( $job, $ctx, $next, '' );
		$this->p->get( 'artifacts' )->save( (int) $project['id'], 'page', (string) $next['key'], $def );
		$done[] = $next['key'];
		return $this->next( $job, 'generate_pages', array( 'pages_done' => $done ) );
	}

	/**
	 * Generate and validate one page definition (also used by page regeneration).
	 *
	 * @param array<string, mixed> $job         Job.
	 * @param Context              $ctx         Context.
	 * @param array<string, mixed> $page        Sitemap page.
	 * @param string               $instruction Optional extra instruction.
	 * @return array<string, mixed>
	 * @throws AIException On failure.
	 */
	public function generate_page_definition( array $job, Context $ctx, array $page, string $instruction ): array {
		$sitemap   = (array) $ctx->artifact( 'sitemap' );
		$keys      = array_column( (array) $sitemap['pages'], 'key' );
		$validator = new PageValidator( $keys, $ctx->asset_refs() );
		$key       = (string) $page['key'];
		$page_vars = $page;
		if ( '' !== $instruction ) {
			$page_vars['owner_instruction_for_this_page'] = \AIThemeStudio\AI\PromptLibrary::untrusted( $instruction );
		}

		$result = $this->p->get( 'generator' )->generate(
			'page',
			array_merge(
				Context::vocabulary(),
				array(
					'spec'         => $ctx->artifact( 'spec' ),
					'design'       => $ctx->design_summary(),
					'sitemap'      => $ctx->sitemap_summary(),
					'page'         => $page_vars,
					'images'       => array_values( array_filter( $ctx->images(), static fn( $i ) => $i['use_as_asset'] && 'logo' !== $i['role'] ) ),
					'capabilities' => $ctx->capabilities(),
				)
			),
			'page',
			$this->opts(
				$job,
				array(
					'validate' => static function ( array $data ) use ( $validator, $key ) {
						$data['key'] = $key;
						$result      = $validator->validate( $data );
						return array(
							'data'     => $result['page'],
							'errors'   => $result['errors'],
							'warnings' => $result['warnings'],
						);
					},
					'repair'   => static fn( array $errors ) => preg_grep( '/elements; the maximum/', $errors ) ? 'performance-optimization' : 'elementor-validation',
				)
			)
		);
		$this->p->get( 'artifacts' )->save( (int) $job['project_id'], 'validation', $key, array( 'warnings' => $result['warnings'] ) );
		$this->warn( $job, $result['warnings'], 'page ' . $key );
		return $result['data'];
	}

	/**
	 * 6. Elementor documents, header/footer templates and SEO metadata.
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 * @throws \RuntimeException When Elementor is unavailable.
	 */
	private function build_elementor( array $job, array $project, Context $ctx ): array {
		$compat = $this->p->get( 'compat' );
		if ( ! $compat->meets_minimum() ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: minimum version */
					__( 'برای ساخت صفحات، المنتور نسخه %s یا بالاتر باید نصب و فعال باشد. طرح و محتوای تولیدشده ذخیره شده است؛ پس از فعال‌سازی المنتور «ادامه/تلاش مجدد» را بزنید.', 'ai-theme-studio' ),
					ATS_MIN_ELEMENTOR
				)
			);
		}

		$pid      = (int) $project['id'];
		$sitemap  = (array) $ctx->artifact( 'sitemap' );
		$phase    = (string) ( $job['cursor']['build_phase'] ?? 'shells' );
		$projects = $this->p->get( 'projects' );
		$pages    = $this->p->get( 'pages' );

		if ( 'shells' === $phase ) {
			$ordered = (array) $sitemap['pages'];
			usort( $ordered, static fn( $a, $b ) => ( empty( $a['parent'] ) ? 0 : 1 ) <=> ( empty( $b['parent'] ) ? 0 : 1 ) );
			$map = $projects->pages( $pid );
			foreach ( $ordered as $page ) {
				$parent_id = ! empty( $page['parent'] ) ? (int) ( $map[ $page['parent'] ] ?? 0 ) : 0;
				$id        = $pages->ensure( $pid, $page, $parent_id, (int) ( $map[ $page['key'] ] ?? 0 ) );
				$projects->set_page( $pid, (string) $page['key'], $id );
				$map[ $page['key'] ] = $id;
			}
			$images = $ctx->images();
			$logo   = $ctx->logo_ref();
			$att    = array_column( (array) $project['images'], 'id', 'ref' );
			$spec   = (array) $ctx->artifact( 'spec' );
			update_post_meta(
				$pid,
				'_ats_schema_facts',
				array(
					'name'    => (string) ( $spec['site_name'] ?? '' ),
					'logo_id' => $logo ? (int) ( $att[ $logo ] ?? 0 ) : 0,
					'email'   => (string) ( $ctx->business()['email'] ?? '' ),
					'phone'   => (string) ( $ctx->business()['phone'] ?? '' ),
				)
			);
			unset( $images );
			return $this->next( $job, 'build_elementor', array( 'build_phase' => 'pages', 'built' => array() ) );
		}

		if ( 'pages' === $phase ) {
			$built   = (array) ( $job['cursor']['built'] ?? array() );
			$force   = ! empty( $job['cursor']['force'] );
			$map     = $projects->pages( $pid );
			$builder = new DocumentBuilder( $ctx->build_context() );
			$writer  = $this->p->get( 'writer' );
			$count   = 0;
			foreach ( (array) $sitemap['pages'] as $page ) {
				$key = (string) $page['key'];
				if ( in_array( $key, $built, true ) ) {
					continue;
				}
				if ( $count >= 4 ) {
					return $this->next( $job, 'build_elementor', array( 'built' => $built ) );
				}
				++$count;
				$post_id = (int) ( $map[ $key ] ?? 0 );
				$def     = $ctx->artifact( 'page', $key );
				if ( ! $post_id || ! is_array( $def ) ) {
					$built[] = $key;
					continue;
				}
				if ( ! $force && $writer->is_modified( $post_id ) ) {
					/* translators: %s: page title */
					$projects->add_notice( $pid, sprintf( __( 'صفحه «%s» پس از تولید در المنتور ویرایش شده بود؛ برای حفظ تغییرات شما بازنویسی نشد.', 'ai-theme-studio' ), get_the_title( $post_id ) ) );
					$built[] = $key;
					continue;
				}
				$out = $builder->build_page( $def );
				$writer->write(
					$post_id,
					$out['elements'],
					array(
						'template'      => $project['theme_builder'] ? 'elementor_header_footer' : 'elementor_canvas',
						'fallback_html' => $out['fallback_html'],
						'project_id'    => $pid,
					)
				);
				update_post_meta( $post_id, '_ats_section_map', $out['section_map'] );
				$this->warn( $job, $out['warnings'], 'build ' . $key );
				$built[] = $key;
			}
			return $this->next( $job, 'build_elementor', array( 'build_phase' => 'templates', 'built' => $built ) );
		}

		if ( 'templates' === $phase ) {
			$spec      = (array) $ctx->artifact( 'spec' );
			$builder   = new DocumentBuilder( $ctx->build_context() );
			$templates = $this->p->get( 'templates' );
			$force     = ! empty( $job['cursor']['force'] );
			$name      = (string) ( $spec['site_name'] ?? $project['name'] );

			$header = $templates->save_template( (int) $project['header_id'], 'Header — ' . $name, array( TemplateManager::header_definition( $spec, $sitemap, $ctx->logo_ref() ) ), $builder, $pid, $force );
			$footer = $templates->save_template( (int) $project['footer_id'], 'Footer — ' . $name, TemplateManager::footer_definitions( $spec, $sitemap ), $builder, $pid, $force );
			$projects->set( $pid, 'header_id', $header['id'] );
			$projects->set( $pid, 'footer_id', $footer['id'] );
			if ( $header['skipped'] || $footer['skipped'] ) {
				$projects->add_notice( $pid, __( 'هدر یا فوتر پس از تولید ویرایش شده بود و بازنویسی نشد.', 'ai-theme-studio' ) );
			}
			return $this->next( $job, 'build_elementor', array( 'build_phase' => 'meta' ) );
		}

		$map  = $projects->pages( $pid );
		$meta = $this->p->get( 'seo_meta' );
		foreach ( (array) $sitemap['pages'] as $page ) {
			$post_id = (int) ( $map[ $page['key'] ] ?? 0 );
			if ( $post_id ) {
				$meta->write( $post_id, (string) $page['seo']['title'], (string) $page['seo']['description'], ! empty( $page['noindex'] ) );
			}
		}
		return $this->next( $job, 'quality_checks', array( 'build_phase' => 'done' ) );
	}

	/**
	 * 7. SEO, accessibility and performance checks (+ one AI metadata fix pass).
	 *
	 * @param array<string, mixed> $job     Job.
	 * @param array<string, mixed> $project Project.
	 * @param Context              $ctx     Context.
	 * @return array{step: string, cursor: array<string, mixed>, status: string}
	 */
	private function quality_checks( array $job, array $project, Context $ctx ): array {
		$pid    = (int) $project['id'];
		$report = ( new QualityReport( $this->p ) )->run( $pid );

		$fixable = array_filter( $report['seo']['issues'], static fn( $i ) => in_array( $i['code'], array( 'duplicate_title', 'duplicate_description', 'long_title', 'missing_description', 'missing_title' ), true ) );
		if ( $fixable && empty( $job['cursor']['seo_fixed'] ) ) {
			try {
				$this->fix_seo_metadata( $job, $ctx, array_values( $fixable ) );
				$report = ( new QualityReport( $this->p ) )->run( $pid );
			} catch ( AIException $e ) {
				$this->p->get( 'logger' )->warning( 'SEO metadata fix failed: ' . $e->getMessage(), $pid, (int) $job['id'] );
			}
		}

		$this->p->get( 'projects' )->set_status( $pid, 'ready' );
		return $this->next( $job, 'done', array( 'seo_fixed' => true ), 'completed' );
	}

	/**
	 * Ask the model to rewrite problematic metadata, then store it.
	 *
	 * @param array<string, mixed>             $job    Job.
	 * @param Context                          $ctx    Context.
	 * @param array<int, array<string, mixed>> $issues Issues.
	 */
	private function fix_seo_metadata( array $job, Context $ctx, array $issues ): void {
		$sitemap = (array) $ctx->artifact( 'sitemap' );
		$spec    = (array) $ctx->artifact( 'spec' );
		$result  = $this->p->get( 'generator' )->generate(
			'seo-validation',
			array(
				'language'  => $ctx->language(),
				'site_name' => (string) ( $spec['site_name'] ?? '' ),
				'issues'    => array_map( static fn( $i ) => array( 'code' => $i['code'], 'page' => $i['page'], 'message' => $i['message'] ), $issues ),
				'pages'     => array_map(
					static fn( $p ) => array(
						'key'         => $p['key'],
						'type'        => $p['type'],
						'title'       => $p['seo']['title'],
						'description' => $p['seo']['description'],
						'sections'    => array_column( (array) $p['sections'], 'summary' ),
					),
					(array) $sitemap['pages']
				),
			),
			'seo_fix',
			$this->opts( $job )
		);
		$fixes = array_column( (array) $result['data']['pages'], null, 'key' );
		foreach ( $sitemap['pages'] as &$page ) {
			if ( isset( $fixes[ $page['key'] ] ) ) {
				$page['seo']['title']       = $fixes[ $page['key'] ]['title'];
				$page['seo']['description'] = $fixes[ $page['key'] ]['description'];
			}
		}
		unset( $page );
		$pid = (int) $job['project_id'];
		$this->p->get( 'artifacts' )->save( $pid, 'sitemap', '', $sitemap );

		$map  = $this->p->get( 'projects' )->pages( $pid );
		$meta = $this->p->get( 'seo_meta' );
		foreach ( $sitemap['pages'] as $page ) {
			if ( isset( $fixes[ $page['key'] ], $map[ $page['key'] ] ) ) {
				$meta->write( (int) $map[ $page['key'] ], $page['seo']['title'], $page['seo']['description'], ! empty( $page['noindex'] ) );
			}
		}
	}
}
