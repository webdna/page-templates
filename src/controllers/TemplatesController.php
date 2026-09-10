<?php

namespace webdna\pagetemplates\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\Controller;
use webdna\pagetemplates\exceptions\IncompleteReproductionException;
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

        // The edit screen's own JavaScript uses Craft.t(), which needs the category's messages
        // shipped to the browser — they are not there by default, and a missing one renders as
        // the untranslated key on a translated site rather than failing.
        Craft::$app->getView()->registerTranslations('page-templates', [
            'Put back the content this template held before it was last edited? The current content will be lost.',
            'Something went wrong.',
        ]);

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
            // The edit screen's own JavaScript uses Craft.t(), which needs the category's messages
        // shipped to the browser — they are not there by default, and a missing one renders as
        // the untranslated key on a translated site rather than failing.
        Craft::$app->getView()->registerTranslations('page-templates', [
            'Put back the content this template held before it was last edited? The current content will be lost.',
            'Something went wrong.',
        ]);

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

    /**
     * Starts editing a template's content, and hands the curator the scratch page to edit.
     *
     * A snapshot cannot be edited in place, so this produces a page from the template and sends
     * the curator to Craft's own editor for it — every field type then behaves exactly as it does
     * anywhere else. Saving it back is TemplatesController::actionSaveContent().
     */
    public function actionEditContent(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        $plugin = PageTemplates::getInstance();
        $templateId = (int)Craft::$app->getRequest()->getRequiredBodyParam('templateId');
        $template = $plugin->templates->getTemplateById($templateId);

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        try {
            $reproduction = $plugin->contentEditing->begin(
                $template,
                null,
                Craft::$app->getUser()->getId(),
            );
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), 'page-templates');

            // The refusals here are all things a curator can act on — layout only, nowhere to put
            // it, somebody else is editing — so the reason is shown rather than swallowed.
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(data: [
            'cpEditUrl' => UrlHelper::urlWithParams(
                $reproduction->entry->getCpEditUrl(),
                ['fresh' => 1],
            ),
        ]);
    }

    /**
     * Captures the scratch page back over the template it came from.
     *
     * This is the scratch page's own save button, so the whole edit form posts here. What was
     * typed is applied to the page and saved before it is captured — Craft autosaves drafts as
     * you go, but relying on that would lose whatever was typed in the last moment before
     * saving, which is precisely when people type the thing they came to change.
     */
    public function actionSaveContent(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = PageTemplates::getInstance();
        // **Always elementId, never draftId.** Craft's element editor posts a `draftId` of its
        // own — the row in the `drafts` table — while a scratch page is identified here by its
        // *element* id. Reading `draftId` looks right, matches on nothing, and 404s on a request
        // that is in every other way correct.
        $scratchId = (int)$request->getRequiredBodyParam('elementId');
        $record = $plugin->contentEditing->recordForDraft($scratchId);

        if ($record === null) {
            throw new NotFoundHttpException('That page is not editing a template.');
        }

        $draft = Entry::find()
            ->id($scratchId)
            ->siteId($record->siteId)
            ->status(null)
            ->drafts(null)
            ->one();

        if ($draft === null) {
            throw new NotFoundHttpException('Page not found.');
        }

        if ($request->getBodyParam('fields') !== null) {
            // SCENARIO_ESSENTIALS, as the page was produced under: a template is allowed to hold
            // partial content, and validating it as though it were going live would refuse to
            // save a perfectly good template because a required field is blank.
            $draft->setFieldValuesFromRequest('fields');
            $draft->setScenario(Element::SCENARIO_ESSENTIALS);

            if (!Craft::$app->getElements()->saveElement($draft)) {
                return $this->asFailure(
                    Craft::t('page-templates', 'Couldn’t save the page.'),
                    ['errors' => $draft->getErrors()],
                );
            }
        }

        try {
            $template = $plugin->contentEditing->saveBack($record, $draft);
        } catch (IncompleteReproductionException $e) {
            // BR-30. Named rather than generic: a curator can only act on knowing *what* would
            // have been lost, and the alternative offered is a real one.
            return $this->asFailure($e->getMessage(), [
                'lost' => $e->lost,
                'canSaveAsNew' => true,
            ]);
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), 'page-templates');

            return $this->asFailure($e->getMessage());
        }

        $redirect = UrlHelper::cpUrl("page-templates/$template->id");

        return $this->asSuccess(
            Craft::t('page-templates', 'Updated the content of “{name}”.', ['name' => $template->name]),
            ['redirect' => $redirect],
            $redirect,
        );
    }

    /**
     * Throws a scratch page away, leaving the template as it was.
     */
    public function actionDiscardContent(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        $plugin = PageTemplates::getInstance();
        // elementId, not draftId — see the note in actionSaveContent().
        $scratchId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');
        $record = $plugin->contentEditing->recordForDraft($scratchId);

        if ($record === null) {
            throw new NotFoundHttpException('That page is not editing a template.');
        }

        $templateId = $record->templateId;
        $plugin->contentEditing->discard($record);

        Craft::$app->getSession()->setNotice(
            Craft::t('page-templates', 'Discarded the changes. The template is unchanged.'),
        );

        return $this->asSuccess(data: [
            'redirect' => UrlHelper::cpUrl("page-templates/$templateId"),
        ]);
    }

    /**
     * Puts back the content a template held before it was last edited (BR-33).
     */
    public function actionRevertContent(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        $plugin = PageTemplates::getInstance();
        $templateId = (int)Craft::$app->getRequest()->getRequiredBodyParam('templateId');
        $template = $plugin->templates->getTemplateById($templateId);

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        if (!$plugin->contentEditing->revert($template)) {
            return $this->asFailure(
                Craft::t('page-templates', 'There is nothing to go back to.'),
            );
        }

        // A session notice rather than the response's own message: the browser navigates away
        // immediately, which would take an inline message with it.
        Craft::$app->getSession()->setNotice(
            Craft::t('page-templates', 'Put back the previous content of “{name}”.', [
                'name' => $template->name,
            ]),
        );

        return $this->asSuccess(data: [
            'redirect' => UrlHelper::cpUrl("page-templates/$template->id"),
        ]);
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
