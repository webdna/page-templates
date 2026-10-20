<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\PageTemplates;

/**
 * Issue #2: the incomplete-copy warning is kept with the page until it is first saved or
 * discarded, not carried in a flash that a reload consumes (BR-44, BR-45).
 *
 * See docs/specs/2026-10-20-page-templates-1-1.md.
 */
class IncompletePagesTest extends Unit
{
    /**
     * A page from a template whose only block type has been retired, made the way the From
     * template button makes one.
     */
    private function incompletePage(): Entry
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = new Entry();
        $source->sectionId = $landing->id;
        $source->typeId = Craft::$app->getEntries()->getEntryTypeByHandle('page')->id;
        $source->title = 'Incomplete source';
        $source->setAuthorId(User::find()->one()->id);
        $source->setFieldValues([
            'heading' => 'Kept',
            'blocks' => ['new1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Intro']]],
        ]);
        Craft::$app->getElements()->saveElement($source);

        $template = $service->captureFromEntry($source, 'Has a retired block', null, true);
        $snapshot = $template->snapshot;
        foreach ($snapshot['blocks'] as $key => $block) {
            $snapshot['blocks'][$key]['type'] = 'retiredBlock';
        }
        $template->snapshot = $snapshot;

        $result = $service->reproduce($template, $landing, null, User::find()->one()->id);
        $service->rememberIncomplete($result, $template);

        return $result->entry;
    }

    public function testTheWarningIsReadOnEveryViewUntilTheFirstSave(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $page = $this->incompletePage();

        $expected = [
            'templateName' => 'Has a retired block',
            'droppedBlockTypes' => ['retiredBlock'],
            'emptiedFields' => [],
        ];

        // Two reads: a reload must not consume it (the 1.0 flash did).
        $this->assertSame($expected, $service->incompleteFor($page->id));
        $this->assertSame($expected, $service->incompleteFor($page->id), 'still there on a second view');

        // An autosave of the draft is not a save.
        $page->setFieldValue('heading', 'Edited');
        Craft::$app->getElements()->saveElement($page);
        $this->assertNotNull($service->incompleteFor($page->id), 'an autosave does not clear it');

        Craft::$app->getDrafts()->applyDraft($page);

        $this->assertNull($service->incompleteFor($page->id), 'the first save clears it');
    }

    public function testDiscardingThePageClearsTheWarning(): void
    {
        $service = PageTemplates::getInstance()->templates;

        $soft = $this->incompletePage();
        Craft::$app->getElements()->deleteElement($soft);
        $this->assertNull($service->incompleteFor($soft->id), 'deleted to the trash');

        $hard = $this->incompletePage();
        Craft::$app->getElements()->deleteElement($hard, true);
        $this->assertNull($service->incompleteFor($hard->id), 'deleted outright');
    }

    public function testAFaithfulPageWritesNothing(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $template = new PageTemplate();
        $template->name = 'Faithful';
        $template->entryTypeUid = Craft::$app->getEntries()->getEntryTypeByHandle('page')->uid;
        $template->snapshot = ['heading' => 'Whole'];
        $template->snapshotVersion = 1;
        $template->setAllowedSectionUids([$landing->uid]);
        $service->saveTemplate($template);

        $result = $service->reproduce($template, $landing, null, User::find()->one()->id);
        $service->rememberIncomplete($result, $template);

        $this->assertTrue($result->isFaithful());
        $this->assertNull($service->incompleteFor($result->entry->id));
    }
}
