import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyRoster,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * Roster page — F-7 added a Mon–Sun week-grid overview on desktop (`lg+`)
 * above the today/upcoming/recent sections. The grid hides on mobile by
 * design, so this spec is desktop-only.
 */
test.describe('my roster — week grid overview (desktop)', () => {
    test.skip(({ viewport }) => {
        return !viewport || viewport.width < 1024;
    }, 'Week grid is desktop-only');

    test('renders all 7 weekday columns and at least one shift block', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyRoster(page);

        const weekGrid = page.getByLabel(/Week overview/i);
        await expect(weekGrid).toBeVisible();

        for (const label of ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) {
            await expect(
                weekGrid.getByText(new RegExp(`^${label}$`, 'i')).first(),
            ).toBeVisible();
        }

        // The seeded data has multiple shifts in the current week, so at
        // least one shift block should render inside the grid.
        const grid = weekGrid;
        const blockCount = await grid.getByRole('button').count();
        expect(blockCount).toBeGreaterThan(0);

        expectNoConsoleErrors(consoleErrors);
    });
});
