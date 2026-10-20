<?php

namespace webdna\pagetemplates\events;

use yii\base\Event;

/**
 * Raised for each field value in a layout-only capture, to decide whether it is kept (BR-48).
 *
 * `keep` arrives set from the `keepInLayoutOnly` config setting; a handler may set it either way.
 */
class KeepValueEvent extends Event
{
    /**
     * The field's handle as it appears in the page's values (its layout handle).
     */
    public string $handle;

    /**
     * The handles leading to this value: field, block type, field, … ending in $handle.
     *
     * @var string[]
     */
    public array $path = [];

    /**
     * The value as Craft serialized it.
     */
    public mixed $value = null;

    /**
     * Whether the layout-only template keeps this value.
     */
    public bool $keep = false;
}
