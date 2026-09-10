import {expect} from '@playwright/test';

/**
 * Credentials come from the environment, never from the repository.
 *
 * Export them before running, e.g.:
 *   export PT_EDITOR_PASSWORD=… PT_CURATOR_PASSWORD=…
 *
 * The fixture users are created by
 * migrations/m260909_143350_create_page_templates_test_users.php; set their passwords with
 * `ddev craft users/set-password <username>`.
 */
export function passwordFor(username) {
    const key = `PT_${username.toUpperCase()}_PASSWORD`;
    const value = process.env[key];

    if (!value) {
        throw new Error(
            `${key} is not set. These tests sign in as the fixture user "${username}"; ` +
            `set a password with "ddev craft users/set-password ${username}" and export ${key}.`
        );
    }

    return value;
}

export async function signIn(page, username) {
    await page.goto('/admin/login');

    // Craft's login form is rendered by JavaScript and its input ids are randomised per render
    // (`text134194231`, `password2031857766`, …), so these must key on the field names.
    // Craft's login page also carries a hidden password-reset form with its own username field,
    // so these are scoped to what is actually visible.
    await page.locator('input[name="username"]:visible').fill(username);
    await page.locator('input[name="password"]:visible').fill(passwordFor(username));
    await page.locator('button[type="submit"]:visible').first().click();

    // Craft lands on the dashboard or a redirect target; either way the CP chrome appears.
    await expect(page.locator('#global-sidebar')).toBeVisible({timeout: 20000});
}

/**
 * Every template these tests create is named with this prefix, so cleanup can find them without
 * risking anything a person made. Saving a template is the behaviour under test, so the suite
 * cannot avoid creating them — it can only be responsible for removing them again.
 */
export const TEST_TEMPLATE_PREFIX = 'Playwright ';

/**
 * Deletes the templates a run created.
 *
 * Goes through the plugin's own delete endpoint rather than the table's delete button: Craft's
 * VueAdminTable confirms with a native window.confirm(), which blocks the browser and would take
 * the rest of the session with it.
 */
export async function deleteTestTemplates(browser) {
    const page = await browser.newPage();

    try {
        await signIn(page, 'curator');
        await page.goto('/admin/page-templates');

        // An empty list renders no table at all, which is a clean result, not a failure.
        if (!(await page.locator('#page-templates-table table').count())) {
            return;
        }

        const ids = await page.$$eval(
            '#page-templates-table table tbody tr td a',
            (links, prefix) => links
                .filter((a) => a.textContent.trim().startsWith(prefix))
                .map((a) => (a.getAttribute('href') || '').match(/(\d+)$/)?.[1])
                .filter(Boolean),
            TEST_TEMPLATE_PREFIX
        );

        for (const id of ids) {
            await page.evaluate(
                (templateId) => window.Craft.sendActionRequest(
                    'POST',
                    'page-templates/templates/delete',
                    {data: {id: templateId}}
                ),
                id
            );
        }
    } finally {
        await page.close();
    }
}

/**
 * Opens the save-as-template dialogue on the page currently being edited.
 *
 * Craft's edit screen has several disclosure menus ("Actions", the breadcrumb, the account menu).
 * Rather than hard-code which one Craft currently puts element actions in — it has moved before
 * and would move again — this finds the menu that actually contains the item and opens its own
 * trigger.
 */
export async function openSaveDialogue(page) {
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

/**
 * Saves a template from the example page and returns its name.
 *
 * Goes through the real dialogue rather than seeding the database, so a test that depends on a
 * template also depends on the flow that makes one — and cannot pass against a build where
 * saving is broken.
 */
export async function saveTemplateFromExamplePage(page, name, {includeContent = true} = {}) {
    await openExamplePage(page);

    const modal = await openSaveDialogue(page);
    await modal.locator('input[type="text"]').first().fill(name);

    if (!includeContent) {
        // Clicked by its label: Craft styles the checkbox so the label sits over it, and
        // Playwright refuses a click it can see would land on something else.
        await modal.getByText('Include this page').click();
        await expect(modal.locator('input[type="checkbox"]')).not.toBeChecked();
    }

    await modal.getByRole('button', {name: 'Save template'}).click();
    await expect(modal).toBeHidden({timeout: 10000});

    return name;
}

/**
 * The control-panel URL of a template, found by name in the management list.
 */
export async function templateUrlByName(page, name) {
    await page.goto('/admin/page-templates');
    await expect(page.locator('#page-templates-table table tbody tr').first()).toBeVisible();

    const href = await page.$$eval(
        '#page-templates-table table tbody tr td a',
        (links, wanted) => links.find((a) => a.textContent.trim() === wanted)?.getAttribute('href') ?? null,
        name
    );

    expect(href, `a template named "${name}" should be in the list`).toBeTruthy();

    return href;
}

/**
 * The example page every fidelity scenario is built around.
 */
export async function openExamplePage(page) {
    await page.goto('/admin/content/entries/landing/33');
    await expect(page.locator('#main-content')).toBeVisible();
}
