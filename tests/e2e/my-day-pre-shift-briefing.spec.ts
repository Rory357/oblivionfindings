import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    loginAs,
    runLaravelPhp,
} from './helpers';

test.describe('pre-shift briefing card', () => {
    test('worker can open the exact next-shift briefing from My shift', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);
        const fixture = JSON.parse(
            runLaravelPhp(`
echo json_encode(app(\\Database\\Seeders\\MyDayPreShiftBriefingE2ESeeder::class)->seedFixture(), JSON_THROW_ON_ERROR);
`),
        ) as {
            workerEmail: string;
            personName: string;
            siteName: string;
            notes: string;
        };

        await loginAs(page, fixture.workerEmail, 'password');
        await gotoMyDay(page);
        const shiftTab = page
            .getByRole('tablist', { name: 'My Day views' })
            .getByRole('tab', { name: 'My shift', exact: true });
        await shiftTab.click();
        await expect(shiftTab).toHaveAttribute('aria-selected', 'true');
        const briefing = page.getByTestId('my-day-tomorrow');
        await expect(briefing).toBeVisible();
        await expect(briefing).toContainText('Next shift');
        await expect(briefing).toContainText(fixture.personName);
        await expect(briefing).toContainText(fixture.siteName);
        await expect(briefing).toContainText(fixture.notes);
        await expect(briefing.getByRole('link')).toHaveAttribute(
            'href',
            '/my-roster',
        );

        expectNoConsoleErrors(consoleErrors);
    });
});
