import { expect, test } from '@playwright/test';
import {
    loginAsGovernancePersona,
    seedGovernanceFixtures,
} from './fixtures';

test.describe('Governance Journeys', () => {
    test.beforeAll(async () => {
        // Ensure fixtures are seeded in disposable database
        seedGovernanceFixtures();
    });

    test('Member Journey (1366x768): Overview -> Work Feed -> Meetings -> Packs', async ({
        page,
    }) => {
        // 1. Login as synthetic board member
        await loginAsGovernancePersona(page, 'member');

        // 2. Navigate to Governance Dashboard
        await page.goto('/governance/dashboard');
        await expect(page).toHaveURL(/\/governance\/dashboard/);
        await expect(
            page.locator('h1, h2, [data-test="page-header"]').first(),
        ).toBeVisible();

        // 3. Navigate to My Work feed
        await page.goto('/governance/my-work');
        await expect(page).toHaveURL(/\/governance\/my-work/);
        await expect(
            page.locator('text=/My Work|Governance Work|Work Feed/i').first(),
        ).toBeVisible();

        // 4. Navigate to Meetings
        await page.goto('/governance/meetings');
        await expect(page).toHaveURL(/\/governance\/meetings/);
        await expect(
            page.locator('text=/Meetings|Board Meetings/i').first(),
        ).toBeVisible();

        // 5. Navigate to Packs
        await page.goto('/governance/packs');
        await expect(page).toHaveURL(/\/governance\/packs/);
        await expect(
            page.locator('text=/Board Packs|Packs/i').first(),
        ).toBeVisible();
    });

    test('Chair Journey (1920x1080): Overview -> Strategy -> Actions -> Resolutions -> Settings', async ({
        page,
    }) => {
        // 1. Login as synthetic chair
        await loginAsGovernancePersona(page, 'chair');

        // 2. Governance Dashboard / Cockpit
        await page.goto('/governance/dashboard');
        await expect(page).toHaveURL(/\/governance\/dashboard/);
        await expect(
            page.locator('h1, h2, [data-test="page-header"]').first(),
        ).toBeVisible();

        // 3. Strategy
        await page.goto('/governance/strategy');
        await expect(page).toHaveURL(/\/governance\/strategy/);
        await expect(
            page.locator('text=/Strategy|Strategic Plans/i').first(),
        ).toBeVisible();

        // 4. Actions
        await page.goto('/governance/actions');
        await expect(page).toHaveURL(/\/governance\/actions/);
        await expect(
            page.locator('text=/Actions|Board Actions/i').first(),
        ).toBeVisible();

        // 5. Resolutions
        await page.goto('/governance/resolutions');
        await expect(page).toHaveURL(/\/governance\/resolutions/);
        await expect(
            page.locator('text=/Resolutions/i').first(),
        ).toBeVisible();

        // 6. Settings & Rules Authority
        await page.goto('/governance/settings');
        await expect(page).toHaveURL(/\/governance\/settings/);
        await expect(
            page.locator('text=/Settings|Governance Settings|Rules/i').first(),
        ).toBeVisible();
    });
});
