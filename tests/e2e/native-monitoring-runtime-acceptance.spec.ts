import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
} from './helpers';
import { seedNativeMonitoringRuntimeFixtures } from './native-monitoring-runtime-fixtures';

// S&D rebuild C6 retired the Monitoring, Discovery, Network & IT, Integrations
// and Settings pages this journey used to walk; their screens are covered by
// OverviewScreenTest, DeviceAlertsTest, SitesScreensTest, SetupScreensTest,
// DevicePagesTest and the pages' vitest files. What stays here is the privacy
// promise: raw monitoring evidence never reaches the new pages, and another
// Site stays hidden.

function approvedDesktopViewport(projectName: string) {
    if (projectName === 'it-security-desktop-1440') {
        return { width: 1440, height: 900 };
    }

    if (projectName === 'it-security-desktop-1280') {
        return { width: 1280, height: 800 };
    }

    throw new Error(`Unexpected IT/Security desktop project: ${projectName}`);
}

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

/** Nothing on screen shows the given values, and the page fits its viewport. */
async function expectNoneShown(page: Page, values: readonly string[]) {
    for (const value of values) {
        await expect(page.getByText(value)).toHaveCount(0);
    }
    await expectNoPageOverflow(page);
}

/** The page source, Inertia props included, never carries the given values. */
async function expectSourceWithout(
    page: Page,
    route: string,
    values: readonly string[],
) {
    const response = await page.request.get(route);
    expect(response.ok(), `${route} response`).toBe(true);
    const source = await response.text();
    for (const value of values) {
        expect(source, `${route} source`).not.toContain(value);
    }
}

/** Finds a device on Devices by searching for it and returns its page address. */
async function deviceHref(page: Page, name: string): Promise<string> {
    await page.goto(`/security-devices/devices?q=${encodeURIComponent(name)}`);
    await expect(
        page.getByRole('heading', { name: 'Devices', level: 1, exact: true }),
    ).toBeVisible();
    const link = page
        .locator('a[href^="/security-devices/devices/"]', { hasText: name })
        .first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toMatch(/^\/security-devices\/devices\/\d+$/);

    return href as string;
}

test.describe('native monitoring runtime desktop acceptance', () => {
    test('keeps raw monitoring evidence off every new Security & devices page at the approved project viewport', async ({
        page,
    }, testInfo) => {
        test.setTimeout(900_000);
        expect(page.viewportSize()).toEqual(
            approvedDesktopViewport(testInfo.project.name),
        );
        const fixture = seedNativeMonitoringRuntimeFixtures();
        const rawSentinels = [
            fixture.rawSentinel,
            fixture.operations.rawSentinel,
            fixture.network.rawSentinel,
        ];
        const consoleErrors = collectConsoleErrors(page);
        const failedRequests: string[] = [];
        page.on('response', (response) => {
            if (
                response.url().includes('/security-devices') &&
                response.status() >= 400
            ) {
                failedRequests.push(
                    `${response.status()} ${response.request().method()} ${response.url()}`,
                );
            }
        });

        await loginAsStaff(page);

        const pages: Array<[string, string]> = [
            ['/security-devices', 'Security & devices'],
            ['/security-devices?tab=trends', 'Security & devices'],
            ['/security-devices/alerts', 'Alerts'],
            ['/security-devices/alerts?tab=rules', 'Alerts'],
            ['/security-devices/devices?tab=network', 'Devices'],
            ['/security-devices/devices?tab=upkeep', 'Devices'],
            [
                `/security-devices/sites/${fixture.allowedSiteId}`,
                fixture.allowedSiteName,
            ],
            [
                `/security-devices/sites/${fixture.allowedSiteId}?tab=network`,
                fixture.allowedSiteName,
            ],
            [
                `/security-devices/sites/${fixture.allowedSiteId}?tab=internet`,
                fixture.allowedSiteName,
            ],
            ['/security-devices/setup/hubs?tab=networks', 'Setup'],
            ['/security-devices/setup/services?tab=connected', 'Setup'],
            ['/security-devices/setup/history', 'Setup'],
        ];

        for (const [route, heading] of pages) {
            await test.step(route, async () => {
                const response = await page.goto(route);
                expect(response?.status(), `${route} response`).toBe(200);
                await expect(
                    page.getByRole('heading', {
                        name: heading,
                        level: 1,
                        exact: true,
                    }),
                ).toBeVisible();
                await expectNoneShown(page, rawSentinels);
                await expectSourceWithout(page, route, rawSentinels);
            });
        }

        // Each monitored device's own page: the root failure, the device behind
        // the remote collector, and the mapped gateway and switch.
        for (const name of [
            fixture.matchedDeviceName,
            fixture.operations.remoteDeviceName,
            fixture.network.gatewayName,
            fixture.network.switchName,
        ]) {
            await test.step(name, async () => {
                const href = await deviceHref(page, name);
                await page.goto(href);
                await expect(
                    page.getByRole('heading', {
                        name,
                        level: 1,
                        exact: true,
                    }),
                ).toBeVisible();
                await expectNoneShown(page, rawSentinels);
                await expectSourceWithout(page, href, rawSentinels);
            });
        }

        expectNoConsoleErrors(consoleErrors);
        expect(failedRequests).toEqual([]);
    });

    test('denies another Site without leaking identifiers, counts, filters, or response content', async ({
        page,
    }, testInfo) => {
        test.setTimeout(240_000);
        expect(page.viewportSize()).toEqual(
            approvedDesktopViewport(testInfo.project.name),
        );
        const fixture = seedNativeMonitoringRuntimeFixtures();
        const rawSentinels = [
            fixture.rawSentinel,
            fixture.operations.rawSentinel,
            fixture.network.rawSentinel,
        ];
        const hidden = [fixture.hiddenDeviceName, fixture.hiddenSiteName];
        const consoleErrors = collectConsoleErrors(page);
        await loginAs(page, fixture.restrictedEmail, 'password');

        // The person's own Site's device shows; another Site's never does, even searched for.
        await page.goto(
            `/security-devices/devices?q=${encodeURIComponent(fixture.matchedDeviceName)}`,
        );
        await expect(
            page.getByText(fixture.matchedDeviceName).first(),
        ).toBeVisible();
        // A search that matches the hidden device's name (but doesn't echo it whole).
        const hiddenSearch = `/security-devices/devices?q=${encodeURIComponent('Playwright denied')}`;
        await page.goto(hiddenSearch);
        await expect(
            page.getByRole('heading', { name: 'Devices', level: 1, exact: true }),
        ).toBeVisible();
        await expectNoneShown(page, [...hidden, ...rawSentinels]);
        await expectSourceWithout(page, hiddenSearch, [
            ...hidden,
            ...rawSentinels,
        ]);

        // Alerts ignore another Site's filter instead of showing it.
        for (const route of [
            '/security-devices/alerts',
            `/security-devices/alerts?site=${fixture.hiddenSiteId}`,
        ]) {
            const response = await page.goto(route);
            expect(response?.status(), `${route} response`).toBe(200);
            await expect(
                page.getByRole('heading', { name: 'Alerts', level: 1, exact: true }),
            ).toBeVisible();
            await expectNoneShown(page, [...hidden, ...rawSentinels]);
            await expectSourceWithout(page, route, [...hidden, ...rawSentinels]);
        }

        expectNoConsoleErrors(consoleErrors);
        consoleErrors.length = 0;

        // Another Site's Devices filter, Site page and device page are refused.
        for (const route of [
            `/security-devices/devices?site=${fixture.hiddenSiteId}`,
            `/security-devices/sites/${fixture.hiddenSiteId}`,
            `/security-devices/devices/${fixture.hiddenDeviceId}`,
        ]) {
            const denied = await page.goto(route);
            expect([403, 404], `${route} status`).toContain(denied?.status());
            await expect(page.getByText(fixture.hiddenDeviceName)).toHaveCount(0);
            await expect(page.getByText(fixture.hiddenSiteName)).toHaveCount(0);
            await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        }

        expect(
            consoleErrors.filter((error) => !/status of 40[34]/.test(error)),
        ).toEqual([]);
    });
});
