<?php

namespace webdna\pagetemplates\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The save-as-template dialogue, loaded only on a page's edit screen.
 */
class EntryEditAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/../web/assets/entryedit/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['js/PageTemplatesSave.js'];

        parent::init();
    }
}
