import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

const VIEWPORTS = [
    { width: 1440, height: 900 },
    { width: 1280, height: 800 },
    { width: 1024, height: 768 },
    { width: 390, height: 844 },
];

test.describe('corrective actions responsive register', () => {
    test('contains width and preserves labelled actions at every acceptance viewport', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await page.setViewportSize(VIEWPORTS[0]);
        await loginAsStaff(page);
        await page.goto('/health-safety/corrective-actions');
        await expect(
            page.getByRole('heading', {
                name: 'Corrective actions',
                exact: true,
            }),
        ).toBeVisible();

        const cards = page.getByTestId('corrective-action-cards');
        const tableRegion = page.getByTestId(
            'corrective-action-table-scroll',
        );

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            if (viewport.width < 768) {
                await expect(page.locator('#main-content')).toHaveCSS(
                    'margin-left',
                    '0px',
                );
            }

            const widths = await page.evaluate(() => ({
                clientWidth: document.documentElement.clientWidth,
                documentWidth: document.documentElement.scrollWidth,
                bodyWidth: document.body.scrollWidth,
            }));
            expect(
                widths.documentWidth,
                `${viewport.width}px document width`,
            ).toBeLessThanOrEqual(widths.clientWidth);
            expect(
                widths.bodyWidth,
                `${viewport.width}px body width`,
            ).toBeLessThanOrEqual(widths.clientWidth);

            if (viewport.width < 768) {
                await expect(cards).toBeVisible();
                await expect(tableRegion).toBeHidden();
            } else {
                await expect(cards).toBeHidden();
                await expect(tableRegion).toBeVisible();
                expect(
                    await tableRegion.evaluate(
                        (element) =>
                            element.scrollWidth >= element.clientWidth,
                    ),
                ).toBe(true);
            }
        }

        const firstCard = cards.getByRole('listitem').first();
        await expect(firstCard).toBeVisible();
        await expect(firstCard.getByText('Due', { exact: true })).toBeVisible();
        await expect(
            firstCard.getByText('Priority', { exact: true }),
        ).toBeVisible();
        await expect(
            firstCard.getByText('Owner', { exact: true }),
        ).toBeVisible();
        await expect(
            firstCard.getByText('Parent event', { exact: true }),
        ).toBeVisible();
        await expect(
            firstCard.getByText('Stage', { exact: true }),
        ).toBeVisible();
        await expect(
            firstCard.getByText('Flags', { exact: true }),
        ).toBeVisible();

        expect(
            await firstCard.evaluate(
                (element) => element.scrollWidth <= element.clientWidth,
            ),
        ).toBe(true);

        const openAction = firstCard
            .getByRole('button', {
                name: /Open parent event for action/,
            })
            .first();
        const lifecycleActions = firstCard
            .getByRole('button', {
                name: /Lifecycle actions for/,
            })
            .first();
        const lifecycleBox = await lifecycleActions.boundingBox();
        expect(lifecycleBox?.width ?? 0).toBeGreaterThanOrEqual(44);
        expect(lifecycleBox?.height ?? 0).toBeGreaterThanOrEqual(44);

        await openAction.focus();
        await expect(openAction).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(lifecycleActions).toBeFocused();

        const axe = await new AxeBuilder({ page })
            .include('[data-test="corrective-action-cards"]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();
        const blocking = axe.violations.filter((violation) =>
            ['serious', 'critical'].includes(violation.impact ?? ''),
        );
        expect(
            blocking,
            `corrective action card axe blockers:\n${blocking
                .map(
                    (violation) =>
                        `  - [${violation.impact}] ${violation.id}: ${violation.help}`,
                )
                .join('\n')}`,
        ).toEqual([]);

        expectNoConsoleErrors(consoleErrors);
    });
});
