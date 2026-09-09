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
                '%s pages are not available in %s, so this template cannot be used there.',
                $entryType->name,
                $section->name,
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

        return new Reproduction($entry, $prepared['droppedBlockTypes']);
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
        $template->snapshot = Json::decode($record->snapshot) ?? [];
        $template->snapshotVersion = (int)$record->snapshotVersion;
        $template->sourceEntryId = $record->sourceEntryId;
        $template->sourceSiteId = $record->sourceSiteId;
        $template->sortOrder = $record->sortOrder;
        $template->uid = $record->uid;

        $template->setAllowedSectionUids(
            PageTemplateSectionRecord::find()
                ->select(['sectionUid'])
                ->where(['templateId' => $record->id])
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
