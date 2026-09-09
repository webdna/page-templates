<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\UserGroup;
use webdna\pagetemplates\PageTemplates;

/**
 * The management section's own logic: ordering, and who can see it at all.
 */
class ManagementTest extends Unit
{
    private function template(string $name): \webdna\pagetemplates\models\PageTemplate
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle('landing')->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle('page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = "Source for $name";
        $entry->enabled = true;
        $entry->setAuthorId(User::find()->admin()->one()->id);
        Craft::$app->getElements()->saveElement($entry);

        return PageTemplates::getInstance()->templates->captureFromEntry($entry, $name, null, true);
    }

    /**
     * BR-14. Curator order is the point: the most useful template should be first in the new-page
     * button, and alphabetical order rarely matches usefulness.
     */
    public function testReorderingSetsTheOrderTemplatesAreListedIn(): void
    {
        $service = PageTemplates::getInstance()->templates;

        $first = $this->template('Aardvark');
        $second = $this->template('Baboon');
        $third = $this->template('Camel');

        // Deliberately not alphabetical, so passing cannot be a coincidence.
        $service->reorderTemplates([$third->id, $first->id, $second->id]);

        $ordered = array_map(
            fn($template) => $template->name,
            array_values(array_filter(
                $service->getAllTemplates(),
                fn($t) => in_array($t->name, ['Aardvark', 'Baboon', 'Camel'], true),
            )),
        );

        $this->assertSame(['Camel', 'Aardvark', 'Baboon'], $ordered);
    }

    /**
     * An id that no longer exists must not derail the whole reorder — a curator dragging rows
     * while someone else deletes one should not lose the ordering they just set.
     */
    public function testReorderingIgnoresIdsThatNoLongerExist(): void
    {
        $service = PageTemplates::getInstance()->templates;

        $first = $this->template('Dingo');
        $second = $this->template('Emu');

        $this->assertTrue($service->reorderTemplates([$second->id, 999999, $first->id]));

        $ordered = array_map(
            fn($template) => $template->name,
            array_values(array_filter(
                $service->getAllTemplates(),
                fn($t) => in_array($t->name, ['Dingo', 'Emu'], true),
            )),
        );

        $this->assertSame(['Emu', 'Dingo'], $ordered);
    }

    /**
     * BR-12. The section must be absent from the navigation without the manage permission, not
     * merely unreachable — a visible item that refuses you is a support call.
     */
    public function testTheNavItemIsAbsentWithoutTheManagePermission(): void
    {
        $plugin = PageTemplates::getInstance();

        $without = $this->userWithPermissions('ptNavNo', ['accessCp']);
        $with = $this->userWithPermissions('ptNavYes', ['accessCp', PageTemplates::PERMISSION_MANAGE]);

        Craft::$app->getUser()->setIdentity($without);
        $this->assertNull($plugin->getCpNavItem(), 'absent without the permission');

        Craft::$app->getUser()->setIdentity($with);
        $item = $plugin->getCpNavItem();

        $this->assertIsArray($item, 'present with it');
        $this->assertSame('page-templates', $item['url'] ?? null);
    }

    private function userWithPermissions(string $handle, array $permissions): User
    {
        $userGroups = Craft::$app->getUserGroups();

        $group = $userGroups->getGroupByHandle($handle) ?? new UserGroup([
            'name' => ucfirst($handle),
            'handle' => $handle,
        ]);

        $userGroups->saveGroup($group);
        Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $permissions);

        $user = new User();
        $user->username = $handle . '-' . mt_rand();
        $user->email = $user->username . '@page-templates.test';
        $user->active = true;
        Craft::$app->getElements()->saveElement($user);
        Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);

        return User::find()->id($user->id)->status(null)->one();
    }
}
