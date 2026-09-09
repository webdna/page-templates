<?php

namespace webdna\pagetemplates\records;

use craft\db\ActiveRecord;

/**
 * One allowed area of the site, per template.
 *
 * @property int $id
 * @property int $templateId
 * @property string $sectionUid
 * @property string $uid
 */
class PageTemplateSectionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pagetemplates_template_sections}}';
    }
}
