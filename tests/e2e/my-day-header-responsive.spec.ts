import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsMedsDemoWorker,
} from './helpers';

const viewports = [
    { width: 320, height: 844 },
    { width: 390, height: 844 },
    { width: 768, height: 1024 },
    { width: 1024, height: 768 },
    { width: 1280, height: 720 },
    { width: 1440, height: 900 },
] as const;

test.describe('My Day responsive safety controls', () => {
    for (const viewport of viewports) {
        test(`keeps safety actions and care access reachable at ${viewport.width}px`, async ({
            page,
        }) => {
            const consoleErrors = collectConsoleErrors(page);
            // The medication fixture has one assigned person and a covering shift.
            await loginAsMedsDemoWorker(page);

            await page.setViewportSize(viewport);
            await page.goto('/my-day');
            const title = page.getByRole('heading', {
                name: 'My Day',
                exact: true,
            });
            await expect(title).toBeVisible();
            const header = page
                .locator('header.eh-header')
                .filter({ has: title });
            const date = header.locator('p').first();
            const report = page.getByRole('button', {
                name: 'Report incident',
                exact: true,
            });
            const refresh = page.getByRole('button', {
                name: 'Refresh',
                exact: true,
            });
            const notifications = page.getByRole('button', {
                name: 'Notifications',
                exact: true,
            });
            const search = page.getByRole('button', {
                name: 'Search or jump to a page',
                exact: true,
            });
            const careRecord = page.getByRole('link', {
                name: /^Open care record for /,
            });

            await expect(date).toContainText(
                /^[A-Z][a-z]+, \d{1,2} [A-Z][a-z]+ \d{4}/,
            );
            await expect(careRecord).toHaveCount(1);
            await expect(careRecord).toHaveAttribute(
                'href',
                /^\/clients\/\d+$/,
            );
            await expect(
                page.getByRole('searchbox', {
                    name: "Find in today's work…",
                    exact: true,
                }),
            ).toBeVisible();

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
                search,
                careRecord,
            ]) {
                await expect(control).toBeVisible();
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(
                    layout.clientWidth + 1,
                );
            }

            // Care and recording controls remain touch sized on every layout.
            // The shared app bar becomes touch sized on phones.
            const touchControls = [report, refresh, careRecord];
            if (viewport.width < 768) touchControls.push(notifications, search);
            for (const control of touchControls) {
                const box = await control.boundingBox();
                expect(box!.width).toBeGreaterThanOrEqual(44);
                expect(box!.height).toBeGreaterThanOrEqual(44);
            }

            if (viewport.width < 768) {
                const ask = page.getByRole('button', {
                    name: 'Ask about a client',
                    exact: true,
                });
                await search.focus();
                await page.keyboard.press('Tab');
                await expect(ask).toBeFocused();
                await page.keyboard.press('Tab');
                await expect(notifications).toBeFocused();
                expect(
                    await notifications.evaluate((element) => {
                        const style = getComputedStyle(element);
                        return (
                            style.outlineStyle !== 'none' ||
                            style.boxShadow !== 'none'
                        );
                    }),
                ).toBe(true);
                await careRecord.focus();
                await page.keyboard.press('Tab');
                await expect(
                    page.getByRole('button', { name: 'All work', exact: true }),
                ).toBeFocused();
            }

            await notifications.click();
            const notificationMenu = page.getByRole('menu', {
                name: 'Notifications',
                exact: true,
            });
            await expect(notificationMenu).toBeVisible();
            const menuBox = await notificationMenu.boundingBox();
            expect(menuBox).not.toBeNull();
            expect(menuBox!.x).toBeGreaterThanOrEqual(0);
            expect(menuBox!.x + menuBox!.width).toBeLessThanOrEqual(
                viewport.width,
            );
            if (viewport.width < 768) {
                for (const action of [
                    notificationMenu.getByRole('button', {
                        name: 'Mark all notifications read',
                        exact: true,
                    }),
                    notificationMenu.getByRole('link', {
                        name: 'View All Notifications',
                        exact: true,
                    }),
                ]) {
                    const box = await action.boundingBox();
                    expect(box).not.toBeNull();
                    expect(box!.height).toBeGreaterThanOrEqual(44);
                }
            }
            await page.keyboard.press('Escape');
            await expect(notificationMenu).toBeHidden();
            await expect(notifications).toBeFocused();

            expectNoConsoleErrors(consoleErrors);
        });
    }
});
