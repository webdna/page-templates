<?php

namespace webdna\pagetemplates\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Extends the New entry button so templates can be picked from it.
 *
 * Depends on CpAsset because the subclass extends Craft.EntryIndex, which that bundle defines —
 * without the dependency the file could load first and the subclass would have nothing to extend.
 */
class EntryIndexAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../web/assets/entryindex/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['js/PageTemplatesEntryIndex.js'];

        parent::init();
    }
}
