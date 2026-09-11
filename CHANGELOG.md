# Page Templates Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.0.0-beta - 2026-09-11
### Added
- Save a page as a reusable template from that page's own actions menu, carrying its content or just its layout
- Templates offered from Craft's existing **New entry** button, filtered to those that apply to the section and that the editor may use
- Templates reproduce custom field values, nested block structure and content to any depth, and relation and asset references — references point at the same targets, so nothing is duplicated
- **Page Templates** control-panel section for renaming, reordering, re-scoping and deleting templates
- Edit the content a template holds: the template opens as a page in Craft's own editor, and saving captures it back
- A warning on any page that is not a complete reproduction of its template, naming the block types that could not be placed and the fields that arrived empty
- Two permissions, both off by default: one to save templates, and Craft's own plugin-access permission to manage the list. Using a template needs neither
- Console commands `page-templates/templates/capture`, `create-entry` and `list`
- Every user-facing string translatable, including those in JavaScript

### Notes
- Editing a template's content is refused when the page produced from it is not a complete copy, since saving it back would permanently lose whatever could not be reproduced. The refusal is shown when the page opens, not after the work is done
- Only one person can edit a given template's content at a time
- Snapshots record the format version they were written with. A version this build cannot read is refused rather than guessed at, and a corrupt snapshot is reported as unreadable while remaining listable and deletable
- Saving a template from a page authored by somebody else also requires Craft's `savePeerEntries` permission for that section
