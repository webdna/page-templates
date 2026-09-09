<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use webdna\pagetemplates\exceptions\UnsupportedSnapshotVersionException;
use webdna\pagetemplates\PageTemplates;
use webdna\pagetemplates\records\PageTemplateRecord;

/**
 * Templates that have gone wrong: TN-4, TN-5, TN-7 and TN-17.
 *
 * These replace the "degraded-template fixtures" the spec originally scheduled as seeded rows.
 * Rows nobody asserts on prove nothing; a test both creates the degraded state and pins the
 * behaviour, which is what those negative cases were for.
 *
 * A deleted entry type and an unresolvable section are stood in for by pointing the stored uid at
 * something that does not exist. That is exactly the state a real deletion leaves behind — the
 * resolution call returns null — without needing to rewrite project config mid-test.
 */
class DegradedTemplatesTest extends Unit
{
    private const MISSING_UID = '00000000-dead-4000-8000-000000000000';

    private function page(string $title, array $fields = []): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle('landing')->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle('page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = $title;
        $entry->enabled = true;
        $entry->setAuthorId(User::find()->one()->id);
        $entry->setFieldValues($fields);
        Craft::$app->getElements()->saveElement($entry);

        return $entry;
    }

    /**
     * TN-4. A template whose kind of page no longer exists is unusable, is offered nowhere, and is
     * still readable and deletable — a curator has to be able to clear it up.
     */
    public function testATemplateWhoseEntryTypeIsGoneIsUnusableButStillManageable(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $template = $service->captureFromEntry($this->page('Orphan source'), 'Orphan', null, true);

        Db::update(PageTemplateRecord::tableName(), ['entryTypeUid' => self::MISSING_UID], ['id' => $template->id]);

        $loaded = $service->getTemplateById($template->id);

        $this->assertNotNull($loaded, 'it is still readable');
        $this->assertNull($loaded->getEntryType(), 'its page kind does not resolve');
        $this->assertFalse($loaded->getIsUsable(), 'and it reports itself unusable');

        $this->assertNotContains(
            $loaded->id,
            array_map(fn($t) => $t->id, $service->getTemplatesForSection($landing)),
            'so it is offered nowhere',
        );

        try {
            $service->reproduce($loaded, $landing, null, User::find()->one()->id);
            $this->fail('reproduction from an unusable template must be refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('unusable', $e->getMessage());
        }

        $this->assertTrue($service->deleteTemplate($loaded), 'and it can still be deleted');
    }

    /**
     * TN-5. An allowed area that no longer resolves is ignored rather than repaired or fatal, and
     * the areas that do resolve keep working.
     */
    public function testAnUnresolvableAllowedAreaIsIgnoredNotFatal(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $entriesService = Craft::$app->getEntries();
        $landing = $entriesService->getSectionByHandle('landing');
        $campaigns = $entriesService->getSectionByHandle('campaigns');

        $template = $service->captureFromEntry($this->page('Stale area source'), 'Stale areas', null, true);

        $template->setAllowedSectionUids([$landing->uid, self::MISSING_UID, $campaigns->uid]);
        $service->saveTemplate($template);

        $loaded = $service->getTemplateById($template->id);

        $this->assertCount(3, $loaded->getAllowedSectionUids(), 'the stale uid is still recorded');
        $this->assertSame(
            ['landing', 'campaigns'],
            array_map(fn($s) => $s->handle, $loaded->getAllowedSections()),
            'but only the ones that resolve are returned',
        );

        $idsFor = fn($section) => array_map(fn($t) => $t->id, $service->getTemplatesForSection($section));

        $this->assertContains($loaded->id, $idsFor($landing), 'and availability still works');
        $this->assertContains($loaded->id, $idsFor($campaigns));
    }

    /**
     * TN-7. A snapshot that is not valid JSON is corrupt, not merely old. It must surface as a
     * clean refusal that names the template, never as a raw decoding error from the guts of the
     * storage layer.
     */
    public function testACorruptSnapshotIsRefusedCleanly(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $template = $service->captureFromEntry($this->page('Corrupt source'), 'Corrupt', null, true);

        Db::update(PageTemplateRecord::tableName(), ['snapshot' => '{not valid json'], ['id' => $template->id]);

        $loaded = $service->getTemplateById($template->id);

        $this->assertNotNull($loaded, 'it is still readable enough to be listed and deleted');
        $this->assertFalse($loaded->getIsReadable(), 'but it reports itself unreadable');

        $this->expectException(UnsupportedSnapshotVersionException::class);
        $service->reproduce($loaded, $landing, null, User::find()->one()->id);
    }

    /**
     * TN-17. A snapshot written by a newer build is refused rather than guessed at — guessing is
     * how a template silently produces a page that looks right and is not.
     */
    public function testASnapshotFromTheFutureIsRefused(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $template = $service->captureFromEntry($this->page('Future source'), 'From the future', null, true);

        Db::update(PageTemplateRecord::tableName(), ['snapshotVersion' => 99], ['id' => $template->id]);

        $loaded = $service->getTemplateById($template->id);

        $this->assertFalse($loaded->getIsReadable(), 'a version this build cannot read');

        $this->expectException(UnsupportedSnapshotVersionException::class);
        $service->reproduce($loaded, $landing, null, User::find()->one()->id);
    }

    /**
     * TN-8 and BR-25. Deleting the example page leaves the template fully usable, because a
     * snapshot is a copy rather than a reference. The provenance column is a `SET NULL` foreign
     * key precisely so this holds at the database level and not only in code.
     */
    public function testDeletingTheExamplePageLeavesTheTemplateUsable(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = $this->page('Soon deleted', [
            'heading' => 'Captured before deletion',
            'blocks' => [
                'b1' => ['type' => 'textBlock', 'enabled' => true, 'fields' => ['heading' => 'Still reproducible']],
            ],
        ]);
        $template = $service->captureFromEntry($source, 'Outlives its source', null, true);

        $this->assertSame($source->id, $template->sourceEntryId, 'provenance was recorded');

        Craft::$app->getElements()->deleteElement($source, true);

        $loaded = $service->getTemplateById($template->id);

        $this->assertNotNull($loaded, 'the template survives');
        $this->assertNull($loaded->sourceEntryId, 'its provenance is cleared, not dangling');
        $this->assertTrue($loaded->getIsUsable());

        $produced = $service->reproduce($loaded, $landing, null, User::find()->one()->id)->entry;

        $this->assertSame('Captured before deletion', $produced->heading, 'and it still produces a full page');
        $this->assertSame(
            ['Still reproducible'],
            array_map(fn($b) => $b->heading, $produced->blocks->all()),
            'with its blocks',
        );
    }

    /**
     * The regression check the engine spec names, and it fails the assumption behind it.
     *
     * Appendix A assumed Craft's field-value serialisation round-trips for third-party field types,
     * "because it is the same format the control panel posts". ImageShop disproves that:
     * serializeValue() emits an array of serialized arrays, while normalizeValue() accepts only
     * Models or a JSON string — so its own serialized form does not feed back in, and the value
     * comes back empty. Craft's save cycle never notices because the control panel posts strings.
     *
     * Losing content is survivable. Losing it *silently* is not, which is the whole premise of
     * BR-27 — so reproduction must report any field that held content in the snapshot and arrived
     * empty, whatever the field type.
     */
    public function testAFieldWhoseSerialisedFormDoesNotRoundTripIsReportedNotSilentlyEmptied(): void
    {
        $service = PageTemplates::getInstance()->templates;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');

        $source = $this->page('Has an ImageShop value', [
            'heading' => 'Kept',
            // The shape the control panel posts.
            'imageshop' => '[{"id":"abc123","name":"Example image"}]',
        ]);

        $serialised = $source->getSerializedFieldValues();

        $this->assertNotEmpty(
            $serialised['imageshop'] ?? null,
            'precondition: the example page really holds an ImageShop value',
        );

        $template = $service->captureFromEntry($source, 'Lossy field', null, true);
        $result = $service->reproduce($template, $landing, null, User::find()->one()->id);

        $this->assertContains(
            'imageshop',
            $result->emptiedFields,
            'a field that held content but arrived empty must be named, not silently dropped',
        );
        $this->assertFalse($result->isFaithful(), 'and the result must not be passed off as faithful');
        $this->assertSame('Kept', $result->entry->heading, 'while everything reproducible still arrives');
    }
}
