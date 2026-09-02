import { expect, test } from '@playwright/test';

import {
    confirmPasswordIfAsked,
    loginAsCommandReviewer,
    seedSecurityDevicesMutatingFixtures,
} from './security-devices-mutating-fixtures';
import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('Security & Devices governed command batches', () => {
    let fixture: ReturnType<typeof seedSecurityDevicesMutatingFixtures>;

    test.beforeAll(() => {
        fixture = seedSecurityDevicesMutatingFixtures();
    });

    test('confirms identity, then decides, dispatches, and exports a governed batch', async ({
        page,
    }) => {
        test.setTimeout(360_000);
        const errors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/security-devices/security?tab=management');
        await expect(
            page.getByRole('heading', { name: 'Security', level: 1 }),
        ).toBeVisible();
        await expect(page.getByText('Governed Device management')).toBeVisible({
            timeout: 30_000,
        });

        await expect(page.getByText(fixture.doorAName)).toBeVisible();
        await expect(page.getByText(fixture.doorBName)).toBeVisible();
        await page
            .locator('#bulk-management-action')
            .selectOption('access.door.unlock_timed');
        await page.getByRole('checkbox', { name: `Select ${fixture.doorAName}` }).check();
        await page.getByRole('checkbox', { name: `Select ${fixture.doorBName}` }).check();
        await page.getByRole('button', { name: 'Review selected targets' }).click();

        const review = page.getByRole('dialog', {
            name: /review governed bulk action/i,
        });
        await expect(review).toBeVisible();
        await expect(
            review.getByRole('button', { name: /wipe|restart/i }),
        ).toHaveCount(0);

        const confirmIdentity = review.getByRole('link', {
            name: /confirm identity/i,
        });
        if (await confirmIdentity.isVisible().catch(() => false)) {
            await confirmIdentity.click();
            await confirmPasswordIfAsked(page);
            await expect(page.getByText('Governed Device management')).toBeVisible({
                timeout: 30_000,
            });
            await page
                .getByRole('checkbox', { name: `Select ${fixture.doorAName}` })
                .check();
            await page
                .getByRole('checkbox', { name: `Select ${fixture.doorBName}` })
                .check();
            await page.getByRole('button', { name: 'Review selected targets' }).click();
            await expect(review).toBeVisible();
        }

        await page.locator('#bulk-parameter-duration_seconds').fill('15');
        await page
            .locator('#bulk-command-reason')
            .fill(
                'Allow the approved engineering attendance through both service doors.',
            );
        const impact = page.locator('#bulk-command-impact-acknowledged');
        if (await impact.isVisible().catch(() => false)) {
            await impact.check();
        }
        const confirmation = page.locator('#bulk-command-confirmation');
        if (await confirmation.isVisible().catch(() => false)) {
            await confirmation.fill('BULK 2 DEVICES');
        }
        await page.getByRole('button', { name: 'Create 2 child requests' }).click();

        await expect(page.getByText('Per-Device results')).toBeVisible({
            timeout: 30_000,
        });
        await expect(page.getByRole('link', { name: 'Download result ledger' })).toBeVisible();
        await expect(page.getByText(fixture.doorAName)).toBeVisible();
        await expect(page.getByText(fixture.doorBName)).toBeVisible();
        await expect(
            page.getByRole('button', { name: /wipe|restart/i }),
        ).toHaveCount(0);

        const batchUrl = page.url();
        expect(batchUrl).toMatch(/\/security-devices\/command-batches\/\d+/);

        await loginAsCommandReviewer(page, fixture.reviewerEmail);
        await page.goto(batchUrl);
        await page.getByRole('button', { name: /Review 2 requests/ }).click();
        await page.locator('#batch-decision-comment').fill(
            'Verified the exact Site, both doors, impact and expected lock state.',
        );
        await page.getByRole('button', { name: 'Record decision' }).click();
        await expect(
            page.getByRole('button', { name: /Queue 2 ready requests/ }),
        ).toBeVisible({ timeout: 30_000 });

        await loginAsStaff(page);
        await page.goto(batchUrl);
        await page.getByRole('button', { name: /Queue 2 ready requests/ }).click();
        await expect(
            page.getByRole('heading', { name: 'Queue ready child requests?' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Queue ready requests' }).click();
        await expect(page.getByText(/queued/i).first()).toBeVisible({
            timeout: 30_000,
        });

        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download result ledger' }).click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toMatch(/\.csv$/i);

        expectNoConsoleErrors(errors);
    });
});
