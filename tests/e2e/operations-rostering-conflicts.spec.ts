import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    resetRosteringReadinessFixtures,
    ROSTERING_DEMO_PUBLISH_TARGET,
} from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

async function expectNoBlockingAxeViolations(page: Page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const blocking = results.violations.filter((violation) =>
        ['serious', 'critical'].includes(violation.impact ?? ''),
    );

    expect(
        blocking,
        `axe found blocking violations:\n${blocking
            .map(
                (v) =>
                    `  - [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length} nodes)`,
            )
            .join('\n')}`,
    ).toEqual([]);
}

test.describe('operations rostering — conflicts page', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Rostering conflicts review is desktop-only',
    );

    test('manager can review conflicts and open an affected shift', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        resetRosteringReadinessFixtures();
        await loginAsStaff(page);
        await page.goto(
            `/operations/rostering/conflicts?week=${ROSTERING_DEMO_PUBLISH_TARGET.week}&site_id=${ROSTERING_DEMO_PUBLISH_TARGET.siteId}`,
        );

        await expect(
            page.getByRole('heading', { name: /Conflict queue/i }),
        ).toBeVisible();
        await expectNoBlockingAxeViolations(page);

        const openShiftLink = page
            .getByRole('link', { name: /Open shift/i })
            .first();

        if ((await openShiftLink.count()) > 0) {
            await openShiftLink.click();
            await expect(page).toHaveURL(/\/operations\/shifts\/\d+/);
            await expect(page.getByRole('heading').first()).toBeVisible();
        } else {
            await expect(
                page
                    .getByText(
                        /No (staff double-bookings detected|client overlap warnings detected|site demand gaps detected|open shifts this week|approved leave clashes detected|risky back-to-back shifts detected|recurring demand drift detected|active replacement workflows)/i,
                    )
                    .first(),
            ).toBeVisible();
        }

        expectNoConsoleErrors(consoleErrors);
    });
});
