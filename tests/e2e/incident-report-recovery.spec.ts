import { expect, test, type Page } from '@playwright/test';

import {
    loginAsFixture,
    seedIncidentHandoverFixtures,
    type IncidentHandoverManifest,
} from './incident-handover-helpers';

async function openScopedIncidentReport(
    page: Page,
    manifest: IncidentHandoverManifest,
) {
    await loginAsFixture(page, manifest.users.worker);
    await page.goto('/incidents?report=incident');
    await expect(
        page.getByRole('heading', { name: 'Type & people' }),
    ).toBeVisible();

    await page.getByRole('button', { name: /^Fall$/ }).click();
    await page.getByRole('combobox', { name: 'Select client' }).click();
    await page
        .getByRole('option', { name: manifest.client.name, exact: true })
        .click();
}

test.describe('incident report recovery', () => {
    test.describe.configure({ timeout: 120_000 });

    test('restores the current step and private report fields after reload, then saves before close', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        await openScopedIncidentReport(page, manifest);

        await expect(page.getByText(/Saved securely at/)).toBeVisible();
        await page.getByRole('button', { name: 'Next' }).click();
        await page
            .getByRole('textbox', { name: 'Description' })
            .fill('Aroha slipped beside the dining table during lunch.');
        await expect(page.getByText(/Saved securely at/)).toBeVisible();

        const pointer = await page.evaluate(() => {
            const key = Object.keys(window.localStorage).find((candidate) =>
                candidate.startsWith('oblivion:incident-report-draft:v1:'),
            );

            return key ? window.localStorage.getItem(key) : null;
        });
        expect(pointer).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
        );
        expect(pointer).not.toContain('Aroha');

        await page.reload();
        await expect(
            page.getByRole('alertdialog', {
                name: 'Resume your incident report?',
            }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Continue draft' }).click();
        await expect(
            page.getByRole('heading', { name: 'What happened' }),
        ).toBeVisible();
        await expect(
            page.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Aroha slipped beside the dining table during lunch.');

        await page.getByRole('button', { name: 'Close' }).click();
        await expect(
            page.getByRole('heading', { name: 'Keep this incident report?' }),
        ).toBeVisible();
        await expect(page.getByText(/last secure save was at/i)).toBeVisible();
        await page.getByRole('button', { name: 'Save and close' }).click();
        await expect(
            page.getByRole('heading', { name: 'Report an incident' }),
        ).toHaveCount(0);
    });

    test('keeps entered details through a network failure and retries when connectivity returns', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        await page.route('**/incidents/drafts/*', async (route) => {
            if (route.request().method() === 'PUT') {
                await route.abort('internetdisconnected');
                return;
            }

            await route.continue();
        });
        await openScopedIncidentReport(page, manifest);

        await expect(page.getByText('Not saved yet')).toBeVisible();
        await expect(
            page.getByText(
                'Not saved yet. Keep this report open, reconnect, then retry.',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Next' }).click();
        await page
            .getByRole('textbox', { name: 'Description' })
            .fill('This detail must survive a failed network save.');

        await page.getByRole('button', { name: 'Close' }).click();
        await expect(
            page.getByText(
                'Not saved yet. Keep this report open, reconnect, then retry.',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Keep editing' }).click();
        await expect(
            page.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('This detail must survive a failed network save.');

        await page.unroute('**/incidents/drafts/*');
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.getByText(/Saved securely at/)).toBeVisible();
    });

    test('preserves the report and gives sign-in guidance when the session ends', async ({
        page,
    }) => {
        const manifest = seedIncidentHandoverFixtures();
        await page.route('**/incidents/drafts/*', async (route) => {
            if (route.request().method() === 'PUT') {
                await route.fulfill({
                    status: 419,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Page Expired' }),
                });
                return;
            }

            await route.continue();
        });
        await openScopedIncidentReport(page, manifest);

        await expect(
            page.getByText(
                'Your session ended. Sign in again, then retry this draft before closing.',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Next' }).click();
        await page
            .getByRole('textbox', { name: 'Description' })
            .fill('Session expiry must not clear this report.');
        await page.getByRole('button', { name: 'Close' }).click();
        await expect(
            page.getByText(
                'Your session ended. Sign in again, then retry this draft before closing.',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Keep editing' }).click();
        await expect(
            page.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Session expiry must not clear this report.');
    });
});
