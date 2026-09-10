# Page Templates

Lets an editor turn a page they have already built into a reusable template, and start future
pages from it — picked from the same **New entry** button they already use.

No new screens to learn: templates are saved from a page's own actions menu, offered from the
button editors already create pages with, and edited in Craft's own editor.

## Requirements

Craft CMS 5.11.0 or later, and PHP 8.2 or later.

The floor is 5.11 rather than 5.0 because Craft changed how caller-supplied nested-element UIDs
are handled in 5.11. The plugin is very likely fine on earlier 5.x releases, but that has not
been tested, and claiming it would be a guess.

## Installation

From the Plugin Store: search for **Page Templates** in **Settings → Plugins**, and install.

Or with Composer:

```bash
composer require webdna/page-templates
php craft plugin/install page-templates
```

## What a template carries

A template is a **snapshot**: a frozen copy of a page's content, taken at the moment it is saved.
Pages produced from it are independent immediately — editing one changes neither the template nor
the page it came from, and editing the template later does not reach pages already made.

Saved **with content**, a template reproduces custom field values, nested block structure and
content to any depth, and relation and asset references. References point at the *same* targets;
nothing is duplicated, so no image is ever copied. Saved as **layout only**, it reproduces the
block skeleton and nothing else — every field arrives empty.

A template is bound to the kind of page it was captured from, and carries a list of areas of the
site it may be used in, which starts as just the area the original page lived in.

## Using it

**Saving a template.** Open a page you are happy with and choose **Save as a page template** from
its own actions menu — the same menu holding Duplicate and Delete. Name it, and decide whether it
carries the page's content or just its layout. The page itself is not changed.

A new template is usable **only in the area the original page belonged to**. Widening it is a
curator's decision, made in the Page Templates section.

**Using one.** Create a page as you normally would. The **New entry** button gains a *From
template* group listing the templates available for that area. Pick one and you land on a new,
unsaved page, already assembled — give it a title and save.

The group appears once you are looking at a specific section. On *All entries* there is no single
section whose templates could be offered, so it is not shown there.

If any part of the template could not be reproduced, the new page says so and names what is
missing, before you save. A page that is not a complete copy never looks like one.

**Changing what a template holds.** Open the template and choose **Edit content**. The template
opens as a page carrying its content — edit it exactly as you would any page, then **Save
template**. It is Craft's ordinary edit screen, so every field behaves as it does everywhere else;
only the title, the save button and the **Discard** beside it differ. Pages already made from the
template are not affected.

A layout-only template has no content to edit. And if the page produced from the template is not
a complete copy of it — because a block type no longer exists, or a field could not be reproduced
— it says so when it opens and refuses to be saved back, since doing so would permanently lose
whatever was missing. You can still save it as a new template.

Only one person can edit a given template's content at a time; a second is told who has it.

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

Everything the engine does is available without a control-panel screen, which is also how it is
tested and how you would diagnose a client's content:

```bash
# Capture a page as a template. Add --structure-only for a skeleton with no content.
php craft page-templates/templates/capture --entry=33 --name="Campaign LP"

# Produce a page from it. Prints the new page's edit URL, and the block types it could not
# place — explicitly "none" when nothing was lost, so you can tell that from nobody checking.
php craft page-templates/templates/create-entry --template=1 --section=landing

# What exists, or what applies to one area. Reports unusable and unreadable templates,
# and counts allowed areas that no longer resolve.
php craft page-templates/templates/list
php craft page-templates/templates/list --section=campaigns
```

## Privacy note

A snapshot may contain personal data if the page it was captured from did. It is not attached to
any user account and is not removed when a user is deleted — a snapshot is content, and is the
template curator's to delete.

## Development

Two test suites.

**Codeception** covers the snapshot logic as pure array transforms with no Craft booted, and
storage, reproduction and content editing against a real install and real pages:

```bash
composer test
```

The integration suite **runs against a separate test database, never a development one** —
Craft's test framework drops every table in whatever database it is handed. See `codeception.yml`.

**Playwright** covers the control-panel JavaScript, which is the part neither PHP nor `curl` can
reach: the save dialogue, the New entry button, drag-reordering, and editing a template's content.
Those tests live in `tests/e2e` and need a running Craft install with the plugin enabled, so they
are run from a host project rather than from this repository alone. They expect the fixture users'
passwords in the environment — see `tests/e2e/helpers.js`.

Code style and static analysis follow Craft's own configurations:

```bash
composer check-cs
composer phpstan
```

## Support

Issues and feature requests: <https://github.com/webdna/page-templates/issues>

## Licence

See [LICENSE.md](LICENSE.md). Copyright © WebDNA.
