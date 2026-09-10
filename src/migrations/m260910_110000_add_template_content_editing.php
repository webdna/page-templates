<?php

namespace webdna\pagetemplates\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Editing a template's content.
 *
 * A template's content is edited by producing a scratch page from it, editing that page in Craft's
 * own editor, and capturing it back over the template. This adds the two things that flow needs
 * and cannot derive: somewhere to record which template a scratch page belongs to, and the
 * template's previous snapshot, so overwriting one has an undo.
 *
 * See section 4 of docs/specs/2026-09-09-page-templates-editor.md.
 */
class m260910_110000_add_template_content_editing extends Migration
{
    private const TEMPLATES = '{{%pagetemplates_templates}}';
    private const EDITING = '{{%pagetemplates_editing}}';

    public function safeUp(): bool
    {
        // One step of undo. Held as the whole envelope — {"version":n,"fields":{…}} — because a
        // snapshot restored without the format version it was written in cannot be read safely.
        if (!$this->db->columnExists(self::TEMPLATES, 'previousSnapshot')) {
            $this->addColumn(self::TEMPLATES, 'previousSnapshot', $this->longText()->after('snapshotVersion'));
        }

        if ($this->db->tableExists(self::EDITING)) {
            return true;
        }

        $this->createTable(self::EDITING, [
            'id' => $this->primaryKey(),
            'templateId' => $this->integer()->notNull(),
            // The scratch page. An unpublished draft, so it stays out of editors' entry indexes.
            'draftId' => $this->integer()->notNull(),
            'siteId' => $this->integer(),
            // Who started editing. Provenance for the "someone else is editing this" case, and
            // nullable so deleting a user cannot orphan the row's foreign key.
            'userId' => $this->integer(),
            // Whether the scratch page was a *complete* reproduction of the template. Recorded at
            // the moment it was produced, because by save time the information is gone — and
            // saving an incomplete reproduction back over the template would destroy whatever it
            // failed to reproduce.
            'wasFaithful' => $this->boolean()->notNull()->defaultValue(true),
            // What was lost, if anything: {"droppedBlockTypes":[…],"emptiedFields":[…]}. Kept so
            // the refusal can name it rather than saying "something".
            'lostDetail' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One scratch page edits one template: the mapping is how the save-back action knows what
        // it is saving into, and two rows for one draft would make that ambiguous.
        $this->createIndex(null, self::EDITING, ['draftId'], true);
        $this->createIndex(null, self::EDITING, ['templateId'], false);

        $this->addForeignKey(null, self::EDITING, ['templateId'], self::TEMPLATES, ['id'], 'CASCADE');
        // Craft garbage-collects abandoned unpublished drafts after purgeUnsavedDraftsDuration
        // (30 days by default), so this is what stops the mapping outliving the page it points at.
        $this->addForeignKey(null, self::EDITING, ['draftId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, self::EDITING, ['siteId'], Table::SITES, ['id'], 'SET NULL');
        $this->addForeignKey(null, self::EDITING, ['userId'], Table::USERS, ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::EDITING);

        if ($this->db->columnExists(self::TEMPLATES, 'previousSnapshot')) {
            $this->dropColumn(self::TEMPLATES, 'previousSnapshot');
        }

        return true;
    }
}
