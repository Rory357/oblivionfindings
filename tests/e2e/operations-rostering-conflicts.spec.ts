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
            page.getByRole('heading', { name: /conflicts need you/i }),
        ).toBeVisible();
        await expectNoBlockingAxeViolations(page);

        await page
            .getByRole('button', {
                name: /Rostering E2E House.*Rostering Publish/i,
            })
            .click();
        await page.getByRole('link', { name: /View shift/i }).click();
        await expect(page).toHaveURL(/\/operations\/shifts\/9201/);
        await expect(page.getByRole('heading').first()).toBeVisible();

        expectNoConsoleErrors(consoleErrors);
    });
});
