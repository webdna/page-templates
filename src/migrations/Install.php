<?php

namespace webdna\pagetemplates\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration.
 *
 * Two tables, both dropped again by safeDown (BR-23). See section 4 of
 * docs/specs/2026-09-09-page-templates-engine.md for why the shape is what it is — in short,
 * sections and entry types are referenced by **UID rather than id**, because applying project
 * config can delete and recreate one with the same uid and a new id, which would silently
 * re-point an id-keyed reference at the wrong entity.
 */
class Install extends Migration
{
    private const TEMPLATES = '{{%pagetemplates_templates}}';
    private const TEMPLATE_SECTIONS = '{{%pagetemplates_template_sections}}';

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

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Dependent table first: its foreign key points at the templates table.
        $this->dropTableIfExists(self::TEMPLATE_SECTIONS);
        $this->dropTableIfExists(self::TEMPLATES);

        return true;
    }
}
