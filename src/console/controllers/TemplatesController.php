<?php

namespace webdna\pagetemplates\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Console;
use webdna\pagetemplates\PageTemplates;
use yii\console\ExitCode;

/**
 * Capture pages as templates and produce pages from them, from the command line.
 *
 * This exists so the engine is provable and supportable without any editor-facing screen: it is
 * the phase 4 gate in docs/specs/2026-09-09-page-templates-engine.md, and it stays useful
 * afterwards for seeding a site and for diagnosing a client's content.
 *
 * Console commands are not permission-gated in Craft, which is consistent with the service
 * knowing nothing about users (BR-26) — authorisation belongs to the control panel.
 */
class TemplatesController extends Controller
{
    /**
     * The id of the page to capture.
     */
    public ?int $entry = null;

    /**
     * The template's name.
     */
    public ?string $name = null;

    /**
     * An optional description.
     */
    public ?string $description = null;

    /**
     * Capture the layout only, with no content.
     */
    public bool $structureOnly = false;

    /**
     * The id of the template to use.
     */
    public ?int $template = null;

    /**
     * The handle of the section to create in, or to list templates for.
     */
    public ?string $section = null;

    /**
     * The id of the site to create in. Defaults to the primary site.
     */
    public ?int $site = null;

    /**
     * The id of the user to author the page as. Defaults to the first admin.
     */
    public ?int $author = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'capture' => ['entry', 'name', 'description', 'structureOnly'],
            'create-entry' => ['template', 'section', 'site', 'author'],
            'list' => ['section'],
            default => [],
        };
    }

    /**
     * Captures an existing page as a template.
     */
    public function actionCapture(): int
    {
        if ($this->entry === null || $this->name === null) {
            $this->stderr("--entry and --name are both required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $entry = Entry::find()->id($this->entry)->status(null)->one();

        if ($entry === null) {
            $this->stderr("No page exists with the id $this->entry.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $template = PageTemplates::getInstance()->templates->captureFromEntry(
            $entry,
            $this->name,
            $this->description,
            !$this->structureOnly,
        );

        $areas = implode(', ', array_map(
            fn($section) => $section->handle,
            $template->getAllowedSections(),
        ));

        $this->stdout("Captured template #$template->id \"$template->name\"\n", Console::FG_GREEN);
        $this->stdout("  page kind:    {$template->getEntryType()->handle}\n");
        $this->stdout('  content:      ' . ($template->includeContent ? 'included' : 'structure only') . "\n");
        $this->stdout("  allowed in:   $areas\n");

        return ExitCode::OK;
    }

    /**
     * Produces a new page from a template.
     *
     * Prints the unplaceable block types explicitly, even when there are none: a caller has to be
     * able to tell "nothing was lost" from "nobody checked" (BR-27).
     */
    public function actionCreateEntry(): int
    {
        if ($this->template === null || $this->section === null) {
            $this->stderr("--template and --section are both required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $service = PageTemplates::getInstance()->templates;
        $template = $service->getTemplateById($this->template);

        if ($template === null) {
            $this->stderr("No template exists with the id $this->template.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($this->section);

        if ($section === null) {
            $this->stderr("No section exists with the handle $this->section.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $authorId = $this->author ?? User::find()->admin()->one()?->id;

        try {
            $result = $service->reproduce($template, $section, $this->site, $authorId);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout("Created page #{$result->entry->id} from \"$template->name\"\n", Console::FG_GREEN);
        $this->stdout('  edit:               ' . $result->entry->getCpEditUrl() . "\n");

        // Both lists print even when empty: a caller has to be able to tell "nothing was lost"
        // from "nobody checked".
        $this->stdout(
            '  unplaceable blocks: ' . ($result->droppedBlockTypes === [] ? 'none' : implode(', ', $result->droppedBlockTypes)) . "\n",
            $result->droppedBlockTypes === [] ? Console::FG_GREY : Console::FG_YELLOW,
        );
        $this->stdout(
            '  emptied fields:     ' . ($result->emptiedFields === [] ? 'none' : implode(', ', $result->emptiedFields)) . "\n",
            $result->emptiedFields === [] ? Console::FG_GREY : Console::FG_YELLOW,
        );

        if (!$result->isFaithful()) {
            $this->stdout(
                "\n  This page is not a complete reproduction of the template.\n",
                Console::FG_YELLOW,
            );
        }

        return ExitCode::OK;
    }

    /**
     * Lists templates — all of them, or only those that apply to one section.
     */
    public function actionList(): int
    {
        $service = PageTemplates::getInstance()->templates;

        if ($this->section !== null) {
            $section = Craft::$app->getEntries()->getSectionByHandle($this->section);

            if ($section === null) {
                $this->stderr("No section exists with the handle $this->section.\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }

            $templates = $service->getTemplatesForSection($section);
            $this->stdout("Templates applying to $this->section:\n\n");
        } else {
            $templates = $service->getAllTemplates();
            $this->stdout("All templates:\n\n");
        }

        if ($templates === []) {
            $this->stdout("  (none)\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        foreach ($templates as $template) {
            $entryType = $template->getEntryType();

            // Reported as a distinct value rather than as prose, so a caller can act on it.
            $state = match (true) {
                !$template->snapshotDecoded => 'unreadable (stored snapshot is not valid JSON)',
                !$template->getIsReadable() => sprintf(
                    'unreadable (snapshot format %d is newer than this build)',
                    $template->snapshotVersion,
                ),
                $entryType === null => 'unusable (page kind deleted)',
                default => 'usable',
            };

            $areas = array_map(fn($section) => $section->handle, $template->getAllowedSections());
            $staleCount = count($template->getAllowedSectionUids()) - count($areas);

            $this->stdout(sprintf(
                "  #%-4d %-28s %-12s %-16s %s%s\n",
                $template->id,
                $template->name,
                $entryType?->handle ?? '-',
                $template->includeContent ? 'with content' : 'structure only',
                $areas === [] ? '(no areas)' : implode(', ', $areas),
                $staleCount > 0 ? " [+$staleCount stale]" : '',
            ));

            if ($state !== 'usable') {
                $this->stdout("        $state\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }
}
