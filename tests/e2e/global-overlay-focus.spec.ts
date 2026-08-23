import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('shared overlay focus return', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('global module search returns focus for click and keyboard openers', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/control-room/settings');

        const trigger = page
            .locator('button[aria-label="Search modules"]:visible')
            .first();
        const dialog = page.getByRole('dialog', {
            name: 'Search modules and pages',
        });
        const search = page.getByPlaceholder(
            'Search modules, pages, ticket #…',
        );

        await trigger.focus();
        await trigger.click();
        await expect(dialog).toBeVisible();
        await expect(search).toBeFocused();

        const axe = await new AxeBuilder({ page })
            .include('[data-slot="dialog-content"]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        const blocking = axe.violations.filter((violation) =>
            ['serious', 'critical'].includes(violation.impact ?? ''),
        );
        expect(
            blocking,
            `module search axe blockers:\n${blocking
                .map(
                    (violation) =>
                        `  - [${violation.impact}] ${violation.id}: ${violation.help}`,
                )
                .join('\n')}`,
        ).toEqual([]);

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(trigger).toBeFocused();

        await page.keyboard.press('Control+K');
        await expect(dialog).toBeVisible();
        await expect(search).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(trigger).toBeFocused();

        expectNoConsoleErrors(consoleErrors);
    });
});
