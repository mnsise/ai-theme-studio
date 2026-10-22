# Task: Reference image and design analysis

You receive {{image_count}} images. Each image is preceded by a label "Image reference: img_N".

## Owner-assigned roles and file names
{{images}}

## Site owner's description (context only)
{{user_prompt}}

## Instructions
For every image return one entry in `images` with the exact `ref`:
- `suggested_role`: one of {{roles}}. Respect the owner-assigned role unless it is "auto". Do NOT assume every image is a hero image. Screenshots of other websites are "homepage_reference" or "inspiration", not assets.
- `use_as_asset`: true only if the image itself should appear on the site (logo, product photos, team photos, original photography owned by the site owner). False for screenshots of other websites and pure inspiration.
- `target_section`: when `use_as_asset` is true, the most suitable section type (hero, about, features, gallery, team, products...), otherwise null.
- `description`: what the image shows.
- `alt_text`: concise, descriptive alternative text in {{site_language}} (max ~120 chars) for meaningful images; empty string when decorative.
- `decorative`: true when the image adds no information (textures, abstract backgrounds).
- `dominant_colors`: up to 6 hex colors actually present.
- `layout_notes`: composition, grid, spacing, visual hierarchy, section structure you observe (for reference screenshots).
- `typography_notes`: type style observed (serif/sans, weight, scale contrast).
- `contains_third_party_branding`: true if logos/brand names that are not the owner's are visible. Such branding must never be reproduced.

`overall`: a combined palette (hex), style keywords, layout patterns worth reusing as guidance, spacing density and corner style.
