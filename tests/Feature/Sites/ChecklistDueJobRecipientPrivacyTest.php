<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\ChecklistDueJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\User;
use App\Notifications\ChecklistDueNotification;
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
function checklistReminderUser(
    Site $site,
    array $profileOverrides = [],
    bool $globalSiteAccess = false,
    bool $houseSiteTypeAccess = false,
    bool $denyRunPermission = false,
): User {
    $missingProfile = (bool) ($profileOverrides['missing_profile'] ?? false);
    unset($profileOverrides['missing_profile']);

    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

    $overrides = [];
    if ($globalSiteAccess) {
        $overrides[Permission::query()->where('key', 'sites.viewAll')->firstOrFail()->id] = ['allowed' => true];
    }
    if ($houseSiteTypeAccess) {
        $overrides[Permission::query()->where('key', 'sites.type.house.view')->firstOrFail()->id] = ['allowed' => true];
    }
    if ($denyRunPermission) {
        $overrides[Permission::query()->where('key', 'checklists.run')->firstOrFail()->id] = ['allowed' => false];
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

function checklistReminderTemplate(string $name = 'Medication room safety check'): SiteChecklistTemplate
{
    return SiteChecklistTemplate::query()->create([
        'key' => 'reminder_'.Str::lower(Str::random(12)),
        'name' => $name,
        'applicable_to_type' => 'house',
        'frequency' => 'daily',
        'is_active' => true,
    ]);
}

function checklistReminderRun(
    Site $site,
    SiteChecklistTemplate $template,
    ?User $assignmentAssignee,
    ?User $runAssignee = null,
    ?Carbon $scheduledDate = null,
    ?Site $assignmentSite = null,
    ?SiteChecklistTemplate $assignmentTemplate = null,
): SiteChecklistRun {
    $assignment = SiteChecklistAssignment::query()->create([
        'site_id' => ($assignmentSite ?? $site)->id,
        'template_id' => ($assignmentTemplate ?? $template)->id,
        'frequency' => 'daily',
        'assigned_to_user_id' => $assignmentAssignee?->id,
        'start_date' => today(),
        'is_active' => true,
    ]);

    return SiteChecklistRun::query()->create([
        'assignment_id' => $assignment->id,
        'site_id' => $site->id,
        'template_id' => $template->id,
        'assigned_to_user_id' => $runAssignee?->id,
        'scheduled_date' => $scheduledDate ?? today()->addDay(),
        'status' => 'scheduled',
    ]);
}

test('a run-level assignee receives the reminder instead of the stale assignment recipient', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house', 'name' => 'Kauri House']);
    $staleAssignmentUser = checklistReminderUser($site);
    $currentRunUser = checklistReminderUser($site);
    $template = checklistReminderTemplate();
    $run = checklistReminderRun($site, $template, $staleAssignmentUser, $currentRunUser);

    app()->call([app(ChecklistDueJob::class), 'handle']);

    Notification::assertNotSentTo($staleAssignmentUser, ChecklistDueNotification::class);
    Notification::assertSentTo(
        $currentRunUser,
        ChecklistDueNotification::class,
        fn (ChecklistDueNotification $notification): bool => $notification->toDatabase($currentRunUser) === [
            'title' => 'Due Tomorrow: Medication room safety check',
            'message' => 'Checklist at Kauri House is due tomorrow',
            'run_id' => $run->id,
            'site_id' => $site->id,
            'type' => 'checklist_due',
            'action_url' => "/sites/{$site->id}/checklists",
        ],
    );
});

test('assignment fallback and the explicit global Site bypass remain compatible', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house']);
    $remoteSite = Site::factory()->create(['type' => 'house']);
    $localFallback = checklistReminderUser($site);
    $globalFallback = checklistReminderUser($remoteSite, globalSiteAccess: true);
    $localTemplate = checklistReminderTemplate('Local fallback checklist');
    $globalTemplate = checklistReminderTemplate('Global fallback checklist');

    checklistReminderRun($site, $localTemplate, $localFallback);
    checklistReminderRun($site, $globalTemplate, $globalFallback);

    app()->call([app(ChecklistDueJob::class), 'handle']);

    Notification::assertSentToTimes($localFallback, ChecklistDueNotification::class, 1);
    Notification::assertSentToTimes($globalFallback, ChecklistDueNotification::class, 1);
});

test('an eligible type-scoped assignee receives the overdue notification before status advances', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house', 'name' => 'Totara House']);
    $recipient = checklistReminderUser($site, houseSiteTypeAccess: true);
    $run = checklistReminderRun(
        $site,
        checklistReminderTemplate('Overdue fire safety check'),
        $recipient,
        scheduledDate: today()->subDay(),
    );

    app()->call([app(ChecklistDueJob::class), 'handle']);

    Notification::assertSentTo(
        $recipient,
        ChecklistDueNotification::class,
        fn (ChecklistDueNotification $notification): bool => $notification->toDatabase($recipient) === [
            'title' => 'OVERDUE: Overdue fire safety check',
            'message' => 'Checklist at Totara House is overdue',
            'run_id' => $run->id,
            'site_id' => $site->id,
            'type' => 'checklist_overdue',
            'action_url' => "/checklists/runs/{$run->id}",
        ],
    );
    expect($run->refresh()->status)->toBe('overdue');
});

test('remote ended inactive missing-profile permission-revoked and noncanonical recipients are excluded', function () {
    Notification::fake();

    $site = Site::factory()->create(['type' => 'house']);
    $remoteSite = Site::factory()->create(['type' => 'house']);
    $remote = checklistReminderUser($remoteSite);
    $ended = checklistReminderUser($site, ['end_date' => today()->subDay()]);
    $inactive = checklistReminderUser($site, ['is_active' => false]);
    $missingProfile = checklistReminderUser($site, ['missing_profile' => true]);
    $permissionRevoked = checklistReminderUser($site, denyRunPermission: true);
    $noncanonical = checklistReminderUser($site);

    $overdue = checklistReminderRun(
        $site,
        checklistReminderTemplate('Remote overdue checklist'),
        $remote,
        scheduledDate: today()->subDay(),
    );
    checklistReminderRun($site, checklistReminderTemplate('Ended recipient checklist'), $ended);
    checklistReminderRun($site, checklistReminderTemplate('Inactive recipient checklist'), $inactive);
    checklistReminderRun($site, checklistReminderTemplate('Missing profile checklist'), $missingProfile);
    checklistReminderRun($site, checklistReminderTemplate('Permission revoked checklist'), $permissionRevoked);
    checklistReminderRun(
        $site,
        checklistReminderTemplate('Noncanonical run template'),
        $noncanonical,
        assignmentTemplate: checklistReminderTemplate('Noncanonical assignment template'),
    );

    app()->call([app(ChecklistDueJob::class), 'handle']);

    foreach ([$remote, $ended, $inactive, $missingProfile, $permissionRevoked, $noncanonical] as $excluded) {
        Notification::assertNotSentTo($excluded, ChecklistDueNotification::class);
    }

    expect($overdue->refresh()->status)->toBe('overdue');
});
