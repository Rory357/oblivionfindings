<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Notifications\EmployeeInviteNotification;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function peopleDirectLegacyPartitionKey(): string
{
    return 'ten'.'ant_id';
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed Direct People Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Direct People Site']);
    $this->manager = User::factory()->create([
        'name' => 'Direct People Manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $allSites = Permission::query()
        ->where('key', 'hr.employees.viewAllSites')
        ->firstOrFail();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $allSites->id => ['allowed' => false],
    ]);
    peopleDirectProfile($this->manager, $this->allowedSite, [
        'employee_number' => 'EMP-DIRECT-MANAGER',
        'position_role' => 'hr',
    ]);
});

function peopleDirectProfile(User $user, ?Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-DIRECT-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site?->id,
        'secondary_site_ids' => [],
        ...$overrides,
    ]);
}

function peopleDirectUser(string $name, ?Site $site, array $profileOverrides = [], array $userOverrides = []): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    peopleDirectProfile($user, $site, $profileOverrides);

    return $user;
}

test('allowed Site former profiles remain directly readable while hidden and missing provenance profiles are concealed', function () {
    $former = peopleDirectUser('Allowed Former Direct Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    $hidden = peopleDirectUser('Hidden Direct Person', $this->hiddenSite);
    $missing = peopleDirectUser('Missing Direct Person', null);

    $this->actingAs($this->manager)
        ->get('/hr/people/'.$former->hrEmployeeProfile->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/employees/show')
            ->where('profile.user.name', 'Allowed Former Direct Person'));

    $this->actingAs($this->manager)
        ->get('/hr/people/'.$former->hrEmployeeProfile->id.'/edit')
        ->assertOk();

    foreach ([$hidden, $missing] as $concealed) {
        $this->actingAs($this->manager)
            ->get('/hr/people/'.$concealed->hrEmployeeProfile->id)
            ->assertNotFound();
        $this->actingAs($this->manager)
            ->get('/hr/people/'.$concealed->hrEmployeeProfile->id.'/edit')
            ->assertNotFound();
    }
});

test('show and edit explicitly shape profiles and never serialize hidden Site data or storage markers', function () {
    $partitionKey = peopleDirectLegacyPartitionKey();
    $staff = peopleDirectUser('Allowed Secondary Direct Person', $this->hiddenSite, [
        'secondary_site_ids' => [$this->allowedSite->id],
        'bank_account' => '12-3456-7890123-00',
        'ird_number' => '123-456-789',
        'restricted_notes' => 'Never serialize raw restricted notes.',
    ]);
    $profile = $staff->hrEmployeeProfile;

    $show = $this->actingAs($this->manager)->get('/hr/people/'.$profile->id);
    $show->assertOk();
    expect($show->inertiaProps('profile.primary_site'))->toBeNull()
        ->and(json_encode($show->inertiaProps()))
        ->not->toContain('Hidden Direct People Site')
        ->not->toContain($partitionKey)
        ->not->toContain('12-3456-7890123-00')
        ->not->toContain('123-456-789')
        ->not->toContain('Never serialize raw restricted notes.');

    $edit = $this->actingAs($this->manager)->get('/hr/people/'.$profile->id.'/edit');
    $edit->assertOk();
    expect(array_keys($edit->inertiaProps('profile')))
        ->not->toContain($partitionKey, 'bank_account', 'ird_number', 'restricted_notes', 'created_by', 'updated_by')
        ->and(collect($edit->inertiaProps('sites'))->pluck('name')->all())
        ->toBe(['Allowed Direct People Site'])
        ->and(json_encode($edit->inertiaProps()))
        ->not->toContain('Hidden Direct People Site');
});

test('hidden and missing provenance profiles cannot be mutated or invited by direct URL', function () {
    Notification::fake();

    $hiddenActive = peopleDirectUser('Hidden Active Mutation Person', $this->hiddenSite);
    $hiddenFormer = peopleDirectUser('Hidden Former Mutation Person', $this->hiddenSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ], ['approved_at' => null]);
    $missing = peopleDirectUser('Missing Mutation Person', null);

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$hiddenActive->hrEmployeeProfile->id, ['notes' => 'hidden mutation'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->patch('/hr/people/'.$hiddenActive->hrEmployeeProfile->id.'/active', ['is_active' => false])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/people/'.$hiddenFormer->hrEmployeeProfile->id.'/rehire', [
            'start_date' => now()->toDateString(),
            'primary_site_id' => $this->hiddenSite->id,
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/people/'.$hiddenFormer->hrEmployeeProfile->id.'/invite')
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->patch('/hr/people/'.$missing->hrEmployeeProfile->id.'/active', ['is_active' => false])
        ->assertNotFound();

    expect($hiddenActive->hrEmployeeProfile->fresh()->notes)->toBeNull()
        ->and($hiddenActive->hrEmployeeProfile->fresh()->is_active)->toBeTrue()
        ->and($hiddenFormer->hrEmployeeProfile->fresh()->is_active)->toBeFalse()
        ->and($missing->hrEmployeeProfile->fresh()->is_active)->toBeTrue();
    Notification::assertNotSentTo($hiddenFormer, EmployeeInviteNotification::class);
});

test('invalid set-active payloads cannot distinguish a hidden profile from a missing profile', function () {
    $hidden = peopleDirectUser('Hidden Invalid Active Target', $this->hiddenSite)->hrEmployeeProfile;
    $missingId = HrEmployeeProfile::query()->max('id') + 1000;

    $hiddenResponse = $this->actingAs($this->manager)
        ->patch('/hr/people/'.$hidden->id.'/active', ['is_active' => 'not-a-boolean']);
    $missingResponse = $this->actingAs($this->manager)
        ->patch('/hr/people/'.$missingId.'/active', ['is_active' => 'not-a-boolean']);

    $hiddenResponse->assertNotFound();
    $missingResponse->assertNotFound();
    expect($hiddenResponse->getContent())->toBe($missingResponse->getContent())
        ->and($hidden->fresh()->is_active)->toBeTrue();
});

test('a profile touching an inaccessible Site is readable through an allowed assignment but cannot be mutated', function () {
    $staff = peopleDirectUser('Mixed Site Direct Person', $this->hiddenSite, [
        'secondary_site_ids' => [$this->allowedSite->id],
    ]);
    $profile = $staff->hrEmployeeProfile;

    $this->actingAs($this->manager)
        ->get('/hr/people/'.$profile->id)
        ->assertOk();
    $this->actingAs($this->manager)
        ->put('/hr/people/'.$profile->id, ['notes' => 'must not cross the hidden Site'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->patch('/hr/people/'.$profile->id.'/active', ['is_active' => false])
        ->assertNotFound();

    expect($profile->fresh()->notes)->toBeNull()
        ->and($profile->fresh()->is_active)->toBeTrue();
});

test('submitted Site and manager IDs must be canonical accessible choices', function () {
    $target = peopleDirectUser('Allowed Update Target', $this->allowedSite);
    $hiddenManager = peopleDirectUser('Hidden Submitted Manager', $this->hiddenSite);

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$target->hrEmployeeProfile->id, [
            'primary_site_id' => $this->hiddenSite->id,
        ])
        ->assertInvalid('primary_site_id');
    $this->actingAs($this->manager)
        ->put('/hr/people/'.$target->hrEmployeeProfile->id, [
            'secondary_site_ids' => [$this->hiddenSite->id],
        ])
        ->assertInvalid('secondary_site_ids.0');

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Hidden Site New Employee',
            'email' => 'hidden-site-new-employee@example.test',
            'role' => 'support_worker',
            'primary_site_id' => $this->hiddenSite->id,
        ])
        ->assertInvalid('primary_site_id');
    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Hidden Manager New Employee',
            'email' => 'hidden-manager-new-employee@example.test',
            'role' => 'support_worker',
            'primary_site_id' => $this->allowedSite->id,
            'manager_user_id' => $hiddenManager->id,
        ])
        ->assertInvalid('manager_user_id');

    expect(User::query()->whereIn('email', [
        'hidden-site-new-employee@example.test',
        'hidden-manager-new-employee@example.test',
    ])->exists())->toBeFalse();
});

test('employee creation requires accessible Site provenance', function () {
    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Missing Provenance New Employee',
            'email' => 'missing-provenance-new-employee@example.test',
            'role' => 'support_worker',
        ])
        ->assertInvalid('primary_site_id');

    expect(User::query()->where('email', 'missing-provenance-new-employee@example.test')->exists())
        ->toBeFalse();
});

test('employee intake cannot link or overwrite an existing hidden Site profile by email', function () {
    $hidden = peopleDirectUser('Hidden Existing Intake Person', $this->hiddenSite);
    $profile = $hidden->hrEmployeeProfile;
    $originalTitle = $profile->position_title;

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Hidden Existing Intake Person',
            'email' => $hidden->email,
            'role' => 'support_worker',
            'position_title' => 'Hidden Overwrite Attempt',
            'primary_site_id' => $this->allowedSite->id,
            'link_existing' => true,
        ])
        ->assertNotFound();

    expect($profile->fresh()->position_title)->toBe($originalTitle)
        ->and($profile->fresh()->primary_site_id)->toBe($this->hiddenSite->id);
});

test('employee intake rejects profileless privileged external and unproven existing accounts', function () {
    $accounts = [
        ['Profileless Disabled Admin', 'admin'],
        ['Profileless Disabled Client', 'client'],
        ['Profileless Disabled External', 'next_of_kin'],
        ['Profileless Disabled Unproven', 'support_worker'],
    ];

    foreach ($accounts as [$name, $roleName]) {
        $account = User::factory()->create([
            'name' => $name,
            'email' => str($name)->slug().'@example.test',
            'role' => $roleName,
            'approved_at' => null,
        ]);
        if ($role = Role::query()->where('name', $roleName)->first()) {
            $account->roles()->syncWithoutDetaching([$role->id]);
        }

        $this->actingAs($this->manager)
            ->post('/hr/people', [
                'name' => $name,
                'email' => $account->email,
                'role' => 'support_worker',
                'primary_site_id' => $this->allowedSite->id,
                'link_existing' => true,
            ])
            ->assertInvalid('email');

        expect($account->fresh()->approved_at)->toBeNull()
            ->and($account->fresh()->role)->toBe($roleName)
            ->and(HrEmployeeProfile::query()->where('user_id', $account->id)->exists())->toBeFalse();
    }
});

test('employee intake can link a profileless account only through an accepted offer at an accessible Site', function () {
    $account = User::factory()->create([
        'name' => 'Accepted Candidate Existing Account',
        'email' => 'accepted-candidate-existing@example.test',
        'role' => null,
        'approved_at' => null,
    ]);
    $candidate = HrCandidate::factory()->create([
        'personal_email' => $account->email,
        'status' => 'offer_accepted',
    ]);
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'target_site_id' => $this->allowedSite->id,
        'status' => 'offer_accepted',
    ]);
    HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $this->allowedSite->id,
        'approval_status' => 'approved',
        'response' => 'accepted',
        'response_at' => now(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => $account->name,
            'email' => $account->email,
            'role' => 'support_worker',
            'primary_site_id' => $this->allowedSite->id,
        ])
        ->assertRedirect();

    expect(HrEmployeeProfile::query()->where('user_id', $account->id)->exists())->toBeTrue()
        ->and($account->fresh()->approved_at)->not->toBeNull()
        ->and($account->fresh()->role)->toBe('support_worker');
});

test('employee intake rejects an accepted-offer account with a direct permission override', function () {
    $account = User::factory()->create([
        'name' => 'Privileged Accepted Candidate',
        'email' => 'privileged-accepted-candidate@example.test',
        'role' => null,
        'approved_at' => null,
    ]);
    $account->permissionOverrides()->attach(
        Permission::query()->where('key', 'settings.access.manage')->firstOrFail()->id,
        ['allowed' => true],
    );
    $candidate = HrCandidate::factory()->create([
        'personal_email' => $account->email,
        'status' => 'offer_accepted',
    ]);
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'target_site_id' => $this->allowedSite->id,
        'status' => 'offer_accepted',
    ]);
    HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $this->allowedSite->id,
        'approval_status' => 'approved',
        'response' => 'accepted',
        'response_at' => now(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => $account->name,
            'email' => $account->email,
            'role' => 'support_worker',
            'primary_site_id' => $this->allowedSite->id,
        ])
        ->assertInvalid('email');

    expect($account->fresh()->approved_at)->toBeNull()
        ->and(HrEmployeeProfile::query()->where('user_id', $account->id)->exists())->toBeFalse()
        ->and($account->fresh()->canDo('settings.access.manage'))->toBeTrue();
});

test('employee intake rejects every unrelated existing RBAC role even when the legacy role matches', function () {
    $requestedRole = Role::query()->where('name', 'support_worker')->firstOrFail();
    $incompatibleRoles = Role::query()
        ->where('name', '!=', 'support_worker')
        ->orderBy('id')
        ->get();

    foreach ($incompatibleRoles as $incompatibleRole) {
        $account = User::factory()->create([
            'name' => 'Pivot Guard '.$incompatibleRole->name,
            'email' => 'pivot-guard-'.$incompatibleRole->name.'@example.test',
            'role' => 'support_worker',
            'approved_at' => null,
        ]);
        $account->roles()->sync([$requestedRole->id, $incompatibleRole->id]);

        expect(fn () => app(EmployeeIntakeService::class)->intake(
            name: $account->name,
            email: $account->email,
            roleName: 'support_worker',
            profileAttributes: [
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'primary_site_id' => $this->allowedSite->id,
                'start_date' => now()->toDateString(),
            ],
            actorId: $this->manager->id,
            startOnboarding: false,
            sendInvite: false,
            authorizedExistingUserId: $account->id,
        ))->toThrow(InvalidArgumentException::class, 'cannot be linked');

        expect($account->fresh()->approved_at)->toBeNull()
            ->and(HrEmployeeProfile::query()->where('user_id', $account->id)->exists())->toBeFalse();
    }
});

test('an accepted offer cannot be replayed into a different accessible Site', function () {
    $otherAllowedSite = Site::factory()->create(['name' => 'Other Allowed Offer Site']);
    $this->manager->hrEmployeeProfile->update([
        'secondary_site_ids' => [$otherAllowedSite->id],
    ]);
    $account = User::factory()->create([
        'name' => 'Cross Site Accepted Candidate',
        'email' => 'cross-site-accepted-candidate@example.test',
        'role' => null,
        'approved_at' => null,
    ]);
    $candidate = HrCandidate::factory()->create([
        'personal_email' => $account->email,
        'status' => 'offer_accepted',
    ]);
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
        'target_site_id' => $this->allowedSite->id,
        'status' => 'offer_accepted',
    ]);
    HrOffer::query()->create([
        'application_id' => $application->id,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'proposed_start_date' => now()->addWeek()->toDateString(),
        'employment_type' => 'full_time',
        'primary_site_id' => $this->allowedSite->id,
        'approval_status' => 'approved',
        'response' => 'accepted',
        'response_at' => now(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => $account->name,
            'email' => $account->email,
            'role' => 'support_worker',
            'primary_site_id' => $otherAllowedSite->id,
        ])
        ->assertInvalid('email');

    expect(HrEmployeeProfile::query()->where('user_id', $account->id)->exists())->toBeFalse()
        ->and($account->fresh()->approved_at)->toBeNull();
});

test('soft deleted former profiles are readable but only the rehire path can restore them', function () {
    $former = peopleDirectUser('Archived Rehire Direct Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ], ['approved_at' => null]);
    $profile = $former->hrEmployeeProfile;
    $profile->delete();

    $this->actingAs($this->manager)
        ->get('/hr/people/'.$profile->id)
        ->assertOk();
    $this->actingAs($this->manager)
        ->get('/hr/people/'.$profile->id.'/edit')
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->put('/hr/people/'.$profile->id, ['notes' => 'ordinary archived edit'])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/people/'.$profile->id.'/rehire', [
            'start_date' => now()->toDateString(),
            'send_invite' => false,
            'start_onboarding' => false,
        ])
        ->assertSessionHas('success');

    $restored = HrEmployeeProfile::withTrashed()->findOrFail($profile->id);
    expect($restored->trashed())->toBeFalse()
        ->and($restored->is_active)->toBeTrue();
});

test('store derives department identity from an accessible canonical department ID', function () {
    $department = HrDepartment::query()->create([
        'name' => 'Accessible Intake Department',
        'is_active' => true,
    ]);
    $department->sites()->attach($this->allowedSite->id);

    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Department Intake Person',
            'email' => 'department-intake-person@example.test',
            'role' => 'support_worker',
            'primary_site_id' => $this->allowedSite->id,
            'department_id' => $department->id,
        ])
        ->assertRedirect();

    $profile = User::query()->where('email', 'department-intake-person@example.test')
        ->firstOrFail()
        ->hrEmployeeProfile;
    expect($profile->department_id)->toBe($department->id)
        ->and($profile->department)->toBe('Accessible Intake Department');

    $hiddenDepartment = HrDepartment::query()->create([
        'name' => 'Hidden Intake Department',
        'is_active' => true,
    ]);
    $hiddenDepartment->sites()->attach($this->hiddenSite->id);
    $this->actingAs($this->manager)
        ->post('/hr/people', [
            'name' => 'Hidden Department Intake Person',
            'email' => 'hidden-department-intake-person@example.test',
            'role' => 'support_worker',
            'primary_site_id' => $this->allowedSite->id,
            'department_id' => $hiddenDepartment->id,
        ])
        ->assertInvalid('department_id');
});

test('financial and emergency edit fields persist through one canonical request contract', function () {
    $target = peopleDirectUser('Canonical Edit Contract Person', $this->allowedSite)->hrEmployeeProfile;

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$target->id, [
            'hourly_rate' => 42.5,
            'pay_frequency' => 'fortnightly',
            'emergency_contacts' => [[
                'name' => 'Aroha Contact',
                'relationship' => 'Whānau',
                'phone' => '021 555 0101',
            ]],
        ])
        ->assertSessionHasNoErrors();

    $fresh = $target->fresh();
    expect((float) $fresh->hourly_rate)->toBe(42.5)
        ->and($fresh->pay_frequency)->toBe('fortnightly')
        ->and($fresh->emergency_contacts)->toEqual([[
            'name' => 'Aroha Contact',
            'relationship' => 'Whānau',
            'phone' => '021 555 0101',
        ]]);
});

test('employee managers without financial access cannot read or write pay fields', function () {
    $financialPermission = Permission::query()->where('key', 'hr.employees.viewFinancial')->firstOrFail();
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        $financialPermission->id => ['allowed' => false],
    ]);
    $target = peopleDirectUser('Financially Restricted Edit Person', $this->allowedSite, [
        'hourly_rate' => 35,
        'pay_frequency' => 'weekly',
    ])->hrEmployeeProfile;

    $edit = $this->actingAs($this->manager)->get('/hr/people/'.$target->id.'/edit');
    $edit->assertOk();
    expect($edit->inertiaProps('profile'))->not->toHaveKeys(['hourly_rate', 'annual_salary', 'pay_frequency']);

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$target->id, [
            'hourly_rate' => 99,
            'pay_frequency' => 'monthly',
        ])
        ->assertInvalid(['hourly_rate', 'pay_frequency']);

    expect((float) $target->fresh()->hourly_rate)->toBe(35.0)
        ->and($target->fresh()->pay_frequency)->toBe('weekly');
});

test('historical employment entries expose approved fields only', function () {
    $partitionKey = peopleDirectLegacyPartitionKey();
    $former = peopleDirectUser('Historical Shape Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
        'employment_history' => [[
            'start_date' => '2023-01-01',
            'end_date' => '2024-01-01',
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'archived_at' => now()->subMonth()->toIso8601String(),
            'bank_account' => 'hidden-history-bank-account',
            $partitionKey => 999,
        ]],
    ]);

    $response = $this->actingAs($this->manager)
        ->get('/hr/people/'.$former->hrEmployeeProfile->id);
    $response->assertOk();
    expect($response->inertiaProps('profile.employment_history.0'))
        ->toHaveKeys(['start_date', 'end_date', 'position_title', 'position_role', 'employment_type', 'archived_at'])
        ->not->toHaveKeys(['bank_account', $partitionKey]);
});

test('mixed allowed and hidden bulk IDs fail atomically with zero mutation', function () {
    $allowed = peopleDirectUser('Allowed Bulk Atomic Person', $this->allowedSite)->hrEmployeeProfile;
    $hidden = peopleDirectUser('Hidden Bulk Atomic Person', $this->hiddenSite)->hrEmployeeProfile;

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$allowed->id, $hidden->id],
        ])
        ->assertNotFound();

    expect($allowed->fresh()->is_active)->toBeTrue()
        ->and($hidden->fresh()->is_active)->toBeTrue();
});

test('bulk actions reject archived profiles atomically and never restore or mutate them', function () {
    $active = peopleDirectUser('Active Bulk Archive Guard', $this->allowedSite)->hrEmployeeProfile;
    $archived = peopleDirectUser('Archived Bulk Archive Guard', $this->allowedSite)->hrEmployeeProfile;
    $archived->delete();

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$active->id, $archived->id],
        ])
        ->assertNotFound();

    expect($active->fresh()->is_active)->toBeTrue()
        ->and(HrEmployeeProfile::withTrashed()->findOrFail($archived->id)->trashed())->toBeTrue()
        ->and(HrEmployeeProfile::withTrashed()->findOrFail($archived->id)->is_active)->toBeTrue();

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'assign_site',
            'ids' => [$archived->id],
            'site_id' => $this->hiddenSite->id,
        ])
        ->assertNotFound();

    expect(HrEmployeeProfile::withTrashed()->findOrFail($archived->id)->primary_site_id)
        ->toBe($this->allowedSite->id);
});

test('a permissioned actor with an archived employee profile has no Site access', function () {
    $target = peopleDirectUser('Archived Actor Hidden Target', $this->allowedSite)->hrEmployeeProfile;
    $actorProfile = $this->manager->hrEmployeeProfile;
    $actorProfile->delete();

    $response = $this->actingAs($this->manager)->get('/hr/people');
    $response->assertOk();
    expect(collect($response->inertiaProps('employees.data'))->pluck('id'))->not->toContain($target->id);

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'deactivate',
            'ids' => [$target->id],
        ])
        ->assertNotFound();

    expect($target->fresh()->is_active)->toBeTrue();
});

test('bulk destination Site manager and department choices cannot cross the canonical Site boundary', function () {
    $target = peopleDirectUser('Allowed Bulk Destination Person', $this->allowedSite)->hrEmployeeProfile;
    $hiddenManager = peopleDirectUser('Hidden Bulk Destination Manager', $this->hiddenSite);
    $hiddenDepartment = HrDepartment::query()->create([
        'name' => 'Hidden Operational Department',
        'is_active' => true,
    ]);
    $hiddenDepartment->sites()->attach($this->hiddenSite->id);

    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'assign_site',
            'ids' => [$target->id],
            'site_id' => $this->hiddenSite->id,
        ])
        ->assertInvalid('site_id');
    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'assign_manager',
            'ids' => [$target->id],
            'manager_user_id' => $hiddenManager->id,
        ])
        ->assertInvalid('manager_user_id');
    $this->actingAs($this->manager)
        ->post('/hr/people/bulk', [
            'action' => 'assign_department',
            'ids' => [$target->id],
            'department_id' => $hiddenDepartment->id,
        ])
        ->assertInvalid('department_id');

    expect($target->fresh()->primary_site_id)->toBe($this->allowedSite->id)
        ->and($target->fresh()->manager_user_id)->toBeNull()
        ->and($target->fresh()->department_id)->toBeNull();
});

test('rehire options and submitted Site are restricted to accessible Sites', function () {
    $former = peopleDirectUser('Allowed Rehire Direct Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    $profile = $former->hrEmployeeProfile;

    $show = $this->actingAs($this->manager)->get('/hr/people/'.$profile->id);
    expect(collect($show->inertiaProps('rehireSites'))->pluck('name')->all())
        ->toBe(['Allowed Direct People Site']);

    $this->actingAs($this->manager)
        ->post('/hr/people/'.$profile->id.'/rehire', [
            'start_date' => now()->toDateString(),
            'primary_site_id' => $this->hiddenSite->id,
        ])
        ->assertInvalid('primary_site_id');

    expect($profile->fresh()->is_active)->toBeFalse()
        ->and($profile->fresh()->primary_site_id)->toBe($this->allowedSite->id);
});
