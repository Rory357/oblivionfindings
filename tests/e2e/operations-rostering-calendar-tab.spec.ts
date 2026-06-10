import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

/**
 * The Rostering "Calendar" tab is the custom month grid (CalendarPane), fed by
 * operations.rostering.calendar.events. These cover the contracts the tab
 * depends on: it renders + loads from the endpoint, and the retired
 * /scheduling URL redirects into the tab.
 */
test.describe('Rostering Calendar tab', () => {
    test('renders the month grid and loads events from the rostering calendar endpoint', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);
        await loginAsStaff(page);

        const eventsRequest = page.waitForRequest((req) =>
            req.url().includes('/operations/rostering/calendar/events'),
        );

        await page.goto('/operations/rostering?tab=calendar');

        // CalendarPane mounts only when the Calendar tab is the active tab —
        // proves ?tab=calendar selected it and the month grid rendered.
        await expect(
            page.locator('[data-testid="rostering-calendar"]'),
        ).toBeVisible({ timeout: 15000 });
        await eventsRequest;

        // The summary ribbon + weekday header are part of the new grid.
        await expect(
            page
                .locator('[data-testid="rostering-calendar"]')
                .getByText('Coverage gaps'),
        ).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });

    test('retired /scheduling URL redirects into the Rostering calendar tab', async ({
        page,
    }) => {
        await loginAsStaff(page);
        await page.goto('/scheduling');
        await expect(page).toHaveURL(/\/operations\/rostering\?tab=calendar/);
    });
});
