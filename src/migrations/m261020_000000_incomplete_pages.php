<?php

namespace webdna\pagetemplates\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * What a page made from a template failed to reproduce, kept with that page (BR-44).
 *
 * 1.0 carried it in a one-shot session flash, so the warning was gone after a reload (issue #2).
 * See docs/specs/2026-10-20-page-templates-1-1.md.
 */
class m261020_000000_incomplete_pages extends Migration
{
    private const INCOMPLETE = '{{%pagetemplates_incomplete}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::INCOMPLETE)) {
            return true;
        }

        $this->createTable(self::INCOMPLETE, [
            'id' => $this->primaryKey(),
            // The new page. An unpublished draft keeps its id when it is first saved, so the row
            // can be found again and cleared then (BR-45).
            'elementId' => $this->integer()->notNull(),
            // The name as it was when the page was made: the template may be renamed or deleted
            // while the page is still being worked on.
            'templateName' => $this->string()->notNull(),
            // {"droppedBlockTypes":[…],"emptiedFields":[…]}, the shape pagetemplates_editing uses.
            'lostDetail' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::INCOMPLETE, ['elementId'], true);
        // Craft garbage-collects abandoned unpublished drafts, so this is what stops the warning
        // outliving the page it describes.
        $this->addForeignKey(null, self::INCOMPLETE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::INCOMPLETE);

        return true;
    }
}
