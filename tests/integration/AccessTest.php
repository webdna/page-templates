<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\UserGroup;
use webdna\pagetemplates\PageTemplates;

/**
 * Who may do what: BR-1, BR-12 and BR-13.
 *
 * These rules live in their own class rather than inside a controller so they can be proven with
 * real users and real permissions. A controller's authorisation can only be exercised through a
 * web request, which this harness has no clean way to make — and a rule that cannot be tested is a
 * rule that quietly rots.
 *
 * Note this can only be meaningful because the suite runs as Craft Pro: `User::can()` returns true
 * for every permission on Solo (`elements/User.php:1874`), so on the sandbox itself these
 * assertions would all pass vacuously.
 */
class AccessTest extends Unit
{
    private function userWithPermissions(string $handle, array $permissions): User
    {
        $userGroups = Craft::$app->getUserGroups();

        $group = $userGroups->getGroupByHandle($handle) ?? new UserGroup([
            'name' => ucfirst($handle),
            'handle' => $handle,
        ]);

        if (!$userGroups->saveGroup($group)) {
            $this->fail(sprintf('Could not save the %s group: %s', $handle, json_encode($group->getErrors())));
        }

        Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $permissions);

        $user = new User();
        $user->username = $handle . '-' . mt_rand();
        $user->email = $user->username . '@page-templates.test';
        $user->active = true;

        if (!Craft::$app->getElements()->saveElement($user)) {
            $this->fail(sprintf('Could not save the user: %s', json_encode($user->getErrors())));
        }

        Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);

        // Re-fetch so the permission cache reflects the group assignment.
        return User::find()->id($user->id)->status(null)->one();
    }

    private function landingPage(): Entry
    {
        $entriesService = Craft::$app->getEntries();

        $entry = new Entry();
        $entry->sectionId = $entriesService->getSectionByHandle('landing')->id;
        $entry->typeId = $entriesService->getEntryTypeByHandle('page')->id;
        $entry->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title = 'Access subject';
        $entry->enabled = true;
        $entry->setAuthorId(User::find()->admin()->one()->id);
        Craft::$app->getElements()->saveElement($entry);

        return $entry;
    }

    /**
     * BR-1. Saving needs the permission *and* edit access to that page — either alone is not
     * enough, or an editor could turn a page they cannot even open into a template.
     */
    public function testSavingATemplateNeedsBothThePermissionAndEditAccess(): void
    {
        $access = PageTemplates::getInstance()->access;
        $page = $this->landingPage();

        $withNeither = $this->userWithPermissions('ptNoPerms', ['accessCp']);
        $withPermissionOnly = $this->userWithPermissions('ptSaveNoSection', [
            'accessCp',
            PageTemplates::PERMISSION_SAVE,
        ]);
        // The peer permissions are needed because the page is authored by the admin, not by this
        // user, and Craft distinguishes own from peer entries. That is the realistic case: an
        // editor turning a colleague's page into a template.
        $withBoth = $this->userWithPermissions('ptSaveAndSection', [
            'accessCp',
            PageTemplates::PERMISSION_SAVE,
            'viewEntries:' . $page->getSection()->uid,
            'saveEntries:' . $page->getSection()->uid,
            'viewPeerEntries:' . $page->getSection()->uid,
            'savePeerEntries:' . $page->getSection()->uid,
        ]);

        $this->assertFalse($access->canSaveTemplate($withNeither, $page), 'neither');
        $this->assertFalse($access->canSaveTemplate($withPermissionOnly, $page), 'permission but no edit access');
        $this->assertTrue($access->canSaveTemplate($withBoth, $page), 'both');
    }

    /**
     * BR-12. Managing the list is its own permission, separate from saving.
     */
    public function testManagingTheListNeedsItsOwnPermission(): void
    {
        $access = PageTemplates::getInstance()->access;

        $saver = $this->userWithPermissions('ptSaverOnly', ['accessCp', PageTemplates::PERMISSION_SAVE]);
        $curator = $this->userWithPermissions('ptCuratorOnly', ['accessCp', PageTemplates::PERMISSION_MANAGE]);

        $this->assertFalse($access->canManageTemplates($saver), 'saving does not imply managing');
        $this->assertTrue($access->canManageTemplates($curator));
        $this->assertFalse($access->canSaveTemplate($curator, $this->landingPage()), 'nor the reverse');
    }

    /**
     * BR-13. Using a template needs no plugin permission at all — only the ability to create a
     * page in that area. Requiring more would mean granting a permission to everyone who creates
     * pages, which is the same as having no permission.
     */
    public function testUsingATemplateNeedsOnlyTheAbilityToCreatePagesThere(): void
    {
        $access = PageTemplates::getInstance()->access;
        $landing = Craft::$app->getEntries()->getSectionByHandle('landing');
        $campaigns = Craft::$app->getEntries()->getSectionByHandle('campaigns');

        $editor = $this->userWithPermissions('ptCreatesInLanding', [
            'accessCp',
            'viewEntries:' . $landing->uid,
            'createEntries:' . $landing->uid,
        ]);

        $this->assertTrue($access->canUseTemplatesIn($editor, $landing), 'where they may create');
        $this->assertFalse($access->canUseTemplatesIn($editor, $campaigns), 'and nowhere else');
    }
}
