# Task: Website planning (structured website specification)

Analyse the site owner's description and preferences and produce a structured website specification.

## Site owner's description
{{user_prompt}}

## Preferences selected in the form (JSON)
{{preferences}}

## Reference images supplied (roles assigned by the owner; contents analysed separately)
{{image_summary}}

## Instructions
- `site_name`: the business/brand name if given; otherwise a short neutral working title in the site language (the owner can change it).
- `site_type`: one of {{site_types}}. Use the owner's selected category unless the description clearly contradicts it.
- `language`: "{{site_language}}". `direction`: "{{direction}}".
- `required_pages`: choose only page types that this specific website needs, from: {{page_types}}. Always include "home". Include "contact" for businesses. Include "privacy" when the site collects any personal data (contact forms, newsletters, e-commerce). Include "terms" for SaaS/e-commerce/services with contracts. Include "not_found" (404). Include "blog" only if the owner asked for a blog/news/articles or it clearly fits. Do not add pages the site does not need.
- `primary_cta`: the single most important conversion action (text in site language) and the page key it points to (usually "contact" or "pricing").
- `design_intent`: summarise the visual direction requested (style keywords, color notes, typography notes, layout notes). Keep explicit owner requirements verbatim in meaning.
- `business`: copy contact details ONLY if the owner wrote them explicitly. Otherwise use null. Never invent.
- `warnings`: list anything you could not satisfy or that needs real data from the owner (e.g. testimonials, prices, team names).
