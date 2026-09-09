<?php

namespace webdna\pagetemplates;

use Craft;
use craft\base\Plugin as BasePlugin;
use webdna\pagetemplates\services\Snapshots;

/**
 * Page Templates plugin
 *
 * @method static PageTemplates getInstance()
 * @property-read Snapshots $snapshots
 * @author WebDNA <sam@webdna.co.uk>
 * @copyright WebDNA
 * @license https://craftcms.github.io/license/ Craft License
 */
class PageTemplates extends BasePlugin
{
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
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                // Pure transforms over serialized field values. Deliberately free of any
                // dependency on a booted Craft application so it stays unit-testable.
                'snapshots' => ['class' => Snapshots::class],
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

    private function attachEventHandlers(): void
    {
        // Permissions, the control-panel section and the entry-index asset bundle are registered
        // here by the editor spec. The engine half attaches nothing.
    }
}
