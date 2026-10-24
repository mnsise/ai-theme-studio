<?php
/**
 * Admin app container. All UI is rendered by admin/js/app.js using the REST API.
 *
 * @package AIThemeStudio
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ats-wrap">
	<header class="ats-brand">
		<div class="ats-brand__copy">
			<p class="ats-brand__eyebrow">360Dialog</p>
			<h1 class="ats-title"><?php esc_html_e( 'استودیو قالب هوشمند', 'ai-theme-studio' ); ?></h1>
		</div>
		<p class="ats-brand__tag"><?php esc_html_e( 'از توضیح متنی و تصاویر مرجع تا سایت قابل‌ویرایش در المنتور.', 'ai-theme-studio' ); ?></p>
	</header>
	<div id="ats-app" class="ats-app" aria-live="polite">
		<p class="ats-loading"><?php esc_html_e( 'در حال بارگذاری…', 'ai-theme-studio' ); ?></p>
	</div>
	<noscript><p><?php esc_html_e( 'این صفحه به جاوااسکریپت نیاز دارد.', 'ai-theme-studio' ); ?></p></noscript>
</div>
