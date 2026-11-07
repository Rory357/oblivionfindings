import { expect, test, type Page } from '@playwright/test';

import { seedHealthcareWorkspaceReadinessFixtures } from './healthcare-workspace-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

// S&D rebuild C6 retired the Healthcare workspace (Stephan, 1 Nov: the Health
// kit becomes a Devices › Sensors filter). Its shared-device, data-flow and
// calibration tabs are covered by DevicesMenuPresenterTest and
// RetiredPagesRedirectTest; the client round trip and the clinical privacy
// promise stay here.

const healthKitHref = '/security-devices/devices?tab=sensors&group=health';

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

async function expectHealthKit(page: Page) {
    await expect(
        page.getByRole('heading', { name: 'Devices', level: 1, exact: true }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('tablist', { name: 'Kinds of device' })
            .getByRole('tab', { name: /^Sensors/ }),
    ).toHaveAttribute('aria-selected', 'true');
}

test.describe('Healthcare devices on Security & Devices', () => {
    let fixture: ReturnType<typeof seedHealthcareWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedHealthcareWorkspaceReadinessFixtures();
    });

    test('the Health kit filter and the client round trip stay technical and permission-safe', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto(healthKitHref);
        await expectHealthKit(page);
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        await page.goto(
            `/operations/clients/${fixture.clientId}?tab=healthcare_devices`,
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
        const deviceHref = `/security-devices/devices/${fixture.clientDeviceId}`;
        await expect(deviceProfileLink).toHaveAttribute('href', deviceHref);
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
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        const devicePayload = await (await page.request.get(deviceHref)).text();
        expect(devicePayload).not.toContain(fixture.clinicalSentinel);
        await expectNoPageOverflow(page);

        await page.goto(
            `/operations/clients/${fixture.clientId}?tab=healthcare_devices`,
        );
        await expect(
            page.getByTestId('client-healthcare-devices'),
        ).toBeVisible();
        const fullHealthcareWorkspace = page.getByRole('link', {
            name: /open full device workspace/i,
        });
        await expect(fullHealthcareWorkspace).toHaveAttribute(
            'href',
            healthKitHref,
        );
        await fullHealthcareWorkspace.click();
        await expectHealthKit(page);
        await expect(page.getByText(fixture.clinicalSentinel)).toHaveCount(0);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
