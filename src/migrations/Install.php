<?php

namespace webdna\pagetemplates\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration.
 *
 * Three tables, all dropped again by safeDown (BR-23). See section 4 of
 * docs/specs/2026-09-09-page-templates-engine.md for why the shape is what it is — in short,
 * sections and entry types are referenced by **UID rather than id**, because applying project
 * config can delete and recreate one with the same uid and a new id, which would silently
 * re-point an id-keyed reference at the wrong entity.
 */
class Install extends Migration
{
    private const TEMPLATES = '{{%pagetemplates_templates}}';
    private const TEMPLATE_SECTIONS = '{{%pagetemplates_template_sections}}';
    private const EDITING = '{{%pagetemplates_editing}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTable(self::TEMPLATES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'description' => $this->text(),
            // The kind of page this template makes. Not a foreign key: entry types live in
            // project config, so this is resolved at read time and treated as absent when it no
            // longer resolves (BR-20).
            'entryTypeUid' => $this->char(36)->notNull(),
            'includeContent' => $this->boolean()->notNull()->defaultValue(true),
            'snapshot' => $this->longText()->notNull(),
            'snapshotVersion' => $this->smallInteger()->unsigned()->notNull()->defaultValue(1),
            // One step of undo for editing a template's content. The whole envelope —
            // {"version":n,"fields":{…}} — because a snapshot restored without the format
            // version it was written in cannot be read safely.
            'previousSnapshot' => $this->longText(),
            // Provenance only, and nullable: deleting the example page must leave the template
            // fully usable (BR-25), because a snapshot is a copy rather than a reference.
            'sourceEntryId' => $this->integer(),
            'sourceSiteId' => $this->integer(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TEMPLATES, ['entryTypeUid'], false);
        $this->createIndex(null, self::TEMPLATES, ['sortOrder'], false);

        $this->addForeignKey(
            null,
            self::TEMPLATES,
            ['sourceEntryId'],
            Table::ELEMENTS,
            ['id'],
            'SET NULL',
        );
        $this->addForeignKey(
            null,
            self::TEMPLATES,
            ['sourceSiteId'],
            Table::SITES,
            ['id'],
            'SET NULL',
        );

        // The allowed areas of the site. A row per template/section pair rather than a JSON
        // column, so a template's availability can be resolved in one query.
        $this->createTable(self::TEMPLATE_SECTIONS, [
            'id' => $this->primaryKey(),
            'templateId' => $this->integer()->notNull(),
            'sectionUid' => $this->char(36)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TEMPLATE_SECTIONS, ['templateId', 'sectionUid'], true);
        $this->createIndex(null, self::TEMPLATE_SECTIONS, ['sectionUid'], false);

        $this->addForeignKey(
            null,
            self::TEMPLATE_SECTIONS,
            ['templateId'],
            self::TEMPLATES,
            ['id'],
            'CASCADE',
        );

        // Which scratch page is currently editing which template's content. A scratch page is an
        // unpublished draft produced from the template, so it stays out of editors' entry indexes.
        $this->createTable(self::EDITING, [
            'id' => $this->primaryKey(),
            'templateId' => $this->integer()->notNull(),
            'draftId' => $this->integer()->notNull(),
            'siteId' => $this->integer(),
            'userId' => $this->integer(),
            // Whether the scratch page was a *complete* reproduction, recorded when it was
            // produced: by save time the information is gone, and saving an incomplete
            // reproduction back over the template would destroy what it failed to reproduce.
            'wasFaithful' => $this->boolean()->notNull()->defaultValue(true),
            'lostDetail' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::EDITING, ['draftId'], true);
        $this->createIndex(null, self::EDITING, ['templateId'], false);

        $this->addForeignKey(null, self::EDITING, ['templateId'], self::TEMPLATES, ['id'], 'CASCADE');
        // Craft garbage-collects abandoned unpublished drafts, so this is what stops the mapping
        // outliving the page it points at.
        $this->addForeignKey(null, self::EDITING, ['draftId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, self::EDITING, ['siteId'], Table::SITES, ['id'], 'SET NULL');
        $this->addForeignKey(null, self::EDITING, ['userId'], Table::USERS, ['id'], 'SET NULL');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Dependent tables first: their foreign keys point at the templates table.
        $this->dropTableIfExists(self::EDITING);
        $this->dropTableIfExists(self::TEMPLATE_SECTIONS);
        $this->dropTableIfExists(self::TEMPLATES);

        return true;
    }
}
