<?php

namespace webdna\pagetemplates;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementsController;
use craft\elements\Entry;
use craft\events\DefineMenuItemsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use webdna\pagetemplates\assetbundles\EntryEditAsset;
use webdna\pagetemplates\services\Access;
use webdna\pagetemplates\services\Snapshots;
use webdna\pagetemplates\services\Templates;
use yii\base\Event;

/**
 * Page Templates plugin
 *
 * @method static PageTemplates getInstance()
 * @property-read Access $access
 * @property-read Snapshots $snapshots
 * @property-read Templates $templates
 * @author WebDNA <sam@webdna.co.uk>
 * @copyright WebDNA
 * @license https://craftcms.github.io/license/ Craft License
 */
class PageTemplates extends BasePlugin
{
    /**
     * Permission to save a page as a template (BR-1). Off by default, so the list does not fill up
     * with half-finished experiments before anyone has decided who should curate it.
     */
    public const PERMISSION_SAVE = 'pageTemplates:save';

    /**
     * Permission to view and change the template list (BR-12).
     *
     * This is Craft's own automatic section-access permission, not one of ours, and deliberately
     * so. Craft registers `accessPlugin-<handle>` for any plugin with a control-panel section and
     * enforces it in `web/Application.php` before a controller runs. A second permission of our
     * own alongside it would add no expressiveness — there is no useful "manage but not access",
     * or the reverse — while creating exactly the trap BR-12 warns against: an admin who ticks
     * only ours gets a section that is visible and then refuses them, with no explanation.
     *
     * Craft also hides the nav item without it (`web/twig/variables/Cp.php:299`), so "absent
     * rather than forbidden" comes for free.
     *
     * *Using* a template needs neither permission (BR-13): if an editor can create a page in an
     * area, they can start it from a template.
     */
    public const PERMISSION_MANAGE = 'accessPlugin-page-templates';

    public string $schemaVersion = '1.0.0';

    /**
     * Deliberately false, and not an oversight: a plugin settings page writes through
     * Plugin::setSettings() into project config, which makes it read-only in production and liable
     * to be overwritten on the next deployment. Templates are editor-authored content, so they are
     * managed from a permission-gated control-panel section backed by the plugin's own tables.
     *
     * See docs/specs/2026-09-09-page-templates-editor.md.
     */
    public bool $hasCpSettings = false;

    /**
     * Templates are editor-authored content, so they get their own permission-gated section rather
     * than a settings page. See $hasCpSettings above for why that distinction matters.
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                // Pure transforms over serialized field values. Deliberately free of any
                // dependency on a booted Craft application so it stays unit-testable.
                'snapshots' => ['class' => Snapshots::class],
                // Storage, and answering which templates apply where. Knows nothing about users.
                'templates' => ['class' => Templates::class],
                // Who may do what. Kept out of the controllers so the rules are testable, and out
                // of Templates so that stays free of request context.
                'access' => ['class' => Access::class],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Anything that creates an element query or loads Twig has to wait until Craft is fully
        // initialised, or it races other plugins and modules.
        Craft::$app->onInit(function() {
            // ...
        });
    }

    /**
     * BR-12. Absent from the navigation without the manage permission, not merely unreachable: an
     * item that is visible and then refuses you is a support call, not a security boundary.
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$this->access->canManageTemplates($user)) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('page-templates', 'Page Templates');
        $item['icon'] = '@webdna/pagetemplates/icon.svg';

        return $item;
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event): void {
                $event->rules['page-templates'] = 'page-templates/templates/index';
                $event->rules['page-templates/<templateId:\\d+>'] = 'page-templates/templates/edit';
            },
        );

        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event): void {
                $this->addSaveAsTemplateItem($event);
            },
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('page-templates', 'Page Templates'),
                    'permissions' => [
                        self::PERMISSION_SAVE => [
                            'label' => Craft::t('page-templates', 'Save a page as a template'),
                        ],
                        // Managing the list is gated by Craft's own accessPlugin permission — see
                        // PERMISSION_MANAGE. Registering a second one here would mean two boxes
                        // for one capability.
                    ],
                ];
            },
        );
    }

    /**
     * Adds "Save as a page template" to a page's own action menu, where editors already look for
     * whole-page operations like duplicate and delete.
     *
     * The guard matters. getActionMenuItems() also feeds element chips and cards, so without it the
     * item would appear against every page in every list — Craft's own items guard the same way.
     */
    private function addSaveAsTemplateItem(DefineMenuItemsEvent $event): void
    {
        $entry = $event->sender;

        if (!$entry instanceof Entry) {
            return;
        }

        $controller = Craft::$app->controller;

        if (
            $entry->getIsRevision() ||
            !$controller instanceof ElementsController ||
            $controller->element !== $entry
        ) {
            return;
        }

        // A nested entry has no section, so there is nothing to capture it as.
        if ($entry->getSection() === null) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$this->access->canSaveTemplate($user, $entry)) {
            return;
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EntryEditAsset::class);

        $itemId = sprintf('page-templates-save-%s', mt_rand());

        $event->items[] = [
            'id' => $itemId,
            'icon' => 'layer-group',
            'label' => Craft::t('page-templates', 'Save as a page template'),
        ];

        $view->registerJsWithVars(
            fn($id, $entryId, $siteId) => <<<JS
(() => {
  $('#' + $id).on('activate', () => {
    new Craft.PageTemplates.SaveDialogue($entryId, $siteId);
  });
})();
JS,
            [
                $view->namespaceInputId($itemId),
                (int)$entry->id,
                (int)$entry->siteId,
            ],
        );
    }
}
