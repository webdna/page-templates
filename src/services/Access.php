<?php

namespace webdna\pagetemplates\services;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\Section;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\PageTemplates;
use yii\base\Component;

/**
 * Who may do what.
 *
 * These decisions live here rather than inside the controllers for two reasons. They can be tested
 * with real users and real permissions, which a controller's authorisation cannot be without a web
 * request; and the same answers are needed in more than one place — the action menu has to decide
 * whether to show an item, and the endpoint behind it has to decide whether to honour the request.
 * A rule expressed twice is a rule that will eventually disagree with itself.
 *
 * The Templates service stays free of all this by design (BR-26): it answers what applies where,
 * and this answers who is allowed.
 */
class Access extends Component
{
    /**
     * BR-1. Saving a page as a template needs the permission *and* permission to edit that page.
     *
     * Either alone is not enough: the permission without edit access would let someone turn a page
     * they cannot even open into a template, and edit access without the permission is exactly the
     * proliferation the permission exists to prevent.
     */
    public function canSaveTemplate(User $user, Entry $entry): bool
    {
        return $user->can(PageTemplates::PERMISSION_SAVE)
            && Craft::$app->getElements()->canSave($entry, $user);
    }

    /**
     * BR-12. Viewing or changing the template list is its own permission, deliberately separate
     * from saving: authoring a starting point and curating everyone else's are different jobs.
     */
    public function canManageTemplates(User $user): bool
    {
        return $user->can(PageTemplates::PERMISSION_MANAGE);
    }

    /**
     * BR-13. Using a template needs no plugin permission at all — only the ability to create a
     * page in that area.
     *
     * Requiring more would mean granting a permission to everybody who creates pages, which is
     * indistinguishable from having no permission while still being one more thing to configure.
     */
    public function canUseTemplatesIn(User $user, Section $section): bool
    {
        return $user->can("createEntries:$section->uid");
    }

    /**
     * BR-4 in full: whether this user may start a new page from this template in this area.
     *
     * The Templates service answers the first two conditions — the curator allowed the area, and
     * the area accepts the template's kind of page — and this adds the third.
     */
    public function canUseTemplate(User $user, PageTemplate $template, Section $section): bool
    {
        if (!$this->canUseTemplatesIn($user, $section)) {
            return false;
        }

        $applicable = PageTemplates::getInstance()->templates->getTemplatesForSection($section);

        foreach ($applicable as $candidate) {
            if ($candidate->id === $template->id) {
                return true;
            }
        }

        return false;
    }
}
