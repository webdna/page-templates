<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;
use webdna\pagetemplates\PageTemplates;

/**
 * The two permissions this half introduces, and the fact that they are off by default.
 *
 * BR-1 gates saving a template; BR-12 gates the management section. Using a template needs neither
 * (BR-13) — if you can create a page in an area, you can start it from a template.
 */
class PermissionsTest extends Unit
{
    /**
     * Every permission key Craft knows about, flattened out of its nested group structure and
     * lowercased, because permission checks are case-insensitive.
     *
     * @return string[]
     */
    private function registeredPermissions(): array
    {
        $found = [];

        $walk = function(array $permissions) use (&$walk, &$found): void {
            foreach ($permissions as $key => $permission) {
                if (is_string($key)) {
                    $found[] = strtolower($key);
                }

                if (is_array($permission)) {
                    $walk($permission['nested'] ?? $permission);
                }
            }
        };

        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $walk($group['permissions'] ?? []);
        }

        return $found;
    }

    public function testBothPermissionsAreRegistered(): void
    {
        $registered = $this->registeredPermissions();

        $this->assertContains(strtolower(PageTemplates::PERMISSION_SAVE), $registered);
        $this->assertContains(strtolower(PageTemplates::PERMISSION_MANAGE), $registered);
    }

    /**
     * BR-1 and BR-12: both off by default. A permission that arrives switched on would let the
     * template list fill up before anyone had decided who should curate it.
     */
    public function testNeitherPermissionIsGrantedToAnOrdinaryUserByDefault(): void
    {
        $group = Craft::$app->getUserGroups()->getGroupByHandle('pageTemplatesTestGroup')
            ?? $this->makeGroup();

        $this->assertFalse(
            Craft::$app->getUserPermissions()->doesGroupHavePermission(
                $group->id,
                PageTemplates::PERMISSION_SAVE,
            ),
        );
        $this->assertFalse(
            Craft::$app->getUserPermissions()->doesGroupHavePermission(
                $group->id,
                PageTemplates::PERMISSION_MANAGE,
            ),
        );
    }

    private function makeGroup(): \craft\models\UserGroup
    {
        $group = new \craft\models\UserGroup([
            'name' => 'Page Templates Test Group',
            'handle' => 'pageTemplatesTestGroup',
        ]);

        Craft::$app->getUserGroups()->saveGroup($group);

        return $group;
    }
}
