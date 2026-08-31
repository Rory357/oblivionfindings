<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\InspectionDueJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Notifications\InspectionDueNotification;
use App\Services\Facility\FacilitySignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

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
function inspectionReminderUser(
    Site $site,
    array $profileOverrides = [],
    bool $globalSiteAccess = false,
    bool $denyChecklistView = false,
    bool $denyFacilityType = false,
): User {
    $missingProfile = (bool) ($profileOverrides['missing_profile'] ?? false);
    unset($profileOverrides['missing_profile']);

    $user = User::factory()->create([
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $user->roles()->attach(Role::query()->where('name', 'team_lead')->firstOrFail());

    $overrides = [];
    if ($globalSiteAccess) {
        $overrides[Permission::query()->where('key', 'sites.viewAll')->firstOrFail()->id] = ['allowed' => true];
    }
    if ($denyChecklistView) {
        $overrides[Permission::query()->where('key', 'checklists.view')->firstOrFail()->id] = ['allowed' => false];
    }
    if ($denyFacilityType) {
        $overrides[Permission::query()->where('key', 'sites.type.facility.view')->firstOrFail()->id] = ['allowed' => false];
    }
    if ($overrides !== []) {
        $user->permissionOverrides()->syncWithoutDetaching($overrides);
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

function inspectionReminderSchedule(
    Site $site,
    User $assigned,
    Carbon $dueDate,
    string $title,
): SiteInspectionSchedule {
    return SiteInspectionSchedule::query()->create([
        'site_id' => $site->id,
        'inspection_type' => 'fire_safety',
        'title' => $title,
        'description' => 'Inspect emergency lighting and fire exits',
        'frequency' => 'monthly',
        'first_due_date' => $dueDate,
        'next_due_date' => $dueDate,
        'assigned_to_user_id' => $assigned->id,
        'is_active' => true,
    ]);
}

test('eligible assigned users receive exact upcoming and overdue notifications', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house', 'name' => 'Kauri House']);
    $recipient = inspectionReminderUser($site);
    $upcoming = inspectionReminderSchedule($site, $recipient, today()->addWeek(), 'Monthly fire doors');
    $overdue = inspectionReminderSchedule($site, $recipient, today()->subDays(3), 'Emergency lighting');
    $expectedDaysOverdue = (int) $overdue->next_due_date->diffInDays(now());
    $signals = Mockery::mock(FacilitySignalService::class);
    $signals->shouldReceive('emitInspectionOverdue')
        ->once()
        ->withArgs(fn (SiteInspectionSchedule $schedule, int $days): bool => $schedule->is($overdue)
            && $days === $expectedDaysOverdue);

    app(InspectionDueJob::class)->handle($signals);

    Notification::assertSentToTimes($recipient, InspectionDueNotification::class, 2);
    Notification::assertSentTo(
        $recipient,
        InspectionDueNotification::class,
        fn (InspectionDueNotification $notification): bool => $notification->toDatabase($recipient) === [
            'title' => 'Due Soon: Monthly fire doors',
            'message' => 'Inspection at Kauri House is due soon',
            'schedule_id' => $upcoming->id,
            'site_id' => $site->id,
            'type' => 'inspection_due',
            'action_url' => "/sites/{$site->id}/inspections",
        ],
    );
    Notification::assertSentTo(
        $recipient,
        InspectionDueNotification::class,
        fn (InspectionDueNotification $notification): bool => $notification->toDatabase($recipient) === [
            'title' => 'OVERDUE: Emergency lighting',
            'message' => 'Inspection at Kauri House is overdue',
            'schedule_id' => $overdue->id,
            'site_id' => $site->id,
            'type' => 'inspection_overdue',
            'action_url' => "/sites/{$site->id}/inspections",
        ],
    );
});

test('upcoming notifications exclude recipients without current canonical Site visibility', function () {
    Notification::fake();

    $house = Site::factory()->create(['type' => 'house']);
    $facility = Site::factory()->create(['type' => 'facility']);
    $remoteSite = Site::factory()->create(['type' => 'house']);
    $remote = inspectionReminderUser($remoteSite);
    $ended = inspectionReminderUser($house, ['end_date' => today()->subDay()]);
    $inactive = inspectionReminderUser($house, ['is_active' => false]);
    $missingProfile = inspectionReminderUser($house, ['missing_profile' => true]);
    $permissionRevoked = inspectionReminderUser($house, denyChecklistView: true);
    $wrongType = inspectionReminderUser($facility, denyFacilityType: true);
    $global = inspectionReminderUser($remoteSite, globalSiteAccess: true);

    foreach ([$remote, $ended, $inactive, $missingProfile, $permissionRevoked] as $index => $recipient) {
        inspectionReminderSchedule($house, $recipient, today()->addDay(), "Restricted house inspection {$index}");
    }
    inspectionReminderSchedule($facility, $wrongType, today()->addDay(), 'Restricted facility inspection');
    inspectionReminderSchedule($house, $global, today()->addDay(), 'Global inspection access');
    $signals = Mockery::mock(FacilitySignalService::class);
    $signals->shouldNotReceive('emitInspectionOverdue');

    app(InspectionDueJob::class)->handle($signals);

    foreach ([$remote, $ended, $inactive, $missingProfile, $permissionRevoked, $wrongType] as $excluded) {
        Notification::assertNotSentTo($excluded, InspectionDueNotification::class);
    }
    Notification::assertSentToTimes($global, InspectionDueNotification::class, 1);
});

test('an overdue operational signal is emitted even when its recipient is no longer eligible', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house']);
    $remoteSite = Site::factory()->create(['type' => 'house']);
    $remote = inspectionReminderUser($remoteSite);
    $overdue = inspectionReminderSchedule($site, $remote, today()->subDays(9), 'Remote-assigned overdue inspection');
    $expectedDaysOverdue = (int) $overdue->next_due_date->diffInDays(now());
    $signals = Mockery::mock(FacilitySignalService::class);
    $signals->shouldReceive('emitInspectionOverdue')
        ->once()
        ->withArgs(fn (SiteInspectionSchedule $schedule, int $days): bool => $schedule->is($overdue)
            && $days === $expectedDaysOverdue);

    app(InspectionDueJob::class)->handle($signals);

    Notification::assertNotSentTo($remote, InspectionDueNotification::class);
});
