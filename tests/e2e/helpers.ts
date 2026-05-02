import { expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

function resolvePhpBinary(): string {
    const explicit = process.env.PHP_BINARY;
    if (explicit && existsSync(explicit)) {
        return explicit;
    }

    const candidates = [
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.bat`,
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.exe`,
        `${process.env.HOME ?? ''}/.config/herd-lite/bin/php`,
        `${process.env.HOME ?? ''}/Library/Application Support/Herd/bin/php`,
        'php',
    ];

    return (
        candidates.find(
            (candidate) => candidate === 'php' || existsSync(candidate),
        ) ?? 'php'
    );
}

export function resetMedicationReadinessFixtures() {
    runArtisan(['db:seed', '--class=FrontlineLifecycleDemoSeeder', '--force']);
}

export function seedGovernancePrivacyConsentsReadinessFixtures() {
    runArtisan([
        'db:seed',
        '--class=GovernancePrivacyConsentsReadinessSeeder',
        '--force',
    ]);

    const output = runLaravelPhp(
        "echo json_encode(['clientId' => \\App\\Models\\Client::where('first_name', 'Playwright')->where('last_name', 'Consent')->value('id'), 'portalEmail' => 'portal.consent.readiness@demo.test']);",
    );

    return JSON.parse(output) as {
        clientId: number;
        portalEmail: string;
    };
}

export function runArtisan(args: string[]) {
    const phpBin = resolvePhpBinary();
    const useShell = phpBin.toLowerCase().endsWith('.bat') || phpBin === 'php';

    return execFileSync(phpBin, ['artisan', ...args], {
        cwd: process.cwd(),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        shell: useShell,
    });
}

export function runLaravelPhp(code: string) {
    const phpBin = resolvePhpBinary();
    const useShell = phpBin.toLowerCase().endsWith('.bat') || phpBin === 'php';
    const tempDir = mkdtempSync(join(tmpdir(), 'of-laravel-'));
    const scriptPath = join(tempDir, 'run.php');

    writeFileSync(
        scriptPath,
        [
            '<?php',
            "require getcwd() . '/vendor/autoload.php';",
            "$app = require getcwd() . '/bootstrap/app.php';",
            '$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
            code,
        ].join('\n'),
        'utf8',
    );

    try {
        return execFileSync(phpBin, [scriptPath], {
            cwd: process.cwd(),
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
            shell: useShell,
        });
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
}

export async function loginAs(
    page: Page,
    email: string,
    password = 'password',
) {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
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

export async function loginAsMedsDemoWorker(page: Page) {
    await loginAs(page, 'sw-meds@demo.test', 'password');
}

export async function loginAsRestrictedMedsWorker(page: Page) {
    await loginAs(page, 'sw-meds-no-record@demo.test', 'password');
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
