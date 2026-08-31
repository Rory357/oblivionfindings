<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\HazardOverdueJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\HazardOverdueNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-08-31 09:00:00', 'Pacific/Auckland'));
    config(['app.worker_timezone' => 'Pacific/Auckland']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $profileOverrides
 */
function hazardReminderUser(
    Site $site,
    bool $healthSafetyOfficer = false,
    bool $globalHealthSafetyAccess = false,
    array $profileOverrides = [],
): User {
    $missingProfile = (bool) ($profileOverrides['missing_profile'] ?? false);
    unset($profileOverrides['missing_profile']);

    $user = User::factory()->create([
        'role' => $healthSafetyOfficer ? 'health_safety_officer' : 'support_worker',
        'approved_at' => now(),
    ]);

    if ($healthSafetyOfficer) {
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();
        $user->roles()->attach($role);

        if (! $globalHealthSafetyAccess) {
            $viewAll = Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail();
            $user->permissionOverrides()->syncWithoutDetaching([
                $viewAll->id => ['allowed' => false],
            ]);
        }
    }

    if (! $missingProfile) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            ...$profileOverrides,
        ]);
    }

    return $user->fresh(['roles', 'permissionOverrides', 'hrEmployeeProfile']);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hazardReminderHazard(Site $site, User $reporter, ?User $assigned, array $overrides = []): SiteHazard
{
    return SiteHazard::withoutEvents(fn (): SiteHazard => SiteHazard::query()->create([
        'site_id' => $site->id,
        'reference_number' => 'HAZ-PRIV-'.Str::upper(Str::random(8)),
        'reported_by_user_id' => $reporter->id,
        'assigned_to_user_id' => $assigned?->id,
        'hazard_type' => 'electrical',
        'severity' => 'high',
        'likelihood' => 'possible',
        'risk_rating' => 'high',
        'description' => 'Exposed wiring near the medication room',
        'status' => 'open',
        'due_date' => today()->subDay(),
        ...$overrides,
    ]));
}

test('overdue assigned and health and safety notifications stay within current canonical Site access', function () {
    Notification::fake();

    $hazardSite = Site::factory()->create(['type' => 'house', 'name' => 'Kauri House']);
    $remoteSite = Site::factory()->create(['type' => 'house', 'name' => 'Rimu House']);
    $reporter = hazardReminderUser($hazardSite);
    $localAssigned = hazardReminderUser($hazardSite);
    $remoteAssigned = hazardReminderUser($remoteSite);
    $localOfficer = hazardReminderUser($hazardSite, healthSafetyOfficer: true);
    $remoteOfficer = hazardReminderUser($remoteSite, healthSafetyOfficer: true);
    $endedOfficer = hazardReminderUser($hazardSite, healthSafetyOfficer: true, profileOverrides: [
        'end_date' => today()->subDay(),
    ]);
    $inactiveOfficer = hazardReminderUser($hazardSite, healthSafetyOfficer: true, profileOverrides: [
        'is_active' => false,
    ]);
    $missingProfileOfficer = hazardReminderUser($hazardSite, healthSafetyOfficer: true, profileOverrides: [
        'missing_profile' => true,
    ]);
    $globalOfficer = hazardReminderUser($remoteSite, healthSafetyOfficer: true, globalHealthSafetyAccess: true);
    $unrelatedLocalWorker = hazardReminderUser($hazardSite);

    $localHazard = hazardReminderHazard($hazardSite, $reporter, $localAssigned);
    $remoteAssignmentHazard = hazardReminderHazard($hazardSite, $reporter, $remoteAssigned, [
        'description' => 'Remote assignment must not disclose this hazard',
    ]);

    app()->call([app(HazardOverdueJob::class), 'handle']);

    Notification::assertSentTo(
        $localAssigned,
        HazardOverdueNotification::class,
        fn (HazardOverdueNotification $notification): bool => $notification->toDatabase($localAssigned)['type']
            === 'hazard_overdue',
    );
    Notification::assertNotSentTo($remoteAssigned, HazardOverdueNotification::class);
    Notification::assertSentToTimes($localOfficer, HazardOverdueNotification::class, 2);
    Notification::assertSentToTimes($globalOfficer, HazardOverdueNotification::class, 2);

    foreach ([$remoteOfficer, $endedOfficer, $inactiveOfficer, $missingProfileOfficer, $unrelatedLocalWorker] as $excluded) {
        Notification::assertNotSentTo($excluded, HazardOverdueNotification::class);
    }

    expect($localHazard->refresh()->overdue_notified_at)->not->toBeNull()
        ->and($remoteAssignmentHazard->refresh()->overdue_notified_at)->not->toBeNull();
});

test('upcoming assigned reminders exclude remote ended inactive and missing-profile workers', function () {
    Notification::fake();

    $hazardSite = Site::factory()->create(['type' => 'house']);
    $remoteSite = Site::factory()->create(['type' => 'house']);
    $reporter = hazardReminderUser($hazardSite);
    $local = hazardReminderUser($hazardSite);
    $remote = hazardReminderUser($remoteSite);
    $ended = hazardReminderUser($hazardSite, profileOverrides: ['end_date' => today()->subDay()]);
    $inactive = hazardReminderUser($hazardSite, profileOverrides: ['is_active' => false]);
    $missingProfile = hazardReminderUser($hazardSite, profileOverrides: ['missing_profile' => true]);

    $hazards = collect([$local, $remote, $ended, $inactive, $missingProfile])
        ->map(fn (User $assigned): SiteHazard => hazardReminderHazard($hazardSite, $reporter, $assigned, [
            'due_date' => today()->addDays(2),
        ]));

    app()->call([app(HazardOverdueJob::class), 'handle']);

    Notification::assertSentTo(
        $local,
        HazardOverdueNotification::class,
        fn (HazardOverdueNotification $notification): bool => $notification->toDatabase($local)['type']
            === 'hazard_warning',
    );

    foreach ([$remote, $ended, $inactive, $missingProfile] as $excluded) {
        Notification::assertNotSentTo($excluded, HazardOverdueNotification::class);
    }

    expect($hazards->every(fn (SiteHazard $hazard): bool => $hazard->refresh()->warning_sent_at !== null))->toBeTrue();
});
