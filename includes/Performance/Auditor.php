<?php
/**
 * Static performance audit of generated Elementor documents and the
 * Elementor performance configuration. This is a heuristic pre-check; real
 * measurements come from Lighthouse (CLI workflow or PageSpeed Insights).
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\Performance;

use AIThemeStudio\Elementor\Compatibility;
use AIThemeStudio\Elementor\DocumentValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Performance auditor.
 */
class Auditor {

	private const SYSTEM_FONTS = array( 'Tahoma', 'system-ui', 'Arial', 'sans-serif', 'serif' );

	/**
	 * Compatibility.
	 *
	 * @var Compatibility
	 */
	private Compatibility $compat;

	/**
	 * Constructor.
	 *
	 * @param Compatibility $compat Compatibility.
	 */
	public function __construct( Compatibility $compat ) {
		$this->compat = $compat;
	}

	/**
	 * Audit.
	 *
	 * @param array<int, array<string, mixed>> $pages Pages: key, elements.
	 * @param array<int, string>               $fonts Font families in use.
	 * @param int                              $template_elements Element count of header + footer.
	 * @return array{issues: array<int, array<string, string>>, pages: array<int, array<string, mixed>>, config: array<string, mixed>}
	 */
	public function audit( array $pages, array $fonts, int $template_elements = 0 ): array {
		$issues = array();
		$stats  = array();
		$add    = static function ( string $severity, string $code, string $message, string $page = '' ) use ( &$issues ) {
			$issues[] = array(
				'severity' => $severity,
				'code'     => $code,
				'message'  => $message,
				'page'     => $page,
			);
		};

		foreach ( $pages as $p ) {
			$elements = (array) ( $p['elements'] ?? array() );
			if ( ! $elements ) {
				continue;
			}
			$check      = DocumentValidator::validate( $elements );
			$containers = $check['stats']['elements'] - $check['stats']['widgets'];
			$dom        = $containers * 2 + $check['stats']['widgets'] * 4 + $template_elements * 3 + 60;
			$stats[]    = array(
				'page'          => $p['key'],
				'elements'      => $check['stats']['elements'],
				'widgets'       => $check['stats']['widgets'],
				'depth'         => $check['stats']['max_depth'],
				'estimated_dom' => $dom,
			);
			if ( $dom > 1400 ) {
				/* translators: %d: node count */
				$add( 'warning', 'dom_size', sprintf( __( 'تعداد تخمینی گره‌های DOM حدود %d است (بیش از ۱۴۰۰ در Lighthouse هشدار می‌گیرد).', 'ai-theme-studio' ), $dom ), (string) $p['key'] );
			}
			if ( $check['stats']['max_depth'] > 5 ) {
				$add( 'info', 'nesting_depth', __( 'تودرتویی کانتینرها زیاد است؛ گروه‌های غیرضروری را حذف کنید.', 'ai-theme-studio' ), (string) $p['key'] );
			}
			$first = $elements[0]['settings'] ?? array();
			if ( ! empty( $first['background_image']['url'] ) ) {
				$add( 'warning', 'lcp_background', __( 'بخش اول صفحه تصویر پس‌زمینه دارد؛ تصاویر پس‌زمینه CSS دیر کشف می‌شوند و LCP را کند می‌کنند. ترجیحاً از ویجت تصویر استفاده کنید.', 'ai-theme-studio' ), (string) $p['key'] );
			}
			$images = \AIThemeStudio\SEO\LinkExtractor::images( $elements );
			foreach ( $images as $img ) {
				if ( $img['attachment'] ) {
					$file = get_attached_file( $img['attachment'] );
					if ( $file && is_readable( $file ) && filesize( $file ) > 600 * 1024 && 'image/webp' !== get_post_mime_type( $img['attachment'] ) ) {
						$add( 'info', 'heavy_image', __( 'یک تصویر بزرگ‌تر از ۶۰۰ کیلوبایت است؛ از WebP/AVIF یا فشرده‌سازی استفاده کنید (وردپرس اندازه‌های واکنش‌گرا را خودکار تولید می‌کند).', 'ai-theme-studio' ), (string) $p['key'] );
					}
				}
			}
		}

		$web_fonts = array_values( array_diff( $fonts, self::SYSTEM_FONTS ) );
		if ( count( $fonts ) > 2 ) {
			$add( 'warning', 'font_count', __( 'بیش از دو خانواده فونت استفاده شده است.', 'ai-theme-studio' ) );
		}

		$config = $this->config();
		if ( $web_fonts && ! $config['local_google_fonts'] ) {
			$add( 'warning', 'remote_fonts', __( 'فونت‌ها از سرور Google بارگذاری می‌شوند (درخواست خارجی و مسدودکننده رندر). در المنتور > تنظیمات > عملکرد، گزینه «بارگذاری محلی فونت‌های گوگل» را فعال کنید.', 'ai-theme-studio' ) );
		}
		if ( 'swap' !== $config['font_display'] && $web_fonts ) {
			$add( 'info', 'font_display', __( 'مقدار font-display را در تنظیمات المنتور روی swap قرار دهید تا متن بدون تأخیر نمایش داده شود.', 'ai-theme-studio' ) );
		}
		if ( 'internal' === $config['css_print_method'] ) {
			$add( 'info', 'css_print', __( 'روش چاپ CSS المنتور «داخلی» است؛ حالت «فایل خارجی» امکان کش مرورگر را فراهم می‌کند.', 'ai-theme-studio' ) );
		}
		if ( false === $config['inline_svg_icons'] ) {
			$add( 'info', 'icon_fonts', __( 'ویژگی «Inline Font Icons» المنتور غیرفعال است؛ فعال کردن آن از بارگذاری کامل فونت آیکون جلوگیری می‌کند.', 'ai-theme-studio' ) );
		}

		return array(
			'issues' => $issues,
			'pages'  => $stats,
			'config' => $config,
		);
	}

	/**
	 * Elementor performance-relevant configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		$icons = null;
		if ( $this->compat->is_active() && isset( \Elementor\Plugin::$instance->experiments ) ) {
			$exp = \Elementor\Plugin::$instance->experiments;
			if ( null !== $exp->get_features( 'e_font_icon_svg' ) ) {
				$icons = (bool) $exp->is_feature_active( 'e_font_icon_svg' );
			}
		}
		return array(
			'local_google_fonts' => '1' === (string) get_option( 'elementor_local_google_fonts', '' ) || '0' === (string) get_option( 'elementor_google_font', '1' ),
			'font_display'       => (string) get_option( 'elementor_font_display', 'auto' ),
			'css_print_method'   => (string) get_option( 'elementor_css_print_method', 'external' ),
			'inline_svg_icons'   => $icons,
		);
	}
}
