import {expect, test} from '@playwright/test';
import {
    TEST_TEMPLATE_PREFIX,
    deleteTestTemplates,
    saveTemplateFromExamplePage,
    signIn,
    templateUrlByName,
} from './helpers.js';

/**
 * Editing the content a template holds.
 *
 * A template's content is edited by producing a temporary page from it, editing that page in
 * Craft's own editor, and capturing it back. Almost none of that can be checked without a
 * browser: the scratch page is Craft's own entry edit screen, reshaped through the CP screen
 * response — its title, its save button and where that button posts — and none of that is
 * visible until the page renders.
 *
 * Each test works on a template it created itself, so a run that fails part-way leaves an
 * abandoned editing session on a throwaway template rather than on a real one.
 */
test.describe('Editing a template’s content', () => {
    let editableUrl;
    let skeletonUrl;

    test.beforeAll(async ({browser}) => {
        // Two templates saved through the real dialogue, plus three sign-ins: comfortably more
        // than a single test's budget.
        test.setTimeout(120_000);

        const stamp = Date.now();

        // Two identities, so two pages: browser.newPage() gives each its own context, and
        // visiting the login screen while already signed in simply redirects to the dashboard.
        const asEditor = await browser.newPage();
        await signIn(asEditor, 'editor');
        const editable = await saveTemplateFromExamplePage(asEditor, `${TEST_TEMPLATE_PREFIX}editable ${stamp}`);
        const skeleton = await saveTemplateFromExamplePage(
            asEditor,
            `${TEST_TEMPLATE_PREFIX}skeleton ${stamp}`,
            {includeContent: false}
        );
        await asEditor.close();

        const asCurator = await browser.newPage();
        await signIn(asCurator, 'curator');
        editableUrl = await templateUrlByName(asCurator, editable);
        skeletonUrl = await templateUrlByName(asCurator, skeleton);
        await asCurator.close();
    });

    test.afterAll(async ({browser}) => {
        // Deleting the templates also clears any editing session pointing at them, since the
        // mapping row's foreign key cascades.
        await deleteTestTemplates(browser);
    });

    test.beforeEach(async ({page}) => {
        await signIn(page, 'curator');
        // The discard button confirms natively; accepting keeps a stray dialog from wedging the
        // page for every test that follows.
        page.on('dialog', (dialog) => dialog.accept());
    });

    /**
     * Opens the scratch page for a template.
     */
    async function beginEditing(page, url) {
        await page.goto(url);
        await page.locator('#page-templates-edit-content').click();

        await page.waitForURL(/\/admin\/content\/entries\/.+/, {timeout: 25000});
        await expect(page.locator('#main-content')).toBeVisible();
        await expect(page.locator('#page-templates-discard-content')).toBeVisible();
    }

    const saveTemplate = (page) =>
        page.locator('#action-buttons button[type="submit"]').first().click();

    test('the scratch page reads as the template, not as a new entry', async ({page}) => {
        await beginEditing(page, editableUrl);

        // Left alone, Craft titles an unpublished draft "Create a new entry" — which is what a
        // curator would be told they were doing while actually editing a template.
        await expect(page.locator('#header h1')).toContainText('Template');
        await expect(page.locator('#header h1')).not.toContainText('Create a new entry');

        // Craft's own editor is doing the work — the point of the whole approach.
        await expect(page.locator('input[name="fields[heading]"]')).toBeVisible();

        // Craft's notice slot, not a banner of ours.
        await expect(page.locator('#main-content')).toContainText('Changes here update the template');
    });

    test('the save button saves the template rather than publishing the page', async ({page}) => {
        // The single most important assertion on this screen. Craft points an unpublished
        // draft's save button at elements/apply-draft, which would publish the scratch page as a
        // real page on the site — silently, and looking like a success.
        await beginEditing(page, editableUrl);

        await expect(page.locator('input[name="action"]')).toHaveValue(
            'page-templates/templates/save-content'
        );
        await expect(page.locator('#action-buttons button[type="submit"]').first())
            .toHaveText(/Save template/);
    });

    test('the way out sits with the other buttons, in Craft’s own order', async ({page}) => {
        await beginEditing(page, editableUrl);

        const labels = await page.locator('#action-buttons button, #action-buttons a')
            .evaluateAll((els) => els.map((el) => el.textContent.trim()).filter(Boolean));

        expect(labels).toContain('Discard');
        // Between viewing the page and saving it, which is where a way out belongs.
        expect(labels.indexOf('Discard')).toBeGreaterThan(labels.indexOf('View'));
        expect(labels.indexOf('Discard')).toBeLessThan(labels.findIndex((l) => /Save template/.test(l)));
    });

    test('an edit made on the scratch page reaches the template', async ({page}) => {
        await beginEditing(page, editableUrl);

        const marker = `Edited in a browser ${Date.now()}`;
        await page.locator('input[name="fields[heading]"]').fill(marker);
        // Craft autosaves the draft; the save-back reads what is stored, not what is on screen.
        await page.waitForTimeout(2500);

        await saveTemplate(page);
        await page.waitForURL(/\/admin\/page-templates\/\d+/, {timeout: 25000});
        await expect(page.locator('#notifications')).toContainText('Updated the content');

        // That the replaced version is kept is covered by ContentEditingTest — there is no
        // control for it on screen while rollback is hidden.

        // What the template now *produces* is the only thing that matters. Reopening the scratch
        // page is the cheapest way to see the stored snapshot rendered.
        await beginEditing(page, editableUrl);
        await expect(page.locator('input[name="fields[heading]"]')).toHaveValue(marker);
        await page.locator('#page-templates-discard-content').click();
        await page.waitForURL(/\/admin\/page-templates\/\d+/, {timeout: 25000});
    });

    test('discarding leaves the template as it was', async ({page}) => {
        await beginEditing(page, editableUrl);

        const before = await page.locator('input[name="fields[heading]"]').inputValue();
        await page.locator('input[name="fields[heading]"]').fill('This should never be kept');
        await page.waitForTimeout(2500);

        await page.locator('#page-templates-discard-content').click();
        await page.waitForURL(/\/admin\/page-templates\/\d+/, {timeout: 25000});
        await expect(page.locator('#notifications')).toContainText('unchanged');

        await beginEditing(page, editableUrl);
        await expect(page.locator('input[name="fields[heading]"]')).toHaveValue(before);
        await page.locator('#page-templates-discard-content').click();
        await page.waitForURL(/\/admin\/page-templates\/\d+/, {timeout: 25000});
    });

    test('a layout-only template offers no content to edit, and says why', async ({page}) => {
        // BR-29. It holds no content, so a page made from it is empty — capturing that back
        // would wipe the template rather than edit it.
        await page.goto(skeletonUrl);

        await expect(page.locator('#page-templates-edit-content')).toHaveCount(0);
        await expect(page.locator('[data-page-templates-content]')).toContainText('layout only');
    });

    test('an ordinary page is left exactly as Craft built it', async ({page}) => {
        // The screen is reshaped from a response hook that runs on every control-panel screen,
        // so a wrong condition would retitle and rewire every entry in the site.
        await page.goto('/admin/content/entries/landing/33');
        await expect(page.locator('#main-content')).toBeVisible();

        await expect(page.locator('#page-templates-discard-content')).toHaveCount(0);
        await expect(page.locator('input[name="action"]')).not.toHaveValue(
            'page-templates/templates/save-content'
        );
    });
});
