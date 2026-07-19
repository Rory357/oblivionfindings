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

export function seedSecurityDevicesOperationsReadinessFixtures() {
    return runLaravelJson<{
        siteId: number;
        siteName: string;
        deviceId: number;
        deviceName: string;
    }>(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$tenantId = (int) ($admin->organization_id ?? 1);
$site = \\App\\Models\\Site::query()
    ->where('tenant_id', $tenantId)
    ->where('archived', false)
    ->orderBy('id')
    ->first();

if (! $site) {
    $site = \\App\\Models\\Site::factory()->create([
        'tenant_id' => $tenantId,
        'name' => 'Playwright Technology Site',
    ]);
}

$device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
    ->where('tenant_id', $tenantId)
    ->where('device_uid', 'PW-ESTATE-EDGE')
    ->first();

if (! $device) {
    $device = new \\App\\Domain\\SecurityDevices\\Models\\Device([
        'tenant_id' => $tenantId,
        'device_uid' => 'PW-ESTATE-EDGE',
    ]);
} elseif ($device->trashed()) {
    $device->restore();
}

$device->forceFill([
    'name' => 'Playwright SD-WAN edge',
    'domain' => 'it_infrastructure',
    'category' => 'network',
    'subcategory' => 'edge_router',
    'manufacturer' => 'Oblivion Demo',
    'model' => 'Native Edge',
    'status' => 'offline',
    'health_status' => 'critical',
    'last_seen_at' => now()->subMinutes(20),
    'provider' => 'oblivion_native',
])->save();

\\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
    ->where('device_id', $device->id)
    ->delete();
\\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
    'device_id' => $device->id,
    'assignable_type' => \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
    'assignable_id' => $site->id,
    'assigned_at' => now(),
]);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->firstOrCreate(
    ['tenant_id' => $tenantId, 'name' => 'Playwright native monitoring'],
    [
        'description' => 'Deterministic Security and Devices browser fixture',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);

\\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
    [
        'tenant_id' => $tenantId,
        'device_id' => $device->id,
        'name' => 'Playwright ICMP availability',
    ],
    [
        'profile_id' => $profile->id,
        'kind' => 'icmp',
        'target' => '192.0.2.10',
        'config' => [],
        'current_state' => 'failed',
        'pending_state' => null,
        'pending_count' => 0,
        'affects_availability' => true,
        'is_enabled' => true,
        'last_observation_at' => now(),
        'last_state_changed_at' => now(),
    ],
);

\\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::query()
    ->where('device_id', $device->id)
    ->where('source', 'playwright_task2')
    ->delete();
\\App\\Domain\\SecurityDevices\\Models\\DeviceEvent::create([
    'device_id' => $device->id,
    'event_type' => 'availability_failed',
    'severity' => 'critical',
    'source' => 'playwright_task2',
    'occurred_at' => now(),
]);

\\App\\Domain\\SecurityDevices\\Models\\DeviceMaintenanceRecord::query()->updateOrCreate(
    [
        'device_id' => $device->id,
        'type' => 'repair',
        'description' => 'Playwright overdue WAN recovery',
    ],
    [
        'status' => 'scheduled',
        'scheduled_for' => now()->subDay()->toDateString(),
    ],
);

echo json_encode([
    'siteId' => $site->id,
    'siteName' => $site->name,
    'deviceId' => $device->id,
    'deviceName' => $device->name,
]);
`);
}

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
