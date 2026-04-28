import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    gotoMyDay,
    gotoMyRoster,
    loginAsFrontlineDemoWorker,
} from './helpers';

/**
 * Smoke-level WCAG 2.1 AA scan of the two pages a frontline worker spends
 * almost all their day on. Asserts zero `serious` or `critical` axe
 * violations — moderate / minor issues are reported but do not fail the
 * build, so the suite stays green during normal UX iteration.
 */
test.describe('frontline a11y smoke', () => {
    test('/my-day has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyDay(page);

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

        expectNoConsoleErrors(consoleErrors);
    });

    test('/my-roster has no serious or critical axe violations', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsFrontlineDemoWorker(page);
        await gotoMyRoster(page);

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

        expectNoConsoleErrors(consoleErrors);
    });
});
