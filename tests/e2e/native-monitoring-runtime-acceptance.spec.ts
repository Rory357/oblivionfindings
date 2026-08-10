import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAs,
    loginAsStaff,
} from './helpers';
import {
    markCollectorOrderedReturn,
    markCollectorOutage,
    seedNativeMonitoringRuntimeFixtures,
} from './native-monitoring-runtime-fixtures';

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

async function selectPageTab(page: Page, label: string, expectedValue: string) {
    await page.getByRole('tab', { name: label, exact: true }).click();
    await expect
        .poll(() => new URL(page.url()).searchParams.get('tab'), {
            timeout: 30_000,
        })
        .toBe(expectedValue);
}

async function expectSafePage(page: Page, rawSentinels: string[]) {
    for (const rawSentinel of rawSentinels) {
        await expect(page.getByText(rawSentinel)).toHaveCount(0);
    }
    await expect(
        page.getByRole('button', {
            name: /^(scan|discover|adopt|execute|replay|restart|wipe|push|apply|upgrade|run command)$/i,
        }),
    ).toHaveCount(0);
    await expectNoPageOverflow(page);
}

test.describe('native monitoring runtime desktop acceptance', () => {
    test('keeps the end-to-end operational story clear at the approved project viewport', async ({
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

        await page.goto('/security-devices/monitoring');
        await expect(
            page.getByRole('heading', { name: 'Monitoring', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: fixture.rootMonitorName }).first(),
        ).toBeVisible();
        await expect(page.getByText(fixture.tlsMonitorName)).toBeVisible();
        await expect(
            page
                .getByRole('link', {
                    name: new RegExp(
                        `Review Control Room correlation ${fixture.controlRoomReference}`,
                    ),
                })
                .first(),
        ).toBeVisible();
        await expect(
            page
                .getByRole('link', {
                    name: new RegExp(
                        `IT incident ${fixture.ticketReference}.*technician closure pending`,
                    ),
                })
                .first(),
        ).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await selectPageTab(page, 'Dependencies', 'dependencies');
        await expect(
            page.getByText(/3 suppressed symptoms remain inspectable/),
        ).toBeVisible();
        for (const symptomName of fixture.symptomNames) {
            await expect(page.getByText(symptomName)).toBeVisible();
        }
        await expect(page.getByText('96% confidence').first()).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await selectPageTab(page, 'Trends', 'trends');
        await expect(
            page.getByText('External history and retention', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText(/p95 86\.2/)).toBeVisible();
        await expect(page.getByText(/Projection Not Configured/)).toBeVisible();
        await expectSafePage(page, rawSentinels);

        markCollectorOutage(fixture.collectorUuid);
        await page.goto('/security-devices/discovery');
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expect(
            page.getByText(/7 buffered · 1 sequence gaps/),
        ).toBeVisible();
        const affectedCollector = page.getByRole('article', {
            name: `Collector ${fixture.collectorName}`,
        });
        await expect(
            affectedCollector.getByText(/results are uncertain/),
        ).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await selectPageTab(page, 'Discovery scopes', 'scopes');
        await expect(page.getByText(fixture.discoveryScopeName)).toBeVisible();
        const discoveryScope = page.getByRole('article', {
            name: `Discovery scope ${fixture.discoveryScopeName}`,
        });
        await expect(
            discoveryScope.getByText(/1 network ranges · 1 exclusions/),
        ).toBeVisible();

        await selectPageTab(page, 'Runs', 'runs');
        await expect(
            page.getByText('3 found · 1 matched · 1 proposed · 1 unresolved'),
        ).toBeVisible();

        await selectPageTab(page, 'Candidates', 'candidates');
        await expect(
            page.getByRole('link', { name: fixture.matchedDeviceName }),
        ).toBeVisible();
        await expect(page.getByText('Matched', { exact: true })).toBeVisible();
        await expect(page.getByText('Proposed', { exact: true })).toBeVisible();
        await expect(
            page.getByText('Ambiguous', { exact: true }),
        ).toBeVisible();
        await expectSafePage(page, rawSentinels);

        markCollectorOrderedReturn(fixture.collectorUuid);
        await page.goto('/security-devices/discovery?tab=collectors');
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expect(
            page.getByText(/7 buffered · 1 sequence gaps/),
        ).toHaveCount(0);
        await expect(page.getByText('Available').first()).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await page.goto('/security-devices/network-it');
        const networkTabs = page.getByRole('navigation', {
            name: 'Network & IT workspace tabs',
        });
        await networkTabs
            .getByRole('link', { name: 'Map', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Known topology evidence',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByLabel('Topology visual overview')).toBeVisible();
        await expect(
            page.getByLabel('Keyboard-readable topology relationships'),
        ).toBeVisible();
        await expect(page.getByText(/97% confidence/)).toBeVisible();
        await expect(page.getByText(/2 changes/)).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await networkTabs
            .getByRole('link', {
                name: 'Configuration & firmware',
                exact: true,
            })
            .click();
        await expect(page.getByText('Latest governed snapshot')).toBeVisible();
        await expect(page.getByText(/Approved read only/i)).toBeVisible();
        await expect(page.getByText(/2,048 bytes|2048 bytes/)).toBeVisible();
        await expectSafePage(page, rawSentinels);

        await page.goto('/security-devices/integrations');
        const unifiRuntime = page.getByLabel('UniFi runtime capabilities');
        await expect(unifiRuntime).toContainText('Runtime contract v1.5');
        await expect(unifiRuntime).toContainText('device sync');
        await expect(unifiRuntime).toContainText('Page limit');
        await expect(unifiRuntime).toContainText('backfill limit');
        await expect(unifiRuntime).toContainText('1 cursor scopes');
        await expect(unifiRuntime).toContainText('1 runtime exceptions');
        await expectSafePage(page, rawSentinels);

        await page.goto('/security-devices/settings');
        await page.getByRole('tab', { name: 'Retention' }).click();
        await expect(page.getByText(fixture.retentionPolicyName)).toBeVisible();
        await expect(
            page.getByText('Runtime workers, queues and dead letters', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText(/1 dead letters/)).toBeVisible();
        await expect(page.getByText(/read-only and append-only/)).toBeVisible();
        await expectSafePage(page, rawSentinels);

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
        const consoleErrors = collectConsoleErrors(page);
        await loginAs(page, fixture.restrictedEmail, 'password');

        await page.goto('/security-devices/monitoring');
        await expect(
            page.getByRole('link', { name: fixture.rootMonitorName }).first(),
        ).toBeVisible();
        await expect(page.getByText(fixture.hiddenDeviceName)).toHaveCount(0);
        await expect(page.getByText(fixture.hiddenSiteName)).toHaveCount(0);
        await expectSafePage(page, rawSentinels);

        await page.goto(
            `/security-devices/monitoring?site_id=${fixture.hiddenSiteId}`,
        );
        await expect(page.getByText(fixture.hiddenDeviceName)).toHaveCount(0);
        await expect(page.getByText(fixture.hiddenSiteName)).toHaveCount(0);
        await expectSafePage(page, rawSentinels);

        expectNoConsoleErrors(consoleErrors);
        consoleErrors.length = 0;

        const denied = await page.goto(
            `/security-devices/devices/${fixture.hiddenDeviceId}`,
        );
        const deniedStatus = denied?.status();
        expect([403, 404]).toContain(deniedStatus);
        await expect(page.getByText(fixture.hiddenDeviceName)).toHaveCount(0);
        await expect(page.getByText(fixture.hiddenSiteName)).toHaveCount(0);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);

        expect(
            consoleErrors.filter(
                (error) => !error.includes(`status of ${deniedStatus}`),
            ),
        ).toEqual([]);
        expect(consoleErrors.length).toBeLessThanOrEqual(1);
    });
});
