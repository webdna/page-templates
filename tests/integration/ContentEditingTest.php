<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use webdna\pagetemplates\exceptions\IncompleteReproductionException;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\PageTemplates;
use webdna\pagetemplates\records\EditingDraftRecord;
use yii\base\InvalidArgumentException;

/**
 * Editing the content a template holds.
 *
 * The behaviour worth guarding here is not the happy path — it is the refusals. Saving a page
 * back over a template is the plugin's only destructive write, and every one of these tests
 * exists because some version of it would silently destroy a curator's content.
 */
class ContentEditingTest extends Unit
{
    private function page(string $title, array $fields = [], string $section = 'landing'): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle($section)->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle($section === 'news' ? 'article' : 'page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = true;
        $entry->setAuthorId(User::find()->one()?->id);
        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            $this->fail(sprintf('Could not save the test page: %s', json_encode($entry->getErrors())));
        }

        return $entry;
    }

    private function template(string $name, array $fields = [], bool $includeContent = true): PageTemplate
    {
        return PageTemplates::getInstance()->templates->captureFromEntry(
            $this->page("$name source", $fields),
            $name,
            null,
            $includeContent,
        );
    }

    private function editing(): \webdna\pagetemplates\services\ContentEditing
    {
        return PageTemplates::getInstance()->contentEditing;
    }

    /**
     * The happy path, stated once: what a curator changes on the scratch page is what the
     * template goes on to produce.
     */
    public function testSavingTheScratchPageBackChangesWhatTheTemplateProduces(): void
    {
        $template = $this->template('Editable', ['heading' => 'Before']);

        $reproduction = $this->editing()->begin($template, null, User::find()->one()?->id);
        $draft = $reproduction->entry;

        $draft->setFieldValues(['heading' => 'After']);
        Craft::$app->getElements()->saveElement($draft);

        $record = $this->editing()->recordForDraft($draft->id);
        $updated = $this->editing()->saveBack($record, $draft);

        $this->assertSame('After', $updated->snapshot['heading'], 'the template holds the edit');

        // The template must still *work*, not merely store different bytes.
        $section = Craft::$app->getEntries()->getSectionByHandle('landing');
        $produced = PageTemplates::getInstance()->templates->reproduce($updated, $section);

        $this->assertSame('After', $produced->entry->getFieldValue('heading'));
        $this->assertTrue($produced->isFaithful(), 'an edited template still reproduces cleanly');
    }

    /**
     * BR-30. **The test this whole feature hangs on.**
     *
     * A scratch page that was never a complete copy must not be written back: whatever failed to
     * reproduce exists only in the template, and saving the page over it destroys that copy. The
     * refusal has to be based on what was recorded when the page was produced, because by save
     * time there is nothing left to compare against.
     */
    public function testAnIncompleteReproductionIsRefusedRatherThanDestroyingTheTemplate(): void
    {
        $template = $this->template('Lossy', ['heading' => 'Precious']);

        $reproduction = $this->editing()->begin($template, null, User::find()->one()?->id);
        $draft = $reproduction->entry;
        $record = $this->editing()->recordForDraft($draft->id);

        // Stand in for a reproduction that lost something. Faking the record rather than
        // engineering a lossy field keeps the test about the *guard*, which is what protects the
        // content — the loss itself is the engine's own BR-28 territory.
        $record->wasFaithful = false;
        $record->lostDetail = json_encode([
            'droppedBlockTypes' => ['calloutBlock'],
            'emptiedFields' => ['gallery'],
        ]);
        $record->save(false);

        try {
            $this->editing()->saveBack($record, $draft);
            $this->fail('saving an incomplete reproduction back over the template was allowed');
        } catch (IncompleteReproductionException $e) {
            $this->assertContains('calloutBlock', $e->lost['droppedBlockTypes'], 'names what was lost');
            $this->assertContains('gallery', $e->lost['emptiedFields']);
        }

        $unchanged = PageTemplates::getInstance()->templates->getTemplateById($template->id);

        $this->assertSame('Precious', $unchanged->snapshot['heading'], 'the template is untouched');
        $this->assertNull($unchanged->previousSnapshot, 'and nothing was staged as an undo');
    }

    /**
     * BR-29. A structure-only template holds no content, so a page made from it is empty —
     * capturing that back would wipe the template rather than edit it.
     */
    public function testAStructureOnlyTemplateCannotHaveItsContentEdited(): void
    {
        $template = $this->template('Skeleton', ['heading' => 'Ignored'], includeContent: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/layout only/');

        $this->editing()->begin($template);
    }

    /**
     * BR-33. Overwriting is otherwise irreversible — the page a template came from may since
     * have changed or gone, so "capture it again" is not an undo.
     */
    public function testTheContentHeldBeforeAnEditCanBePutBackExactlyOnce(): void
    {
        $template = $this->template('Revertible', ['heading' => 'Original']);

        $reproduction = $this->editing()->begin($template, null, User::find()->one()?->id);
        $draft = $reproduction->entry;
        $draft->setFieldValues(['heading' => 'Replacement']);
        Craft::$app->getElements()->saveElement($draft);

        $updated = $this->editing()->saveBack($this->editing()->recordForDraft($draft->id), $draft);
        $this->assertSame('Replacement', $updated->snapshot['heading']);

        $this->assertTrue($this->editing()->revert($updated));
        $this->assertSame('Original', $updated->snapshot['heading'], 'the previous content is back');

        // One step, not a history: there is nothing further back to go.
        $this->assertFalse($this->editing()->revert($updated));
        $this->assertFalse(
            PageTemplates::getInstance()->templates->getTemplateById($template->id)->getHasPreviousSnapshot(),
        );
    }

    /**
     * Two curators editing one template would mean one of them losing their work with no sign it
     * had happened, since the loser's scratch page saves perfectly well over the winner's.
     */
    public function testASecondCuratorIsRefusedWhileSomebodyElseIsEditing(): void
    {
        $template = $this->template('Contested', ['heading' => 'Shared']);
        $first = User::find()->one()?->id;

        $this->editing()->begin($template, null, $first);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already editing/');

        $this->editing()->begin($template, null, $first + 9999);
    }

    /**
     * The same curator coming back should land on the page they were already editing, not a
     * second scratch page that silently abandons their changes.
     */
    public function testTheSameCuratorResumesTheirOwnUnfinishedPage(): void
    {
        $template = $this->template('Resumable', ['heading' => 'Draft in progress']);
        $userId = User::find()->one()?->id;

        $first = $this->editing()->begin($template, null, $userId);
        $second = $this->editing()->begin($template, null, $userId);

        $this->assertSame($first->entry->id, $second->entry->id, 'the same scratch page comes back');
        $this->assertSame(
            1,
            EditingDraftRecord::find()->where(['templateId' => $template->id])->count(),
            'and no second one was created',
        );
    }

    /**
     * Discarding must leave nothing behind: an abandoned scratch page in an editor's index would
     * be a page nobody meant to create.
     */
    public function testDiscardingRemovesTheScratchPageAndLeavesTheTemplateAlone(): void
    {
        $template = $this->template('Abandoned', ['heading' => 'Keep me']);

        $reproduction = $this->editing()->begin($template, null, User::find()->one()?->id);
        $draftId = $reproduction->entry->id;

        $reproduction->entry->setFieldValues(['heading' => 'Never saved']);
        Craft::$app->getElements()->saveElement($reproduction->entry);

        $this->editing()->discard($this->editing()->recordForDraft($draftId));

        $this->assertNull($this->editing()->recordForDraft($draftId), 'the session is gone');
        $this->assertNull(
            Entry::find()->id($draftId)->status(null)->drafts(null)->one(),
            'and so is the scratch page',
        );
        $this->assertSame(
            'Keep me',
            PageTemplates::getInstance()->templates->getTemplateById($template->id)->snapshot['heading'],
        );
    }

    /**
     * BR-2. The kind of page a template makes is fixed at capture. Craft only enforces an entry
     * type against its section when an entry goes live, so a changed scratch page would save
     * back without complaint and leave a template that cannot be used anywhere.
     */
    public function testAPageWhoseKindHasChangedCannotBeSavedBack(): void
    {
        $template = $this->template('Typed', ['heading' => 'Original']);

        $reproduction = $this->editing()->begin($template, null, User::find()->one()?->id);
        $draft = $reproduction->entry;
        $record = $this->editing()->recordForDraft($draft->id);

        // Point the template at a different kind of page, which is the same mismatch seen from
        // the other side and needs no second entry type in the test content model.
        $template->entryTypeUid = Craft::$app->getEntries()->getEntryTypeByHandle('article')->uid;
        PageTemplates::getInstance()->templates->saveTemplate($template);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no longer the kind of page/');

        $this->editing()->saveBack($record, $draft);
    }
}
