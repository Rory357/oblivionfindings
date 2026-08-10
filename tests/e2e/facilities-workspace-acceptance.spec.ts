import { expect, test, type Page } from '@playwright/test';

import { seedFacilitiesWorkspaceReadinessFixtures } from './facilities-workspace-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

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

test.describe('Facilities & IoT specialist workspace', () => {
    let fixture: ReturnType<typeof seedFacilitiesWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedFacilitiesWorkspaceReadinessFixtures();
    });

    test('native facility evidence remains clear, private, responsive and read-only', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/facilities-iot');

        await expect(
            page.getByRole('heading', {
                name: 'Facilities & IoT',
                level: 1,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Facilities operations at a glance',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Technical facilities evidence, not building control',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.siteName).first()).toBeVisible();
        await expect(page.getByText('4 facility devices')).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        const tabs = page.getByRole('navigation', {
            name: 'Facilities & IoT workspace tabs',
        });

        await tabs
            .getByRole('link', { name: 'Environment', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Environmental sensor evidence',
                level: 2,
            }),
        ).toBeVisible();
        const environment = page.getByRole('article', {
            name: fixture.environmentName,
        });
        await expect(environment).toContainText('3.2 C');
        await expect(environment).toContainText('Fresh');
        await expect(environment).toContainText(fixture.thresholdLabel);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Building systems', exact: true })
            .click();
        const building = page.getByRole('article', {
            name: fixture.buildingName,
        });
        await expect(building).toContainText('1 open maintenance item');
        await expect(
            building.getByRole('link', { name: 'Open maintenance' }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Utilities', exact: true })
            .click();
        const utility = page.getByRole('article', {
            name: fixture.utilityName,
        });
        await expect(utility).toContainText('74.5 percent');
        await expect(utility).toContainText(fixture.integrationName);
        await expect(utility).toContainText('Last sync: Success');
        await expect(utility).toContainText('Utility Metering');
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Automations', exact: true })
            .click();
        const automation = page.getByRole('article', {
            name: fixture.automationName,
        });
        await expect(automation).toContainText(fixture.automationEvidenceName);
        await expect(automation).toContainText('Last execution: Success');
        await expect(
            page.getByText(
                'Automation controls remain read-only until governed command workflows are available.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(
            automation.getByRole('button', {
                name: /run|start|stop|enable|disable|switch|control/i,
            }),
        ).toHaveCount(0);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'History', exact: true }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Canonical facility history',
                level: 2,
            }),
        ).toBeVisible();
        const eventHistory = page
            .getByRole('heading', { name: 'Device events', level: 3 })
            .locator('..');
        await expect(
            eventHistory.getByText(fixture.thresholdLabel, { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(fixture.environmentMonitorName),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Export events' }),
        ).toHaveAttribute('href', /domain=facilities/);
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
