<?php
/**
 * SEO and accessibility audit of a generated website.
 *
 * @package AIThemeStudio
 */

namespace AIThemeStudio\SEO;

use AIThemeStudio\Validation\Contrast;

defined( 'ABSPATH' ) || exit;

/**
 * Auditor.
 */
class Auditor {

	private const GENERIC_LINK_TEXT = array( 'اینجا', 'کلیک کنید', 'اینجا کلیک کنید', 'بیشتر', 'ادامه', 'click here', 'here', 'read more', 'more', 'learn more' );

	/**
	 * Run the audit.
	 *
	 * Each page: key, type, post_id, title, seo{title, description}, noindex, elements, url.
	 *
	 * @param array<int, array<string, mixed>> $pages    Pages.
	 * @param array<string, string>            $colors   Design token colors.
	 * @param array<int, array<string, mixed>> $nav      Navigation proposal.
	 * @param array<string, mixed>             $site     Site context: seo_plugin, permalinks, blog_public, home, header_elements, footer_elements, allowed_urls.
	 * @return array{score: int, issues: array<int, array<string, string>>, summary: array<string, int>}
	 */
	public function audit( array $pages, array $colors, array $nav, array $site ): array {
		$issues = array();
		$add    = static function ( string $severity, string $code, string $message, string $page = '' ) use ( &$issues ) {
			$issues[] = array(
				'severity' => $severity,
				'code'     => $code,
				'message'  => $message,
				'page'     => $page,
			);
		};

		if ( empty( $site['blog_public'] ) ) {
			$add( 'error', 'site_noindex', __( 'گزینه «از موتورهای جستجو درخواست شود محتوای سایت را بررسی نکنند» در تنظیمات خواندن فعال است؛ سایت ایندکس نمی‌شود.', 'ai-theme-studio' ) );
		}
		if ( empty( $site['permalinks'] ) ) {
			$add( 'warning', 'plain_permalinks', __( 'ساختار پیوند یکتا «ساده» است؛ برای نشانی‌های خوانا از تنظیمات > پیوندهای یکتا «نام نوشته» را انتخاب کنید.', 'ai-theme-studio' ) );
		}
		if ( 'aioseo' === ( $site['seo_plugin'] ?? '' ) ) {
			$add( 'info', 'aioseo_manual', __( 'All in One SEO فعال است و API نوشتن متادیتا ندارد؛ عنوان و توضیحات پیشنهادی را از فهرست صفحات در AIOSEO وارد کنید.', 'ai-theme-studio' ) );
		}

		$titles      = array();
		$descs       = array();
		$linked_urls = array();

		foreach ( $pages as $p ) {
			$key   = (string) $p['key'];
			$title = trim( (string) ( $p['seo']['title'] ?? '' ) );
			$desc  = trim( (string) ( $p['seo']['description'] ?? '' ) );

			if ( '' === $title ) {
				$add( 'error', 'missing_title', __( 'عنوان سئو تعریف نشده است.', 'ai-theme-studio' ), $key );
			} elseif ( mb_strlen( $title ) > 65 ) {
				/* translators: %d: characters */
				$add( 'warning', 'long_title', sprintf( __( 'عنوان سئو %d کاراکتر است؛ بیش از ۶۰ کاراکتر ممکن است در نتایج جستجو کوتاه شود.', 'ai-theme-studio' ), mb_strlen( $title ) ), $key );
			}
			if ( '' === $desc ) {
				$add( 'warning', 'missing_description', __( 'توضیحات متا تعریف نشده است.', 'ai-theme-studio' ), $key );
			} elseif ( mb_strlen( $desc ) < 70 || mb_strlen( $desc ) > 170 ) {
				/* translators: %d: characters */
				$add( 'info', 'description_length', sprintf( __( 'طول توضیحات متا %d کاراکتر است (پیشنهاد: ۱۲۰ تا ۱۶۰).', 'ai-theme-studio' ), mb_strlen( $desc ) ), $key );
			}
			if ( '' !== $title ) {
				$titles[ mb_strtolower( $title ) ][] = $key;
			}
			if ( '' !== $desc ) {
				$descs[ mb_strtolower( $desc ) ][] = $key;
			}

			if ( ! empty( $p['noindex'] ) && 'not_found' !== $p['type'] ) {
				$add( 'home' === $key ? 'error' : 'warning', 'page_noindex', __( 'این صفحه noindex است و در نتایج جستجو نمایش داده نمی‌شود.', 'ai-theme-studio' ), $key );
			}

			$elements = (array) ( $p['elements'] ?? array() );
			if ( ! $elements ) {
				$add( 'warning', 'not_built', __( 'صفحه هنوز در المنتور ساخته نشده است.', 'ai-theme-studio' ), $key );
				continue;
			}

			foreach ( HeadingValidator::audit_outline( HeadingValidator::outline_from_elementor( $elements ), true ) as $h ) {
				$add( $h['severity'], $h['code'], $h['message'], $key );
			}

			foreach ( LinkExtractor::images( $elements ) as $img ) {
				$alt = $img['alt'];
				if ( '' === $alt && $img['attachment'] ) {
					$alt = (string) get_post_meta( $img['attachment'], '_wp_attachment_image_alt', true );
				}
				if ( '' === trim( $alt ) ) {
					$add( 'warning', 'missing_alt', __( 'تصویری بدون متن جایگزین (alt) وجود دارد. اگر تصویر تزئینی نیست، alt توصیفی اضافه کنید.', 'ai-theme-studio' ), $key );
				}
			}

			foreach ( LinkExtractor::links( $elements ) as $link ) {
				$url = trim( $link['url'] );
				if ( '' === $url || '#' === $url ) {
					/* translators: %s: link text */
					$add( 'warning', 'empty_link', sprintf( __( 'پیوند «%s» مقصد ندارد.', 'ai-theme-studio' ), $link['text'] ), $key );
					continue;
				}
				if ( in_array( mb_strtolower( trim( $link['text'] ) ), self::GENERIC_LINK_TEXT, true ) ) {
					/* translators: %s: link text */
					$add( 'info', 'generic_link_text', sprintf( __( 'متن پیوند «%s» توصیفی نیست؛ مقصد پیوند را در متن مشخص کنید.', 'ai-theme-studio' ), $link['text'] ), $key );
				}
				if ( 0 === strpos( $url, '#' ) || preg_match( '#^(mailto|tel):#i', $url ) ) {
					continue;
				}
				if ( $this->is_internal( $url, (string) $site['home'] ) ) {
					$normalized                 = untrailingslashit( strtok( $url, '#' ) );
					$linked_urls[ $normalized ] = true;
					if ( ! $this->resolves( $url, (array) ( $site['allowed_urls'] ?? array() ) ) ) {
						/* translators: %s: URL */
						$add( 'error', 'broken_link', sprintf( __( 'پیوند داخلی به مقصد نامعتبر اشاره می‌کند: %s', 'ai-theme-studio' ), $url ), $key );
					}
				}
			}

			$text = LinkExtractor::text( $elements );
			if ( preg_match( '/\[(نمونه|Sample)/u', $text ) || false !== mb_strpos( $text, '(نمونه)' ) ) {
				$add( 'warning', 'placeholder_content', __( 'صفحه شامل محتوای نمونه (Placeholder) است؛ پیش از انتشار آن را با اطلاعات واقعی جایگزین کنید.', 'ai-theme-studio' ), $key );
			}
		}

		foreach ( $titles as $keys ) {
			if ( count( $keys ) > 1 ) {
				/* translators: %s: page keys */
				$add( 'error', 'duplicate_title', sprintf( __( 'عنوان سئو تکراری بین صفحات: %s', 'ai-theme-studio' ), implode( '، ', $keys ) ) );
			}
		}
		foreach ( $descs as $keys ) {
			if ( count( $keys ) > 1 ) {
				/* translators: %s: page keys */
				$add( 'warning', 'duplicate_description', sprintf( __( 'توضیحات متای تکراری بین صفحات: %s', 'ai-theme-studio' ), implode( '، ', $keys ) ) );
			}
		}

		foreach ( array( 'header_elements' => false, 'footer_elements' => false ) as $tpl => $unused ) {
			if ( ! empty( $site[ $tpl ] ) ) {
				foreach ( HeadingValidator::audit_outline( HeadingValidator::outline_from_elementor( (array) $site[ $tpl ] ), false ) as $h ) {
					$add( $h['severity'], $h['code'], $h['message'], 'header_elements' === $tpl ? 'header' : 'footer' );
				}
				foreach ( LinkExtractor::links( (array) $site[ $tpl ] ) as $link ) {
					if ( $this->is_internal( $link['url'], (string) $site['home'] ) ) {
						$linked_urls[ untrailingslashit( strtok( $link['url'], '#' ) ) ] = true;
					}
				}
			}
		}

		$in_nav = array_column( $nav, 'key' );
		foreach ( $pages as $p ) {
			if ( in_array( $p['type'], array( 'not_found', 'home' ), true ) || in_array( $p['key'], $in_nav, true ) ) {
				continue;
			}
			$url = untrailingslashit( (string) ( $p['url'] ?? '' ) );
			if ( $url && ! isset( $linked_urls[ $url ] ) ) {
				$add( 'warning', 'orphan_page', __( 'هیچ پیوند داخلی به این صفحه وجود ندارد (صفحه یتیم).', 'ai-theme-studio' ), (string) $p['key'] );
			}
		}

		foreach ( Contrast::failures( $colors ) as $f ) {
			/* translators: 1: fg token, 2: bg token, 3: ratio */
			$add( 'error', 'contrast', sprintf( __( 'کنتراست رنگ %1$s روی %2$s برابر %3$s:1 است (حداقل ۴.۵:۱ برای متن).', 'ai-theme-studio' ), $f['fg'], $f['bg'], $f['ratio'] ) );
		}

		$summary = array(
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
		);
		foreach ( $issues as $i ) {
			++$summary[ $i['severity'] ];
		}
		$score = max( 0, 100 - $summary['error'] * 10 - $summary['warning'] * 3 - $summary['info'] );

		return array(
			'score'   => $score,
			'issues'  => $issues,
			'summary' => $summary,
		);
	}

	/**
	 * Same-host URL.
	 *
	 * @param string $url  URL.
	 * @param string $home Home URL.
	 */
	private function is_internal( string $url, string $home ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return ! $host || strtolower( (string) $host ) === strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
	}

	/**
	 * Whether an internal URL resolves to known content.
	 *
	 * @param string             $url     URL.
	 * @param array<int, string> $allowed Known project URLs (including future permalinks of drafts).
	 */
	private function resolves( string $url, array $allowed ): bool {
		$clean = untrailingslashit( strtok( $url, '#' ) );
		foreach ( $allowed as $a ) {
			if ( untrailingslashit( $a ) === $clean ) {
				return true;
			}
		}
		if ( function_exists( 'url_to_postid' ) && url_to_postid( $url ) ) {
			return true;
		}
		return false;
	}
}
