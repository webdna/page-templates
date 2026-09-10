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
 * The example page every fidelity scenario is built around.
 */
export async function openExamplePage(page) {
    await page.goto('/admin/content/entries/landing/33');
    await expect(page.locator('#main-content')).toBeVisible();
}
