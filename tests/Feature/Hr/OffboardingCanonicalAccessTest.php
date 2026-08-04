<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create([
        'name' => 'Allowed Offboarding Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Offboarding Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $this->viewer = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    canonicalOffboardingProfile($this->viewer, $this->allowedSite, [
        'position_role' => 'hr',
        'position_title' => 'HR Manager',
    ]);

    $this->allowedEmployee = User::factory()->create(['approved_at' => now()]);
    $this->allowedProfile = canonicalOffboardingProfile($this->allowedEmployee, $this->allowedSite);
    $this->hiddenEmployee = User::factory()->create(['approved_at' => now()]);
    $this->hiddenProfile = canonicalOffboardingProfile($this->hiddenEmployee, $this->hiddenSite);
});

function canonicalOffboardingProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'position_role' => 'support_worker',
        'position_title' => 'Support Worker',
    ], $overrides));
}

function canonicalOffboardingChecklist(
    HrEmployeeProfile $profile,
    User $creator,
    array $overrides = [],
): HrOffboardingChecklist {
    return HrOffboardingChecklist::query()->create(array_merge([
        'employee_profile_id' => $profile->id,
        'template_key' => 'offboarding:canonical-test',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => today()->addWeek(),
        'created_by' => $creator->id,
    ], $overrides));
}

function canonicalOffboardingTask(HrOffboardingChecklist $checklist): HrOffboardingTask
{
    return HrOffboardingTask::query()->create([
        'offboarding_checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Revoke hidden access',
        'description' => 'Hidden task sentinel',
        'is_required' => true,
        'status' => 'pending',
        'sort_order' => 1,
    ]);
}

function canonicalExitInterview(
    HrEmployeeProfile $profile,
    User $interviewer,
    User $creator,
    string $comments = 'Visible interview notes',
): HrExitInterview {
    return HrExitInterview::query()->create([
        'employee_profile_id' => $profile->id,
        'interviewer_user_id' => $interviewer->id,
        'interview_date' => today(),
        'departure_reason' => 'career_growth',
        'additional_comments' => $comments,
        'created_by' => $creator->id,
    ]);
}

test('offboarding and exit-interview worklists use complete canonical Site provenance', function () {
    $this->allowedProfile->update([
        'bank_account' => '12-3456-7890123-00',
        'ird_number' => '123-456-789',
        'restricted_notes' => 'Never serialize canonical offboarding profile secrets.',
    ]);

    $allowedChecklist = canonicalOffboardingChecklist($this->allowedProfile, $this->viewer);
    $hiddenChecklist = canonicalOffboardingChecklist($this->hiddenProfile, $this->viewer);
    $eligibleEmployee = User::factory()->create(['approved_at' => now()]);
    $eligibleProfile = canonicalOffboardingProfile($eligibleEmployee, $this->allowedSite);

    $former = User::factory()->create(['approved_at' => null]);
    $formerProfile = canonicalOffboardingProfile($former, $this->allowedSite, [
        'is_active' => false,
        'end_date' => today()->subMonth(),
    ]);
    $formerChecklist = canonicalOffboardingChecklist($formerProfile, $this->viewer, [
        'status' => 'completed',
    ]);

    $mixed = User::factory()->create(['approved_at' => now()]);
    $mixedProfile = canonicalOffboardingProfile($mixed, $this->allowedSite, [
        'secondary_site_ids' => [$this->hiddenSite->id],
    ]);
    canonicalOffboardingChecklist($mixedProfile, $this->viewer);

    $allowedInterview = canonicalExitInterview($this->allowedProfile, $this->viewer, $this->viewer);
    canonicalExitInterview(
        $this->hiddenProfile,
        $this->viewer,
        $this->viewer,
        'Hidden interview sentinel',
    );

    $offboarding = $this->actingAs($this->viewer)->get('/hr/offboarding');
    $offboarding->assertOk();
    $offboardingRows = collect($offboarding->inertiaProps('checklists.data'));
    $allowedChecklistRow = $offboardingRows->firstWhere('id', $allowedChecklist->id);

    expect($offboardingRows->pluck('id')->all())
        ->toEqualCanonicalizing([$allowedChecklist->id, $formerChecklist->id])
        ->and($offboarding->inertiaProps('summary.total'))->toBe(2)
        ->and(collect($offboarding->inertiaProps('employees'))->pluck('id'))->toContain($eligibleProfile->id)
        ->not->toContain($this->allowedProfile->id)
        ->not->toContain($this->hiddenProfile->id)
        ->not->toContain($mixedProfile->id)
        ->not->toContain($formerProfile->id)
        ->and(collect($offboarding->inertiaProps('interviewers'))->pluck('id'))->toContain($this->viewer->id)
        ->not->toContain($this->hiddenEmployee->id)
        ->and(array_keys($allowedChecklistRow))->not->toContain(...$allowedChecklist->getHidden())
        ->and(array_keys($allowedChecklistRow['employee_profile']))
        ->not->toContain(...array_merge(
            $this->allowedProfile->getHidden(),
            [
                'bank_account',
                'ird_number',
                'restricted_notes',
                'personal_email',
                'personal_phone',
                'home_address',
                'hourly_rate',
                'annual_salary',
                'emergency_contacts',
            ],
        ));
    $offboarding->assertDontSee('Hidden Offboarding Site');
    $offboarding->assertDontSee('12-3456-7890123-00');
    $offboarding->assertDontSee('123-456-789');
    $offboarding->assertDontSee('Never serialize canonical offboarding profile secrets.');

    $interviews = $this->actingAs($this->viewer)->get('/hr/exit-interviews');
    $interviews->assertOk();
    $interviewRows = collect($interviews->inertiaProps('interviews.data'));
    $allowedInterviewRow = $interviewRows->firstWhere('id', $allowedInterview->id);

    expect($interviewRows->pluck('employee_profile_id'))
        ->toContain($this->allowedProfile->id)
        ->not->toContain($this->hiddenProfile->id)
        ->and($interviews->inertiaProps('stats.total'))->toBe(1)
        ->and(array_keys($allowedInterviewRow))->not->toContain(...array_merge(
            $allowedInterview->getHidden(),
            [
                'what_went_well',
                'what_could_improve',
                'management_feedback',
                'culture_feedback',
                'additional_comments',
                'created_by',
                'updated_at',
            ],
        ))
        ->and(array_keys($allowedInterviewRow['employee_profile']))
        ->not->toContain(...array_merge(
            $this->allowedProfile->getHidden(),
            [
                'bank_account',
                'ird_number',
                'restricted_notes',
                'personal_email',
                'personal_phone',
                'home_address',
                'hourly_rate',
                'annual_salary',
                'emergency_contacts',
            ],
        ));
    $interviews->assertDontSee('Hidden interview sentinel');
    $interviews->assertDontSee('Visible interview notes');

    $offboardingShow = $this->actingAs($this->viewer)->get("/hr/offboarding/{$allowedChecklist->id}");
    $offboardingShow->assertOk();
    expect(array_keys($offboardingShow->inertiaProps('checklist')))->not->toContain(...$allowedChecklist->getHidden())
        ->and(array_keys($offboardingShow->inertiaProps('checklist.employee_profile')))
        ->not->toContain(...array_merge(
            $this->allowedProfile->getHidden(),
            ['bank_account', 'ird_number', 'restricted_notes'],
        ));

    $interviewShow = $this->actingAs($this->viewer)->get("/hr/exit-interviews/{$allowedInterview->id}");
    $interviewShow->assertOk();
    expect(array_keys($interviewShow->inertiaProps('interview')))->not->toContain(...$allowedInterview->getHidden())
        ->and(array_keys($interviewShow->inertiaProps('interview.employee_profile')))
        ->not->toContain(...array_merge(
            $this->allowedProfile->getHidden(),
            ['bank_account', 'ird_number', 'restricted_notes'],
        ));
});

test('hidden offboarding and interview direct objects are concealed before mutation validation', function () {
    $hiddenChecklist = canonicalOffboardingChecklist($this->hiddenProfile, $this->viewer);
    $hiddenTask = canonicalOffboardingTask($hiddenChecklist);
    $hiddenInterview = canonicalExitInterview(
        $this->hiddenProfile,
        $this->viewer,
        $this->viewer,
        'Hidden interview sentinel',
    );

    $this->actingAs($this->viewer)
        ->get("/hr/offboarding/{$hiddenChecklist->id}")
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post("/hr/offboarding/{$hiddenChecklist->id}/status", [])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post("/hr/offboarding/tasks/{$hiddenTask->id}/complete", [])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->get("/hr/exit-interviews/{$hiddenInterview->id}")
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post("/hr/exit-interviews/{$hiddenInterview->id}/addenda", [])
        ->assertNotFound();

    expect($hiddenChecklist->fresh()->status)->toBe('in_progress')
        ->and($hiddenTask->fresh()->status)->toBe('pending')
        ->and($hiddenInterview->fresh()->additional_comments)->toBe('Hidden interview sentinel');
});

test('hidden profile and interviewer identifiers share the not-found path with missing identifiers', function () {
    foreach ([$this->hiddenProfile->id, 999999999] as $profileId) {
        $this->actingAs($this->viewer)
            ->post('/hr/offboarding', [
                'employee_profile_id' => $profileId,
                'end_date' => today()->addWeek()->toDateString(),
            ])
            ->assertNotFound();
    }

    foreach ([$this->hiddenEmployee->id, 999999999] as $interviewerId) {
        $this->actingAs($this->viewer)
            ->post('/hr/exit-interviews', [
                'employee_profile_id' => $this->allowedProfile->id,
                'interviewer_user_id' => $interviewerId,
                'interview_date' => today()->toDateString(),
                'departure_reason' => 'career_growth',
            ])
            ->assertNotFound();
    }

    expect(HrOffboardingChecklist::query()->count())->toBe(0)
        ->and(HrExitInterview::query()->count())->toBe(0);
});

test('required task ownership skips inaccessible and former role holders', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'approved_at' => now(),
    ]);
    canonicalOffboardingProfile($manager, $this->allowedSite);
    $this->allowedProfile->update(['manager_user_id' => $manager->id]);

    $hiddenRoleOwner = User::factory()->create([
        'role' => 'it_admin',
        'approved_at' => now(),
    ]);
    canonicalOffboardingProfile($hiddenRoleOwner, $this->hiddenSite);

    $formerRoleOwner = User::factory()->create([
        'role' => 'it_admin',
        'approved_at' => null,
    ]);
    canonicalOffboardingProfile($formerRoleOwner, $this->allowedSite, [
        'is_active' => false,
        'end_date' => today()->subMonth(),
    ]);

    $checklist = app(OnboardingService::class)->generateOffboardingChecklist(
        $this->allowedProfile,
        $this->viewer->id,
    );
    $itOwner = $checklist->tasks->firstWhere('assigned_to_role', 'it_admin');
    expect($itOwner?->assigned_to_user_id)->toBe($manager->id)
        ->and($checklist->tasks->where('is_required', true)->pluck('assigned_to_user_id'))
        ->not->toContain($hiddenRoleOwner->id)
        ->not->toContain($formerRoleOwner->id);

    $actorFallbackEmployee = User::factory()->create(['approved_at' => now()]);
    $actorFallbackProfile = canonicalOffboardingProfile($actorFallbackEmployee, $this->allowedSite);
    $actorFallback = app(OnboardingService::class)->generateOffboardingChecklist(
        $actorFallbackProfile,
        $this->viewer->id,
    );

    expect($actorFallback->tasks->where('is_required', true)->pluck('assigned_to_user_id')->unique()->all())
        ->toBe([$this->viewer->id]);
});
