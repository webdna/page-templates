# Page Templates

Lets an editor turn a page they have already built into a reusable template, and start
future pages from it — picked from the same **New entry** button they already use.

Built in two halves, specified in `docs/specs/`:

- **Engine** (`page-templates-engine`) — capture, storage and reproduction, driven by console
  commands and covered by tests. No editor-facing surface.
- **Editor experience** (`page-templates-editor`) — permissions, the save action, the management
  section, and the New entry button.

## What a template carries

A template is a **snapshot**: a frozen copy of a page's content, taken at the moment it is saved.
Pages produced from it are independent immediately — editing one changes neither the template nor
the page it came from, and editing the template later does not reach pages already made.

Saved **with content**, a template reproduces custom field values, nested block structure and
content to any depth, and relation and asset references. References point at the *same* targets;
nothing is duplicated, so no image is ever copied. Saved as **structure only**, it reproduces the
block skeleton and nothing else — every field arrives empty.

A template is bound to the kind of page it was captured from, and carries a list of areas of the
site it may be used in, which starts as just the area the original page lived in.

## Console commands

The engine is fully usable without any control-panel screen, which is also how it is tested and
how you would diagnose a client's content:

```bash
# Capture a page as a template. Add --structure-only for a skeleton with no content.
ddev craft page-templates/templates/capture --entry=33 --name="Campaign LP"

# Produce a page from it. Prints the new page's edit URL, and the block types it could not
# place — explicitly "none" when nothing was lost, so you can tell that from nobody checking.
ddev craft page-templates/templates/create-entry --template=1 --section=landing

# What exists, or what applies to one area. Reports unusable and unreadable templates,
# and counts allowed areas that no longer resolve.
ddev craft page-templates/templates/list
ddev craft page-templates/templates/list --section=campaigns
```

## Tests

Codeception, with two suites. The unit suite covers the snapshot logic as pure array transforms
with no Craft booted; the integration suite exercises storage and reproduction against a real
install and real pages.

```bash
ddev exec "vendor/bin/codecept run -c plugins/page-templates"
```

**The integration suite runs against a separate `db_test` database, never the development one** —
Craft's test framework drops every table in whatever database it is handed.

## Requirements

Craft CMS 5.11.0 or later, PHP 8.2 or later.

Everything in this plugin has been verified against Craft 5.11.1. The floor is 5.11 rather than
5.0 because Craft changed how caller-supplied nested-element UIDs are handled in 5.11; the plugin
is very likely fine on earlier 5.x releases, but that has not been tested, and claiming it would
be a guess.

## Privacy note

A snapshot may contain personal data if the page it was captured from did. It is not attached to
any user account and is not removed when a user is deleted — a snapshot is content, and is the
template curator's to delete.
