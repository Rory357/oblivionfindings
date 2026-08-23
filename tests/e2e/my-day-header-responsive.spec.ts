import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsFrontlineDemoWorker,
} from './helpers';

const viewports = [
    { width: 390, height: 844 },
    { width: 768, height: 1024 },
    { width: 1024, height: 768 },
    { width: 1280, height: 720 },
    { width: 1440, height: 900 },
] as const;

test.describe('My Day responsive staff header', () => {
    test('keeps every safety action reachable without horizontal overflow', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);
        await loginAsFrontlineDemoWorker(page);

        for (const viewport of viewports) {
            await page.setViewportSize(viewport);
            await page.goto('/my-day');
            await page.waitForLoadState('domcontentloaded');

            const staffHeader = page
                .locator('header')
                .filter({
                    has: page.locator('[data-staff-header-actions]'),
                })
                .first();
            const actions = staffHeader.locator('[data-staff-header-actions]');
            const title = staffHeader.getByRole('heading', {
                name: /^Today$/i,
            });
            const date = staffHeader.locator('p').first();
            const titleButton = staffHeader.getByRole('button', {
                name: /^Today\b/,
            });
            const report = actions.getByRole('button', {
                name: 'Report incident',
            });
            const refresh = actions.getByRole('button', {
                name: 'Refresh now',
            });
            const notifications = actions.getByRole('link', {
                name: /^Notifications \(/,
            });

            await expect(title).toBeVisible();
            await expect(date).toBeVisible();
            await expect(date).toHaveText(
                /^[A-Z][a-z]+, \d{1,2} [A-Z][a-z]+ \d{4}$/,
            );
            await expect(report).toBeVisible();
            await expect(refresh).toBeVisible();
            await expect(notifications).toBeVisible();
            expect(
                await title.evaluate(
                    (element) => element.scrollWidth <= element.clientWidth,
                ),
            ).toBe(true);
            expect(
                await date.evaluate(
                    (element) => element.scrollWidth <= element.clientWidth,
                ),
            ).toBe(true);

            const layout = await page.evaluate(() => ({
                clientWidth: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
            }));
            expect(
                layout.scrollWidth,
                `${viewport.width}x${viewport.height} must not scroll sideways`,
            ).toBeLessThanOrEqual(layout.clientWidth);

            for (const control of [
                title,
                date,
                report,
                refresh,
                notifications,
            ]) {
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(
                    layout.clientWidth,
                );
            }

            for (const control of [
                titleButton,
                report,
                refresh,
                notifications,
            ]) {
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.width).toBeGreaterThanOrEqual(44);
                expect(box!.height).toBeGreaterThanOrEqual(44);
            }

            if (viewport.width >= 768) {
                const clients = staffHeader.getByRole('link', {
                    name: 'Clients',
                });
                const searchbox = staffHeader.getByRole('searchbox');

                await expect(clients).toBeVisible();
                await expect(searchbox).toBeVisible();

                for (const control of [clients, searchbox]) {
                    const box = await control.boundingBox();
                    expect(box).not.toBeNull();
                    expect(box!.width).toBeGreaterThanOrEqual(44);
                    expect(box!.height).toBeGreaterThanOrEqual(44);
                }
            }

            if (viewport.width === 390) {
                await report.focus();
                await expect(report).toBeFocused();
                await page.keyboard.press('Tab');
                await expect(refresh).toBeFocused();
                expect(
                    await refresh.evaluate(
                        (element) =>
                            getComputedStyle(element).outlineStyle !== 'none',
                    ),
                ).toBe(true);
                await page.keyboard.press('Tab');
                await expect(notifications).toBeFocused();
                expect(
                    await notifications.evaluate(
                        (element) =>
                            getComputedStyle(element).outlineStyle !== 'none',
                    ),
                ).toBe(true);
            }
        }

        expectNoConsoleErrors(consoleErrors);
    });
});
