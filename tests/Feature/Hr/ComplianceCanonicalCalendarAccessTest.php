<?php

use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Notifications\ComplianceReminderNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

class EndingComplianceRenewalLockService extends PeopleMutationLockService
{
    public function __construct(private readonly int $targetProfileId) {}

    public function lock(iterable $userIds, iterable $profileIds = []): array
    {
        HrEmployeeProfile::query()->whereKey($this->targetProfileId)->update([
            'end_date' => now()->subDay()->toDateString(),
        ]);

        return parent::lock($userIds, $profileIds);
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->calendarAllowedSite = Site::factory()->create(['name' => 'Renewals Allowed Site']);
    $this->calendarHiddenSite = Site::factory()->create(['name' => 'Renewals Hidden Site']);
    $this->calendarViewer = complianceCalendarStaff(
        'Renewals Site Manager',
        $this->calendarAllowedSite,
        ['role' => 'hr'],
        ['position_role' => 'hr'],
    );
    $this->calendarViewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->calendarAllowed = complianceCalendarStaff('Allowed Renewal Worker', $this->calendarAllowedSite);
    $this->calendarHidden = complianceCalendarStaff('Hidden Renewal Worker', $this->calendarHiddenSite);
    $this->calendarEnded = complianceCalendarStaff(
        'Ended Renewal Worker',
        $this->calendarAllowedSite,
        [],
        ['end_date' => now()->subDay()->toDateString()],
    );
    $this->calendarRequirement = HrComplianceRequirement::query()->create([
        'code' => 'APPLICATION_RENEWAL',
        'name' => 'Application renewal requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->calendarViewer->id,
    ]);
});

function complianceCalendarStaff(
    string $name,
    Site $site,
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-RENEW-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);
    $user->setRelation('hrEmployeeProfile', $profile);

    return $user;
}

/** @return array{compliance:HrStaffComplianceStatus,vetting:StaffBackgroundCheck,driver:HrDriverEligibility} */
function complianceRenewalRecords(
    User $staff,
    HrComplianceRequirement $requirement,
    User $creator,
): array {
    return [
        'compliance' => HrStaffComplianceStatus::query()->create([
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'expiring_soon',
            'evidence_type' => 'manual',
            'expires_at' => now()->addDays(30)->toDateString(),
        ]),
        'vetting' => StaffBackgroundCheck::query()->create([
            'user_id' => $staff->id,
            'check_type' => 'police_check',
            'status' => 'renewal_due',
            'expires_at' => now()->addDays(31)->toDateString(),
            'created_by' => $creator->id,
        ]),
        'driver' => HrDriverEligibility::query()->create([
            'user_id' => $staff->id,
            'licence_number' => 'LIC-'.$staff->id,
            'licence_class' => '1',
            'licence_expires_at' => now()->addDays(32)->toDateString(),
            'status' => 'eligible',
            'created_by' => $creator->id,
        ]),
    ];
}

test('renewals calendar scopes every record type to current staff at accessible Sites', function () {
    $allowed = complianceRenewalRecords(
        $this->calendarAllowed,
        $this->calendarRequirement,
        $this->calendarViewer,
    );
    $hidden = complianceRenewalRecords(
        $this->calendarHidden,
        $this->calendarRequirement,
        $this->calendarViewer,
    );
    $ended = complianceRenewalRecords(
        $this->calendarEnded,
        $this->calendarRequirement,
        $this->calendarViewer,
    );

    $response = $this->actingAs($this->calendarViewer)
        ->get('/hr/compliance/calendar')
        ->assertOk();
    $ids = collect($response->inertiaProps('events'))->pluck('id')->all();

    expect($ids)->toContain(
        'compliance-'.$allowed['compliance']->id,
        'vetting-'.$allowed['vetting']->id,
        'driver-'.$allowed['driver']->id,
    )->not->toContain(
        'compliance-'.$hidden['compliance']->id,
        'vetting-'.$hidden['vetting']->id,
        'driver-'.$hidden['driver']->id,
        'compliance-'.$ended['compliance']->id,
        'vetting-'.$ended['vetting']->id,
        'driver-'.$ended['driver']->id,
    );
});

test('renewal reminders notify only current Site visible staff across all record types', function () {
    Notification::fake();
    $allowed = complianceRenewalRecords(
        $this->calendarAllowed,
        $this->calendarRequirement,
        $this->calendarViewer,
    );
    $hidden = complianceRenewalRecords(
        $this->calendarHidden,
        $this->calendarRequirement,
        $this->calendarViewer,
    );

    foreach ($allowed as $type => $record) {
        $this->actingAs($this->calendarViewer)
            ->post('/hr/compliance/renewals/remind', ['type' => $type, 'id' => $record->id])
            ->assertSessionHas('success');
    }
    foreach ($hidden as $type => $record) {
        $this->actingAs($this->calendarViewer)
            ->postJson('/hr/compliance/renewals/remind', ['type' => $type, 'id' => $record->id])
            ->assertNotFound();
    }

    Notification::assertSentToTimes($this->calendarAllowed, ComplianceReminderNotification::class, 3);
    Notification::assertNothingSentTo($this->calendarHidden);
});

test('snooze is durable and works for compliance vetting and driver renewals', function () {
    $records = complianceRenewalRecords(
        $this->calendarAllowed,
        $this->calendarRequirement,
        $this->calendarViewer,
    );

    foreach ($records as $type => $record) {
        $this->actingAs($this->calendarViewer)
            ->post('/hr/compliance/renewals/snooze', [
                'type' => $type,
                'id' => $record->id,
                'days' => 7,
            ])
            ->assertSessionHas('success');
    }
    expect(HrComplianceRenewalSnooze::query()
        ->where('snoozed_by', $this->calendarViewer->id)
        ->count())->toBe(3);

    $hiddenIds = $this->actingAs($this->calendarViewer)
        ->get('/hr/compliance/calendar')
        ->assertOk()
        ->inertiaProps('events');
    expect(collect($hiddenIds)->pluck('user_id'))->not->toContain($this->calendarAllowed->id);

    $this->travel(8)->days();
    $visibleIds = collect($this->actingAs($this->calendarViewer)
        ->get('/hr/compliance/calendar')
        ->assertOk()
        ->inertiaProps('events'))->pluck('id')->all();
    expect($visibleIds)->toContain(
        'compliance-'.$records['compliance']->id,
        'vetting-'.$records['vetting']->id,
        'driver-'.$records['driver']->id,
    );
});

test('renewal actions reauthorise the target after the shared People lock', function () {
    $records = complianceRenewalRecords(
        $this->calendarAllowed,
        $this->calendarRequirement,
        $this->calendarViewer,
    );
    $this->app->instance(
        PeopleMutationLockService::class,
        new EndingComplianceRenewalLockService($this->calendarAllowed->hrEmployeeProfile->id),
    );

    $this->actingAs($this->calendarViewer)
        ->postJson('/hr/compliance/renewals/snooze', [
            'type' => 'driver',
            'id' => $records['driver']->id,
            'days' => 7,
        ])
        ->assertNotFound();
    expect(HrComplianceRenewalSnooze::query()->count())->toBe(0);
});
