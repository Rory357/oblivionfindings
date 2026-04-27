import { expect, type Page } from '@playwright/test';

export async function loginAsStaff(page: Page) {
    const email = process.env.VISUAL_EMAIL ?? 'admin@demo.test';
    const password = process.env.VISUAL_PASSWORD ?? 'password';

    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByTestId('login-button').click();
    await expect(page).not.toHaveURL(/\/login$/);
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
