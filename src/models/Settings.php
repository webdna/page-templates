<?php

namespace webdna\pagetemplates\models;

use craft\base\Model;

/**
 * Page Templates settings, set in `config/page-templates.php` only.
 *
 * There is no settings screen, for the reason given on PageTemplates::$hasCpSettings.
 */
class Settings extends Model
{
    /**
     * Fields whose values a layout-only template keeps (BR-47).
     *
     * Each entry is a field class — matching that class and its subclasses — or a field handle,
     * matching a field's layout handle or its original handle, at any depth. A site that keeps
     * each block's look in a field (layout, spacing, tone) lists it here, so a layout-only
     * template carries the design while every other field arrives empty.
     *
     * ```php
     * 'keepInLayoutOnly' => [MyDesignField::class, 'lightswitchHandle'],
     * ```
     *
     * @var string[]
     */
    public array $keepInLayoutOnly = [];
}
