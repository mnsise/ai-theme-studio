<p align="center">
  <img src="assets/AI%20Theme%20Studio%20Landing%20Banner.webp" alt="360Dialog AI Theme Studio" width="100%">
</p>

<h1 align="center">360Dialog AI Theme Studio</h1>

<p align="center">
  <strong>From a brief and a few images → a complete Elementor site you can actually edit.</strong><br>
  Native Flexbox containers · Global design tokens · SEO that plays nice · Drafts until you say publish.
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.3%2B-21759B?style=for-the-badge&logo=wordpress&logoColor=white" alt="WordPress 6.3+"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+"></a>
  <a href="https://elementor.com/"><img src="https://img.shields.io/badge/Elementor-3.16%2B-92003B?style=for-the-badge&logo=elementor&logoColor=white" alt="Elementor 3.16+"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPL--2.0-4CAF50?style=for-the-badge" alt="GPL-2.0"></a>
</p>

<p align="center">
  <a href="#-quick-start">Quick start</a> ·
  <a href="#-why-it-s-different">Why different</a> ·
  <a href="#-features">Features</a> ·
  <a href="#-how-it-works">Pipeline</a> ·
  <a href="#-ai-providers">Providers</a> ·
  <a href="#-docs">Docs</a> ·
  <a href="https://360dialog.ir/">360dialog.ir</a>
</p>

---

## ✨ What you get

AI Theme Studio is a WordPress plugin that **plans, designs, and builds real Elementor documents** from your project description and reference images.

| Not this | This |
| :---: | :---: |
| Static HTML dumps | Native Elementor pages |
| Locked “AI themes” | Fully editable widgets & containers |
| Fake Lighthouse scores | Honest audits + live PageSpeed |
| Silent overwrites | Drafts, backups, explicit confirm |

**Author:** [Masoud Nasrollahi Far](https://github.com/mnsise) · **Product site:** [360dialog.ir](https://360dialog.ir/)

---

## 🚀 Quick start

```bash
# 1. Install Elementor (3.16+)
# 2. Copy this folder → wp-content/plugins/ai-theme-studio
# 3. Activate “360Dialog AI Theme Studio”
# 4. Theme Studio → Settings → provider + API key → Connection test
# 5. New project → describe → generate → review → publish
```

Optional keys in `wp-config.php`:

```php
define( 'ATS_API_KEY', 'sk-...' );
define( 'ATS_PAGESPEED_API_KEY', '...' ); // live PageSpeed tests
```

<details>
<summary><strong>Requirements</strong></summary>

| | Minimum | Notes |
| --- | --- | --- |
| PHP | 8.0 | Tested 8.0 / 8.2 / 8.5 |
| WordPress | 6.3 | |
| Elementor | 3.16 free | Flexbox Container recommended |
| Elementor Pro | Optional | Site-wide Theme Builder |
| Permalinks | Post name | Recommended for SEO |

Without Elementor the plugin still activates — generation is refused with a clear message (no half-built pages).

</details>

---

## 💎 Why it's different

| Principle | What it means |
| --- | --- |
| **Native Elementor** | Opens like anything you built by hand — containers, widgets, globals. |
| **AI never runs code** | Models return JSON only. Plugin code validates & writes documents. |
| **Honest by design** | No invented testimonials, fake scores, or silent Vision failures. |
| **Safe by default** | Drafts first. Menus & your edits never overwritten without OK + backup. |
| **SEO that cooperates** | Writes into Yoast / Rank Math / SEOPress / TSF — no duplicate `<head>` meta. |

---

## 🧩 Features

### Plan → design → build
- Brief analysis (business, audience, tone, goals) + optional reference images
- **Editable sitemap** before any page is generated
- **Design system** with WCAG-aware colors → Elementor Globals
- Pages as **Flexbox + native widgets** (header, footer, nav, 404 included)

### Images with roles
Logo · inspiration · product · team · hero · gallery…  
Inspiration stays inspiration — not dropped onto the live site. Brand marks in uploads are called out honestly.

### SEO & quality
- One H1, clean heading hierarchy, internal links, orphan checks
- Meta via your SEO plugin (or built-in when none is active)
- JSON-LD only from **your** data (`Organization`, `WebSite`, `BreadcrumbList`)
- Static performance audit + optional **live PageSpeed Insights**

### Languages
- Studio UI: Persian source + full **English (`en_US`)** — set WP/user language to English
- Generated site: `fa` / `en` / `ar` / … with automatic RTL · LTR

---

## 🔄 How it works

```text
┌─────────┐   ┌──────────┐   ┌─────────┐   ┌────────┐   ┌───────────┐   ┌─────────┐
│  Brief  │ → │ Analyze  │ → │ Sitemap │ → │ Design │ → │  Pages +  │ → │ Publish │
│ + images│   │ + Vision │   │ review  │   │ system │   │ Elementor │   │  (you)  │
└─────────┘   └──────────┘   └─────────┘   └────────┘   └───────────┘   └─────────┘
```

1. **Project** — description, language, type, colors, fonts, pages, real contact details  
2. **Images** — up to 10 (JPEG / PNG / WebP) with roles  
3. **Generate** — resumable pipeline, cancel / continue, retry on 429 / 5xx  
4. **Review** — preview, edit in Elementor, regenerate page or one section, restore history  
5. **Menu** — apply project menu; theme locations never replaced without consent  
6. **Publish** — explicit confirm only (front page, 404, globals, Pro Theme Builder optional)

---

## 🤖 AI providers

| Provider | Vision | Notes |
| --- | :---: | --- |
| **OpenAI** | ✅ | Default · JSON mode |
| **Anthropic Claude** | ✅ | Claude 3+ |
| **Google Gemini** | ✅ | JSON mime type |
| **OpenAI-compatible** | depends | OpenRouter, local gateways — **HTTPS only** |

Keys are encrypted at rest (AES-256-CBC + HMAC). Prefer `ATS_API_KEY` in `wp-config.php` if you want zero DB storage.

<details>
<summary><strong>Privacy — what leaves your site</strong></summary>

**Sent:** project brief, design prefs, contact fields you entered, reference images (if Vision is on).  

**Never sent:** API keys, WP users, admin email, unrelated content, server secrets.  

PageSpeed sends the **public URL** only.  
[OpenAI](https://openai.com/policies/privacy-policy) · [Anthropic](https://www.anthropic.com/legal/privacy) · [Google](https://policies.google.com/privacy)

</details>

---

## ❓ FAQ

<details>
<summary><strong>Is Elementor Pro required?</strong></summary>
No. Without Pro, header/footer are editable templates on generated Canvas pages. With Pro you can go site-wide via Theme Builder.
</details>

<details>
<summary><strong>What if my model has no vision?</strong></summary>
Analysis is skipped and reported honestly. Images follow the roles you set.
</details>

<details>
<summary><strong>Will regen wipe my Elementor edits?</strong></summary>
Not without confirmation. Backups first; restore from History anytime.
</details>

<details>
<summary><strong>Lighthouse score guarantee?</strong></summary>
No. Internal checks are risk signals. Real numbers = PageSpeed or <a href="docs/LIGHTHOUSE.md">Lighthouse CI</a>. Hosting still matters.
</details>

<details>
<summary><strong>English UI?</strong></summary>
Set site or user language to <strong>English (United States)</strong>. Site content language is chosen per project.
</details>

---

## ⚠️ Honest limits

- Does **not** generate images — only your uploads  
- Forms: CF7 / WPForms if present; otherwise real contact info or marked placeholders  
- Without Pro, blog singles/archives use the active theme  
- Always human-review legal claims, prices, and contact details  
- If `DISABLE_WP_CRON` is on, keep the project screen open or use real system cron  

---

## 🛠 Developers

| Hook | Type | Purpose |
| --- | --- | --- |
| `ats_ai_provider` | filter | Custom `ProviderInterface` (corporate proxy, etc.) |
| `ats_prompt_template` | filter | Override `prompts/*.md` |
| `ats_capability` | filter | Capability map per action |

```bash
composer install && npm install
npm run test:unit
npm run i18n:build-en
```

`vendor/`, `tests/`, and `node_modules/` are for development — not required in a production zip.

---

## 📚 Docs

| Doc | Topic |
| --- | --- |
| [Architecture](docs/ARCHITECTURE.md) | Pipeline, data model, safety rules |
| [User guide](docs/README.md) | Install & day-to-day use |
| [QA checklist](docs/QA-CHECKLIST.md) | Manual QA |
| [Lighthouse CI](docs/LIGHTHOUSE.md) | Real performance runs |
| [Testing](docs/TESTING.md) | Unit & integration |

---

## 🗑 Uninstall

Plugin data is kept by default. Enable **Delete data on uninstall** to drop tables / projects / settings.

**Generated pages, menus, media, and Elementor templates are your content — never deleted by the plugin.**

---

## 📄 License

**GPL-2.0-or-later** — [GNU GPL v2](https://www.gnu.org/licenses/gpl-2.0.html)

---

<p align="center">
  <sub>AI speed + Elementor control — without pretending the hard parts don’t exist.</sub><br>
  <a href="https://github.com/mnsise/ai-theme-studio">github.com/mnsise/ai-theme-studio</a>
</p>
