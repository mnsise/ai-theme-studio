# روند Lighthouse

**اهداف:** Performance ≥ 90، Accessibility ≥ 95، Best Practices ≥ 95، SEO ≥ 95 — برای موبایل و دسکتاپ.

افزونه هیچ امتیاز Lighthouse را تخمین، تضمین یا جعل نمی‌کند. سه منبع داده وجود دارد و هر کدام همان‌طور که هست گزارش می‌شود:

| منبع | چه چیزی | کجا |
| --- | --- | --- |
| ممیزی ایستا | پیش‌بررسی: تعداد عناصر، DOM تخمینی (هشدار بالای ۱۴۰۰)، عمق تودرتویی، فونت‌ها، حجم تصاویر، تنظیمات عملکرد المنتور | پروژه › عملکرد (بدون امتیاز) |
| PageSpeed Insights | Lighthouse واقعی روی سرور گوگل، برای صفحات **منتشرشده و عمومی** | پروژه › عملکرد › «اجرای PageSpeed» |
| Lighthouse CI | Lighthouse واقعی روی ماشین شما/CI، با آستانه‌های بالا | این راهنما |

## Lighthouse CI

پیش‌نیاز: Node.js ≥ 20 و Chrome/Chromium.

```bash
cd wp-content/plugins/ai-theme-studio
npm install

export ATS_LH_URLS="https://staging.example.com/,https://staging.example.com/services/,https://staging.example.com/contact/"
npm run lighthouse:mobile
npm run lighthouse:desktop
```

- گزارش‌ها: `.lighthouseci/mobile/` و `.lighthouseci/desktop/` (HTML و JSON).
- هر نشانی ۳ بار اجرا و میانه سنجیده می‌شود (`ATS_LH_RUNS` برای تغییر).
- اگر هر دسته زیر هدف باشد، فرمان با کد خطا خارج می‌شود (مناسب CI).
- صفحات باید منتشرشده و بدون ورود قابل دسترس باشند؛ پیش‌نویس‌ها به صفحه ورود هدایت می‌شوند و نتیجه بی‌معنی است. برای بررسی پیش از انتشار عمومی، روی نسخه staging منتشر کنید.
- `ATS_LH_LOCALE` زبان گزارش را تعیین می‌کند (پیش‌فرض `fa`).

### نمونه GitHub Actions

```yaml
name: lighthouse
on: workflow_dispatch
jobs:
  lhci:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - run: npm ci
        working-directory: ai-theme-studio
      - run: npm run lighthouse
        working-directory: ai-theme-studio
        env:
          ATS_LH_URLS: ${{ vars.ATS_LH_URLS }}
      - uses: actions/upload-artifact@v4
        if: always()
        with: { name: lighthouse, path: ai-theme-studio/.lighthouseci }
```

## چه چیزی امتیاز را تعیین می‌کند؟

آنچه افزونه کنترل می‌کند:

- ساختار Flexbox Container کم‌عمق و سقف تعداد عنصر در هر صفحه (اعتبارسنج صفحه، صفحات بزرگ را رد یا اصلاح می‌کند).
- یک H1، ترتیب صحیح عنوان‌ها، alt برای همه تصاویر غیرتزئینی، متن لینک معنادار، کنتراست WCAG AA در سیستم طراحی.
- عنوان و توضیحات متا، `noindex` برای ۴۰۴، JSON-LD معتبر.
- تصاویر فقط از کتابخانه رسانه و با ویجت Image المنتور درج می‌شوند (ابعاد width/height و srcset را وردپرس اضافه می‌کند). تصمیم lazy-load با هسته وردپرس/المنتور است؛ اگر تصویر hero در Lighthouse به‌عنوان «LCP lazy-loaded» علامت خورد، آن را در المنتور یا با افزونه بهینه‌سازی اصلاح کنید.
- فونت‌ها فقط از فهرست محدود و مناسب زبان سایت انتخاب می‌شوند.

آنچه **خارج از کنترل افزونه** است و باید جداگانه بررسی شود:

- هاست و TTFB، کش صفحه، CDN، فشرده‌سازی، HTTP/2+.
- قالب فعال وردپرس و افزونه‌های دیگر (اسکریپت‌ها و CSS اضافه).
- تنظیمات عملکرد المنتور (پروژه › عملکرد این‌ها را نمایش می‌دهد): Improved Asset Loading، Inline Font Icons، Optimized DOM/Markup، بارگذاری Google Fonts.
- حجم و فرمت تصاویری که بارگذاری می‌کنید (WebP/AVIF توصیه می‌شود).

## اندازه‌گیری پایه (سایت نمونه تست)

اسکریپت `tests/Integration/lighthouse.sh` سایت نمونه (`tests/fixtures/sample-site.json`) را با Pipeline واقعی در WordPress Playground می‌سازد، منتشر می‌کند و Lighthouse CI را اجرا می‌کند:

```bash
CHROME_PATH="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" bash tests/Integration/lighthouse.sh
```

نتیجه واقعی اجرای ۱۴ اکتبر ۲۰۲۶ — وردپرس 7.1.3، PHP 8.2 (WASM)، المنتور 4.3.5، Lighthouse CI 0.15.1، Chrome محلی، ۴ نشانی × ۳ اجرا:

| دستگاه | Performance | Accessibility | Best Practices | SEO | LCP | CLS |
| --- | --- | --- | --- | --- | --- | --- |
| موبایل | 77–82 ❌ | 100 ✅ | 100 ✅ | 100 ✅ | 3.6–4.1 s | ≤ 0.004 |
| دسکتاپ | 70–96 (میانه 83–95) ❌/✅ | 100 ✅ | 100 ✅ | 100 ✅ | 0.9–2.4 s | ≤ 0.021 |

تفسیر صادقانه:

- **هدف Performance ≥ 90 در این محیط برآورده نشد.** TTFB سرور توسعه Playground (PHP روی WebAssembly، بدون کش صفحه) در همه اجراها ۱.۲ تا ۱.۴ ثانیه بود و فشرده‌سازی متن هم ندارد؛ این‌ها مستقیماً به LCP اضافه می‌شوند. برای قضاوت درباره هدف، روی هاست واقعی با کش صفحه اندازه بگیرید.
- منابع مسدودکننده رندر باقی‌مانده متعلق به المنتور/وردپرس‌اند: jQuery و اسکریپت‌های frontend المنتور، و Google Fonts با همه ۱۸ وزن فونت (رفتار پیش‌فرض المنتور). «Load Google Fonts Locally» و «Improved Asset Loading» در المنتور و یک افزونه کش/بهینه‌سازی این موارد را کاهش می‌دهند.
- مشکلاتی که این اندازه‌گیری در خود افزونه پیدا کرد و رفع شد: (۱) ویجت Accordion قدیمی المنتور (ARIA نامعتبر و لینک بدون href) با Nested Accordion بومی (`<details>`) جایگزین شد؛ (۲) عکس پیش‌فرض placeholder در ویجت Testimonial حذف شد؛ (۳) جابه‌جایی چیدمان موبایل (CLS 0.14) ناشی از جمع‌شدن دیرهنگام منو با جاوااسکریپت، با علامت‌گذاری پیش از رندر رفع شد (CLS ≈ 0.003).
- محتوای سایت نمونه، داده تست است؛ سایت‌های واقعی محتوا و تصاویر متفاوتی دارند و باید جداگانه اندازه‌گیری شوند.

## گزارش نتایج

نتایج را فقط از گزارش واقعی (`.lighthouseci/*/manifest.json` یا PageSpeed) نقل کنید و نشانی، تاریخ، دستگاه (موبایل/دسکتاپ) و تعداد اجرا را ذکر کنید.
