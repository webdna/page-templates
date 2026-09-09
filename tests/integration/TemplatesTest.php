<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use webdna\pagetemplates\PageTemplates;
use webdna\pagetemplates\records\PageTemplateSectionRecord;

/**
 * Storage and capture, against a real Craft install and real entries.
 *
 * The test install inherits the sandbox content model from project config but none of its
 * content, so these tests build the pages they need. The phase 1 example page exists for
 * console-driven and manual verification instead.
 */
class TemplatesTest extends Unit
{
    /**
     * Creates and saves a live page in the given section.
     */
    private function page(string $title, array $fields = [], string $section = 'landing'): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle($section)->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle($section === 'news' ? 'article' : 'page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = true;
        // Sections default to minAuthors 1, so a live entry needs one.
        $entry->setAuthorId(User::find()->one()?->id);
        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            $this->fail(sprintf('Could not save the test page: %s', json_encode($entry->getErrors())));
        }

        return $entry;
    }

    /**
     * BR-2 and BR-3. A template is bound once to the kind of page it came from, and starts out
     * usable only in the area that page lived in — a curator widens it later.
     */
    public function testCapturingAPageBindsItToItsEntryTypeAndItsOwnAreaOnly(): void
    {
        $page = $this->page('Campaign LP Example', ['heading' => 'Campaign landing page']);

        $template = PageTemplates::getInstance()->templates->captureFromEntry(
            $page,
            'Campaign LP',
            null,
            true,
        );

        $this->assertSame('page', $template->getEntryType()->handle, 'bound to the page kind');
        $this->assertSame(
            ['landing'],
            array_map(fn($section) => $section->handle, $template->getAllowedSections()),
            'allowed only where it came from',
        );
    }

    public function testATemplateSurvivesARoundTripThroughStorage(): void
    {
        $service = PageTemplates::getInstance()->templates;

        $page = $this->page('Round trip', [
            'heading' => 'Kept heading',
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Block one']],
            ],
        ]);

        $captured = $service->captureFromEntry($page, 'Round trip template', 'A description', true);
        $loaded = $service->getTemplateById($captured->id);

        $this->assertNotNull($loaded);
        $this->assertSame('Round trip template', $loaded->name);
        $this->assertSame('A description', $loaded->description);
        $this->assertTrue($loaded->includeContent);
        $this->assertSame('page', $loaded->getEntryType()->handle);
        $this->assertSame(['landing'], array_map(fn($s) => $s->handle, $loaded->getAllowedSections()));
        $this->assertSame('Kept heading', $loaded->snapshot['heading'], 'the snapshot decodes back');

        // Craft re-keys blocks by their real entry id when it serializes, so the 'b1' used to
        // build the page is gone by capture time. The keys are only sort-order tokens and are
        // discarded again on replay, so assert on order and content, never on the key itself.
        $blocks = array_values($loaded->snapshot['blocks']);

        $this->assertCount(1, $blocks);
        $this->assertSame('textBlock', $blocks[0]['type']);
        $this->assertSame('Block one', $blocks[0]['fields']['heading'], 'nested block content survives');
    }

    /**
     * BR-26. Availability is the intersection of two things: the areas a curator allowed, and
     * whether that area accepts the template's kind of page at all. The second is not negotiable —
     * a `page` template must never be offered in a section that only takes `article`, however the
     * allowed list reads, or it would produce an entry that saves now and fails on publish.
     */
    public function testTemplatesForSectionRespectsBothAllowedAreasAndPageKind(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $entriesService = Craft::$app->getEntries();

        $landing = $entriesService->getSectionByHandle('landing');
        $campaigns = $entriesService->getSectionByHandle('campaigns');
        $news = $entriesService->getSectionByHandle('news');

        $template = $service->captureFromEntry($this->page('Area test'), 'Landing only', null, true);

        $idsFor = fn($section) => array_map(
            fn($t) => $t->id,
            $service->getTemplatesForSection($section),
        );

        $this->assertContains($template->id, $idsFor($landing), 'offered where it came from');
        $this->assertNotContains($template->id, $idsFor($campaigns), 'not offered elsewhere yet');

        // A curator widens it to every section, including one that takes a different page kind.
        $template->setAllowedSectionUids([$landing->uid, $campaigns->uid, $news->uid]);
        $service->saveTemplate($template);

        $this->assertContains($template->id, $idsFor($campaigns), 'offered once allowed');
        $this->assertNotContains(
            $template->id,
            $idsFor($news),
            'never offered where the page kind is not available, allowed list notwithstanding',
        );
    }

    /**
     * Deleting a template takes its allowed-area rows with it, via the cascade in the install
     * migration. That pages built from it survive is BR-19, which needs reproduction to test and
     * so belongs to phase 4 (TS-4).
     */
    public function testDeletingATemplateRemovesItAndItsAllowedAreas(): void
    {
        $service = PageTemplates::getInstance()->templates;

        $template = $service->captureFromEntry($this->page('To delete'), 'Doomed', null, true);
        $id = $template->id;

        $this->assertTrue($service->deleteTemplate($template));

        $this->assertNull($service->getTemplateById($id), 'the template is gone');
        $this->assertSame(
            0,
            (int)PageTemplateSectionRecord::find()->where(['templateId' => $id])->count(),
            'and so are its allowed-area rows',
        );
    }
}
