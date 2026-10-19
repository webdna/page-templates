# Plugin Store listing — draft

**Status:** draft by Claude, 19 Oct 2026. Sam owns the final text, the screenshots and the price.
Nothing here is published until Sam submits it.

## Fields

| Field | Draft |
|---|---|
| Name | Page Templates |
| Handle | `page-templates` |
| Package | `webdna/page-templates` |
| Short description | Turn a page you have already built into a reusable template, and start new pages from it. |
| Categories | Content · Editing tools |
| Keywords | templates, page templates, blueprints, matrix, entries, content, starter pages |
| Edition | One edition, paid: **$49**, renewal **$19**/year |
| Licence | Craft License |
| Requires | Craft CMS 5.11+, PHP 8.2+ (permissions need Craft Pro) |
| Documentation URL | https://github.com/webdna/page-templates |
| Changelog URL | https://raw.githubusercontent.com/webdna/page-templates/main/CHANGELOG.md |
| Repository | https://github.com/webdna/page-templates |
| Icon | `src/icon.svg` (CP section icon: `src/icon-mask.svg`) |

## Long description

Page Templates turns a page you have already built into a reusable template, and starts future
pages from it. There are no new screens for editors to learn: templates are saved from a page's
own actions menu, offered from the **New entry** button, and edited in Craft's own editor.

### Save a page as a template

Open a page you are happy with and choose **Save as a page template** from its actions menu —
the same menu holding Duplicate and Delete. Name it, and choose whether it carries the page's
content or just its layout. The page itself is not changed.

Saved **with content**, a template reproduces custom field values, nested blocks to any depth,
and relation and asset references — pointing at the same targets, so nothing is duplicated and
no image is ever copied. Saved as **layout only**, it reproduces the block skeleton with every
field empty.

### Start a page from one

The **New entry** button gains a *From template* group listing the templates available for that
section. Pick one and you land on a new, unsaved page, already assembled. Pages made from a
template are independent immediately: editing one changes neither the template nor the page it
came from.

If any part of a template could not be reproduced, the new page says so and names what is
missing before you save. A page that is not a complete copy never looks like one.

### Curate the list

The **Page Templates** section lists every template with its state. Rename and reorder them,
widen the sections they may be used in, edit a template's content in Craft's own editor, and
delete them — deleting a template never affects pages already made from it.

### Permissions that stay out of the way

- **Save a page as a template** — plus permission to edit that page.
- **Page Templates** section access — to manage the list.
- *Using* a template needs nothing extra: if you can create a page in a section, you can start
  it from a template.

Both plugin permissions are off by default. Console commands cover capturing, producing and
listing templates for scripted setups.

## Screenshots

From `_store-screenshots/shots/out/page-templates/`:

1. `01-templates.png` — the Page Templates section: every template, its kind and its state.
2. `02-edit-template.png` — a template: its name, description, allowed sections and **Edit content**.
3. `03-save-as-template.png` — *Save as a page template* from a page's actions menu.
4. `04-new-page-from-template.png` — the New entry button's *From template* group.
5. `05-permissions.png` — the two plugin permissions on a user group.

## Before submitting

- [ ] Price confirmed: $49, renewal $19
- [ ] Copy reviewed — the table and long description above
- [ ] Screenshots checked against the captions above
- [ ] GitHub repo, Packagist and the Plugin Store plugin connected
- [ ] `1.0.0` tagged with a matching `CHANGELOG.md` heading
