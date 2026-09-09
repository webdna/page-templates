<?php

namespace webdna\pagetemplates\tests\integration;

use Codeception\Test\Unit;
use Craft;

/**
 * Proves the integration harness is what it claims to be, before anything relies on it.
 *
 * Without this, a harness fault — project config not applied, plugin not installed, tests aimed
 * at the wrong database — would surface as a confusing failure in every fidelity test rather than
 * one clear failure here.
 */
class HarnessTest extends Unit
{
    public function testTheSuiteRunsAgainstTheTestDatabaseAndNotTheDevelopmentOne(): void
    {
        $this->assertStringContainsString(
            'db_test',
            Craft::$app->getDb()->dsn,
            'the suite must never point at the development database: it runs with dbSetup.clean',
        );
    }

    public function testTheSandboxContentModelIsPresent(): void
    {
        $entriesService = Craft::$app->getEntries();

        $this->assertNotNull($entriesService->getEntryTypeByHandle('page'), 'the page entry type');
        $this->assertNotNull($entriesService->getSectionByHandle('landing'), 'the landing section');
        $this->assertNotNull($entriesService->getSectionByHandle('campaigns'), 'the campaigns section');
        $this->assertNotNull(Craft::$app->getFields()->getFieldByHandle('blocks'), 'the blocks Matrix field');
        $this->assertNotNull(Craft::$app->getFields()->getFieldByHandle('columns'), 'the nested Matrix field');
    }

    public function testThePluginIsInstalledWithItsTables(): void
    {
        $this->assertTrue(Craft::$app->getPlugins()->isPluginInstalled('page-templates'));
        $this->assertTrue(Craft::$app->getDb()->tableExists('{{%pagetemplates_templates}}'));
        $this->assertTrue(Craft::$app->getDb()->tableExists('{{%pagetemplates_template_sections}}'));
    }
}
