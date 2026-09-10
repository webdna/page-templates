<?php

namespace webdna\pagetemplates\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\Section;
use webdna\pagetemplates\exceptions\IncompleteReproductionException;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\models\Reproduction;
use webdna\pagetemplates\PageTemplates;
use webdna\pagetemplates\records\EditingDraftRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Editing the content a template holds.
 *
 * A snapshot is not editable in place — it is Craft's serialized field data, and the only thing
 * that can meaningfully edit that is Craft's own element editor. So a template's content is
 * edited by producing a **scratch page** from it, editing that page normally, and capturing it
 * back over the template. Every field type, Matrix included, therefore works exactly as it does
 * anywhere else, with no field UI of this plugin's own to keep in step with Craft.
 *
 * The scratch page is an unpublished draft, so it stays out of editors' entry indexes, and Craft
 * garbage-collects it if the curator wanders off.
 *
 * **The danger this service exists to contain.** Reproduction is not always lossless: a block type
 * the layout no longer allows is dropped, and a field whose serialized form does not round-trip
 * comes back empty (BR-10, BR-28). Until now that only ever affected a *new page* — the template
 * itself stayed pristine. Saving back over the template turns the same loss into the permanent
 * destruction of whatever could not be reproduced. So faithfulness is recorded when the scratch
 * page is produced, and an unfaithful one is refused at save time (BR-30). By then the
 * information is gone, which is why it is recorded up front rather than recomputed.
 */
class ContentEditing extends Component
{
    /**
     * Starts editing a template's content, or resumes the caller's own unfinished session.
     *
     * @throws InvalidArgumentException if the template's content cannot be edited, or somebody
     * else is already editing it.
     */
    public function begin(PageTemplate $template, ?int $siteId = null, ?int $userId = null): Reproduction
    {
        // BR-29. A structure-only template carries no content, so producing a page from it and
        // capturing it back would capture emptiness — it would quietly wipe the template rather
        // than edit it.
        if (!$template->includeContent) {
            throw new InvalidArgumentException(sprintf(
                'The template "%s" is layout only, so it holds no content to edit.',
                $template->name,
            ));
        }

        if (!$template->getIsUsable()) {
            throw new InvalidArgumentException(sprintf(
                'The template "%s" cannot produce a page, so its content cannot be edited.',
                $template->name,
            ));
        }

        $existing = $this->openSessionFor($template);

        if ($existing !== null) {
            $draft = $this->draftFor($existing);

            if ($draft === null) {
                // The scratch page was garbage-collected or deleted out from under the row.
                $existing->delete();
            } elseif ($existing->userId !== null && $existing->userId !== $userId) {
                $who = Craft::$app->getUsers()->getUserById($existing->userId);

                throw new InvalidArgumentException(sprintf(
                    'Somebody else is already editing this template’s content (%s). '
                        . 'Two people editing it at once would mean one of them losing their work.',
                    $who?->friendlyName ?? 'another user',
                ));
            } else {
                // The caller's own unfinished session: hand back the page they were already on
                // rather than littering another scratch page they would have to abandon.
                return $this->reproductionFrom($existing, $draft);
            }
        }

        $reproduction = PageTemplates::getInstance()->templates->reproduce(
            $template,
            $this->scratchSectionFor($template),
            $siteId,
            $userId,
        );

        $record = new EditingDraftRecord();
        $record->templateId = $template->id;
        $record->draftId = $reproduction->entry->id;
        $record->siteId = $reproduction->entry->siteId;
        $record->userId = $userId;
        $record->wasFaithful = $reproduction->isFaithful();
        $record->lostDetail = Json::encode([
            'droppedBlockTypes' => $reproduction->droppedBlockTypes,
            'emptiedFields' => $reproduction->emptiedFields,
        ]);
        $record->save(false);

        return $reproduction;
    }

    /**
     * The editing session a scratch page belongs to, or null if it is an ordinary page.
     */
    public function recordForDraft(int $draftId): ?EditingDraftRecord
    {
        return EditingDraftRecord::findOne(['draftId' => $draftId]);
    }

    /**
     * The template a scratch page is editing, or null if it is an ordinary page.
     */
    public function templateForDraft(int $draftId): ?PageTemplate
    {
        $record = $this->recordForDraft($draftId);

        if ($record === null) {
            return null;
        }

        return PageTemplates::getInstance()->templates->getTemplateById($record->templateId);
    }

    /**
     * Captures the scratch page back over the template, and clears the session.
     *
     * @throws IncompleteReproductionException if the scratch page was never a complete copy of
     * the template, in which case saving it back would destroy what it failed to reproduce.
     * @throws InvalidArgumentException if the template has gone, or the page is no longer the
     * kind of page the template makes.
     */
    public function saveBack(EditingDraftRecord $record, Entry $draft): PageTemplate
    {
        $plugin = PageTemplates::getInstance();
        $template = $plugin->templates->getTemplateById($record->templateId);

        if ($template === null) {
            throw new InvalidArgumentException('The template being edited no longer exists.');
        }

        // BR-30. The whole reason this service exists — see the note at the top of the class.
        if (!$record->wasFaithful) {
            throw new IncompleteReproductionException($template, $this->lostFrom($record));
        }

        // BR-2: the kind of page a template makes is fixed at capture. Craft only enforces an
        // entry type against its section when an entry goes live, so a mismatched scratch page
        // would save here without complaint and produce a template that cannot be used.
        if ($draft->getType()->uid !== $template->entryTypeUid) {
            throw new InvalidArgumentException(
                'This page is no longer the kind of page the template makes, so it cannot be saved back into it.',
            );
        }

        $captured = $plugin->snapshots->capture($draft->getSerializedFieldValues(), true);

        // BR-33. Overwriting is otherwise irreversible, and the page the template was originally
        // captured from may since have changed or gone.
        $template->previousSnapshot = [
            'version' => $template->snapshotVersion,
            'fields' => $template->snapshot,
        ];
        $template->snapshot = $captured['fields'];
        $template->snapshotVersion = $captured['version'];

        if (!$plugin->templates->saveTemplate($template)) {
            throw new InvalidArgumentException(sprintf(
                'Could not save the template: %s',
                Json::encode($template->getErrors()),
            ));
        }

        $this->discard($record);

        return $template;
    }

    /**
     * Throws the scratch page away, leaving the template as it was.
     */
    public function discard(EditingDraftRecord $record): void
    {
        $draft = $this->draftFor($record);

        // Deleted before the row, so the row's foreign key does not delete it for us and leave
        // this method's own lookup racing the cascade.
        $record->delete();

        if ($draft !== null) {
            // Hard: an unpublished draft nobody kept is not something anyone would restore, and
            // leaving it soft-deleted would keep it in the way of the unique draftId index.
            Craft::$app->getElements()->deleteElement($draft, true);
        }
    }

    /**
     * Puts back the snapshot a template held before its content was last edited (BR-33).
     *
     * One step, not a history: reverting clears the stored previous snapshot, so a second revert
     * has nothing to go back to and says so rather than toggling between two states.
     */
    public function revert(PageTemplate $template): bool
    {
        if ($template->previousSnapshot === null) {
            return false;
        }

        $previous = $template->previousSnapshot;

        $template->snapshot = $previous['fields'] ?? [];
        $template->snapshotVersion = (int)($previous['version'] ?? Snapshots::FORMAT_VERSION);
        $template->previousSnapshot = null;

        return PageTemplates::getInstance()->templates->saveTemplate($template);
    }

    /**
     * Where to put the scratch page.
     *
     * The scratch page is never published and is deleted as soon as editing finishes, so any
     * section that accepts this kind of page will do. An allowed area is preferred so the page
     * behaves the way a real page from this template would — the same URL rules, the same
     * per-section field conditions — but a template whose allowed areas have all been removed is
     * still worth being able to edit.
     *
     * @throws InvalidArgumentException if no section accepts this kind of page at all.
     */
    private function scratchSectionFor(PageTemplate $template): Section
    {
        $accepts = function(Section $section) use ($template): bool {
            foreach ($section->getEntryTypes() as $entryType) {
                if ($entryType->uid === $template->entryTypeUid) {
                    return true;
                }
            }

            return false;
        };

        foreach ($template->getAllowedSections() as $section) {
            if ($accepts($section)) {
                return $section;
            }
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($accepts($section)) {
                return $section;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'No area of the site accepts the kind of page "%s" makes, so there is nowhere to edit it.',
            $template->name,
        ));
    }

    private function openSessionFor(PageTemplate $template): ?EditingDraftRecord
    {
        return EditingDraftRecord::findOne(['templateId' => $template->id]);
    }

    private function draftFor(EditingDraftRecord $record): ?Entry
    {
        return Entry::find()
            ->id($record->draftId)
            ->siteId($record->siteId)
            ->status(null)
            ->drafts(null)
            ->one();
    }

    /**
     * @return array{droppedBlockTypes: string[], emptiedFields: string[]}
     */
    private function lostFrom(EditingDraftRecord $record): array
    {
        try {
            $lost = Json::decode((string)$record->lostDetail) ?? [];
        } catch (\Throwable) {
            $lost = [];
        }

        return [
            'droppedBlockTypes' => $lost['droppedBlockTypes'] ?? [],
            'emptiedFields' => $lost['emptiedFields'] ?? [],
        ];
    }

    private function reproductionFrom(EditingDraftRecord $record, Entry $draft): Reproduction
    {
        $lost = $this->lostFrom($record);

        return new Reproduction($draft, $lost['droppedBlockTypes'], $lost['emptiedFields']);
    }
}
