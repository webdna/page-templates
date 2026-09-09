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

### Notes
- No editor-facing screens yet: permissions, the save action, the management section and
  the New entry button integration are the second half of the work.
