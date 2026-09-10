<?php

namespace webdna\pagetemplates\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $entryTypeUid
 * @property bool $includeContent
 * @property string $snapshot JSON
 * @property int $snapshotVersion
 * @property string|null $previousSnapshot JSON
 * @property int|null $sourceEntryId
 * @property int|null $sourceSiteId
 * @property int|null $sortOrder
 * @property string $uid
 */
class PageTemplateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pagetemplates_templates}}';
    }
}
