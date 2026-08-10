import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedOperationsWorkspaceFixtures } from './operations-workspaces-fixtures';

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

async function selectMonitoringTab(
    page: Page,
    name: string,
    value: string,
): Promise<void> {
    await Promise.all([
        page.waitForURL(
            (url) =>
                url.pathname === '/security-devices/monitoring' &&
                url.searchParams.get('tab') === value,
        ),
        page.getByRole('tab', { name }).click(),
    ]);
}

test.describe('Monitoring, maintenance, and collector operations', () => {
    let fixture: ReturnType<typeof seedOperationsWorkspaceFixtures>;

    test.beforeAll(() => {
        fixture = seedOperationsWorkspaceFixtures();
    });

    test('operations stay understandable, private, responsive, and honestly scoped', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/monitoring');
        await expect(
            page.getByRole('heading', { name: 'Monitoring', level: 1 }),
        ).toBeVisible();
        await expect(page.getByText(fixture.directMonitorName)).toBeVisible();
        await expect(page.getByText(fixture.remoteMonitorName)).toBeVisible();
        const directMonitorCard = page
            .locator('div.rounded-xl.border.p-4')
            .filter({ hasText: fixture.directMonitorName });
        await expect(directMonitorCard).toHaveCount(1);
        await expect(
            directMonitorCard.getByText('Direct from main application'),
        ).toBeVisible();
        await expect(
            page.getByText('Collection Unavailable').last(),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await selectMonitoringTab(page, 'Findings', 'findings');
        await expect(
            page.getByRole('heading', {
                name: 'Collection-path findings',
            }),
        ).toBeVisible();
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expect(page.getByText(/affected devices/).first()).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await selectMonitoringTab(page, 'Coverage', 'coverage');
        await expect(page.getByText('Known coverage limits')).toBeVisible();
        await expect(page.getByText('Evidence Backed').first()).toBeVisible();
        await expectNoPageOverflow(page);

        await selectMonitoringTab(page, 'Dependencies', 'dependencies');
        await expect(
            page.getByText('Canonical dependency model active'),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await selectMonitoringTab(page, 'Trends', 'trends');
        await expect(page.getByText(fixture.directMonitorName)).toBeVisible();
        await expect(page.getByText(/2 samples/)).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await selectMonitoringTab(page, 'Data collection', 'collection');
        await expect(page.getByText('Main application').first()).toBeVisible();
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await page.goto('/security-devices/maintenance');
        await expect(
            page.getByRole('heading', { name: 'Maintenance', level: 1 }),
        ).toBeVisible();
        await expect(page.getByText(fixture.overdueWork)).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await page.getByRole('tab', { name: 'Due & overdue' }).click();
        await expect(page.getByText(fixture.overdueWork)).toBeVisible();
        await expectNoPageOverflow(page);

        await page.getByRole('tab', { name: 'Calibration' }).click();
        await expect(page.getByText(fixture.calibrationWork)).toBeVisible();
        await expectNoPageOverflow(page);

        await page
            .getByRole('tab', { name: 'Firmware & configuration' })
            .click();
        await expect(page.getByText(fixture.firmwareWork)).toBeVisible();
        await expect(page.getByText(fixture.configurationWork)).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await page.goto('/security-devices/discovery');
        await expect(
            page.getByRole('heading', {
                name: 'Discovery & collectors',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByText('Collector-free configuration'),
        ).toBeVisible();
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await page.getByRole('tab', { name: 'Remote collectors' }).click();
        await expect(
            page.getByText(fixture.collectorName).first(),
        ).toBeVisible();
        await expect(page.getByText(/results are uncertain/)).toBeVisible();
        await expectNoPageOverflow(page);

        await page.getByRole('tab', { name: 'Coverage & paths' }).click();
        await expect(page.getByText('Direct path').first()).toBeVisible();
        await expect(page.getByText('Remote paths').first()).toBeVisible();
        await expectNoPageOverflow(page);

        await page.getByRole('tab', { name: 'Limitations' }).click();
        await expect(page.getByText('Known limitations')).toBeVisible();
        await expect(page.getByText('Not assessed').first()).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: /scan|discover|adopt|execute|run discovery/i,
            }),
        ).toHaveCount(0);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
