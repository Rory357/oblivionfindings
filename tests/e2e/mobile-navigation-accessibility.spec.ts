import { expect, test } from '@playwright/test';

import {
    collectConsoleErrors,
    expectNoConsoleErrors,
    loginAsStaff,
} from './helpers';

test.describe('global mobile navigation accessibility', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('is a named modal with trapped focus and complete close recovery', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/dashboard');

        const trigger = page.getByRole('button', { name: 'Toggle Menu' });
        await expect(trigger).toBeVisible();
        const triggerBounds = await trigger.boundingBox();
        expect(triggerBounds?.width).toBeGreaterThanOrEqual(44);
        expect(triggerBounds?.height).toBeGreaterThanOrEqual(44);
        await trigger.focus();
        await page.keyboard.press('Enter');

        const dialog = page.getByRole('dialog', { name: 'Menu' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        await expect(dialog).toHaveAccessibleDescription(
            'Choose an area to navigate to.',
        );
        await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');
        expect(
            await page
                .locator('#main-content')
                .evaluate((main) =>
                    Boolean(main.closest('[aria-hidden="true"]')),
                ),
        ).toBe(true);
        expect(
            await dialog.evaluate((element) =>
                element.contains(document.activeElement),
            ),
        ).toBe(true);

        const close = page.getByRole('button', { name: 'Close menu' });
        const closeBounds = await close.boundingBox();
        expect(closeBounds?.width).toBeGreaterThanOrEqual(44);
        expect(closeBounds?.height).toBeGreaterThanOrEqual(44);
        await close.focus();
        await page.keyboard.press('Tab');
        expect(
            await dialog.evaluate((element) =>
                element.contains(document.activeElement),
            ),
        ).toBe(true);

        const firstNavigationControl = dialog
            .getByRole('navigation', { name: 'Main navigation' })
            .locator('a[href], button:not([disabled])')
            .first();
        const navigationControlBounds =
            await firstNavigationControl.boundingBox();
        expect(navigationControlBounds?.height).toBeGreaterThanOrEqual(44);
        await firstNavigationControl.focus();
        await page.keyboard.press('Shift+Tab');
        expect(
            await dialog.evaluate((element) =>
                element.contains(document.activeElement),
            ),
        ).toBe(true);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();

        await trigger.click();
        await expect(dialog).toBeVisible();
        await page.locator('[data-slot="sheet-overlay"]').click({
            position: { x: 380, y: 400 },
        });
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();

        await trigger.click();
        await expect(dialog).toBeVisible();
        await page.getByRole('button', { name: 'Close menu' }).click();
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
        expect(
            await page
                .locator('#main-content')
                .evaluate((main) =>
                    Boolean(main.closest('[aria-hidden="true"]')),
                ),
        ).toBe(false);

        await trigger.click();
        await expect(dialog).toBeVisible();
        await dialog.getByRole('link', { name: 'My Day', exact: true }).click();
        await expect(dialog).toBeHidden();
        await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');

        expectNoConsoleErrors(consoleErrors);
    });

    test('releases the modal state when the viewport crosses to desktop', async ({
        page,
    }) => {
        const consoleErrors = collectConsoleErrors(page);

        await loginAsStaff(page);
        await page.goto('/dashboard');

        const trigger = page.getByRole('button', { name: 'Toggle Menu' });
        await trigger.click();

        const dialog = page.getByRole('dialog', { name: 'Menu' });
        await expect(dialog).toBeVisible();
        await expect(page.locator('body')).toHaveCSS('overflow', 'hidden');

        await page.setViewportSize({ width: 1024, height: 844 });

        await expect(dialog).toBeHidden();
        await expect(page.locator('body')).not.toHaveCSS('overflow', 'hidden');
        await expect(trigger).toBeHidden();
        await expect(page.locator('#main-content')).toBeVisible();
        expect(
            await page
                .locator('#main-content')
                .evaluate((main) =>
                    Boolean(main.closest('[aria-hidden="true"]')),
                ),
        ).toBe(false);

        expectNoConsoleErrors(consoleErrors);
    });
});
