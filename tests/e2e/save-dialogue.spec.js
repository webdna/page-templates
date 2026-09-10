import {expect, test} from '@playwright/test';
import {
    TEST_TEMPLATE_PREFIX,
    deleteTestTemplates,
    openExamplePage,
    signIn,
} from './helpers.js';

/**
 * The save-as-template dialogue.
 *
 * This is one half of what HTTP verification could not reach. Fetching the edit screen proves the
 * menu item is rendered and its handler wired; it cannot prove that clicking actually opens a
 * dialogue, or — the part that matters most — that a validation error leaves what the editor typed
 * intact rather than throwing it away.
 */
test.describe('Save as a page template', () => {
    test.beforeEach(async ({page}) => {
        await signIn(page, 'editor');
        await openExamplePage(page);
    });

    // Saving is the behaviour under test, so these runs necessarily leave templates behind.
    // Removing them keeps a developer's control panel usable after a few dozen runs.
    test.afterAll(async ({browser}) => {
        await deleteTestTemplates(browser);
    });

    async function openDialogue(page) {
        // Craft's edit screen has several disclosure menus ("Actions", "More actions", the
        // breadcrumb, the account menu). Rather than hard-code which one Craft currently puts
        // element actions in — it has moved before and would move again — find the menu that
        // actually contains the item and open its own trigger.
        const menuId = await page.evaluate(() => {
            const label = [...document.querySelectorAll('.menu-item-label, a, button')]
                .find((el) => el.textContent.trim() === 'Save as a page template');

            return label?.closest('.menu')?.id ?? null;
        });

        expect(menuId, 'the save-as-template item should be in a menu on this page').toBeTruthy();

        await page.locator(`[data-disclosure-trigger][aria-controls="${menuId}"]`).click();

        const item = page.locator(`#${menuId}`).getByText('Save as a page template', {exact: true});

        await expect(item).toBeVisible();
        await item.click();

        const modal = page.locator('.modal').filter({hasText: 'Save as a page template'});
        await expect(modal).toBeVisible();

        return modal;
    }

    test('clicking the menu item opens the dialogue, focused and ready', async ({page}) => {
        const modal = await openDialogue(page);

        await expect(modal.getByText('This page is not changed')).toBeVisible();
        await expect(modal.locator('input[type="checkbox"]')).toBeChecked();
        await expect(modal.locator('input[type="text"]').first()).toBeFocused();
    });

    test('an empty name is refused without losing what was typed', async ({page}) => {
        const modal = await openDialogue(page);

        const description = 'A description that must survive the error';
        await modal.locator('input[type="text"]').nth(1).fill(description);
        await modal.getByRole('button', {name: 'Save template'}).click();

        // The error belongs in the dialogue, not in a toast that takes the typing with it.
        await expect(modal.locator('.errors')).toBeVisible();
        await expect(modal.locator('.errors')).toContainText('name is required');
        await expect(modal).toBeVisible();
        await expect(modal.locator('input[type="text"]').nth(1)).toHaveValue(description);
    });

    test('saving closes the dialogue and says where the template can be used', async ({page}) => {
        const modal = await openDialogue(page);
        const name = `${TEST_TEMPLATE_PREFIX}saved ${Date.now()}`;

        await modal.locator('input[type="text"]').first().fill(name);
        await modal.getByRole('button', {name: 'Save template'}).click();

        await expect(modal).toBeHidden({timeout: 10000});

        // Craft renders more than one notification region; assert against the container.
        const notices = page.locator('#notifications');

        await expect(notices).toContainText(name);
        // BR-3: a new template starts out usable only where its page lives, and the editor is told
        // so — otherwise they will look for it elsewhere and report it missing.
        await expect(notices).toContainText('Landing');
    });

    test('the page itself is unchanged by being captured', async ({page}) => {
        const before = await page.locator('#title, input[name="title"]').first().inputValue();

        const modal = await openDialogue(page);
        await modal.locator('input[type="text"]').first().fill(`${TEST_TEMPLATE_PREFIX}unchanged ${Date.now()}`);
        await modal.getByRole('button', {name: 'Save template'}).click();
        await expect(modal).toBeHidden({timeout: 10000});

        await page.reload();
        await expect(page.locator('#title, input[name="title"]').first()).toHaveValue(before);
    });
});
