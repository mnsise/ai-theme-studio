<?php
/**
 * Main plugin class: service container and hook registration.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Core;

use AIThemeStudio\Admin\AdminPage;
use AIThemeStudio\AI\PromptLibrary;
use AIThemeStudio\AI\ProviderFactory;
use AIThemeStudio\AI\StructuredGenerator;
use AIThemeStudio\Database\ArtifactRepository;
use AIThemeStudio\Database\JobRepository;
use AIThemeStudio\Database\Schema;
use AIThemeStudio\Elementor\CanvasLayout;
use AIThemeStudio\Elementor\Compatibility;
use AIThemeStudio\Elementor\DocumentWriter;
use AIThemeStudio\Elementor\KitManager;
use AIThemeStudio\Elementor\TemplateManager;
use AIThemeStudio\Elementor\Widgets\Registrar;
use AIThemeStudio\Generation\Pipeline;
use AIThemeStudio\Generation\Publisher;
use AIThemeStudio\Generation\QualityReport;
use AIThemeStudio\Generation\SectionRegenerator;
use AIThemeStudio\Jobs\Runner;
use AIThemeStudio\Media\ImageUploader;
use AIThemeStudio\Performance\Auditor as PerformanceAuditor;
use AIThemeStudio\Performance\FrontendAssets;
use AIThemeStudio\Performance\PageSpeedClient;
use AIThemeStudio\REST\Routes;
use AIThemeStudio\SEO\Auditor as SeoAuditor;
use AIThemeStudio\SEO\MetaManager;
use AIThemeStudio\SEO\MetaOutput;
use AIThemeStudio\SEO\SeoPluginDetector;
use AIThemeStudio\WordPress\MenuManager;
use AIThemeStudio\WordPress\NotFoundHandler;
use AIThemeStudio\WordPress\PageManager;
use AIThemeStudio\WordPress\PostTypes;
use AIThemeStudio\WordPress\ProjectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap and lightweight dependency container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Resolved services.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Service factories.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor registers factories.
	 */
	private function __construct() {
		$this->register_factories();
	}

	/**
	 * Register service factories. Each service is created once on first use.
	 */
	private function register_factories(): void {
		$this->factories = array(
			'settings'      => fn() => new Settings(),
			'logger'        => fn() => new Logger(),
			'jobs'          => fn() => new JobRepository(),
			'artifacts'     => fn() => new ArtifactRepository(),
			'projects'      => fn() => new ProjectRepository(),
			'compat'        => fn() => new Compatibility(),
			'seo_detector'  => fn() => new SeoPluginDetector(),
			'prompts'       => fn() => new PromptLibrary( ATS_DIR . 'prompts' ),
			'providers'     => fn() => new ProviderFactory( $this->get( 'settings' ) ),
			'generator'     => fn() => new StructuredGenerator( $this->get( 'providers' ), $this->get( 'prompts' ), $this->get( 'logger' ) ),
			'pages'         => fn() => new PageManager(),
			'menus'         => fn() => new MenuManager( $this->get( 'projects' ) ),
			'writer'        => fn() => new DocumentWriter( $this->get( 'compat' ), $this->get( 'artifacts' ) ),
			'kit'           => fn() => new KitManager( $this->get( 'compat' ), $this->get( 'artifacts' ) ),
			'templates'     => fn() => new TemplateManager( $this->get( 'compat' ), $this->get( 'writer' ) ),
			'seo_meta'      => fn() => new MetaManager( $this->get( 'seo_detector' ) ),
			'seo_auditor'   => fn() => new SeoAuditor(),
			'perf_auditor'  => fn() => new PerformanceAuditor( $this->get( 'compat' ) ),
			'pagespeed'     => fn() => new PageSpeedClient( $this->get( 'settings' ) ),
			'uploader'      => fn() => new ImageUploader( $this->get( 'settings' ) ),
			'pipeline'      => fn() => new Pipeline( $this ),
			'publisher'     => fn() => new Publisher( $this ),
			'quality'       => fn() => new QualityReport( $this ),
			'regenerator'   => fn() => new SectionRegenerator( $this ),
			'runner'        => fn() => new Runner( $this->get( 'jobs' ), $this->get( 'pipeline' ), $this->get( 'logger' ) ),
		);
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \InvalidArgumentException When the service is unknown.
	 */
	public function get( string $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Unknown service: ' . $id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$this->services[ $id ] = ( $this->factories[ $id ] )();
		}
		return $this->services[ $id ];
	}

	/**
	 * Replace a service (used by tests and extensions).
	 *
	 * @param string $id      Service id.
	 * @param object $service Instance.
	 */
	public function set( string $id, $service ): void {
		$this->services[ $id ] = $service;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'ai-theme-studio', false, dirname( plugin_basename( ATS_FILE ) ) . '/languages' );

		Schema::maybe_upgrade();

		add_action( 'init', array( PostTypes::class, 'register' ) );
		add_action( 'rest_api_init', fn() => ( new Routes( $this ) )->register() );
		add_action( Runner::CRON_HOOK, fn( $job_id ) => $this->get( 'runner' )->run_cron( (int) $job_id ), 10, 1 );
		add_action( 'ats_daily_cleanup', fn() => $this->get( 'logger' )->purge( 30 ) );
		add_action( 'elementor/widgets/register', array( Registrar::class, 'register' ) );

		if ( is_admin() ) {
			( new AdminPage( $this ) )->register();
		}

		( new CanvasLayout( $this ) )->register();
		( new MetaOutput( $this ) )->register();
		( new NotFoundHandler( $this ) )->register();
		( new FrontendAssets( $this ) )->register();
	}
}
