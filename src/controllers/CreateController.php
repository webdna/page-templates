<?php

namespace webdna\pagetemplates\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use webdna\pagetemplates\PageTemplates;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Creating a page from a template.
 *
 * The response deliberately mirrors what Craft's own `entries/create` returns, so the new-page
 * button's existing redirect behaviour is reused rather than reimplemented — one less thing to
 * keep in step with Craft.
 */
class CreateController extends Controller
{
    /**
     * The session flash key carrying what could not be reproduced, keyed by the new page's id.
     *
     * A flash rather than a query parameter: it survives the redirect Craft's button performs,
     * cannot be forged by editing the URL, and disappears once seen.
     */
    public const INCOMPLETE_FLASH_PREFIX = 'page-templates-incomplete-';

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();

        return parent::beforeAction($action);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $request = Craft::$app->getRequest();
        $plugin = PageTemplates::getInstance();

        $template = $plugin->templates->getTemplateById((int)$request->getRequiredBodyParam('templateId'));

        if ($template === null) {
            throw new NotFoundHttpException('Template not found.');
        }

        $section = Craft::$app->getEntries()->getSectionByHandle(
            (string)$request->getRequiredBodyParam('section'),
        );

        if ($section === null) {
            throw new NotFoundHttpException('Section not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        // BR-4 in full: the curator allowed this area, the area accepts this kind of page, and
        // this user may create pages here. The first two come from the Templates service, the
        // third from Access — and asking for all three here means a forged request cannot skip
        // the checks the button applied when it decided what to offer.
        if (!$plugin->access->canUseTemplate($user, $template, $section)) {
            throw new ForbiddenHttpException('You are not allowed to use this template here.');
        }

        try {
            $result = $plugin->templates->reproduce(
                $template,
                $section,
                $request->getBodyParam('siteId') ? (int)$request->getBodyParam('siteId') : null,
                $user->id,
            );
        } catch (\Throwable $e) {
            Craft::error($e->getMessage(), 'page-templates');

            return $this->asFailure($e->getMessage());
        }

        // BR-10. Anything the engine could not reproduce is carried to the new page rather than
        // dropped here, so the editor sees it in the context of the page it concerns.
        if (!$result->isFaithful()) {
            Craft::$app->getSession()->setFlash(
                self::INCOMPLETE_FLASH_PREFIX . $result->entry->id,
                [
                    'templateName' => $template->name,
                    'droppedBlockTypes' => $result->droppedBlockTypes,
                    'emptiedFields' => $result->emptiedFields,
                ],
            );
        }

        return $this->asSuccess(data: [
            // Named as Craft names it, so the button can treat this response like the stock one.
            'cpEditUrl' => UrlHelper::urlWithParams($result->entry->getCpEditUrl(), ['fresh' => 1]),
            'droppedBlockTypes' => $result->droppedBlockTypes,
            'emptiedFields' => $result->emptiedFields,
        ]);
    }
}
