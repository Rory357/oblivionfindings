import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

/**
 * The standalone /scheduling FullCalendar was consolidated into the Rostering
 * workspace as a "Calendar" tab (data/writes re-homed under
 * operations.rostering.calendar.*). These cover the two contracts that the
 * consolidation depends on: the tab renders + loads from the new endpoint, and
 * the retired /scheduling URL redirects into the tab.
 */
test.describe('Rostering Calendar tab', () => {
    test('renders the FullCalendar and loads events from the rostering calendar endpoint', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);
        await loginAsStaff(page);

        const eventsRequest = page.waitForRequest((req) =>
            req.url().includes('/operations/rostering/calendar/events'),
        );

        await page.goto('/operations/rostering?tab=calendar');

        // FullCalendar mounts its `.fc` root only when the Calendar tab is the
        // active tab — proves ?tab=calendar selected it and the embedded view
        // rendered without the old AppLayout chrome.
        await expect(page.locator('.fc').first()).toBeVisible({
            timeout: 15000,
        });
        await eventsRequest;

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
