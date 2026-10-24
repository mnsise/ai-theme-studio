=== 360Dialog AI Theme Studio ===
Contributors: mnsise
Tags: elementor, ai, website builder, seo, rtl, openai, gemini, claude
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build a complete, SEO-aware, fully editable Elementor website from a written brief and reference images — powered by AI you control.

== Description ==

360Dialog AI Theme Studio turns your project description and reference images (logo, design samples, products, team) into a real WordPress site built with native Elementor documents — not static HTML dumps.

* Analyzes requirements and proposes a sitemap you can review and edit before generation.
* Creates a design system (WCAG-aware colors, typography, spacing) and registers Elementor Global styles.
* Builds every page with Flexbox containers and native Elementor widgets — fully editable in Elementor.
* Creates header, footer, navigation, and a 404 page; never replaces existing menus without permission.
* Writes SEO meta into Yoast / Rank Math / SEOPress / The SEO Framework when available, or outputs its own meta without duplicating tags.
* Audits headings, internal links, image alt text, and structured data.
* Runs real PageSpeed Insights tests for published pages — scores are never fabricated.
* Keeps all pages as drafts until you explicitly publish.

Supported AI providers: OpenAI, Anthropic Claude, Google Gemini, and any OpenAI-compatible HTTPS API.

Plugin UI includes full English (en_US) translations. Set your WordPress or user language to English to use them. Generated site language is chosen per project.

Author: Masoud Nasrollahi Far — https://360dialog.ir/ — https://github.com/mnsise/ai-theme-studio

= Privacy and data sent to AI =

For content generation, the plugin sends your project description, design preferences, and uploaded reference images to the AI provider you configure. API keys, WordPress users, and other site data are not sent. PageSpeed tests send the public page URL to Google PageSpeed Insights.

* OpenAI: https://openai.com/policies/privacy-policy
* Anthropic: https://www.anthropic.com/legal/privacy
* Google: https://policies.google.com/privacy

== Installation ==

1. Install and activate Elementor (3.16 or later).
2. Upload the ai-theme-studio folder to wp-content/plugins and activate the plugin.
3. Go to Theme Studio → Settings, enter your provider, model, and API key, then run Connection test.
4. Start a New project.

Full documentation lives in the docs folder of the plugin (and on GitHub).

== Frequently Asked Questions ==

= Is Elementor Pro required? =

No. Without Pro, header and footer are built as editable Elementor templates and shown on generated pages. With Pro, you can activate them site-wide via Theme Builder.

= What if my model does not support images? =

Image analysis is skipped and reported honestly. Images are used only according to the roles you assign; alt text may need manual completion.

= Will my Elementor edits be overwritten? =

Not unless you explicitly confirm. A backup is saved before any overwrite, and you can restore from History.

= Does the English interface work? =

Yes. Set the site or user language to English (United States). Generated site content language is chosen separately in the project form.

= Are Lighthouse scores guaranteed? =

No. Internal checks are static risk signals. Real scores come from PageSpeed Insights or Lighthouse CI. Hosting and other plugins still matter.

== Changelog ==

= 1.0.1 =
* Modernized admin UI: teal design tokens, brand header, segmented nav, refined cards and tables.

= 1.0.0 =
* Initial release: AI-powered Elementor site generation, SEO/performance audits, Persian UI with full English (en_US) translation.
