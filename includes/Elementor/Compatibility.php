<?php
/**
 * Elementor feature detection. All Elementor-specific checks live here so the
 * rest of the plugin never assumes an API, widget or Pro feature exists.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility layer.
 */
class Compatibility {

	/**
	 * Cached widget availability.
	 *
	 * @var array<string, bool>
	 */
	private array $widgets = array();

	/**
	 * Elementor plugin files exist (installed but maybe inactive).
	 */
	public function is_installed(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || file_exists( WP_PLUGIN_DIR . '/elementor/elementor.php' );
	}

	/**
	 * Elementor is loaded.
	 */
	public function is_active(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Installed version.
	 */
	public function version(): string {
		return defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
	}

	/**
	 * Version satisfies the plugin minimum.
	 */
	public function meets_minimum(): bool {
		return $this->is_active() && version_compare( $this->version(), ATS_MIN_ELEMENTOR, '>=' );
	}

	/**
	 * Elementor Pro is active.
	 */
	public function has_pro(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Pro version.
	 */
	public function pro_version(): string {
		return defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '';
	}

	/**
	 * Flexbox Container support. If the "container" experiment is no longer
	 * registered (feature graduated into core) containers are always on.
	 */
	public function containers_active(): bool {
		if ( ! $this->meets_minimum() ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( ! isset( $plugin->experiments ) ) {
			return true;
		}
		$experiments = $plugin->experiments;
		if ( method_exists( $experiments, 'get_features' ) && null === $experiments->get_features( 'container' ) ) {
			return true;
		}
		return (bool) $experiments->is_feature_active( 'container' );
	}

	/**
	 * Whether a widget type is registered.
	 *
	 * @param string $name Widget name.
	 */
	public function widget_available( string $name ): bool {
		if ( ! $this->is_active() ) {
			return false;
		}
		if ( ! isset( $this->widgets[ $name ] ) ) {
			$manager                = \Elementor\Plugin::$instance->widgets_manager;
			$this->widgets[ $name ] = $manager && null !== $manager->get_widget_types( $name );
		}
		return $this->widgets[ $name ];
	}

	/**
	 * Pro Theme Builder (header/footer/single templates with display conditions).
	 */
	public function theme_builder_available(): bool {
		return $this->has_pro() && class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' );
	}

	/**
	 * Active kit (Site Settings) id.
	 */
	public function kit_id(): int {
		if ( ! $this->is_active() || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return 0;
		}
		return (int) \Elementor\Plugin::$instance->kits_manager->get_active_id();
	}

	/**
	 * Detect a supported contact form plugin and return the shortcode of its first form.
	 *
	 * @return array{plugin: string, shortcode: string}|null
	 */
	public function contact_form(): ?array {
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$forms = get_posts(
				array(
					'post_type'   => 'wpcf7_contact_form',
					'numberposts' => 1,
					'post_status' => 'publish',
				)
			);
			if ( $forms ) {
				return array(
					'plugin'    => 'Contact Form 7',
					'shortcode' => sprintf( '[contact-form-7 id="%d"]', $forms[0]->ID ),
				);
			}
		}
		if ( function_exists( 'wpforms' ) ) {
			$forms = get_posts(
				array(
					'post_type'   => 'wpforms',
					'numberposts' => 1,
					'post_status' => 'publish',
				)
			);
			if ( $forms ) {
				return array(
					'plugin'    => 'WPForms',
					'shortcode' => sprintf( '[wpforms id="%d"]', $forms[0]->ID ),
				);
			}
		}
		return null;
	}

	/**
	 * Feature/limitation report for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$active  = $this->is_active();
		$ok      = $this->meets_minimum();
		$form    = $ok ? $this->contact_form() : null;
		$issues  = array();
		$notices = array();

		if ( ! $this->is_installed() ) {
			$issues[] = __( 'المنتور نصب نیست. برای ساخت صفحات قابل‌ویرایش، افزونه رایگان Elementor را نصب و فعال کنید.', 'ai-theme-studio' );
		} elseif ( ! $active ) {
			$issues[] = __( 'المنتور نصب است اما فعال نیست.', 'ai-theme-studio' );
		} elseif ( ! $ok ) {
			/* translators: 1: installed version, 2: minimum version */
			$issues[] = sprintf( __( 'نسخه المنتور (%1$s) قدیمی است؛ حداقل نسخه %2$s لازم است.', 'ai-theme-studio' ), $this->version(), ATS_MIN_ELEMENTOR );
		}

		if ( $ok && ! $this->containers_active() ) {
			$notices[] = __( 'قابلیت Flexbox Container در المنتور غیرفعال است؛ صفحات با ساختار قدیمی Section/Column ساخته می‌شوند. توصیه می‌شود از المنتور > تنظیمات > ویژگی‌ها آن را فعال کنید.', 'ai-theme-studio' );
		}
		if ( $ok && ! $this->theme_builder_available() ) {
			$notices[] = __( 'المنتور پرو فعال نیست: هدر و فوتر به‌صورت قالب‌های قابل‌ویرایش المنتور ساخته و روی صفحات تولیدشده (قالب Canvas) نمایش داده می‌شوند. قالب‌های Theme Builder (هدر سراسری، نوشته تکی، بایگانی) فقط با المنتور پرو در دسترس است.', 'ai-theme-studio' );
		}
		if ( $ok && ! $form && ! $this->has_pro() ) {
			$notices[] = __( 'هیچ افزونه فرم (Contact Form 7 یا WPForms) پیدا نشد؛ صفحه تماس با اطلاعات تماس و دکمه ایمیل ساخته می‌شود.', 'ai-theme-studio' );
		}

		return array(
			'installed'        => $this->is_installed(),
			'active'           => $active,
			'version'          => $this->version(),
			'min_version'      => ATS_MIN_ELEMENTOR,
			'compatible'       => $ok,
			'pro'              => $this->has_pro(),
			'pro_version'      => $this->pro_version(),
			'containers'       => $ok && $this->containers_active(),
			'theme_builder'    => $ok && $this->theme_builder_available(),
			'kit_id'           => $this->kit_id(),
			'contact_form'     => $form,
			'accordion_widget' => $ok && $this->containers_active() && $this->widget_available( 'nested-accordion' ),
			'issues'           => $issues,
			'notices'          => $notices,
		);
	}
}
