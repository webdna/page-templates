# Release Notes for Page Templates

## Unreleased

### Added
- Capture an existing page as a reusable template, with its content or as a
  structure-only skeleton.
- Produce new pages from a template, reproducing custom field values, nested block
  structure and content to any depth, and relation and asset references — which point
  at the same targets rather than being copied.
- Templates are bound to the kind of page they were captured from, and carry a list of
  areas of the site they may be used in, starting as just the area the original page
  belonged to.
- Reproduction reports every block type it could not place, so nothing is ever lost in
  silence. Craft itself drops a block whose type a field no longer allows with no error
  and no log entry.
- Console commands `page-templates/templates/capture`, `create-entry` and `list`, so the
  engine is usable and diagnosable without any control-panel screen.
- Snapshots record the format version they were written with. A version this build cannot
  read is refused rather than guessed at, and a corrupt snapshot is reported as unreadable
  while remaining listable and deletable.

- **Save as a page template** on a page's own actions menu, with a dialogue for the name,
  description and whether the template carries content.
- Templates offered from Craft's existing **New entry** button, filtered to the templates that
  apply to that area and that the editor may use. The button is left exactly as Craft builds it
  when no template applies.
- A **Page Templates** control-panel section for renaming, reordering, re-scoping and deleting
  templates, with an empty state that explains the feature.
- A warning on any page that is not a complete reproduction of its template, naming the block types
  that could not be placed and the fields that arrived empty.
- Two permissions, both off by default: one to save templates, one (Craft's own plugin-access
  permission) to manage the list. Using a template needs neither.
- Every user-facing string translatable, including those in JavaScript.

### Notes
- Managing templates is gated by Craft's own plugin-access permission rather than a second custom
  one, so there is a single tickbox rather than two that must agree.
- Saving a template from a page authored by someone else also requires Craft's `savePeerEntries`
  permission for that section.
