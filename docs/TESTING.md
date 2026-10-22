# تست‌ها

دو لایه تست خودکار وجود دارد. هیچ‌کدام به کلید API واقعی یا شبکه ارائه‌دهنده نیاز ندارند؛ پاسخ‌های مدل با یک ارائه‌دهنده آزمایشی (از طریق فیلتر `ats_ai_provider`) شبیه‌سازی می‌شوند. این ارائه‌دهنده فقط در تست‌ها وجود دارد و هیچ پاسخ ثابتی در کد افزونه نیست.

## ۱. تست واحد (PHPUnit)

```bash
composer install
composer test          # یا vendor/bin/phpunit
```

- بدون وردپرس؛ توابع لازم وردپرس در `tests/bootstrap.php` شبیه‌سازی شده‌اند.
- داده نمونه: `tests/fixtures/sample-site.json` (شامل عمداً خطاهایی مثل دو H1، `<script>`، لینک `javascript:`، آیکون نامعتبر، تصویر ناموجود، صفحه ۴۰۴ با index).
- پوشش: اعتبارسنجی Schema، استخراج JSON، دور اصلاح خروجی، خطاهای ارائه‌دهنده (429، 401، کلید خالی، خروجی بریده‌شده)، شکل درخواست OpenAI، تشخیص Vision، رمزنگاری و ماسک کلید، محدودیت نرخ، پاک‌سازی ورودی، قوانین نقشه سایت و منو، ساخت سند Container/Section، پاک‌سازی HTML و لینک‌ها، عنوان‌ها، تصاویر و alt، ارجاع به رنگ سراسری، کنتراست.

## ۲. تست یکپارچه (وردپرس واقعی + المنتور واقعی)

با [WordPress Playground CLI](https://github.com/WordPress/wordpress-playground) (وردپرس کامل روی PHP-WASM و SQLite):

```bash
bash tests/Integration/run.sh            # PHP 8.2
ATS_PHP=8.0 bash tests/Integration/run.sh
```

- نیازمند Node.js ≥ 20 و دسترسی به اینترنت (دانلود وردپرس و المنتور از wordpress.org).
- نسخه CLI روی `@wp-playground/cli@3.1.20` ثابت شده است (نسخه‌های جدیدتر Node 24 می‌خواهند)؛ با `PLAYGROUND_CLI` قابل تغییر است.
- دو اجرا: `blueprint-elementor.json` (آخرین نسخه المنتور رایگان) و `blueprint-no-elementor.json`.
- خروجی خطوط `PASS`/`FAIL`/`SKIP` است و در صورت شکست، کد خروج ۱ برمی‌گرداند.

### نگاشت تست‌های الزامی

| تست الزامی | کجا |
| --- | --- |
| فعال‌سازی | یکپارچه › Activation (جدول‌ها، تنظیمات، کران، CPT، مسیرها و permission callback) |
| نبود المنتور | یکپارچه › Missing Elementor (اجرای بدون المنتور) |
| خطای ارائه‌دهنده | واحد › AI؛ یکپارچه › Provider failures (تکرار، شکست، ادامه) |
| خروجی نامعتبر AI | واحد › Validation/AI؛ یکپارچه › «invalid AI output ends in failure» |
| آپلود تصویر | یکپارچه › Uploads (PHP در قالب PNG، حجم زیاد، PNG معتبر) |
| ساخت صفحه | یکپارچه › Generation pipeline |
| ساخت منو | یکپارچه › Navigation |
| جلوگیری از تکرار | یکپارچه › «no duplicate pages»، «applying again creates no duplicates» |
| اعتبار سند المنتور | واحد › Elementor؛ یکپارچه › «stored Elementor data is structurally valid»، «frontend renders» |
| تداخل سئو | یکپارچه › SEO plugin conflict (Yoast) |
| ساختار عنوان‌ها | واحد › Heading*؛ یکپارچه › one H1 در داده ذخیره‌شده و HTML رندرشده |
| پیوندهای داخلی | واحد › link rules؛ یکپارچه › «rendered links»، «no orphan pages» |
| انتشار غیرمجاز | یکپارچه › Publishing permissions (403/401/400) |
| تلاش مجدد | یکپارچه › «retryable error requeues» و «failed job can be resumed» |
| حفظ ویرایش کاربر | یکپارچه › Duplicate prevention and edit preservation |
| حذف افزونه | یکپارچه › Uninstall |

### محدودیت‌های محیط تست

- Playground از SQLite به‌جای MySQL استفاده می‌کند؛ کوئری‌های افزونه فقط از API وردپرس و `$wpdb->prepare` استفاده می‌کنند، اما تست روی MySQL واقعی در [چک‌لیست QA](QA-CHECKLIST.md) آمده است.
- المنتور پرو، Yoast و Rank Math واقعی نصب نمی‌شوند (پرو رایگان نیست). تداخل سئو با تعریف `WPSEO_VERSION` شبیه‌سازی می‌شود؛ تست با افزونه‌های واقعی در چک‌لیست QA است.
- تست یکپارچه، رابط کاربری مرورگر را آزمایش نمی‌کند؛ آن هم در چک‌لیست QA است.

## ۳. Lighthouse

[LIGHTHOUSE.md](LIGHTHOUSE.md) را ببینید. `bash tests/Integration/lighthouse.sh` سایت نمونه را در Playground می‌سازد، منتشر می‌کند و Lighthouse CI را روی آن اجرا می‌کند. نتایج Lighthouse فقط از اجرای واقعی گزارش می‌شوند.
