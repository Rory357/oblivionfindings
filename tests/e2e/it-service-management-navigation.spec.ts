import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('IT & Support service management navigation', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsStaff(page);
    });

    test('keeps setup and service-delivery workspaces understandable on desktop', async ({
        page,
    }) => {
        // This journey includes sign-in, setup panels, two wizards and a final
        // service-desk navigation. Keep individual assertions bounded at 10s.
        test.setTimeout(60_000);
        const errors = collectConsoleErrors(page);

        await page.goto('/it/setup');
        await expect(
            page.getByRole('heading', {
                name: 'IT setup',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'New team' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'New queue' }),
        ).toHaveCount(0);
        await page.getByRole('tab', { name: 'Queues' }).click();
        await expect(
            page.getByRole('button', { name: 'New queue' }),
        ).toBeVisible();
        await page.getByRole('tab', { name: 'Services' }).click();
        await expect(
            page.getByRole('button', { name: 'New service' }),
        ).toBeVisible();
        await page.getByRole('tab', { name: 'Workflows', exact: true }).click();
        await expect(
            page.getByRole('heading', {
                name: 'IT setup',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Lifecycle workflow templates',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText('Standard joiner')).toBeVisible();
        await expect(
            page.getByText(
                'Verify and grant approved healthcare system access',
            ),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'New template' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'New template' }).click();
        const templateDialog = page.getByRole('dialog');
        await expect(
            templateDialog.getByRole('heading', {
                name: 'New lifecycle template',
            }),
        ).toBeVisible();
        await templateDialog
            .getByRole('button', {
                name: /Workflow steps.*Ownership, dependencies and evidence/,
            })
            .click();
        await expect(
            templateDialog.getByText('Minimum employee details shown'),
        ).toBeVisible();
        await expect(
            templateDialog.getByText('Approval required'),
        ).toBeVisible();
        await expect(
            templateDialog.getByText('Evidence required'),
        ).toBeVisible();
        await templateDialog
            .getByRole('button', { name: 'Close', exact: true })
            .click();
        await expect(templateDialog).toBeHidden();
        await page.getByRole('tab', { name: 'API', exact: true }).click();
        await expect(
            page.getByRole('heading', { name: 'API identities', level: 2 }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'New API identity' })
            .first()
            .click();
        await expect(
            page.getByRole('dialog').getByRole('heading', {
                name: 'New API identity',
            }),
        ).toBeVisible();
        await expect(page.getByLabel('Identity name')).toBeVisible();
        await expect(page.getByLabel('Execution account')).toBeVisible();
        await page
            .getByRole('dialog')
            .getByRole('button', {
                name: /Scope and fields.*Sites and delegated fields/,
            })
            .click();
        await expect(
            page.getByText(
                'Require a timestamped HMAC signature on every request',
            ),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await page
            .getByRole('tab', { name: 'Operations', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Configuration audit',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Email delivery', level: 2 }),
        ).toBeVisible();
        await expect(page.getByText('SLA watchdog')).toBeVisible();
        await expect(
            page.getByText(/does not create a second scheduler/),
        ).toBeVisible();

        const sidebar = page.getByRole('group', {
            name: 'IT & Support navigation',
        });

        await expect(sidebar).toBeVisible();
        for (const destination of [
            'Service desk',
            'Provisioning',
            'Work planning',
            'Problems',
            'Changes',
            'Major incidents',
            'Reports',
            'Setup',
        ]) {
            await expect(
                sidebar.getByRole('link', { name: destination, exact: true }),
            ).toBeVisible();
        }
        await expect(
            sidebar.getByRole('link', {
                name: 'Setup',
                exact: true,
            }),
        ).toHaveAttribute('aria-current', 'page');
        await sidebar
            .getByRole('link', { name: 'Service desk', exact: true })
            .click();
        await expect(page).toHaveURL(/\/it(?:\?.*)?$/);
        await page.getByRole('tab', { name: /Service catalogue/ }).click();

        await expect(page).toHaveURL(/\/it\?.*tab=catalog/);
        await expect(
            page.getByRole('heading', { name: 'Service catalogue', level: 2 }),
        ).toBeVisible();
        expectNoConsoleErrors(errors);
    });
});
