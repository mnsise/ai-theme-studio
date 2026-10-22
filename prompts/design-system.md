# Task: Global design system

## Website specification
{{spec}}

## Image analysis (overall palette and style)
{{image_overall}}

## Owner preferences (highest priority)
{{preferences}}

## Instructions
Produce design tokens that will be applied to Elementor Site Settings (global colors and typography) and used consistently on every page.

Colors (hex `#RRGGBB`):
- `primary`: main brand color (CTAs, highlights). If the owner specified colors, use them.
- `secondary`, `accent`: supporting brand colors.
- `background`: page background. `surface`: alternate section/card background (subtle contrast to background).
- `heading`, `text`, `muted`: text colors on `background`/`surface`.
- `dark`: color for dark sections; `on_dark`: text color on `dark`; `on_primary`: text color on `primary` buttons.
- `border`: subtle border color.

Accessibility requirements (mandatory, WCAG 2.2 AA):
- `text` and `heading` on `background` and on `surface`: contrast ≥ 4.5:1.
- `muted` on `background`: ≥ 4.5:1.
- `on_dark` on `dark`: ≥ 4.5:1. `on_primary` on `primary`: ≥ 4.5:1.
If the owner's palette fails, keep the hue but adjust lightness.

Typography: choose `heading_font` and `body_font` ONLY from this list (they support the site's script): {{fonts}}. Prefer at most two families and the fewest weights. "Tahoma"/"system-ui" are system fonts with zero download cost. `base_size` 15–18 px, `scale` modular scale ratio, `line_height` (Persian/Arabic text needs ≥ 1.7).

Also set radius (px), spacing density, `container_width` (px), `shadow`, `button_style`, and a short `rationale` in the site language explaining the choices.
