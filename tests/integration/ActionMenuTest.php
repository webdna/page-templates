<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;

/**
 * The guard on the action-menu item.
 *
 * `Element::getActionMenuItems()` feeds two very different things: a page's own edit screen, and
 * every element chip and card in every list (`helpers/Cp.php:1210`). An item added without a guard
 * therefore appears against every page an editor can see — which is why Craft's own items check
 * `controller instanceof ElementsController && controller->element === $this`.
 *
 * This asserts the negative case, which is the one that would be embarrassing: outside a page's
 * edit screen, the item must not appear. The positive case needs a real control-panel request and
 * is covered by the manual scenarios in section 7.
 *
 * The test signs an admin in first, and that is not incidental. Without an identity the handler
 * returns early at its user check, so the assertion would pass whether the edit-screen guard
 * existed or not — verified by deleting the guard and watching the test stay green. With an
 * identity present, deleting the guard does fail it.
 */
class ActionMenuTest extends Unit
{
    protected function _before(): void
    {
        // Without this the handler short-circuits at its user check and these assertions become
        // vacuous. See the class docblock.
        Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    }

    private function landingPage(): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle('landing')->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle('page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = 'Menu subject';
        $entry->enabled = true;
        $entry->setAuthorId(User::find()->admin()->one()->id);
        Craft::$app->getElements()->saveElement($entry);

        return $entry;
    }

    private function labels(Entry $entry): array
    {
        return array_map(
            fn(array $item) => $item['label'] ?? '',
            $entry->getActionMenuItems(),
        );
    }

    public function testTheItemIsAbsentOutsideAPagesOwnEditScreen(): void
    {
        $labels = $this->labels($this->landingPage());

        $matching = array_filter($labels, fn(string $label) => str_contains($label, 'page template'));

        $this->assertSame(
            [],
            $matching,
            'the save-as-template item must not appear on chips, cards, or anywhere but the edit screen',
        );
    }

    /**
     * And asking for the items at all must not blow up, whatever the context — this method is
     * called for every element in a list, so an exception here would take out the page.
     */
    public function testAskingForActionMenuItemsIsSafeInAnyContext(): void
    {
        $entry = $this->landingPage();

        $this->assertIsArray($entry->getActionMenuItems());

        // A nested entry has no section; the handler has to cope rather than assume one.
        $nested = new Entry();
        $nested->fieldId = Craft::$app->getFields()->getFieldByHandle('blocks')->id;
        $nested->typeId = Craft::$app->getEntries()->getEntryTypeByHandle('textBlock')->id;
        $nested->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->assertIsArray($nested->getActionMenuItems(), 'a nested entry must not throw');
    }
}
