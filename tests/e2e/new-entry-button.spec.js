import {expect, test} from '@playwright/test';
import {signIn} from './helpers.js';

/**
 * The New entry button.
 *
 * The highest-regression-risk surface in the plugin: it is replaced for *every* editor, including
 * those with no template permissions. Fetching the page proves the subclass is loaded and the
 * template data injected; only a browser can prove the menu opens, the group is in it, and
 * clicking an item actually produces a page.
 *
 * These tests create pages and templates in the development database. That is unavoidable —
 * the control panel runs against it — and they are harmless: the pages are unsaved drafts.
 */
test.describe('New entry button', () => {
    test.beforeEach(async ({page}) => {
        await signIn(page, 'editor');
    });

    /**
     * Selects a section in the index sidebar.
     *
     * The group is deliberately absent on the default "All entries" source: it spans every
     * section, so there is no single section whose templates could be offered. Every one of these
     * tests therefore starts by picking a section, which is also what an editor does.
     */
    async function selectSection(page, handle) {
        await page.goto('/admin/content/entries');
        await expect(page.locator('#main-content')).toBeVisible();

        await page.locator(`#sidebar a[data-handle="${handle}"]`).first().click();

        // The button is rebuilt in JS once the source's entries have loaded.
        await expect(page.locator('.btngroup.submit')).toBeVisible();
        await page.waitForTimeout(1500);
    }

    /**
     * Opens the New entry dropdown for a section and returns the menu.
     */
    async function openNewEntryMenu(page, handle = 'landing') {
        await selectSection(page, handle);

        const trigger = page.locator('.btngroup.submit [data-disclosure-trigger]').first();
        await expect(trigger).toBeVisible();
        await trigger.click();

        const menuId = await trigger.getAttribute('aria-controls');
        const menu = page.locator(`#${menuId}`);
        await expect(menu).toBeVisible();

        return menu;
    }

    test('the button still creates an ordinary blank page', async ({page}) => {
        // The regression that matters most. If the subclass breaks this, editors who have never
        // heard of templates cannot create pages.
        await selectSection(page, 'landing');

        const newBtn = page.locator('.btngroup.submit button.submit.add').first();
        await expect(newBtn).toBeVisible();
        await newBtn.click();

        await page.waitForURL(/\/admin\/content\/entries\/landing\/\d+/, {timeout: 20000});
        await expect(page.locator('#main-content')).toBeVisible();
    });

    test('the dropdown offers a From template group', async ({page}) => {
        const menu = await openNewEntryMenu(page);

        await expect(menu.locator('[data-page-templates-group]')).toBeVisible();
        await expect(menu.getByText('From template')).toBeVisible();
        await expect(menu.locator('[data-page-template-id]').first()).toBeVisible();
    });

    test('the group is absent on All entries, where no one section applies', async ({page}) => {
        // Not a defect, and worth pinning so it is not "fixed" into something misleading: this
        // source spans every section, so any list of templates shown here would be wrong for most
        // of the sections the button can create in.
        await page.goto('/admin/content/entries');
        await expect(page.locator('#main-content')).toBeVisible();
        await page.waitForTimeout(1500);

        const trigger = page.locator('.btngroup.submit [data-disclosure-trigger]').first();
        await trigger.click();

        const menu = page.locator(`#${await trigger.getAttribute('aria-controls')}`);
        await expect(menu).toBeVisible();

        // Craft's own section list is intact; only the template group is missing.
        await expect(menu.locator('a').first()).toBeVisible();
        await expect(menu.locator('[data-page-templates-group]')).toHaveCount(0);
    });

    test('the button gains no second dropdown arrow', async ({page}) => {
        // Regression guard. Garnish moves a disclosure menu's container out to <body>, so looking
        // for Craft's list *inside* the button group finds nothing and quietly builds a second,
        // identical arrow beside Craft's. Nothing throws and the group does appear — in the wrong
        // menu — so only counting the triggers catches it.
        await selectSection(page, 'landing');

        await expect(page.locator('.btngroup.submit [data-disclosure-trigger]')).toHaveCount(1);
    });

    test('switching section leaves no stale group behind', async ({page}) => {
        // updateButton() runs again on every source change. Because the group lives in a container
        // Garnish has moved out to <body>, it does not go away with the rebuilt button group —
        // so without an explicit clear, Landing's templates stay on the menu while the editor is
        // looking at Campaigns, and creating one would put the page in the wrong section.
        const landing = await openNewEntryMenu(page, 'landing');
        expect(await landing.locator('[data-page-template-id]').count()).toBeGreaterThan(0);

        await page.keyboard.press('Escape');
        await page.locator('#sidebar a[data-handle="campaigns"]').first().click();
        await page.waitForTimeout(1500);

        // Campaigns has no templates of its own, so the only group that could be here is Landing's.
        await expect(page.locator('[data-page-templates-group]')).toHaveCount(0);
        await expect(page.locator('[data-page-template-id]')).toHaveCount(0);
    });

    test('picking a template produces an assembled page', async ({page}) => {
        const menu = await openNewEntryMenu(page);

        const item = menu.locator('[data-page-template-id]').first();
        const name = (await item.textContent()).trim();

        await item.click();

        // Lands on the new page, exactly as Craft's own blank option does.
        await page.waitForURL(/\/admin\/content\/entries\/landing\/\d+/, {timeout: 25000});
        await expect(page.locator('#main-content')).toBeVisible();

        // Assembled: the template's blocks are present, not an empty page.
        const blocks = page.locator('.matrix .matrixblock, [data-type*="Entry"] .element');
        await expect(blocks.first()).toBeVisible({timeout: 15000});

        // BR-9: no title carried over — the editor must set it.
        await expect(page.locator('input[name="title"]:visible').first()).toHaveValue('');

        expect(name.length).toBeGreaterThan(0);
    });
});
