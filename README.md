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

## Using it

**Saving a template.** Open a page you are happy with and choose **Save as a page template** from
its own actions menu — the same menu holding Duplicate and Delete. Name it, and decide whether it
carries the page's content or just its layout. The page itself is not changed.

A new template is usable **only in the area the original page belonged to**. Widening it is a
curator's decision, made in the Page Templates section.

**Using one.** Create a page as you normally would. The **New entry** button gains a *From template*
group listing the templates available for that area. Pick one and you land on a new, unsaved page,
already assembled — give it a title and save.

If any part of the template could not be reproduced, the new page says so and names what is missing,
before you save. A page that is not a complete copy never looks like one.

**Curating.** The **Page Templates** section lists every template with its state, and is where you
rename them, order them (the order editors see in the button), widen where they may be used, and
delete ones that have served their purpose. Deleting a template never affects pages already made
from it.

## Permissions

Three capabilities, deliberately separate:

| To… | You need |
|---|---|
| Save a page as a template | **Save a page as a template**, plus permission to edit that page |
| Manage the template list | **Page Templates** under plugin access — Craft's own section permission |
| *Use* a template | nothing extra: if you can create a page in an area, you can start it from a template |

Both plugin permissions are off by default.

> **The most common misconfiguration:** granting *Save a page as a template* alone. Craft
> distinguishes a user's own entries from other people's, so an editor also needs
> `savePeerEntries` for a section before they can capture a colleague's page there. Without it the
> menu item simply will not appear on most pages.

Permission behaviour requires Craft Pro. On Solo, `User::can()` returns true for everything.

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

### Browser tests

The control-panel JavaScript — the save dialogue, the New entry button, and drag-reordering the
list — is covered by Playwright, because none of it is reachable from PHP or `curl`. It earned its
place immediately: it found three defects in the New entry button that HTTP-level checks had all
reported as working, none of which threw anything.

```bash
npx playwright test
```

Requires the environment running (`ddev start`) and the fixture users' passwords in a gitignored
`.env.e2e` at the project root:

```
PT_EDITOR_PASSWORD=…
PT_CURATOR_PASSWORD=…
```

Create the users with `ddev craft migrate/all` (they need Craft Pro) and set their passwords with
`ddev craft users/set-password <username>`.

Unlike the integration suite, these run against the **development** database — the control panel is
served from it and there is no way around that. They are written to be safe there: templates they
create are prefixed and deleted afterwards, and the pages they create are unsaved drafts that
Craft's garbage collection removes.

## Manual test plans

`test-plans/` in the host project holds runnable QA checklists for the control-panel journeys —
creating from a template, saving one, managing the list, permissions, and plugin integrity. Each
declares what it `covers:`, so `/craft-update` can recommend which to re-run after an update.

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
