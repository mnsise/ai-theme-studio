# معماری AI Theme Studio

## اصول

1. **کد تولیدشده توسط AI هرگز اجرا نمی‌شود.** مدل فقط JSON برمی‌گرداند؛ JSON با Schema و اعتبارسنج معنایی بررسی و سپس توسط کد قطعی افزونه به ساختار المنتور تبدیل می‌شود. HTML متنی با `wp_kses` و فهرست مجاز پاک‌سازی می‌شود؛ `<script>`، `javascript:`، `on*` و style دلخواه حذف می‌شوند.
2. **هیچ تغییر مخربی بدون اجازه:** صفحات پیش‌نویس‌اند، منوی موجود جایگزین نمی‌شود، سبک‌های سراسری کیت فقط هنگام انتشار و با پشتیبان، ویرایش کاربر بدون تأیید بازنویسی نمی‌شود.
3. **خروجی بومی و قابل‌ویرایش المنتور** با تشخیص قابلیت (نسخه، Container، پرو، ویجت‌ها) و جایگزین صادقانه برای قابلیت‌های پرو.
4. **صداقت:** نبود Vision، نبود فرم، نبود Theme Builder و نتایج Lighthouse همان‌طور که هست گزارش می‌شود.

## لایه‌ها

```
admin/js/app.js (SPA، wp.apiFetch)  ──REST (nonce + capability + rate limit)──►  includes/REST/*
                                                                                     │
                     ┌───────────────────────────────────────────────────────────────┘
                     ▼
             Jobs/Runner  ── WP-Cron / tick از UI ──►  Generation/Pipeline (ماشین حالت مرحله‌ای)
                     │                                     │
                     │             ┌───────────────────────┼─────────────────────────────┐
                     ▼             ▼                       ▼                             ▼
          Database/JobRepository  AI/StructuredGenerator   Elementor/DocumentBuilder     SEO/*, Performance/*
          ArtifactRepository      ├ PromptLibrary (prompts/*.md)  ├ DocumentValidator    WordPress/PageManager
          Logger                  ├ Providers/*            ├ DocumentWriter (Document API)  MenuManager
                                  └ Validation/*           ├ KitManager, TemplateManager
                                                           └ Compatibility (تنها نقطه وابسته به نسخه المنتور)
```

| پوشه | مسئولیت |
| --- | --- |
| `Core` | راه‌اندازی، کانتینر سرویس (`Plugin::get()`)، تنظیمات رمزنگاری‌شده، فعال‌سازی، لاگ. |
| `Security` | Capabilityها (`ats_capability`)، رمزنگاری کلیدها، محدودیت نرخ (پنجره ثابت با transient). |
| `AI` | رابط مستقل از ارائه‌دهنده، تلاش مجدد با backoff روی 429/5xx، استخراج JSON، تولید ساختاریافته با یک دور اصلاح. |
| `Validation` | Schemaها، اعتبارسنج JSON Schema، واژگان مجاز (نوع سایت، صفحات، ویجت‌ها، آیکون‌ها، فونت‌ها)، کنتراست WCAG، اعتبارسنج صفحه. |
| `Elementor` | تبدیل تعریف صفحه به `_elementor_data`، اعتبارسنجی سند، نوشتن با Document API، کیت و قالب‌ها، تبدیل به Section/Column قدیمی، ویجت‌های رایگان جایگزین (منو، شبکه نوشته‌ها). |
| `WordPress` | CPT پروژه، صفحات (بدون تکرار؛ نگاشت کلید↔شناسه)، منو، صفحه ۴۰۴. |
| `SEO` | تشخیص افزونه سئو، نوشتن متا بدون تکرار، JSON-LD، ممیزی عنوان‌ها/پیوندها/صفحات یتیم. |
| `Performance` | ممیزی ایستا، بارگذاری دارایی‌ها، کلاینت PageSpeed Insights. |
| `Generation` | Pipeline، بازتولید بخش/صفحه، انتشار، گزارش کیفیت. |
| `Jobs` | اجرای مرحله‌ای با قفل، تلاش مجدد، لغو، ادامه. |
| `REST` | مسیرهای `ai-theme-studio/v1` (همه با `permission_callback`). |

## ماشین حالت

وضعیت پروژه: `draft → generating → (review_sitemap) → ready → published` و `failed` / `cancelled`.

وضعیت کار (Job): `queued → running → completed | failed | cancelled | waiting`.

مراحل Pipeline:

| مرحله | ورودی | خروجی (Artifact) | قالب پرامپت |
| --- | --- | --- | --- |
| `analyze_requirements` | ورودی کاربر | `spec` | `planning.md` |
| `analyze_images` | تصاویر (اگر Vision) | `image_analysis` (یا وضعیت `unsupported`/`skipped`) | `image-analysis.md` |
| `create_sitemap` | spec | `sitemap`، پیشنهاد منو | `sitemap.md` |
| (توقف) | | Job در `waiting` تا تأیید نقشه سایت | |
| `design_system` | spec، تصاویر | `design` (+ رنگ/فونت سراسری `ats*` در کیت) | `design-system.md`، `accessibility-validation.md` |
| `generate_pages` | هر بار یک صفحه | `page/{key}` | `page.md`، `elementor-validation.md`، `performance-optimization.md` |
| `build_elementor` | تعریف‌ها | صفحات پیش‌نویس، هدر/فوتر، متای سئو | — |
| `quality_checks` | اسناد ذخیره‌شده | `audit_seo`، `audit_performance` | `seo-validation.md` (فقط برای اصلاح عنوان/توضیح) |

هر tick یک مرحله (یا ۴ صفحه در `build_elementor`) را با قفل اتمی `locked_until` (۳۳۰ ثانیه) اجرا می‌کند؛ بنابراین با محدودیت زمان PHP هاست اشتراکی سازگار است و پس از قطع، از همان نقطه ادامه می‌یابد. `cursor` کار شامل `pages_done`، `build_phase`، `force` و… است (idempotent).

## مدل داده

- `wp_ats_jobs`: `id, project_id, step, status, cursor (JSON), attempts, last_error, locked_until, created_by, …`
- `wp_ats_artifacts`: نسخه‌دار؛ `project_id, type, ref, version, data (JSON)`. پشتیبان سند المنتور (`elementor_backup`) و کیت (`kit_backup_*`) هم اینجاست.
- `wp_ats_logs`: رخدادها و مصرف توکن (۳۰ روز).
- پروژه: CPT خصوصی `ats_project` با متای `_ats_input`، `_ats_pages` (کلید→شناسه)، `_ats_status`، … .
- صفحه تولیدشده: `_ats_project_id`، `_ats_page_key`، `_ats_generated_hash` و `_ats_section_hashes` (هش آخرین نوشتن برای تشخیص ویرایش کاربر)، `_ats_section_map`، `_ats_seo_title`، `_ats_seo_description`، `_ats_noindex`.

## حفظ ویرایش کاربر

پس از هر نوشتن، هش کل سند و هر بخش ذخیره می‌شود. پیش از بازنویسی:

- بازتولید کلی بدون `force`: صفحات ویرایش‌شده **نه تولید مجدد می‌شوند و نه بازنویسی**.
- بازتولید صفحه/بخش ویرایش‌شده: پاسخ 409 `ats_user_edits` و نیاز به تأیید صریح؛ با تأیید، نسخه پشتیبان ذخیره و سپس جایگزین می‌شود. بازگردانی از «تاریخچه» ممکن است.
- بازتولید بخش فقط همان بخش را در سند فعلی جایگزین می‌کند؛ بقیه بخش‌ها (حتی ویرایش‌شده) دست نمی‌خورند.

## لایه سازگاری المنتور

همه بررسی‌های وابسته به نسخه در `Elementor/Compatibility.php` است:
`is_active`، `meets_minimum` (۳.۱۶)، `containers_active`، `has_pro`، `theme_builder_available`، `widget_available`، `kit_id`، `contact_form`.

- Container غیرفعال → `LegacyConverter` به Section/Column.
- ویجت ناموجود → جایگزین ایمن (مثلاً `nav-menu` پرو → ویجت رایگان `ats-nav-menu`؛ `posts` پرو → `ats-posts-grid`).
- نوشتن: `Document::save()` و سپس `set_is_built_with_elementor()`؛ در صورت شکست، نوشتن مستقیم متا با قالب مستند المنتور و پاک‌کردن کش CSS.

## امنیت

| تهدید | کنترل |
| --- | --- |
| CSRF | nonce `wp_rest` در همه درخواست‌های REST (cookie auth). |
| دسترسی غیرمجاز | `permission_callback` روی همه مسیرها؛ دسترسی به پروژه فقط برای سازنده یا مدیر؛ انتشار نیازمند `publish_pages`؛ منو نیازمند `edit_theme_options`؛ تنظیمات نیازمند `manage_options`. |
| Prompt injection | ورودی کاربر در پرامپت به‌صورت داده نامطمئن علامت‌گذاری می‌شود؛ خروجی فقط از واژگان مجاز (ویجت، آیکون، پیوند `page:key`) ساخته می‌شود. |
| XSS | `wp_kses` روی HTML تولیدی؛ SPA فقط با `textContent`؛ escape در همه خروجی‌های PHP. |
| آپلود مخرب | بررسی واقعی نوع فایل (`wp_check_filetype_and_ext` + `getimagesize`)، فهرست مجاز MIME، محدودیت حجم و تعداد، `is_uploaded_file`. |
| سوءاستفاده/هزینه | محدودیت نرخ برای تولید، بازتولید، آپلود، آزمایش اتصال و PageSpeed. |
| نشت کلید | رمزنگاری در DB، نمایش ماسک‌شده، امکان تعریف در `wp-config.php`، عدم ارسال به ارائه‌دهنده دیگر. |
| SQL | فقط `$wpdb->prepare` و API وردپرس؛ AI هیچ SQL تولید نمی‌کند. |
