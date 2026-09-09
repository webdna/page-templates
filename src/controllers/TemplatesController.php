<?php

namespace webdna\pagetemplates\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\Controller;
use webdna\pagetemplates\models\PageTemplate;
use webdna\pagetemplates\PageTemplates;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control-panel actions for templates.
 *
 * Deliberately thin. Every decision it makes is delegated: who may do it to the Access service,
 * what to do to the Templates service. Both are covered by the integration suite, which a
 * controller cannot be without a web request — so the less that lives here, the less is untested.
 */
class TemplatesController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Everything here is control-panel only. Nothing about templates belongs on the front end.
        $this->requireCpRequest();

        return parent::beforeAction($action);
    }

    /**
     * The management list.
     */
    public function actionIndex(): Response
    {
        $this->requireManagePermission();

        $service = PageTemplates::getInstance()->templates;

        $templates = $service->getAllTemplates();

        return $this->renderTemplate('page-templates/index', [
            'templates' => $templates,
            'tableData' => array_map(fn(PageTemplate $template) => $this->tableRow($template), $templates),
        ]);
    }

    /**
     * The screen for renaming a template and setting where it may be used.
     */
    public function actionEdit(?int $templateId = null): Response
    {
        $this->requireManagePermission();

        $template = PageTemplates::getInstance()->templates->getTemplateById($templateId);

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        return $this->renderTemplate('page-templates/_edit', [
            'template' => $template,
            // Only sections that accept this template's kind of page are offered. Allowing any
            // other would let a curator create a combination the engine has to refuse later
            // (BR-17), and an option that cannot work should not be presented.
            'availableSections' => $this->sectionsAccepting($template),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        $service = PageTemplates::getInstance()->templates;
        $request = Craft::$app->getRequest();

        $template = $service->getTemplateById((int)$request->getRequiredBodyParam('templateId'));

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        $template->name = trim((string)$request->getBodyParam('name', ''));
        $template->description = $request->getBodyParam('description') ?: null;
        $template->setAllowedSectionUids($request->getBodyParam('allowedSectionUids') ?: []);

        if (!$service->saveTemplate($template)) {
            Craft::$app->getSession()->setError(Craft::t('page-templates', 'Couldn’t save the template.'));

            // Re-rendered with the submitted model rather than redirected, so the screen keeps
            // what the curator typed and shows the field errors against it.
            return $this->renderTemplate('page-templates/_edit', [
                'template' => $template,
                'availableSections' => $this->sectionsAccepting($template),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('page-templates', 'Template saved.'));

        return $this->redirectToPostedUrl($template);
    }

    public function actionDelete(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $service = PageTemplates::getInstance()->templates;
        $template = $service->getTemplateById((int)Craft::$app->getRequest()->getRequiredBodyParam('id'));

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        // Pages produced from it are untouched (BR-19) — a snapshot is a copy, so nothing built
        // from a template refers back to it.
        $service->deleteTemplate($template);

        return $this->asSuccess(Craft::t('page-templates', 'Template deleted.'));
    }

    public function actionReorder(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        PageTemplates::getInstance()->templates->reorderTemplates($ids);

        return $this->asSuccess();
    }

    /**
     * One row for the management table.
     *
     * State is reported as a readable phrase rather than a flag, because "unreadable" and
     * "unusable" fail for different reasons and a curator can only act on the difference.
     */
    private function tableRow(PageTemplate $template): array
    {
        $entryType = $template->getEntryType();
        $areas = array_map(fn(Section $section) => $section->name, $template->getAllowedSections());
        $staleCount = count($template->getAllowedSectionUids()) - count($areas);

        $state = match (true) {
            !$template->snapshotDecoded => Craft::t('page-templates', 'Unreadable — snapshot is corrupt'),
            !$template->getIsReadable() => Craft::t('page-templates', 'Unreadable — newer snapshot format'),
            $entryType === null => Craft::t('page-templates', 'Unusable — kind of page deleted'),
            $areas === [] => Craft::t('page-templates', 'Usable, but allowed nowhere'),
            default => Craft::t('page-templates', 'Usable'),
        };

        return [
            'id' => $template->id,
            'title' => $template->name,
            'url' => UrlHelper::cpUrl("page-templates/$template->id"),
            'pageKind' => $entryType?->name ?? '—',
            'content' => $template->includeContent
                ? Craft::t('page-templates', 'Content and layout')
                : Craft::t('page-templates', 'Layout only'),
            'areas' => $areas === []
                ? '—'
                : implode(', ', $areas) . ($staleCount > 0 ? sprintf(' (+%d stale)', $staleCount) : ''),
            'state' => $state,
        ];
    }

    /**
     * The sections whose available page kinds include this template's.
     *
     * @return Section[]
     */
    private function sectionsAccepting(PageTemplate $template): array
    {
        $entryType = $template->getEntryType();

        if ($entryType === null) {
            return [];
        }

        return array_values(array_filter(
            Craft::$app->getEntries()->getAllSections(),
            function(Section $section) use ($entryType): bool {
                foreach ($section->getEntryTypes() as $available) {
                    if ($available->uid === $entryType->uid) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    private function requireManagePermission(): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !PageTemplates::getInstance()->access->canManageTemplates($user)) {
            throw new ForbiddenHttpException('You are not allowed to manage page templates.');
        }
    }

    /**
     * Captures a page as a new template.
     *
     * BR-1: needs the save permission *and* edit access to that page.
     */
    public function actionSaveFromEntry(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $request = Craft::$app->getRequest();
        $entryId = $request->getRequiredBodyParam('entryId');
        $siteId = $request->getBodyParam('siteId');

        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->one();

        if ($entry === null) {
            throw new NotFoundHttpException('Page not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!PageTemplates::getInstance()->access->canSaveTemplate($user, $entry)) {
            throw new ForbiddenHttpException('You are not allowed to save this page as a template.');
        }

        $name = trim((string)$request->getBodyParam('name', ''));

        if ($name === '') {
            // BR-15. Returned as a field-level failure rather than an exception so the dialogue can
            // stay open with what the editor typed still in it.
            return $this->asFailure(
                Craft::t('page-templates', 'A name is required.'),
                ['errors' => ['name' => [Craft::t('page-templates', 'A name is required.')]]],
            );
        }

        try {
            $template = PageTemplates::getInstance()->templates->captureFromEntry(
                $entry,
                $name,
                $request->getBodyParam('description') ?: null,
                (bool)$request->getBodyParam('includeContent', true),
            );
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), 'page-templates');

            return $this->asFailure(Craft::t('page-templates', 'Couldn’t save the template.'));
        }

        $areas = array_map(
            fn($section) => $section->name,
            $template->getAllowedSections(),
        );

        return $this->asSuccess(
            // Says where it can be used, because BR-3 restricts a new template to one area and an
            // editor who is not told that will wonder why it is missing elsewhere.
            Craft::t('page-templates', 'Saved “{name}”. It can be used in {areas}.', [
                'name' => $template->name,
                'areas' => implode(', ', $areas),
            ]),
            [
                'template' => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'includeContent' => $template->includeContent,
                    'allowedAreas' => $areas,
                ],
            ],
        );
    }
}
