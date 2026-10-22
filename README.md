# 360Dialog AI Theme Studio

**Turn a brief and a few reference images into a complete, SEO-aware WordPress site — built as native, fully editable Elementor pages.**

[![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)](https://www.php.net/)
[![Elementor](https://img.shields.io/badge/Elementor-3.16%2B-92003B.svg)](https://elementor.com/)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)

AI Theme Studio is a WordPress plugin that plans, designs, and builds real Elementor documents from your project description and reference images. The output is not static HTML or screenshots — it is Flexbox containers, native widgets, global colors/typography, menus, SEO meta, and draft pages you can preview, edit, and publish when you are ready.

**Author:** [Masoud Nasrollahi Far](https://github.com/mnsise) · **Site:** [360dialog.ir](https://360dialog.ir/) · **Repository:** [github.com/mnsise/ai-theme-studio](https://github.com/mnsise/ai-theme-studio)

---

## Why this plugin

Most AI “website builders” dump fragile markup into WordPress. AI Theme Studio takes a different approach:

| Principle | What it means for you |
| --- | --- |
| **Native Elementor output** | Pages open in Elementor like anything you built by hand — containers, widgets, global styles. |
| **AI never executes code** | Models return structured JSON only. Deterministic plugin code validates it and writes Elementor documents. |
| **Honest by design** | No fake Lighthouse scores, invented testimonials, or silent failures. Missing Vision, forms, or Pro features are reported clearly. |
| **Safe by default** | Everything starts as drafts. Existing menus and your Elementor edits are never overwritten without explicit confirmation. Backups are kept. |
| **SEO that cooperates** | Writes into Yoast, Rank Math, SEOPress, or The SEO Framework when present — without duplicating meta in `<head>`. |

---

## Features

### Plan → design → build
- Analyzes your brief (business, audience, tone, goals) and optional reference images
- Proposes a **sitemap you can review and edit** before pages are generated
- Creates a **design system** (WCAG-aware colors, typography, spacing) and registers Elementor Global styles
- Builds each page with **Flexbox containers and native Elementor widgets**
- Generates header, footer, navigation, and a 404 page

### Images with roles, not guesswork
Upload logos, design inspiration, products, team photos, or hero images. Assign a role to each file. Inspiration/sample images inform style only and are **not** placed on the site. Trademarked brands in uploads are treated as inspiration and called out honestly.

### SEO & quality
- Single H1 per page, sensible heading hierarchy, internal linking, orphan-page checks
- Meta titles/descriptions via popular SEO plugins (or built-in output when none is active)
- Real-world JSON-LD only from data you provided (`Organization`, `WebSite`, `BreadcrumbList`)
- Static performance audit plus optional **live PageSpeed Insights** for published URLs — scores are never fabricated

### Multilingual studio & site content
- Plugin UI ships with **Persian source strings** and a full **English (`en_US`)** translation. Set WordPress (or your user profile) language to English to use the English UI.
- Generated site language is chosen per project (`fa`, `en`, `ar`, and more) with automatic RTL/LTR.

### Providers you already use
OpenAI · Anthropic Claude · Google Gemini · any **OpenAI-compatible** HTTPS endpoint (OpenRouter, local gateways, etc.)

API keys are stored encrypted (AES-256-CBC + HMAC). You can also define keys in `wp-config.php` so they never sit in the database.

---

## How it works

```text
Brief + images  →  Requirements & image analysis  →  Sitemap (human review)
        →  Design system  →  Page content (JSON)  →  Elementor documents
        →  SEO & performance checks  →  Drafts  →  Explicit publish
```

1. **New project** — Describe the site, pick language/direction, business type, colors, fonts, density, required pages, and optional real contact details.
2. **Images** — Up to 10 images (JPEG/PNG/WebP). Roles: logo, inspiration, product, team, hero, gallery, and more.
3. **Generate** — Pipeline runs in resumable steps (cancel / continue / auto-retry on 429/5xx). Live progress in the studio UI.
4. **Review** — Preview drafts, edit in Elementor, regenerate a page or a single section, restore from history.
5. **Menu** — Preview and apply a project menu. Theme menu locations are never replaced without your OK.
6. **Publish** — Explicit confirmation only. Optional: front page, custom 404, global kit styles (with backup), Elementor Pro Theme Builder.

Deep dive: [Architecture](docs/ARCHITECTURE.md) · [User guide](docs/README.md) · [QA checklist](docs/QA-CHECKLIST.md) · [Lighthouse CI](docs/LIGHTHOUSE.md) · [Testing](docs/TESTING.md)

---

## Requirements

| Requirement | Minimum | Notes |
| --- | --- | --- |
| PHP | 8.0 | Tested on 8.0, 8.2, 8.5 |
| WordPress | 6.3 | |
| Elementor (free) | 3.16 | Flexbox Container recommended |
| Elementor Pro | Optional | Site-wide Theme Builder (header/footer) |
| Permalinks | Post name recommended | Better URLs and SEO |

Without Elementor, the plugin activates and settings work, but site generation is refused with a clear message — no half-built pages.

---

## Installation

1. Install and activate **Elementor** (3.16+).
2. Copy this folder to `wp-content/plugins/ai-theme-studio`, or upload a zip via **Plugins → Add New**.
3. Activate **360Dialog AI Theme Studio**.
4. Go to **Theme Studio → Settings**, choose a provider, enter the API key, and run **Connection test**.
5. Check **Theme Studio → System status**, then start a **New project**.

### Optional `wp-config.php` keys

```php
define( 'ATS_API_KEY', 'sk-...' );
define( 'ATS_PAGESPEED_API_KEY', '...' ); // optional, for live PageSpeed tests
```

### Development tooling

`vendor/`, `tests/`, `node_modules/`, and Composer/npm manifests are for development. They are not required in a production zip.

```bash
composer install
npm install
npm run test:unit
npm run i18n:build-en   # rebuild English gettext catalogs
```

---

## Privacy & data sent to AI

**Sent (only what you put in the project):** project description, design preferences, business/contact fields you entered, and reference images when the model supports vision.

**Never sent:** API keys, WordPress users, admin email, unrelated site content, or server credentials.

PageSpeed tests send the **public page URL** to Google PageSpeed Insights.

- [OpenAI Privacy](https://openai.com/policies/privacy-policy)
- [Anthropic Privacy](https://www.anthropic.com/legal/privacy)
- [Google Privacy](https://policies.google.com/privacy)

---

## FAQ

**Is Elementor Pro required?**  
No. Without Pro, header and footer are editable Elementor templates shown on generated (Canvas) pages. With Pro, you can activate them site-wide via Theme Builder.

**What if my model does not support images?**  
Image analysis is skipped and reported honestly. Images are used only according to the roles you assign.

**Will regeneration overwrite my Elementor edits?**  
Not without confirmation. Edited pages/sections return a clear conflict; confirming saves a backup first. Restore from History anytime.

**Does the plugin guarantee Lighthouse scores?**  
No. Internal checks are static risk signals. Real scores come from PageSpeed Insights or [Lighthouse CI](docs/LIGHTHOUSE.md). Hosting, cache, theme, and other plugins still matter.

**English UI?**  
Set WordPress or your user language to **English (United States)**. Content language for the generated site is chosen separately in the project form.

---

## Known limitations (honest)

- The plugin **does not generate images**; it only uses uploads you provide.
- Contact forms use Contact Form 7 / WPForms when available; otherwise contact details (or clearly marked placeholders) — no fake broken forms.
- Without Elementor Pro, blog singles/archives use the active theme templates.
- Content quality depends on the model; always review legal claims, prices, and contact details before publish.
- If `DISABLE_WP_CRON` is set, keep the project screen open or configure a real system cron so the pipeline can advance.

---

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `ats_ai_provider` | filter | Swap in a custom `ProviderInterface` (e.g. corporate proxy) |
| `ats_prompt_template` | filter | Override `prompts/*.md` templates |
| `ats_capability` | filter | Adjust capability checks per action |

---

## Uninstall

By default, plugin data is kept. If you enable **Delete data on uninstall**, plugin tables, projects, and settings are removed.

**Generated pages, menus, media, and Elementor templates are your site content and are never deleted by the plugin.**

---

## License

GPL-2.0-or-later — see the [GNU GPL v2](https://www.gnu.org/licenses/gpl-2.0.html).

---

<p align="center">
  Built for people who want AI speed <em>and</em> Elementor control — without pretending the hard parts don’t exist.
</p>
