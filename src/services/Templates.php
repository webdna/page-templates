<?php

namespace webdna\pagetemplates\services;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\fields\Matrix;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use webdna\pagetemplates\exceptions\UnsupportedSnapshotVersionException;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\models\Reproduction;
use webdna\pagetemplates\records\PageTemplateRecord;
use webdna\pagetemplates\records\PageTemplateSectionRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Stores templates and answers which of them apply where.
 *
 * This service knows nothing about users or permissions (BR-26) — deciding what a particular
 * editor may do is the caller's job, and keeping that out of here is what lets the console
 * commands use the same code path as the control panel.
 */
class Templates extends Component
{
    /**
     * Captures a page as a new template.
     *
     * The template is bound to that page's entry type (BR-2) and allowed, initially, only in the
     * section the page belongs to (BR-3).
     */
    public function captureFromEntry(
        Entry $entry,
        string $name,
        ?string $description,
        bool $includeContent,
    ): PageTemplate {
        $entryType = $entry->getType();
        $section = $entry->getSection();

        if ($section === null) {
            throw new InvalidArgumentException(
                'Only a page in a section can be captured; this entry is nested inside a field.',
            );
        }

        $captured = $this->snapshots()->capture($entry->getSerializedFieldValues(), $includeContent);

        $template = new PageTemplate();
        $template->name = $name;
        $template->description = $description;
        $template->entryTypeUid = $entryType->uid;
        $template->includeContent = $includeContent;
        $template->snapshot = $captured['fields'];
        $template->snapshotVersion = $captured['version'];
        $template->sourceEntryId = $entry->id;
        $template->sourceSiteId = $entry->siteId;
        $template->setAllowedSectionUids([$section->uid]);

        if (!$this->saveTemplate($template)) {
            throw new InvalidArgumentException(sprintf(
                'Could not save the template: %s',
                Json::encode($template->getErrors()),
            ));
        }

        return $template;
    }

    /**
     * Produces a new page from a template.
     *
     * Mirrors Craft's own New entry flow rather than inventing one, so the result behaves in every
     * respect like a page an editor created the ordinary way: an unpublished draft, saved with
     * `markAsSaved: false` so an abandoned page stays out of everyone's page list (BR-8).
     */
    public function reproduce(
        PageTemplate $template,
        Section $section,
        ?int $siteId = null,
        ?int $authorId = null,
    ): Reproduction {
        $entryType = $template->getEntryType();

        if ($entryType === null) {
            throw new InvalidArgumentException(
                "The template \"$template->name\" is unusable: the kind of page it makes no longer exists.",
            );
        }

        // BR-17. Craft only enforces this when an entry goes live, so an unvalidated mismatch
        // would save happily here and fail the moment somebody tried to publish it.
        $availableUids = array_map(
            fn(EntryType $available): string => $available->uid,
            $section->getEntryTypes(),
        );

        if (!in_array($entryType->uid, $availableUids, true)) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" page kind is not available in "%s", so this template cannot be used there.',
                $entryType->name,
                $section->name,
            ));
        }

        if (!$template->getIsReadable()) {
            throw new UnsupportedSnapshotVersionException(sprintf(
                'The template "%s" cannot be read: %s.',
                $template->name,
                $template->snapshotDecoded
                    ? sprintf(
                        'it was written in snapshot format %d, and this build understands up to %d',
                        $template->snapshotVersion,
                        Snapshots::FORMAT_VERSION,
                    )
                    : 'its stored snapshot is not valid JSON',
            ));
        }

        $prepared = $this->snapshots()->prepareForReproduction(
            ['version' => $template->snapshotVersion, 'fields' => $template->snapshot],
            $this->allowedBlockTypes($entryType),
        );

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        // BR-9: the example page's title, slug and dates are never carried over. The title is the
        // one thing an editor must set consciously, and every page from one template would
        // otherwise arrive identically named.
        $entry->setAuthorId($authorId);
        $entry->setFieldValues($prepared['fields']);
        $entry->setScenario(Element::SCENARIO_ESSENTIALS);

        $saved = Craft::$app->getDrafts()->saveElementAsDraft(
            $entry,
            $authorId,
            markAsSaved: false,
        );

        if (!$saved) {
            throw new InvalidArgumentException(sprintf(
                'Could not produce a page from "%s": %s',
                $template->name,
                Json::encode($entry->getErrors()),
            ));
        }

        return new Reproduction(
            $entry,
            $prepared['droppedBlockTypes'],
            $this->emptiedFields($prepared['fields'], $entry),
        );
    }

    /**
     * Field handles that held content in the snapshot but arrived empty on the page (BR-28).
     *
     * The design assumed a field's serialized form always feeds back through its own
     * normalisation, since that is the shape the control panel posts. That is true of Craft's own
     * field types and **not** universally true of third-party ones: ImageShop, for instance,
     * serializes to an array of arrays but normalises only Models or a JSON string, so its value
     * does not survive the round trip.
     *
     * Rather than maintain a list of known-lossy field types, this compares what was asked for
     * against what actually landed. It also catches a relation whose target has been deleted since
     * capture (TN-12), which is the same class of problem: content that cannot be reproduced.
     *
     * @return string[]
     */
    private function emptiedFields(array $intendedFields, Entry $entry): array
    {
        $actual = $entry->getSerializedFieldValues();
        $emptied = [];

        foreach ($intendedFields as $handle => $intended) {
            // Nothing was meant to arrive — a structure-only template, or a field that was empty
            // on the example page.
            if ($this->isEmptyFieldValue($intended)) {
                continue;
            }

            if ($this->isEmptyFieldValue($actual[$handle] ?? null)) {
                $emptied[] = $handle;
            }
        }

        return $emptied;
    }

    /**
     * Whether a serialized field value carries no actual content.
     *
     * Recursive, and it has to be: some field types normalise an unset value into a populated-
     * looking structure. ImageShop turns null into a single empty model, which serializes to an
     * array containing an array of nulls — flatly non-empty, but holding nothing. Treating a
     * structure whose every leaf is empty as empty is what stops this reporting a field the editor
     * never filled in.
     *
     * `false` and `0` are left alone: they are real values a lightswitch or number field holds.
     */
    private function isEmptyFieldValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        // Some field types serialize to JSON, and a JSON-encoded null or empty structure carries
        // no content however non-empty the string looks. ImageShop's unset value serializes to the
        // four-character string "null", which is what made this necessary. The cost is that a
        // plain-text field containing literally `null` reads as empty here; that is a fair trade
        // against reporting a field the editor never filled in.
        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === 'null') {
                return true;
            }

            if (Json::isJsonObject($trimmed)) {
                try {
                    return $this->isEmptyFieldValue(Json::decode($trimmed));
                } catch (\Throwable) {
                    return false;
                }
            }

            return false;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isEmptyFieldValue($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The entry type handles each Matrix field in this layout currently allows, at every depth.
     *
     * Field handles are globally unique in Craft 5, so one flat map covers all nesting levels.
     * The isset guard also makes a self-referencing Matrix safe to walk.
     *
     * @param array<string, string[]> $map
     * @return array<string, string[]>
     */
    private function allowedBlockTypes(EntryType $entryType, array $map = []): array
    {
        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            if (!$field instanceof Matrix || isset($map[$field->handle])) {
                continue;
            }

            $blockTypes = $field->getEntryTypes();
            $map[$field->handle] = array_map(
                fn(EntryType $blockType): string => $blockType->handle,
                $blockTypes,
            );

            foreach ($blockTypes as $blockType) {
                $map = $this->allowedBlockTypes($blockType, $map);
            }
        }

        return $map;
    }

    public function getTemplateById(int $id): ?PageTemplate
    {
        $record = PageTemplateRecord::findOne($id);

        return $record !== null ? $this->templateFromRecord($record) : null;
    }

    /**
     * Every template, in curator order then alphabetically.
     *
     * @return PageTemplate[]
     */
    public function getAllTemplates(): array
    {
        $records = PageTemplateRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        return array_map(fn(PageTemplateRecord $record) => $this->templateFromRecord($record), $records);
    }

    /**
     * The templates that apply to a section (BR-26).
     *
     * Two conditions, both required: the curator allowed this area, and the area accepts the
     * template's kind of page. The second is what stops a template producing an entry that saves
     * now and fails when someone tries to publish it — Craft only enforces that constraint when an
     * entry goes live.
     *
     * @return PageTemplate[]
     */
    public function getTemplatesForSection(Section $section): array
    {
        $availableEntryTypeUids = array_map(
            fn(EntryType $entryType): string => $entryType->uid,
            $section->getEntryTypes(),
        );

        $applies = function(PageTemplate $template) use ($section, $availableEntryTypeUids): bool {
            if (!in_array($section->uid, $template->getAllowedSectionUids(), true)) {
                return false;
            }

            return in_array($template->entryTypeUid, $availableEntryTypeUids, true);
        };

        return array_values(array_filter($this->getAllTemplates(), $applies));
    }

    private function templateFromRecord(PageTemplateRecord $record): PageTemplate
    {
        $template = new PageTemplate();
        $template->id = $record->id;
        $template->name = $record->name;
        $template->description = $record->description;
        $template->entryTypeUid = $record->entryTypeUid;
        $template->includeContent = (bool)$record->includeContent;
        $template->snapshotVersion = (int)$record->snapshotVersion;

        // A corrupt snapshot must not stop the template being listed or deleted, so record the
        // failure rather than letting a raw decoding error escape the storage layer. Reproduction
        // refuses it cleanly further down.
        try {
            $template->snapshot = Json::decode($record->snapshot) ?? [];
        } catch (\Throwable) {
            $template->snapshot = [];
            $template->snapshotDecoded = false;
        }
        $template->sourceEntryId = $record->sourceEntryId;
        $template->sourceSiteId = $record->sourceSiteId;
        $template->sortOrder = $record->sortOrder;
        $template->uid = $record->uid;

        $template->setAllowedSectionUids(
            PageTemplateSectionRecord::find()
                ->select(['sectionUid'])
                ->where(['templateId' => $record->id])
                // Ordered so getAllowedSections() is stable, as the model documents. Without
                // this the rows come back in whatever order the database chooses.
                ->orderBy(['id' => SORT_ASC])
                ->column(),
        );

        return $template;
    }

    /**
     * Saves a template and its allowed areas, in one transaction.
     */
    public function saveTemplate(PageTemplate $template, bool $runValidation = true): bool
    {
        if ($runValidation && !$template->validate()) {
            return false;
        }

        $isNew = $template->id === null;
        $record = $isNew
            ? new PageTemplateRecord()
            : PageTemplateRecord::findOne($template->id);

        if ($record === null) {
            throw new InvalidArgumentException("No template exists with the id $template->id.");
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record->name = $template->name;
            $record->description = $template->description;
            $record->entryTypeUid = $template->entryTypeUid;
            $record->includeContent = $template->includeContent;
            $record->snapshot = Json::encode($template->snapshot);
            $record->snapshotVersion = $template->snapshotVersion;
            $record->sourceEntryId = $template->sourceEntryId;
            $record->sourceSiteId = $template->sourceSiteId;
            $record->sortOrder = $template->sortOrder ?? $this->nextSortOrder();
            $record->save(false);

            $template->id = $record->id;
            $template->uid = $record->uid;
            $template->sortOrder = $record->sortOrder;

            $this->saveAllowedSections($template);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Sets the order templates are offered in (BR-14).
     *
     * Ids that no longer resolve are skipped rather than treated as an error: a curator dragging
     * rows while someone else deletes one should not lose the ordering they just set. The surviving
     * rows are still numbered contiguously.
     *
     * @param int[] $ids in the desired order
     */
    public function reorderTemplates(array $ids): bool
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $sortOrder = 0;

            foreach ($ids as $id) {
                $record = PageTemplateRecord::findOne((int)$id);

                if ($record === null) {
                    continue;
                }

                $record->sortOrder = ++$sortOrder;
                $record->save(false);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Deletes a template.
     *
     * Pages produced from it are untouched (BR-19), because a snapshot is a copy: nothing built
     * from a template ever refers back to it. The allowed-area rows go with it through the
     * cascade declared in the install migration.
     */
    public function deleteTemplate(PageTemplate $template): bool
    {
        if ($template->id === null) {
            return false;
        }

        $record = PageTemplateRecord::findOne($template->id);

        if ($record === null) {
            return false;
        }

        return (bool)$record->delete();
    }

    /**
     * Replaces a template's allowed areas with what the model now holds.
     */
    private function saveAllowedSections(PageTemplate $template): void
    {
        Db::delete(PageTemplateSectionRecord::tableName(), ['templateId' => $template->id]);

        foreach ($template->getAllowedSectionUids() as $sectionUid) {
            $row = new PageTemplateSectionRecord();
            $row->templateId = $template->id;
            $row->sectionUid = $sectionUid;
            $row->save(false);
        }
    }

    private function nextSortOrder(): int
    {
        return (int)PageTemplateRecord::find()->max('[[sortOrder]]') + 1;
    }

    private function snapshots(): Snapshots
    {
        return \webdna\pagetemplates\PageTemplates::getInstance()->snapshots;
    }
}
