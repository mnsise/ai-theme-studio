# Task: Sitemap and navigation proposal

## Website specification
{{spec}}

## Image analysis summary
{{image_analysis}}

## Owner preferences
{{preferences}}

## Instructions
Create the list of pages for this website.
- Use exactly the page types in `required_pages` of the specification (you may add individual `service`/`product`/`case_study` pages only when the owner described specific offerings by name; max 6 such pages). Allowed page types: {{page_types}}.
- `key`: short snake_case identifier (e.g. "home", "about", "contact", "service_seo"). The homepage key must be "home"; the 404 page key must be "not_found".
- `title`: page title in the site language. `slug`: lowercase, hyphen-separated URL slug. For Persian sites, short Latin slugs are preferred (e.g. "about", "contact") unless the owner asked for Persian URLs.
- `parent`: key of the parent page for hierarchical pages (e.g. individual service pages under "services"), otherwise null.
- `menu_label`: short navigation label. `in_menu`: true for primary navigation pages (max ~7 top-level items). Legal pages and 404 are `in_menu: false` and legal pages `in_footer: true`.
- `order`: navigation order (home first).
- `noindex`: true only for the 404 page.
- `sections`: ordered list of sections for the page (id: short kebab-case, unique per page; type from {{section_types}}; summary: what the section communicates). The homepage needs a strong hero first. Plan meaningful internal links between related pages.
- `seo.title`: unique, descriptive title (≤ 60 characters) including the brand name where natural. `seo.description`: unique, compelling description (120–160 characters). No duplicates across pages. No keyword stuffing.
- `header_cta`: header button text and target page key (use the specification's primary CTA).
- `footer.about_text`: one or two sentences about the site for the footer.
