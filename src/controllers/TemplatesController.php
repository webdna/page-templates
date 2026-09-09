<?php

namespace webdna\pagetemplates\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
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
