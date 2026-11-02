import { expect, test, type Page } from '@playwright/test';

import { seedFacilitiesWorkspaceReadinessFixtures } from './facilities-workspace-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

// S&D rebuild C6 retired the Facilities & IoT workspace: facility sensors are on
// Devices › Sensors and each sensor's own page, automations on Alerts › Rules &
// automations. Those screens are covered by DevicesMenuPresenterTest,
// SensorTrackerPagesTest, AutomationsTest and the pages' vitest files. What
// stays here is the privacy promise: raw provider evidence never renders.

async function expectNoPageOverflow(page: Page) {
    const layout = await page.evaluate(() => {
        const client = document.documentElement.clientWidth;

        return {
            client,
            scroll: document.documentElement.scrollWidth,
            offenders: [...document.body.querySelectorAll('*')]
                .map((element) => {
                    const rect = element.getBoundingClientRect();
                    return {
                        element: element.tagName.toLowerCase(),
                        classes: element.className.toString().slice(0, 120),
                        left: Math.round(rect.left),
                        right: Math.round(rect.right),
                        text: element.textContent?.trim().slice(0, 80) ?? '',
                    };
                })
                .filter(
                    (element) =>
                        element.right > client + 1 || element.left < -1,
                )
                .slice(0, 8),
        };
    });
    expect(
        layout.scroll,
        `Overflowing elements: ${JSON.stringify(layout.offenders)}`,
    ).toBeLessThanOrEqual(layout.client);
}

async function expectPrivatePage(page: Page, route: string, rawSentinel: string) {
    await expect(page.getByText(rawSentinel)).toHaveCount(0);
    const source = await (await page.request.get(route)).text();
    expect(source, `${route} source`).not.toContain(rawSentinel);
    await expectNoPageOverflow(page);
}

test.describe('Facility sensors on Security & Devices', () => {
    let fixture: ReturnType<typeof seedFacilitiesWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedFacilitiesWorkspaceReadinessFixtures();
    });

    test('native facility evidence stays private and responsive on the new pages', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);

        for (const name of [
            fixture.environmentName,
            fixture.buildingName,
            fixture.utilityName,
            fixture.automationName,
        ]) {
            await test.step(name, async () => {
                const listRoute = `/security-devices/devices?tab=sensors&q=${encodeURIComponent(name)}`;
                await page.goto(listRoute);
                await expect(
                    page.getByRole('heading', {
                        name: 'Devices',
                        level: 1,
                        exact: true,
                    }),
                ).toBeVisible();
                const link = page
                    .locator('a[href^="/security-devices/devices/"]', {
                        hasText: name,
                    })
                    .first();
                await expect(link).toBeVisible();
                await expectPrivatePage(page, listRoute, fixture.rawSentinel);

                const href = await link.getAttribute('href');
                expect(href).toMatch(/^\/security-devices\/devices\/\d+$/);
                await page.goto(href as string);
                await expect(
                    page.getByRole('heading', {
                        name,
                        level: 1,
                        exact: true,
                    }),
                ).toBeVisible();
                await expectPrivatePage(
                    page,
                    href as string,
                    fixture.rawSentinel,
                );
            });
        }

        for (const [route, heading] of [
            ['/security-devices/alerts', 'Alerts'],
            ['/security-devices/alerts?tab=rules', 'Alerts'],
            ['/security-devices/setup/services?tab=connected', 'Setup'],
            ['/security-devices/setup/history', 'Setup'],
        ] as const) {
            await test.step(route, async () => {
                await page.goto(route);
                await expect(
                    page.getByRole('heading', {
                        name: heading,
                        level: 1,
                        exact: true,
                    }),
                ).toBeVisible();
                await expectPrivatePage(page, route, fixture.rawSentinel);
            });
        }

        expectNoConsoleErrors(errors);
    });
});
