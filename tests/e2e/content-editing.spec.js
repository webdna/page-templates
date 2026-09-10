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
 * browser: whether the banner appears on the temporary page, whether its buttons are wired,
 * and — the part that matters — whether an edit made in Craft's editor actually reaches the
 * template.
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
     * Opens the scratch page for a template and returns its banner.
     */
    async function beginEditing(page, url) {
        await page.goto(url);
        await page.locator('#page-templates-edit-content').click();

        await page.waitForURL(/\/admin\/content\/entries\/.+/, {timeout: 25000});
        await expect(page.locator('#main-content')).toBeVisible();

        const banner = page.locator('[data-page-templates-editing]');
        await expect(banner).toBeVisible();

        return banner;
    }

    test('the scratch page says what it is and offers the two ways out', async ({page}) => {
        const banner = await beginEditing(page, editableUrl);

        // Without this the page looks like an ordinary page, and saving it the usual way would
        // look like it had updated the template when it had not.
        await expect(banner).toContainText('You are editing the content of the template');
        await expect(banner).toContainText('This page is temporary');
        await expect(page.locator('#page-templates-save-content')).toBeVisible();
        await expect(page.locator('#page-templates-discard-content')).toBeVisible();

        // Craft's own editor is doing the work — the point of the whole approach.
        await expect(page.locator('input[name="fields[heading]"]')).toBeVisible();
    });

    test('an edit made on the scratch page reaches the template', async ({page}) => {
        await beginEditing(page, editableUrl);

        const marker = `Edited in a browser ${Date.now()}`;
        await page.locator('input[name="fields[heading]"]').fill(marker);
        // Craft autosaves the draft; the save-back reads what is stored, not what is on screen.
        await page.waitForTimeout(2500);

        await page.locator('#page-templates-save-content').click();
        await page.waitForURL(/\/admin\/page-templates\/\d+/, {timeout: 25000});
        await expect(page.locator('#notifications')).toContainText('Updated the content');

        // The undo only appears once there is something to undo, so it doubles as proof the
        // previous content was kept.
        await expect(page.locator('#page-templates-revert-content')).toBeVisible();

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

    test('an ordinary page carries no editing banner', async ({page}) => {
        // The banner is rendered from a hook that runs on every element edit screen, so a wrong
        // condition would put it on every page in the site.
        await page.goto('/admin/content/entries/landing/33');
        await expect(page.locator('#main-content')).toBeVisible();

        await expect(page.locator('[data-page-templates-editing]')).toHaveCount(0);
    });
});
