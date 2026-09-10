import {expect, test} from '@playwright/test';
import {signIn} from './helpers.js';

/**
 * Reordering the template list.
 *
 * The order a curator sets here is the order editors are offered templates in (BR-14), so it has
 * to survive a reload — an order that looks right until you refresh is worse than no ordering at
 * all. The reorder endpoint can be exercised over HTTP, but only a browser proves that dragging a
 * row is wired to it: Craft's VueAdminTable owns the drag handling, and a mis-declared
 * reorderAction fails with no error anywhere the server can see.
 */
test.describe('Reordering templates', () => {
    test.beforeEach(async ({page}) => {
        // Managing the list is the curator's permission, not the editor's.
        await signIn(page, 'curator');
        await page.goto('/admin/page-templates');
        await expect(page.locator('#main-content')).toBeVisible();
        await expect(page.locator('#page-templates-table table tbody tr').first()).toBeVisible();
    });

    async function names(page) {
        return (await page.locator('#page-templates-table table tbody tr td a').allTextContents())
            .map((t) => t.trim())
            .filter(Boolean);
    }

    /**
     * Sortable.js listens to real pointer events, so this moves the mouse in steps rather than
     * jumping — a single move is often not enough for it to register a drag at all.
     */
    async function dragRowToTop(page, rowIndex) {
        const handle = page.locator('#page-templates-table table tbody tr').nth(rowIndex)
            .locator('.move').first();
        const target = page.locator('#page-templates-table table tbody tr').first();

        const from = await handle.boundingBox();
        const to = await target.boundingBox();

        await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
        await page.mouse.down();

        for (let step = 1; step <= 10; step++) {
            await page.mouse.move(
                from.x + from.width / 2,
                from.y + (to.y - from.y) * (step / 10) - 4
            );
        }

        await page.mouse.up();
    }

    test('a dragged row keeps its new position after a reload', async ({page}) => {
        const before = await names(page);

        // Needs at least two rows to be a reordering at all; the table only offers dragging then.
        expect(before.length).toBeGreaterThan(1);

        await dragRowToTop(page, 1);

        // The table reports the save; without it the drag was purely visual.
        await expect(page.locator('#notifications')).toContainText('reordered', {timeout: 10000});

        const after = await names(page);
        expect(after[0]).toBe(before[1]);

        await page.reload();
        await expect(page.locator('#page-templates-table table tbody tr').first()).toBeVisible();

        // The assertion that matters: the curator's order is what an editor will be offered.
        expect(await names(page)).toEqual(after);
    });
});
