import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('Security & Devices specialist workspace shell', () => {
    test('local tabs preserve context and unavailable capabilities remain honest', async ({
        page,
    }) => {
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        await page.goto(
            '/security-devices/cctv?search=camera&device_id=44&status=offline',
        );

        await expect(page).toHaveURL(/\/security-devices\/security\?/);
        await expect(page).toHaveURL(/tab=cctv/);
        await expect(page).toHaveURL(/device_id=44/);
        await expect(page).toHaveURL(/status=offline/);
        await expect(
            page.getByRole('heading', { name: 'Security', level: 1 }),
        ).toBeVisible();

        const securityTabs = page.getByRole('navigation', {
            name: 'Security workspace tabs',
        });
        await expect(securityTabs).toBeVisible();
        await securityTabs
            .getByRole('link', { name: 'Access Control', exact: true })
            .click();

        await expect(page).toHaveURL(/tab=access-control/);
        await expect(page).toHaveURL(/device_id=44/);
        await expect(page).toHaveURL(/status=offline/);
        await expect(
            securityTabs.getByRole('link', {
                name: 'Access Control',
                exact: true,
            }),
        ).toHaveAttribute('aria-current', 'page');

        await page.goto('/security-devices/network-it?tab=traffic-capacity');
        await expect(
            page.getByRole('heading', { name: 'Network & IT', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Traffic & capacity',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByText(
                'Retained traffic and utilisation evidence is not configured for this workspace yet.',
            ),
        ).toBeVisible();
        await expect(
            page.getByText(
                'No metrics or controls are shown until a canonical data source is available.',
            ),
        ).toBeVisible();

        const widths = await page.evaluate(() => ({
            client: document.documentElement.clientWidth,
            scroll: document.documentElement.scrollWidth,
        }));
        expect(widths.scroll).toBeLessThanOrEqual(widths.client);
        expectNoConsoleErrors(errors);
    });
});
