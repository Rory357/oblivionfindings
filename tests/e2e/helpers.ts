import { expect, type Page, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { phpRequiresShell, resolvePhpBinary } from './php-binary';

export const ROSTERING_DEMO_PUBLISH_TARGET = {
    week: '2026-05-04',
    siteId: 9001,
} as const;

export const ROSTERING_DEMO_FRONTLINE_TARGET = {
    week: '2026-05-04',
    siteId: 9002,
} as const;

export const ROSTERING_DEMO_SUGGESTION_TARGET = {
    week: '2026-05-11',
    siteId: 9001,
} as const;

export function resetMedicationReadinessFixtures() {
    runArtisan(['db:seed', '--class=FrontlineLifecycleDemoSeeder', '--force']);
}

export function resetRosteringReadinessFixtures(
    options: { assignmentShiftStatus?: 'draft' | 'scheduled' } = {},
) {
    runArtisan(['db:seed', '--class=RosteringProductionDemoSeeder', '--force']);

    const assignmentShiftStatus = options.assignmentShiftStatus ?? 'scheduled';

    runLaravelPhp(`
$timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
$at = fn (string $value) => \\Carbon\\Carbon::parse($value, $timezone)->utc();
$assignmentShiftStatus = ${JSON.stringify(assignmentShiftStatus)};

$publishWorkerId = \\App\\Models\\User::query()->where('email', 'roster-e2e-worker@demo.test')->value('id');
$candidateId = \\App\\Models\\User::query()->where('email', 'roster-e2e-candidate@demo.test')->value('id');
$frontlineId = \\App\\Models\\User::query()->where('email', 'roster-e2e-frontline@demo.test')->value('id');

\\App\\Models\\Shift::query()
    ->whereIn('id', [9101, 9201, 9202, 9301])
    ->update([
        'roster_period_id' => null,
        'published_at' => null,
        'publish_dirty_at' => null,
    ]);

\\App\\Models\\RosterPeriod::withTrashed()
    ->whereIn('site_id', [9001, 9002])
    ->whereIn('week_start', ['2026-05-04', '2026-05-11'])
    ->where('version', '>', 1)
    ->forceDelete();

\\App\\Models\\RosterPeriod::withTrashed()
    ->whereIn('site_id', [9001, 9002])
    ->whereIn('week_start', ['2026-05-04', '2026-05-11'])
    ->where('version', 1)
    ->get()
    ->each(function ($period): void {
        if ($period->trashed()) {
            $period->restore();
        }

        $period->forceFill([
            'status' => \\App\\Models\\RosterPeriod::STATUS_DRAFT,
            'published_at' => null,
            'published_by' => null,
            'locked_at' => null,
            'ready_at' => null,
            'validating_at' => null,
            'archived_at' => null,
            'archive_reason' => null,
            'snapshot' => null,
            'validation_summary' => null,
            'publish_meta' => null,
            'last_validated_at' => null,
        ])->save();
    });

\\App\\Models\\Shift::query()->whereKey(9101)->update([
    'user_id' => $publishWorkerId,
    'starts_at' => $at('2026-05-04 09:00'),
    'ends_at' => $at('2026-05-04 12:00'),
    'status' => 'scheduled',
    'published_at' => null,
    'publish_dirty_at' => null,
]);

\\App\\Models\\Shift::query()->whereKey(9201)->update([
    'user_id' => null,
    'starts_at' => $at('2026-05-11 10:00'),
    'ends_at' => $at('2026-05-11 13:00'),
    'status' => $assignmentShiftStatus,
    'published_at' => null,
    'publish_dirty_at' => null,
]);

\\App\\Models\\Shift::query()->whereKey(9202)->update([
    'user_id' => $candidateId,
    'starts_at' => $at('2026-05-12 15:00'),
    'ends_at' => $at('2026-05-12 18:00'),
    'status' => 'scheduled',
    'published_at' => null,
    'publish_dirty_at' => null,
]);

\\App\\Models\\Shift::query()->whereKey(9301)->update([
    'user_id' => $frontlineId,
    'starts_at' => $at('2026-05-07 09:00'),
    'ends_at' => $at('2026-05-07 12:00'),
    'status' => 'scheduled',
    'published_at' => null,
    'publish_dirty_at' => null,
]);

\\App\\Models\\TimelineEvent::query()
    ->where('shift_id', 9201)
    ->where('type', \\App\\Services\\ShiftTimelineService::ASSIGNED_EVENT_TYPE)
    ->delete();
`);
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
    const phpBin = resolvePhpBinary() ?? 'php';

    return execFileSync(phpBin, ['artisan', ...args], {
        cwd: process.cwd(),
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        shell: phpRequiresShell(phpBin),
    });
}

export function runLaravelPhp(code: string) {
    const phpBin = resolvePhpBinary() ?? 'php';
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
            shell: phpRequiresShell(phpBin),
        });
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
}

export function runLaravelJson<T>(code: string): T {
    return JSON.parse(runLaravelPhp(code)) as T;
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

export async function publishCurrentWeek(
    page: Page,
    target: { week: string; siteId: number } = ROSTERING_DEMO_PUBLISH_TARGET,
) {
    await page.goto(
        `/operations/rostering?week=${target.week}&site_id=${target.siteId}`,
    );

    await expect(page.getByTestId('rostering-publish-panel')).toBeVisible();
    await page.getByTestId('rostering-review-publish').click();

    await expect(page.getByTestId('publish-review-page')).toBeVisible();
    await expect(page.getByTestId('publish-review-confirm')).toBeEnabled();
    await page.getByTestId('publish-review-confirm').click();

    await expect(page).toHaveURL(/\/operations\/rostering(?:\?|$)/);
    await expect(page.getByTestId('rostering-publish-panel')).toContainText(
        /published/i,
    );
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
