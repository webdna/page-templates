# Release Notes for Page Templates

## 1.0.0 - 2026-09-10

Initial release.

### Added

- Save a page you have already built as a reusable template, from that page's own actions
  menu. A template can carry the page's content, or just its layout as an empty skeleton.
- Start new pages from a template using Craft's existing **New entry** button, which gains a
  *From template* group listing the templates available for that section.
- Templates reproduce custom field values, nested block structure and content to any depth,
  and relation and asset references — references point at the same targets, so nothing is
  duplicated and no asset is ever copied.
- A **Page Templates** control-panel section for renaming templates, reordering them (the
  order editors are offered them in), widening where they may be used, and deleting them.
  Deleting a template never affects pages already made from it.
- Edit the content a template holds: the template opens as a page in Craft's own editor, and
  saving captures it back. Every field type therefore behaves exactly as it does anywhere
  else. A layout-only template has no content to edit and says so.
- A warning on any page that is not a complete reproduction of its template, naming the block
  types that could not be placed and the fields that arrived empty — a page that is not a
  complete copy never looks like one.
- Two permissions, both off by default: one to save a page as a template, and Craft's own
  plugin-access permission to manage the list. Using a template needs neither.
- Console commands `page-templates/templates/capture`, `create-entry` and `list`, so templates
  can be created, used and diagnosed without any control-panel screen.
- Every user-facing string is translatable, including those in JavaScript.

### Notes

- **Editing a template's content is refused when the page produced from it is not a complete
  copy.** Saving it back would permanently lose whatever could not be reproduced, so the
  refusal is shown when the page opens rather than after the work is done. Saving it as a new
  template is still offered.
- Only one person can edit a given template's content at a time. A second is told who has it.
- Snapshots record the format version they were written with. A version this build cannot read
  is refused rather than guessed at, and a corrupt snapshot is reported as unreadable while
  remaining listable and deletable.
- Saving a template from a page authored by somebody else also requires Craft's
  `savePeerEntries` permission for that section — the most common misconfiguration.
- Managing templates is gated by Craft's own plugin-access permission rather than a second
  custom one, so there is a single tickbox rather than two that have to agree.
