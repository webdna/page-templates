<?php

namespace webdna\pagetemplates\records;

use craft\db\ActiveRecord;

/**
 * Links a scratch page to the template whose content it is editing.
 *
 * @property int $id
 * @property int $templateId
 * @property int $draftId
 * @property int|null $siteId
 * @property int|null $userId
 * @property bool $wasFaithful
 * @property string|null $lostDetail JSON
 * @property string $uid
 */
class EditingDraftRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%pagetemplates_editing}}';
    }
}
