import {expect, test} from '@playwright/test';
import {signIn} from './helpers.js';

/**
 * The control-panel nav item.
 *
 * Craft's Cp::iconSvg() returns an **empty string** for an icon it cannot load, logging a warning
 * and nothing else — so a wrong or missing icon path produces a nav item with a blank space where
 * the icon should be, and no error anywhere a person would look. That is how this shipped at
 * first: getCpNavItem() set an alias path to a file that did not exist.
 */
test.describe('Control-panel nav item', () => {
    test('the section appears in the sidebar with its icon drawn', async ({page}) => {
        await signIn(page, 'curator');

        const item = page.locator('#global-sidebar a').filter({hasText: 'Page Templates'});
        await expect(item).toBeVisible();

        // Not merely present: an empty <span class="nav-icon"> is what a broken path produces.
        await expect(item.locator('.nav-icon svg path')).toHaveCount(1);
    });

    test('an editor without manage rights gets no section at all', async ({page}) => {
        // BR-12: absent rather than visible-then-forbidden.
        await signIn(page, 'editor');

        await expect(
            page.locator('#global-sidebar a').filter({hasText: 'Page Templates'})
        ).toHaveCount(0);
    });
});
