import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';
import { seedSecurityWorkspaceReadinessFixtures } from './security-workspace-fixtures';

async function expectNoPageOverflow(page: Page) {
    const widths = await page.evaluate(() => ({
        client: document.documentElement.clientWidth,
        scroll: document.documentElement.scrollWidth,
    }));

    expect(widths.scroll).toBeLessThanOrEqual(widths.client);
}

test.describe('Security specialist workspace', () => {
    let fixture: ReturnType<typeof seedSecurityWorkspaceReadinessFixtures>;

    test.beforeAll(() => {
        fixture = seedSecurityWorkspaceReadinessFixtures();
    });

    test('CCTV, alarms, access control and events use one understandable operational journey', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/security');

        await expect(
            page.getByRole('heading', { name: 'Security', level: 1 }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Security at a glance',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText('What needs action')).toBeVisible();
        await expectNoPageOverflow(page);

        const tabs = page.getByRole('navigation', {
            name: 'Security workspace tabs',
        });
        await tabs.getByRole('link', { name: 'CCTV', exact: true }).click();
        await expect(
            page.getByRole('heading', {
                name: 'CCTV operational evidence',
                level: 2,
            }),
        ).toBeVisible();
        const camera = page.getByRole('article', { name: fixture.cameraName });
        await expect(camera).toContainText(fixture.siteName);
        await expect(camera).toContainText('Stream: Healthy');
        await expect(camera).toContainText('Recording: Degraded');
        await expect(
            camera.getByRole('link', { name: 'Open authorised media' }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs.getByRole('link', { name: 'Alarms', exact: true }).click();
        await expect(
            page.getByRole('article', { name: fixture.alarmName }),
        ).toContainText('Alarm: Armed');
        await expect(page.getByText('Alarm trigger').first()).toBeVisible();
        await expect(
            page.getByRole('link', { name: new RegExp(fixture.alertTitle) }),
        ).toBeVisible();
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Access Control', exact: true })
            .click();
        const door = page.getByRole('article', { name: fixture.doorName });
        await expect(door).toContainText('Door: Secured');
        await expect(door).toContainText('Credentials: 42');
        await expect(page.getByText('Door opened').first()).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: /^(?:unlock|lock|restart|arm|disarm)(?: (?:device|door|alarm|camera))?$/i,
            }),
        ).toHaveCount(0);
        await expectNoPageOverflow(page);

        await tabs
            .getByRole('link', { name: 'Security events', exact: true })
            .click();
        await expect(
            page.getByRole('heading', {
                name: 'Canonical device events',
                level: 2,
            }),
        ).toBeVisible();
        await expect(page.getByText('Door opened').first()).toBeVisible();
        await expect(
            page.getByRole('link', { name: new RegExp(fixture.alertTitle) }),
        ).toHaveAttribute('href', /\/control-room\/alerts\/\d+/);
        await expectNoPageOverflow(page);

        expectNoConsoleErrors(errors);
    });
});
