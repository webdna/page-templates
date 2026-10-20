---
spec: Page Templates 1.1
slug: page-templates-1-1
status: draft
version: 0.1
date: 2026-10-20
author: Claude (with Sam Birch)
client: webdna (Craft Plugin Store product)
approver: Sam Birch
related: [webdna/page-templates #1, #2, #3, #4]
---

# Page Templates 1.1

> **Status:** draft - **Version:** 0.1
> The first issues against 1.0.0, decided with Sam on 20 Oct 2026: a page that is not a complete
> copy of its template always says so — naming nested losses, on every view until it is first
> saved, inside the edit screen's layout — and a layout-only template can keep a site's option fields.

The 1.0 rules live in the sandbox's specs (`~/projects/craft5/docs/specs/2026-09-09-page-templates-{engine,editor}.md`;
they end at BR-33). **Rules here are numbered on from BR-40**, so a `BR-n` in a code comment is never ambiguous.

---

## 1. How it works

**Lost values (#1).** After a page is produced, what was meant to arrive (the prepared snapshot)
is compared with what did (the page's serialized values). 1.0 compared top-level fields only, so a
relation inside a Matrix block whose target had been deleted went unreported: the Matrix field was
still non-empty. 1.1 walks block lists at every depth, pairing blocks by position, and reports a
nested loss as a path: `blocks › imageBlock › image`.

**A warning that stays (#2).** 1.0 carried the warning in a one-shot session flash, so a reload lost
it. 1.1 stores what was lost against the new page in its own table, and every view of that page shows
it until the page is first saved (the draft applied) or discarded (the element deleted).

**Inside the layout (#3).** 1.0 rendered the warning on `cp.layouts.base`, which Craft outputs right
after `<body>` — above the sidebar and header on every install, stock included. 1.1 adds it to the edit
screen's own content, in the same `Response::EVENT_BEFORE_SEND` step that already reshapes scratch pages,
so it sits at the top of `#content.content-pane`, above the fields, on every tab.

**Option fields in a layout-only template (#4).** A layout-only capture empties every value. A site
whose blocks keep their look in a field (layout, spacing, tone) loses the part a designer hands over.
1.1 adds a `keepInLayoutOnly` config setting naming field classes or handles whose values a
layout-only capture keeps, and an event in `Snapshots::stripContent()` for anything a list cannot say.
With neither set, a layout-only capture is byte-for-byte what 1.0 wrote.

## 2. Scope

**In:** the four issues above; README and CHANGELOG; version 1.1.0.

**Out:** replying to or closing the GitHub issues (Sam decides when); a settings screen for
`keepInLayoutOnly` (`$hasCpSettings` stays false, for the reason in `PageTemplates.php`); reporting
which *block* of several identical ones lost a value (paths are deduplicated, like dropped block types);
the scratch-page notice, which already persists in `pagetemplates_editing`.

## 3. User journeys

1. An editor starts a page from a template whose first block's image has since been deleted. The page
   opens with the warning inside the content pane, naming `blocks › imageBlock › image`.
2. They reload, or come back tomorrow: the warning is still there. They choose **Create entry**; the
   page saves, and opening it again shows no warning. Had they deleted the draft instead, the stored
   warning goes with it.
3. A developer adds `'keepInLayoutOnly' => [DesignField::class]` to `config/page-templates.php`. A
   curator saves a page as a layout-only template; pages made from it arrive with every block's design
   options set and every other field empty.

## 4. Data model

New table `pagetemplates_incomplete` (migration `m261020_000000_incomplete_pages`, and in `Install`):

| Column | Notes |
|---|---|
| `id`, `dateCreated`, `dateUpdated`, `uid` | Craft's usual |
| `elementId` | int, not null, **unique**, FK `elements.id` ON DELETE CASCADE — the new page (an unpublished draft keeps its id when applied) |
| `templateName` | varchar 255 — the name as it was when the page was made; the template may be renamed or deleted later |
| `lostDetail` | text, JSON `{"droppedBlockTypes":[…],"emptiedFields":[…]}`, the shape `pagetemplates_editing` uses |

Settings model `webdna\pagetemplates\models\Settings`: `keepInLayoutOnly` — `string[]`, default `[]`.

## 5. Rules

| ID | Rule |
|---|---|
| BR-40 | A value that held content in the prepared snapshot and arrived empty is reported **at any depth**. Block lists are walked recursively; a nested loss is reported as a path of handles joined by ` › `: field, block type, field, … (`pageBuilder › blockHeader › siteLogo`). A top-level field is still reported by its bare handle |
| BR-41 | Blocks are paired **by position** within one field's list: the prepared list (dropped block types already removed) against the page's list, in order. A prepared block with no counterpart on the page is reported as `field › blockType`. A field whose every block is gone is reported by its handle alone, not once per block. Disabled blocks are absent from both lists — Craft's `Matrix::serializeValue()` omits them on capture and on the new page alike (probed 20 Oct 2026) — so they cannot misalign the pairing |
| BR-42 | Paths are deduplicated, in first-seen order. Five image blocks missing their image are one problem |
| BR-43 | The comparison is a pure transform in `Snapshots` — arrays in, a list of paths out — so it is unit-tested without Craft. `Templates` only feeds it |
| BR-44 | When a page made by the **From template** button is not a complete copy, what was lost is stored against that page (§4), not in the session. Every view of the page's edit screen shows the warning, to anyone who opens it |
| BR-45 | The stored warning is removed when the page is **first saved** (`Drafts::EVENT_AFTER_APPLY_DRAFT` for its id) or **discarded** (`Elements::EVENT_AFTER_DELETE_ELEMENT`, soft or hard; the foreign key covers Craft's garbage collection of abandoned drafts). Autosaves of the draft do not remove it |
| BR-46 | The warning is rendered **inside the edit screen's content pane**: prepended to the CP screen's content on `Response::EVENT_BEFORE_SEND`, never on `cp.layouts.base`. It renders nothing on any other screen and never more than once |
| BR-47 | `keepInLayoutOnly` (in `config/page-templates.php`) lists field classes and field handles. A class matches a field of that class or a subclass; any other string matches a field whose layout handle or original handle equals it. It applies at every nesting depth. A kept value is captured exactly as a with-content capture would capture it (uids removed) |
| BR-48 | `Snapshots::EVENT_DEFINE_KEPT_VALUE` (`KeepValueEvent`: `handle`, `path`, `value`, `keep`) is raised for each field value in a layout-only capture, with `keep` preset from BR-47; a handler may set it either way. Raised only when something listens |
| BR-49 | With `keepInLayoutOnly` empty and no handler, a layout-only capture is identical to 1.0's. With-content captures are never affected |
| BR-50 | `Snapshots` stays free of a booted Craft: the config is resolved to layout handles in `Templates` (which has the field layout) and passed in |

## 6. Interfaces

- `Snapshots::capture(array $fieldValues, bool $includeContent, array $keepHandles = [])`
- `Snapshots::stripContent(array $fieldValues, array $keepHandles = [])` — raises BR-48's event
- `Snapshots::lostValues(array $intended, array $actual): string[]` — BR-40–43; `isEmptyFieldValue()` moves here from `Templates`
- `Templates::keptHandles(EntryType $entryType): string[]` — BR-47's resolution over the layout and every nested block type
- `Templates::rememberIncomplete(Reproduction, PageTemplate)`, `incompleteFor(int $elementId): ?array`, `forgetIncomplete(int $elementId)`
- `CreateController::INCOMPLETE_FLASH_PREFIX` — kept, `@deprecated`, no longer written or read
- Config: `config/page-templates.php` → `['keepInLayoutOnly' => [MyDesignField::class, 'lightswitchHandle']]`

## 7. Test plan

| ID | Scenario | Proves |
|---|---|---|
| TS-1 | Unit: intended `blocks` with an image id, actual with the image list empty → `['blocks › imageBlock › image']`; three levels deep → a three-step path; actual one block short → `blocks › textBlock`; every block gone → `blocks`; two identical losses → one path | BR-40–42 |
| TS-2 | Integration (#1's repro): a page whose first block's image is captured, the asset then deleted; reproduce → `emptiedFields` names `blocks › imageBlock › image`, `isFaithful()` false | BR-40 |
| TS-3 | Integration (#2): remember an incomplete page → `incompleteFor()` returns it on a second read; apply the draft → gone; another page, delete it → gone | BR-44, 45 |
| TS-4 | Unit (#4): `stripContent` with `keepHandles: ['design']` keeps a nested `design` value and empties its siblings; a handler flips `keep` both ways; no handles and no handler → output equal to 1.0's | BR-47–49 |
| TS-5 | Integration (#4): `keepInLayoutOnly` set to a field class, then a handle; a layout-only capture keeps that field at top level and inside a block | BR-47, 50 |
| TS-6 | craft5, browser: #1's repro end to end — the warning names the nested path | BR-40 |
| TS-7 | craft5, browser: #2's repro — warning, reload, still there; **Create entry**, reopen → gone | BR-44, 45 |
| TS-8 | craft5, browser: #3 — the warning's nearest `#content` ancestor exists and it is not a direct child of `body`; screenshot | BR-46 |
| TS-9 | craft5: #4's repro — a layout-only template with the config set keeps the option field's value in its snapshot; without it, empty | BR-47, 49 |

**Negative:** a page from a faithful template has no row and no warning; a scratch page (content
editing) never writes a row (its notice is `pagetemplates_editing`'s); a `keepInLayoutOnly` class that
does not exist is ignored, not fatal.

## 8. Build order

One phase; the four issues are independent but share `Templates`/`Snapshots`, so built in this order.

### Tasks

- [x] **1.1 Nested lost values (#1)** — `src/services/Snapshots.php`, `src/services/Templates.php`, `tests/unit/SnapshotsTest.php`, `tests/integration/DegradedTemplatesTest.php`
      Rules: BR-40–43 · Verify: TS-1, TS-2, TS-6
- [x] **1.2 Persistent warning (#2)** — `src/migrations/{Install,m261020_000000_incomplete_pages}.php`, `src/records/IncompletePageRecord.php`, `src/services/Templates.php`, `src/controllers/CreateController.php`, `src/PageTemplates.php` (schemaVersion 1.1.0, the two clearing listeners), `tests/integration/IncompletePagesTest.php`
      Rules: BR-44, BR-45 · Verify: TS-3, TS-7
- [ ] **1.3 Warning inside the layout (#3)** — `src/PageTemplates.php`, `src/templates/_warning.twig`
      Rules: BR-46 · Verify: TS-8
- [ ] **1.4 keepInLayoutOnly (#4)** — `src/models/Settings.php`, `src/events/KeepValueEvent.php`, `src/services/Snapshots.php`, `src/services/Templates.php`, `src/PageTemplates.php`, tests as TS-4/5
      Rules: BR-47–50 · Verify: TS-4, TS-5, TS-9
- [ ] **1.5 Release notes** — `README.md` (config + event), `CHANGELOG.md` (1.1.0, unreleased), `composer.json` untouched (version comes from the tag)
      Verify: B5

---

# Appendix B - build scaffolding

## B1. Before writing anything

Read the triage memory (`plugin-issue-triage-20-oct.md`) and the four issues. The 1.0 rules are in
the craft5 specs named at the top; this spec changes BR-10's delivery only, never what it promises.

## B2. Context to load

1. `src/services/{Templates,Snapshots}.php`, `src/controllers/CreateController.php`, `src/PageTemplates.php` (the hook at 153, `adaptScratchPageScreen()`), `src/templates/_warning.twig`.
2. `vendor/craftcms/cms/src/fields/Matrix.php` `serializeValue()` (811) — blocks come out of `$value->all()`, keyed by id.
3. `vendor/craftcms/cms/src/services/Drafts.php` `applyDraft()` (300–375) — an unpublished draft is its own canonical, so its id survives the apply.
4. `vendor/craftcms/cms/src/controllers/ElementsController.php` ~1100–1150 — `contentHtml` is set on the behavior as a string.
5. `vendor/craftcms/cms/src/templates/_layouts/cp.twig` ~265–300 — `#content.content-pane` wraps `content`.

## B3. Guardrails

- **Do not boot Craft in `Snapshots`** — BR-50; its unit suite runs without the framework.
- **Do not change a with-content capture or a 1.0 layout-only capture's output** — existing templates
  must reproduce exactly as before (BR-49).
- **Do not write the incomplete row for a scratch page** — its own notice and BR-30 already cover it.
- **Do not set `noticeHtml`** for the warning — Craft and the scratch-page code use that slot.
- **Never comment on or close the GitHub issues, never push.**
- **craft5:** run ddev only from `~/projects/craft5` (lowercase), never commit there.

## B4. Definition of done

- [ ] TS-1–TS-9 pass; each issue's own repro passes in craft5
- [ ] Unit and integration suites: no new failures against the baseline below
- [ ] CHANGELOG and README describe the change

## B5. Verification

```bash
# from ~/projects/craft5
ddev exec "vendor/bin/codecept run unit -c plugins/page-templates"
# expect: OK, more than the 15 tests of 1.0.0
ddev exec "vendor/bin/codecept run integration -c plugins/page-templates"
# expect: only the two failures present before 1.1 (AccessTest both-permissions, DegradedTemplatesTest
# imageshop — the test install loads page-templates alone, so ImageShop is a missing field there)
ddev exec "php -l plugins/page-templates/src/..." for each changed file
```

Baseline, 20 Oct 2026 before any change: unit OK (15 tests, 26 assertions); integration 39 tests,
2 failures (named above).

## B6. Out of bounds

- GitHub (issues, pushes). `~/Projects/lll`. Committing in craft5.

---

## Change log

| Date | Version | Change | By |
|---|---|---|---|
| 2026-10-20 | 0.1 | First draft, from the triage of 20 Oct 2026 | Claude (with Sam Birch) |
