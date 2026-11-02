import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

// S&D rebuild C6: the old workspaces, Monitoring, Maintenance and Discovery
// pages redirect into these pages, so their tabs are checked here instead.
const pageTabs = {
    '/security-devices': ['overview', 'trends', 'reports'],
    '/security-devices/devices': [
        'all',
        'network',
        'cameras',
        'alarms_doors',
        'sensors',
        'trackers',
        'computers',
        'other',
        'upkeep',
    ],
    '/security-devices/sites': ['all', 'attention', 'not_set_up'],
    '/security-devices/alerts': ['open', 'snoozed', 'done', 'rules'],
    '/security-devices/access': [
        'doors',
        'people',
        'visitors',
        'schedules',
        'activity',
        'clockins',
    ],
} as const;

const siteTabs = ['overview', 'network', 'internet', 'rooms', 'modes'] as const;

const setupSections = [
    '/security-devices/setup',
    '/security-devices/setup/hubs?tab=networks',
    '/security-devices/setup/hubs?tab=broker',
    '/security-devices/setup/services?tab=connected',
    '/security-devices/setup/services?tab=logins',
    '/security-devices/setup/services?tab=supported',
    '/security-devices/setup/alerts?tab=rules',
    '/security-devices/setup/alerts?tab=checks',
    '/security-devices/setup/access?tab=unifi',
    '/security-devices/setup/access?tab=clockins',
    '/security-devices/setup/computers?tab=remote',
    '/security-devices/setup/history',
] as const;

const deviceSections = [
    'health',
    'monitors',
    'topology',
    'interfaces-sensors',
    'configuration',
    'management',
    'assignments',
    'tickets',
    'events',
    'maintenance',
    'documents',
    'audit',
] as const;

type CachedAsset = {
    body: Buffer;
    headers: Record<string, string>;
    status: number;
};

const productionAssetCache = new Map<string, CachedAsset>();

async function cacheProductionAssets(page: Page) {
    await page.route('**/build/assets/**', async (route) => {
        if (route.request().method() !== 'GET') {
            await route.continue();

            return;
        }

        const url = route.request().url();
        const cached = productionAssetCache.get(url);

        if (cached) {
            await route.fulfill(cached);

            return;
        }

        const response = await route.fetch();
        const asset = {
            body: await response.body(),
            headers: response.headers(),
            status: response.status(),
        };

        productionAssetCache.set(url, asset);
        await route.fulfill(asset);
    });
}

async function blockingAxeViolations(page: Page, route: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    return results.violations
        .filter((violation) =>
            ['serious', 'critical'].includes(violation.impact ?? ''),
        )
        .flatMap((violation) =>
            violation.nodes.map(
                (node) =>
                    `${route} [${violation.impact}] ${violation.id}: ${node.target.join(' > ')} :: ${node.html}`,
            ),
        );
}

function tabRoutes(siteId: number): string[] {
    return [
        ...Object.entries(pageTabs).flatMap(([base, tabs]) =>
            tabs.map((tab) => `${base}?tab=${tab}`),
        ),
        '/security-devices/devices?tab=upkeep&upkeep=updates',
        ...siteTabs.map(
            (tab) => `/security-devices/sites/${siteId}?tab=${tab}`,
        ),
        ...setupSections,
    ];
}

async function expectAccessibleRoutes(page: Page, routes: string[]) {
    await cacheProductionAssets(page);

    const consoleErrors = collectConsoleErrors(page);
    const failedRequests: string[] = [];
    const accessibilityFailures: string[] = [];

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

    for (const route of routes) {
        await test.step(route, async () => {
            const response = await page.goto(route);
            expect(response?.status(), `${route} response`).toBe(200);
            await expect(page.locator('#main-content')).toBeVisible();
            accessibilityFailures.push(
                ...(await blockingAxeViolations(page, route)),
            );
        });
    }

    expect(
        accessibilityFailures,
        `Blocking axe findings:\n${accessibilityFailures.join('\n')}`,
    ).toEqual([]);
    expectNoConsoleErrors(consoleErrors);
    expect(failedRequests).toEqual([]);
}

test.describe('Security & Devices accessibility', () => {
    let fixture: ReturnType<
        typeof seedSecurityDevicesOperationsReadinessFixtures
    >;

    test.beforeAll(() => {
        fixture = seedSecurityDevicesOperationsReadinessFixtures();
    });

    test.beforeEach(({ browserName: _browserName }, testInfo) => {
        test.skip(!testInfo.project.name.includes('desktop'), 'Desktop only.');
        test.setTimeout(600_000);
    });

    test('supporting routes have no serious or critical axe violations', async ({
        page,
    }) => {
        await expectAccessibleRoutes(page, [
            '/security-devices',
            '/security-devices/sites',
            `/security-devices/sites/${fixture.siteId}`,
            '/security-devices/devices',
            '/security-devices/devices/create',
            `/security-devices/devices/${fixture.deviceId}`,
            `/security-devices/devices/${fixture.deviceId}/edit`,
            '/security-devices/alerts',
            '/security-devices/access',
            '/security-devices/reports',
            '/security-devices/setup',
            '/security-devices/integrations/unifi',
            '/security-devices/integrations/milesight',
            '/security-devices/integrations/queclink',
        ]);
    });

    test('page tabs and Setup sections have no serious or critical axe violations', async ({
        page,
    }) => {
        await expectAccessibleRoutes(page, tabRoutes(fixture.siteId));
    });

    test('device profile sections have no serious or critical axe violations', async ({
        page,
    }) => {
        await expectAccessibleRoutes(
            page,
            deviceSections.map(
                (section) =>
                    `/security-devices/devices/${fixture.deviceId}?section=${section}`,
            ),
        );
    });
});
