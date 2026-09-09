<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use webdna\pagetemplates\PageTemplates;
use yii\base\InvalidArgumentException;

/**
 * Reproduction: producing a real page from a stored template.
 *
 * This is the phase 4 gate. If reproduction is not faithful, the whole storage model is wrong,
 * and finding that out here — before any control-panel code exists — is the entire point of
 * splitting the specs.
 */
class ReproduceTest extends Unit
{
    private function authorId(): int
    {
        return User::find()->one()->id;
    }

    private function page(string $title, array $fields = [], string $section = 'landing'): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle($section)->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle($section === 'news' ? 'article' : 'page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = true;
        $entry->setAuthorId($this->authorId());
        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            $this->fail(sprintf('Could not save the test page: %s', json_encode($entry->getErrors())));
        }

        return $entry;
    }

    /**
     * The ids the control panel would list for a section, replicating the criteria its index
     * uses: canonical entries and *saved* unpublished drafts, never provisional ones.
     *
     * @return int[]
     */
    private function idsListedIn(string $sectionHandle): array
    {
        return Entry::find()
            ->section($sectionHandle)
            ->status(null)
            ->drafts(null)
            ->draftOf(false)
            ->savedDraftsOnly()
            ->ids();
    }

    /**
     * BR-8 and BR-9. A reproduced page has to behave exactly like one made with Craft's own New
     * entry button: an unpublished draft that stays out of everyone's page list until it is
     * saved, carrying none of the example page's identity.
     */
    public function testReproductionYieldsAnUnlistedUnpublishedDraftWithItsOwnIdentity(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = $this->page('Campaign LP Example', ['heading' => 'Campaign landing page']);
        $template = $service->captureFromEntry($source, 'Campaign LP', null, true);

        $result = $service->reproduce($template, $landing, null, $this->authorId());
        $entry = $result->entry;

        $this->assertTrue($entry->getIsUnpublishedDraft(), 'an unpublished draft, as New entry makes');
        $this->assertNotContains(
            $entry->id,
            $this->idsListedIn('landing'),
            'and therefore absent from the page list until saved — markAsSaved must be false',
        );

        // Craft normalises an unset title to '' rather than null, so assert on emptiness: the
        // requirement is that no title is carried over, not how Craft represents its absence.
        $this->assertEmpty($entry->title, 'no title: the editor must set it consciously');
        $this->assertNotSame($source->slug, $entry->slug, 'the example page\'s slug is not copied');
        $this->assertNull($entry->postDate, 'nor its post date');
        $this->assertSame($this->authorId(), $entry->getAuthorId(), 'authored by whoever asked');
        $this->assertSame($landing->id, $entry->sectionId, 'created in the requested area');
        $this->assertSame($template->getEntryType()->id, $entry->typeId, 'as the template\'s page kind');
    }

    /**
     * AC-2 and AC-12, the headline promise: same blocks, same order, same content, at every depth,
     * with relations pointing at the same targets rather than copies — and nested elements that
     * are genuinely new, so editing the reproduction cannot reach back into the original (BR-7).
     *
     * What this does and does not guard, established by deliberately breaking the code: removing
     * the recursion from prepareBlocks leaves this test green, because nested field values still
     * pass through and Craft creates the blocks anyway. This test proves nested blocks are
     * *created*; the unit suite's nested-block-types test is what proves they are *validated*, and
     * that one does fail under the same mutation. Do not rely on this test for BR-27 at depth.
     */
    public function testWithContentReproductionIsFaithfulAtEveryDepth(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $linked = $this->page('Reference Page');

        $source = $this->page('Campaign LP Example', [
            'heading' => 'Campaign landing page',
            'relatedPages' => [$linked->id],
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'First']],
                'b2' => [
                    'type' => 'columnsBlock',
                    'enabled' => true,
                    'fields' => [
                        'heading' => 'Two nested columns',
                        'columns' => [
                            'c1' => ['type' => 'column', 'enabled' => true, 'fields' => ['heading' => 'Left']],
                            'c2' => ['type' => 'column', 'enabled' => true, 'fields' => ['heading' => 'Right']],
                        ],
                    ],
                ],
                'b3' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Third']],
            ],
        ]);

        $template = $service->captureFromEntry($source, 'Campaign LP', null, true);
        $result = $service->reproduce($template, $landing, null, $this->authorId());

        $this->assertTrue($result->isFaithful(), 'nothing was reported as unplaceable');
        $this->assertSame('Campaign landing page', $result->entry->heading, 'plain fields carry over');

        // Top-level blocks: same types, same order, same count.
        $blocks = $result->entry->blocks->all();
        $sourceBlocks = $source->blocks->all();

        $this->assertCount(3, $blocks);
        $this->assertSame(
            ['textBlock', 'columnsBlock', 'textBlock'],
            array_map(fn($b) => $b->getType()->handle, $blocks),
        );
        $this->assertSame(['First', 'Two nested columns', 'Third'], array_map(fn($b) => $b->heading, $blocks));

        // The nesting, which is the level that breaks first when replay is wrong.
        $columns = $blocks[1]->columns->all();

        $this->assertCount(2, $columns, 'both nested blocks survive');
        $this->assertSame(['Left', 'Right'], array_map(fn($c) => $c->heading, $columns), 'in order');

        // Nested elements must be new, never shared with or re-parented from the original.
        $this->assertEmpty(
            array_intersect(
                array_map(fn($b) => $b->id, $blocks),
                array_map(fn($b) => $b->id, $sourceBlocks),
            ),
            'no nested element is shared with the example page',
        );

        // Relations point at the same target. Nothing is duplicated.
        $this->assertSame(
            [$linked->id],
            array_map(fn($e) => $e->id, $result->entry->relatedPages->all()),
            'the relation references the same page, not a copy of it',
        );
    }

    /**
     * AC-3. Structure only reproduces the skeleton and nothing else — every field empty,
     * relations included.
     */
    public function testStructureOnlyReproductionArrivesEmpty(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $linked = $this->page('Reference Page');
        $source = $this->page('Skeleton source', [
            'heading' => 'Should not survive',
            'relatedPages' => [$linked->id],
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Nor this']],
                'b2' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Nor this either']],
            ],
        ]);

        $template = $service->captureFromEntry($source, 'LP skeleton', null, false);
        $entry = $service->reproduce($template, $landing, null, $this->authorId())->entry;

        $blocks = $entry->blocks->all();

        $this->assertCount(2, $blocks, 'the skeleton keeps its blocks');
        $this->assertSame(['textBlock', 'textBlock'], array_map(fn($b) => $b->getType()->handle, $blocks));
        $this->assertEmpty($entry->heading, 'plain fields arrive empty');
        $this->assertEmpty($blocks[0]->heading, 'and so do block fields');
        $this->assertEmpty($blocks[1]->heading);
        $this->assertSame([], $entry->relatedPages->all(), 'relations arrive empty too');
    }

    /**
     * TN-14 / BR-17. Craft only enforces entry-type availability when an entry goes live, so
     * without an up-front check this would save happily and fail on publish. Refusal must happen
     * before anything is created.
     */
    public function testReproductionIsRefusedWhereThePageKindIsNotAvailable(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $news = Craft::$app->getEntries()->getSectionByHandle('news');

        $template = $service->captureFromEntry($this->page('Wrong area'), 'Landing template', null, true);
        $before = Entry::find()->section('news')->status(null)->drafts(null)->count();

        try {
            $service->reproduce($template, $news, null, $this->authorId());
            $this->fail('reproduction into a section that does not accept the page kind must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not available', $e->getMessage());
        }

        $this->assertSame($before, Entry::find()->section('news')->status(null)->drafts(null)->count(),
            'and nothing was created before the refusal');
    }

    /**
     * BR-27 end to end. A snapshot holding a block type the field does not allow must lose that
     * block *and say so* — Craft itself drops it with no error and no log entry.
     */
    public function testAnUnplaceableBlockIsDroppedAndReported(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = $this->page('Has blocks', [
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Keeps']],
            ],
        ]);
        $template = $service->captureFromEntry($source, 'With a stale block', null, true);

        // Stand in for a block type that has since been removed from the field, without having to
        // rewrite project config mid-test.
        $snapshot = $template->snapshot;
        $snapshot['blocks']['stale'] = [
            'type' => 'retiredBlock',
            'enabled' => true,
            'fields' => ['heading' => 'Vanishes'],
        ];
        $template->snapshot = $snapshot;
        $service->saveTemplate($template);

        $result = $service->reproduce($service->getTemplateById($template->id), $landing, null, $this->authorId());

        $this->assertFalse($result->isFaithful(), 'the loss is not passed off as success');
        $this->assertSame(['retiredBlock'], $result->droppedBlockTypes, 'and it is named');
        $this->assertCount(1, $result->entry->blocks->all(), 'the placeable block still arrives');
    }

    /**
     * BR-19. Deleting a template leaves pages produced from it whole, because a snapshot is a
     * copy — nothing built from a template ever refers back to it. This is the half of BR-19 that
     * needed reproduction to test.
     */
    public function testDeletingATemplateLeavesPagesProducedFromItIntact(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = $this->page('Doomed template source', [
            'heading' => 'Survives',
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Still here']],
            ],
        ]);
        $template = $service->captureFromEntry($source, 'Doomed', null, true);
        $produced = $service->reproduce($template, $landing, null, $this->authorId())->entry;

        $service->deleteTemplate($template);

        $reloaded = Entry::find()->id($produced->id)->status(null)->drafts(null)->one();

        $this->assertNotNull($reloaded, 'the page outlives the template');
        $this->assertSame('Survives', $reloaded->heading);
        $this->assertCount(1, $reloaded->blocks->all(), 'with its blocks intact');
    }
}
