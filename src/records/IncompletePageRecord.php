<?php

namespace webdna\pagetemplates\records;

use craft\db\ActiveRecord;

/**
 * What a page made from a template failed to reproduce, kept until the page is first saved or
 * discarded (BR-44, BR-45).
 *
 * @property int $id
 * @property int $elementId
 * @property string $templateName
 * @property string|null $lostDetail JSON
 * @property string $uid
 */
class IncompletePageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pagetemplates_incomplete}}';
    }
}
