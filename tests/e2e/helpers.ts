import { expect, type Page } from '@playwright/test';

export async function loginAs(page: Page, email: string, password = 'password') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).not.toHaveURL(/\/login$/);
}

export async function loginAsStaff(page: Page) {
    const email = process.env.VISUAL_EMAIL ?? 'admin@demo.test';
    const password = process.env.VISUAL_PASSWORD ?? 'password';
    await loginAs(page, email, password);
}

/**
 * Frontline support worker pre-seeded by FrontlineLifecycleDemoSeeder with:
 *   - a returned timesheet (Sat) with manager note for the F-1 inline edit flow
 *   - a scheduled shift starting "soon" for the pre-shift briefing card
 *   - site-access aligned to the demo client so resubmit/clock-in succeed
 */
export async function loginAsFrontlineDemoWorker(page: Page) {
    await loginAs(page, 'sw1@demo.test', 'password');
}

export async function gotoMyDay(page: Page) {
    await page.goto('/my-day');
    await page.waitForLoadState('domcontentloaded');
}

export async function gotoMyRoster(page: Page) {
    await page.goto('/my-roster');
    await page.waitForLoadState('domcontentloaded');
}

export function collectConsoleErrors(page: Page) {
    const errors: string[] = [];

    page.on('console', (message) => {
        if (message.type() === 'error') {
            const text = message.text();
            if (!text.includes('Encountered two children with the same key')) {
                errors.push(text);
            }
        }
    });

    page.on('pageerror', (error) => {
        errors.push(error.message);
    });

    return errors;
}

export function expectNoConsoleErrors(errors: string[]) {
    expect(errors).toEqual([]);
}
