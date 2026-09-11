# Page Templates

Turn a page you have already built into a reusable template, and start future pages from it.


## Requirements

This plugin requires Craft CMS 5.11+ and PHP 8.2+.

The floor is 5.11 rather than 5.0 because Craft changed how caller-supplied nested-element UIDs are handled in 5.11. Earlier 5.x releases are likely fine but untested.


## Installation

Install the plugin either via the plugin store or via composer:

`composer require webdna/page-templates && php craft plugin/install page-templates`

While this is a pre-release, Composer will not resolve it under the default `stable` minimum, so ask for the beta explicitly:

`composer require webdna/page-templates:^1.0@beta && php craft plugin/install page-templates`


## Overview

A template is a **snapshot**: a frozen copy of a page's content, taken when it is saved. Pages made from it are independent immediately — editing one changes neither the template nor the page it came from, and editing the template later does not reach pages already made.

Saved **with content**, a template reproduces custom field values, nested block structure and content to any depth, and relation and asset references. References point at the same targets, so nothing is duplicated and no image is ever copied. Saved as **layout only**, it reproduces the block skeleton with every field empty.

A template is bound to the kind of page it was captured from, and carries the areas of the site it may be used in — starting as just the area the original page lived in.

There are no new screens for editors to learn: templates are saved from a page's own actions menu, offered from the **New entry** button, and edited in Craft's own editor.


## Usage

**Saving a template.** Open a page you are happy with and choose **Save as a page template** from its actions menu — the same menu holding Duplicate and Delete. Name it, and choose whether it carries the page's content or just its layout. The page itself is not changed.

**Using one.** Create a page as you normally would. The **New entry** button gains a *From template* group listing the templates available for that area. Pick one and you land on a new, unsaved page, already assembled. The group appears once a specific section is selected — on *All entries* there is no single section whose templates could be offered.

If any part of a template could not be reproduced, the new page says so and names what is missing before you save. A page that is not a complete copy never looks like one.

**Editing a template's content.** Open the template and choose **Edit content**. The template opens as a page in Craft's own editor, so every field behaves as it does anywhere else; only the title, the save button and the **Discard** beside it differ. Choose **Save template** to keep the changes. Pages already made from the template are not affected.

A layout-only template has no content to edit. If the page produced from a template is not a complete copy of it, it says so on opening and refuses to be saved back — doing so would permanently lose whatever could not be reproduced. Saving it as a new template is still offered. Only one person can edit a given template's content at a time.

**Curating.** The **Page Templates** section lists every template with its state, and is where you rename them, reorder them (the order editors are offered them in), widen where they may be used, and delete them. Deleting a template never affects pages already made from it.


## Permissions

Three capabilities, deliberately separate:

| To… | You need |
|---|---|
| Save a page as a template | **Save a page as a template**, plus permission to edit that page |
| Manage the template list | **Page Templates** under plugin access — Craft's own section permission |
| *Use* a template | nothing extra: if you can create a page in an area, you can start it from a template |

Both plugin permissions are off by default.

The most common misconfiguration is granting *Save a page as a template* alone. Craft distinguishes a user's own entries from other people's, so an editor also needs `savePeerEntries` for a section before they can capture a colleague's page there — without it the menu item will not appear on most pages.

Permission behaviour requires Craft Pro. On Solo, `User::can()` returns true for everything.


## Console commands

Everything the plugin does is available without a control-panel screen, which is also how it is tested and how you would diagnose a client's content:

```bash
# Capture a page as a template. Add --structure-only for a skeleton with no content.
php craft page-templates/templates/capture --entry=33 --name="Campaign LP"

# Produce a page from it. Prints the new page's edit URL, and the block types it could not
# place — explicitly "none" when nothing was lost.
php craft page-templates/templates/create-entry --template=1 --section=landing

# What exists, or what applies to one area.
php craft page-templates/templates/list
php craft page-templates/templates/list --section=campaigns
```


## Privacy

A snapshot may contain personal data if the page it was captured from did. It is not attached to any user account and is not removed when a user is deleted — a snapshot is content, and is the template curator's to delete.


## Tests

Codeception covers the snapshot logic as pure array transforms, and storage, reproduction and content editing against a real install:

`composer test`

The integration suite runs against a separate test database, never a development one — Craft's test framework drops every table in whatever database it is handed. See `codeception.yml`.

Playwright covers the control-panel JavaScript, which neither PHP nor `curl` can reach: the save dialogue, the New entry button, drag-reordering, and editing a template's content. Those tests are in `tests/e2e` and need a running Craft install with the plugin enabled, so they are run from a host project rather than from this repository alone.
