# Task: Generate one page

## Website specification
{{spec}}

## Design system (already applied globally — do not output colors or fonts)
{{design}}

## All pages of the site (use `page:<key>` links between them)
{{sitemap}}

## Page to generate
{{page}}

## Available uploaded images (use ONLY these refs; never invent images)
{{images}}

## Available features on this installation
{{capabilities}}

## Element vocabulary
Each section is a container. `elements` may contain these element types only: {{element_types}}.
- `heading`: `text`, `level` (h1–h6 or p), optional `align`.
- `text`: `html` — paragraphs using only <p>, <strong>, <em>, <a href>, <ul>, <ol>, <li>, <br>.
- `button`: `text`, `link`, `style` (primary | secondary | link), optional `align`.
- `image`: `image_ref` (img_N from the list), `alt` (site language) or `decorative: true`.
- `icon_box`: `icon`, `title`, `description`, `level` (usually h3). Icons allowed: {{icons}}.
- `icon_list`: `items` [{ `text`, `icon`, optional `link` }].
- `testimonial`: `text`, `name`, `role`. Only as clearly marked placeholders unless the owner supplied real testimonials.
- `accordion`: `items` [{ `title`, `content` }], `level` (h3) — good for FAQs.
- `spacer` (`size` px), `divider`.
- `posts_grid`: latest blog posts with `count` (only on blog/home pages when a blog exists).
- `contact_form`: renders the site's contact form when available (see features).
- `group`: nested container: `direction` (row | column), `style` (card | plain), optional `width` (%), `elements`. Use a `row` group containing `card` groups for feature/service/pricing grids (2–4 columns). Maximum nesting: section > group > group.

Links: `page:<key>` for internal pages, `#section-id` for anchors on the same page, `https://…`, `mailto:` or `tel:` (only real contact details from the specification).

## Instructions
- Follow the planned sections of this page in order; each section `id` must be the planned id.
- Exactly ONE `h1` on the page: the main heading of the first section. Use `h2` for every section heading and `h3` for cards/items inside sections. Never choose heading levels for visual size.
- Section `background`: alternate `default` and `surface` for rhythm; use `primary` or `dark` sparingly for hero/CTA. Use `image` only with an uploaded image ref that suits a background.
- Hero: concise headline, one supporting sentence, primary CTA (+ optional secondary). If an uploaded image has `target_section` hero or role hero/product, use it as an `image` element (direction row layout) — it will be the LCP image.
- Keep copy specific to this business, scannable and honest. Do not fabricate statistics, clients, prices, testimonials or legal facts. Pricing without owner-supplied prices must use placeholder amounts marked as samples.
- Legal pages (privacy, terms): provide a sensible structure with headings and clearly mark that the text is a template that must be reviewed by a legal professional.
- 404 page: short helpful message, links to the homepage and key pages.
- Keep the DOM lean: avoid unnecessary groups, spacers and decorative elements. Max ~12 sections.
