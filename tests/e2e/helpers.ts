import { expect, type Page, type TestInfo } from '@playwright/test';

export async function loginAs(
    page: Page,
    email: string,
    password = 'password',
) {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    const loginButton = page.getByTestId('login-button');
    await expect(loginButton).toBeEnabled();
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        loginButton.click(),
    ]);
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

export async function loginAsClockOutCleanWorker(
    page: Page,
    testInfo: TestInfo,
) {
    await loginAs(
        page,
        testInfo.project.name.includes('mobile')
            ? 'sw6@demo.test'
            : 'sw2@demo.test',
        'password',
    );
}

export async function loginAsClockInCandidateWorker(
    page: Page,
    testInfo: TestInfo,
) {
    await loginAs(
        page,
        testInfo.project.name.includes('mobile')
            ? 'sw8@demo.test'
            : 'sw3@demo.test',
        'password',
    );
}

export async function loginAsChecklistWorker(page: Page, testInfo: TestInfo) {
    await loginAs(
        page,
        testInfo.project.name.includes('mobile')
            ? 'sw7@demo.test'
            : 'sw4@demo.test',
        'password',
    );
}

export async function loginAsIncidentBlockerWorker(page: Page) {
    await loginAs(page, 'sw5@demo.test', 'password');
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
