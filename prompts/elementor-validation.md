# Task: Fix an invalid page/section structure

Your previous output cannot be converted into a valid, editable Elementor layout. Fix every problem listed below while keeping the content and intent.

Rules reminder:
- Only the element types and fields from the original task; required fields must be present and non-empty.
- Images must use an `image_ref` that exists in the available image list; otherwise remove the image element.
- Links must be `page:<existing key>`, `#section-id`, `https://…`, `mailto:` or `tel:`.
- Groups may be nested at most two levels inside a section.
- One H1 per page, no skipped heading levels.

## Problems
{{errors}}

## Previous output
{{previous_output}}

## Original task
{{original_task}}

Return the complete corrected JSON object.
