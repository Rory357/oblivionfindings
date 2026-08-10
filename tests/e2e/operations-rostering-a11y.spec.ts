import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
    resetRosteringReadinessFixtures,
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

async function openPublishReview(page: Page) {
    await page.goto('/operations/rostering?week=2026-05-04&site_id=9001');
    await expect(page.getByTestId('rostering-publish-panel')).toBeVisible();
    await page.getByTestId('rostering-review-publish').click();
    await expect(page.getByTestId('publish-review-page')).toBeVisible();
}

async function openPublishDiff(page: Page) {
    await openPublishReview(page);

    const reviewUrl = new URL(page.url());
    expect(reviewUrl.pathname).toMatch(/\/review$/);

    await page.goto(
        `${reviewUrl.pathname.replace(/\/review$/, '/diff')}${reviewUrl.search}`,
    );
    await expect(
        page.getByRole('heading', { name: /Publish diff/i }),
    ).toBeVisible();
}

test.describe('operations rostering a11y smoke', () => {
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.beforeEach(() => {
        resetRosteringReadinessFixtures();
    });

    test('dashboard has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-04&site_id=9001');
        await expect(page.getByTestId('rostering-publish-panel')).toBeVisible();

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(consoleErrors);
    });

    test('publish review has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-04&site_id=9001');
        await page.getByTestId('rostering-review-publish').click();
        await expect(page.getByTestId('publish-review-page')).toBeVisible();

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(consoleErrors);
    });

    test('publish diff has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await openPublishDiff(page);

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(consoleErrors);
    });

    test('suggestions page has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?week=2026-05-11&site_id=9001');
        await page.getByTestId('rostering-suggest-assignments').click();
        await expect(page.getByTestId('roster-suggestions-page')).toBeVisible();

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(consoleErrors);
    });

    test('template apply page has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/operations/rostering?tab=templates');
        await page.getByTestId('template-card-9001').click();
        await expect(page.getByTestId('template-apply-card')).toBeVisible();

        await expectNoBlockingAxeViolations(page);
        expectNoConsoleErrors(consoleErrors);
    });
});
