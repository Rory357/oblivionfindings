import { expect, test, type Page } from '@playwright/test';

import { seedHealthcareWorkspaceReadinessFixtures } from './healthcare-workspace-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

test.describe('Healthcare specialist workspace', () => {
    let fixture: ReturnType<typeof seedHealthcareWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedHealthcareWorkspaceReadinessFixtures();
    });

    test('client, shared, data-flow and maintenance views stay technical and permission-safe', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/healthcare');

        await expect(
            page.getByRole('heading', { name: 'Healthcare', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Healthcare devices at a glance',
                level: 2,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Technical device operations only',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        const tabs = page.getByRole('navigation', {
            name: 'Healthcare workspace tabs',
        });
        await tabs
            .getByRole('link', { name: 'Client devices', exact: true })
            .click();
        const clientDevice = page.getByRole('article', {
            name: fixture.clientDeviceName,
        });
        const clientProfileLink = clientDevice.getByRole('link', {
            name: fixture.clientDisplayName,
            exact: true,
        });
        await expect(clientProfileLink).toHaveAttribute(
            'href',
            `/operations/clients/${fixture.clientId}?tab=healthcare_devices`,
        );
        await expect(clientDevice).toContainText(fixture.supportName);
        await expect(clientDevice).toContainText('72% battery');
        await expect(clientDevice).toContainText('Connected');
        await expect(clientDevice).toContainText('Healthy flow');
        await expect(
            clientDevice.getByRole('link', {
                name: new RegExp(fixture.ticketReference),
            }),
        ).toHaveAttribute('href', /\/it\/tickets\/\d+/);
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await clientProfileLink.click();
        await expect(page).toHaveURL(
            new RegExp(
                `/operations/clients/${fixture.clientId}\\?tab=healthcare_devices$`,
            ),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.clientFullName,
                level: 1,
            }),
        ).toBeVisible();
        const clientProjection = page.getByTestId(
            `client-healthcare-device-${fixture.clientDeviceId}`,
        );
        await expect(clientProjection).toContainText(fixture.clientDeviceName);
        const deviceProfileLink = clientProjection.getByRole('link', {
            name: 'Open device',
            exact: true,
        });
        await expect(deviceProfileLink).toHaveAttribute(
            'href',
            `/security-devices/devices/${fixture.clientDeviceId}`,
        );
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await deviceProfileLink.click();
        await expect(page).toHaveURL(
            new RegExp(`/security-devices/devices/${fixture.clientDeviceId}$`),
        );
        await expect(
            page.getByRole('heading', {
                name: fixture.clientDeviceName,
                level: 1,
            }),
        ).toBeVisible();
        const returnToClient = page.getByRole('link', {
            name: fixture.clientFullName,
            exact: true,
        });
        await expect(returnToClient).toHaveAttribute(
            'href',
            `/operations/clients/${fixture.clientId}?tab=healthcare_devices`,
        );
        await returnToClient.click();
        await expect(
            page.getByTestId('client-healthcare-devices'),
        ).toBeVisible();
        const fullHealthcareWorkspace = page.getByRole('link', {
            name: /open full device workspace/i,
        });
        await expect(fullHealthcareWorkspace).toHaveAttribute(
            'href',
            '/security-devices/healthcare?tab=client-devices',
        );
        await fullHealthcareWorkspace.click();
        await expect(
            page.getByRole('heading', { name: 'Healthcare', level: 1 }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Shared & site devices', exact: true })
            .click();
        const sharedDevice = page.getByRole('article', {
            name: fixture.sharedDeviceName,
        });
        await expect(sharedDevice).toContainText(
            `Shared at ${fixture.siteName}`,
        );
        await expect(
            sharedDevice.getByRole('link', {
                name: fixture.siteName,
                exact: true,
            }),
        ).toHaveAttribute('href', /\/sites\/\d+/);
        await expect(sharedDevice).not.toContainText('Assigned client');
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', {
                name: 'Connectivity & data flow',
                exact: true,
            })
            .click();
        for (const label of [
            'Offline',
            'Integration failure',
            'Stale delivery',
            'Monitoring unsupported',
            'Healthy flow',
        ]) {
            await expect(
                page.getByRole('heading', { name: label, level: 3 }),
            ).toBeVisible();
        }
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', {
                name: 'Calibration & maintenance',
                exact: true,
            })
            .click();
        await expect(
            page.getByText(fixture.calibrationDescription),
        ).toBeVisible();
        await expect(page.getByText('PW-CAL-100')).toBeVisible();
        await expect(page.getByText('Overdue')).toBeVisible();
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
