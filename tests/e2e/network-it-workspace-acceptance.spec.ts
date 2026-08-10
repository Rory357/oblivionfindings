import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedNetworkItWorkspaceReadinessFixtures } from './network-it-workspace-fixtures';

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

test.describe('Network & IT specialist workspace', () => {
    let fixture: ReturnType<typeof seedNetworkItWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedNetworkItWorkspaceReadinessFixtures();
    });

    test('native topology, monitors, interfaces, capacity and read-only drift evidence remain clear', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/network-it');

        await expect(
            page.getByRole('heading', { name: 'Network & IT', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Network operations at a glance',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Native monitoring, honest evidence',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page
                .getByRole('link', {
                    name: new RegExp(fixture.gatewayName),
                })
                .first(),
        ).toBeVisible();
        await expect(page.getByText(fixture.ticketTitle)).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        const tabs = page.getByRole('navigation', {
            name: 'Network & IT workspace tabs',
        });
        await tabs.getByRole('link', { name: 'Map', exact: true }).click();
        await expect(
            page.getByRole('heading', {
                name: 'Known topology evidence',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.topologyPort)).toBeVisible();
        const topologyOverview = page.getByLabel('Topology visual overview');
        await expect(
            topologyOverview.getByRole('link', {
                name: fixture.gatewayName,
            }),
        ).toBeVisible();
        await expect(
            topologyOverview.getByRole('link', {
                name: fixture.switchName,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'Devices', exact: true }).click();
        const gatewayCard = page.getByRole('article', {
            name: fixture.gatewayName,
        });
        await expect(gatewayCard).toContainText('WAN path');
        await expect(gatewayCard).toContainText('1.4.0');
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Interfaces', exact: true })
            .click();
        const interfaceCard = page.getByRole('article', {
            name: `${fixture.interfaceName} on ${fixture.switchName}`,
        });
        await expect(interfaceCard).toContainText('85% inbound');
        await expect(interfaceCard).toContainText('62% outbound');
        await expect(interfaceCard).toContainText('1 Gbps');
        await expect(interfaceCard).toContainText('Capacity warning');
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'Services', exact: true }).click();
        await expect(
            page.getByRole('article', { name: fixture.availabilityName }),
        ).toContainText('Healthy');
        await expect(
            page.getByRole('article', { name: fixture.serviceName }),
        ).toContainText('Failed');
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Traffic & capacity', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Traffic and capacity evidence',
                level: 2,
            }),
        ).toBeVisible();
        const retainedInterface = page.getByRole('article').filter({
            has: page.getByRole('heading', {
                name: fixture.interfaceName,
                level: 3,
            }),
        });
        await expect(retainedInterface).toHaveCount(1);
        await expect(
            retainedInterface.getByText('Retained native observation', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            retainedInterface.getByText('Capacity warning', { exact: true }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', {
                name: 'Configuration & firmware',
                exact: true,
            })
            .click();
        const configurationCard = page.getByRole('article', {
            name: fixture.gatewayName,
        });
        await expect(configurationCard).toContainText('Configuration drift');
        await expect(configurationCard).toContainText('Update available');
        await expect(
            page.getByText(/read-only until governed command/i),
        ).toBeVisible();
        await expect(page.getByText(fixture.rawSentinel)).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: /apply|upgrade|push/i }),
        ).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
