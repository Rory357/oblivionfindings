import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    seedSecurityDevicesOperationsReadinessFixtures,
} from './helpers';

const workspaceTabs = {
    '/security-devices/network-it': [
        'overview',
        'map',
        'devices',
        'interfaces',
        'services',
        'traffic-capacity',
        'configuration-firmware',
    ],
    '/security-devices/security': [
        'overview',
        'cctv',
        'alarms',
        'access-control',
        'events',
    ],
    '/security-devices/healthcare': [
        'overview',
        'client-devices',
        'shared-site-devices',
        'data-flow',
        'calibration-maintenance',
    ],
    '/security-devices/tracking': [
        'overview',
        'personal-safety',
        'fleet',
        'assets',
        'geofences',
        'history',
    ],
    '/security-devices/facilities-iot': [
        'overview',
        'environment',
        'building-systems',
        'utilities',
        'automations',
        'history',
    ],
    '/security-devices/monitoring': [
        'overview',
        'findings',
        'coverage',
        'dependencies',
        'trends',
        'collection',
    ],
    '/security-devices/maintenance': [
        'overview',
        'due',
        'planned',
        'in-progress',
        'completed',
        'calibration',
        'firmware-configuration',
    ],
    '/security-devices/discovery': [
        'overview',
        'scopes',
        'runs',
        'candidates',
        'collectors',
        'paths',
        'limitations',
    ],
} as const;

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

function tabRoutes(): string[] {
    return Object.entries(workspaceTabs).flatMap(([base, tabs]) =>
        tabs.map((tab) => `${base}?tab=${tab}`),
    );
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

    test.beforeEach((_fixtures, testInfo) => {
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
            `/security-devices/devices/${fixture.deviceId}/edit`,
            '/security-devices/alerts-events',
            '/security-devices/maintenance-health',
            '/security-devices/device-groups',
            '/security-devices/device-groups/create',
            '/security-devices/reports',
            '/security-devices/integrations',
            '/security-devices/integrations/unifi',
            '/security-devices/integrations/milesight',
            '/security-devices/integrations/queclink',
            '/security-devices/settings',
        ]);
    });

    test('workspace tabs have no serious or critical axe violations', async ({
        page,
    }) => {
        await expectAccessibleRoutes(page, tabRoutes());
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
