<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create([
        'name' => 'Allowed Onboarding Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Onboarding Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $this->viewer = canonicalOnboardingUser('Canonical Onboarding HR', $this->allowedSite, 'hr');
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->allowedOwner = canonicalOnboardingUser('Allowed Onboarding Owner', $this->allowedSite, 'team_lead');
    $this->hiddenOwner = canonicalOnboardingUser('Hidden Onboarding Owner', $this->hiddenSite, 'team_lead');

    $this->allowedJoiner = canonicalOnboardingJoiner('Allowed Future Joiner', $this->allowedSite);
    $this->hiddenJoiner = canonicalOnboardingJoiner('Hidden Future Joiner', $this->hiddenSite);
    $this->template = canonicalOnboardingTemplate();
});

function canonicalOnboardingUser(string $name, Site $site, string $role): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => $role,
        'position_title' => str($role)->replace('_', ' ')->title()->toString(),
        'is_active' => true,
        'start_date' => today()->subYear(),
        'end_date' => null,
    ]);

    return $user;
}

function canonicalOnboardingJoiner(string $name, Site $site, array $profile = []): HrEmployeeProfile
{
    $user = User::factory()->create([
        'name' => $name,
        'approved_at' => null,
    ]);

    return HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => 'support_worker',
        'position_title' => 'Support Worker',
        'is_active' => true,
        'start_date' => today()->addWeek(),
        'end_date' => null,
    ], $profile));
}

function canonicalOnboardingTemplate(array $tasks = []): HrOnboardingTemplate
{
    return HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => $tasks ?: [[
            'category' => 'it',
            'title' => 'Create governed account',
            'is_required' => true,
            'sort_order' => 1,
            'assigned_to_role' => 'team_lead',
        ]],
    ]);
}

function canonicalOnboardingChecklist(HrEmployeeProfile $profile, User $creator): HrOnboardingChecklist
{
    $checklist = HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => today()->addMonth(),
        'created_by' => $creator->id,
    ]);
    HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Canonical onboarding task',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
        'assigned_to_user_id' => $creator->id,
    ]);

    return $checklist;
}

test('onboarding worklists include Site-complete future joiners and exclude inaccessible provenance and private profile data', function () {
    $this->allowedJoiner->update([
        'bank_account' => '12-3456-7890123-00',
        'ird_number' => '123-456-789',
        'restricted_notes' => 'Never serialize onboarding profile secrets.',
    ]);
    $allowedChecklist = canonicalOnboardingChecklist($this->allowedJoiner, $this->viewer);
    canonicalOnboardingChecklist($this->hiddenJoiner, $this->hiddenOwner);

    $mixedJoiner = canonicalOnboardingJoiner('Mixed Onboarding Joiner', $this->allowedSite, [
        'secondary_site_ids' => [$this->hiddenSite->id],
    ]);
    canonicalOnboardingChecklist($mixedJoiner, $this->viewer);
    $eligible = canonicalOnboardingJoiner('Eligible Onboarding Joiner', $this->allowedSite);

    $response = $this->actingAs($this->viewer)->get('/hr/onboarding');
    $response->assertOk();

    expect(collect($response->inertiaProps('checklists.data'))->pluck('id')->all())
        ->toBe([$allowedChecklist->id])
        ->and($response->inertiaProps('summary.total'))->toBe(1)
        ->and(collect($response->inertiaProps('employees'))->pluck('id'))
        ->toContain($eligible->id)
        ->not->toContain($this->allowedJoiner->id, $this->hiddenJoiner->id, $mixedJoiner->id)
        ->and(collect($response->inertiaProps('owners'))->pluck('id'))
        ->toContain($this->viewer->id, $this->allowedOwner->id)
        ->not->toContain($this->hiddenOwner->id)
        ->and(collect($response->inertiaProps('newHireOptions.sites'))->pluck('id')->all())
        ->toBe([$this->allowedSite->id]);

    $payload = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
    $legacyPartitionKey = 'ten'.'ant'.'_id';
    expect($payload)
        ->not->toContain('Hidden Onboarding Site')
        ->not->toContain('12-3456-7890123-00')
        ->not->toContain('123-456-789')
        ->not->toContain('Never serialize onboarding profile secrets.')
        ->not->toContain($legacyPartitionKey);

    $drawer = $this->actingAs($this->viewer)->get('/hr/onboarding?drawer='.$allowedChecklist->id);
    $drawer->assertOk();
    expect($drawer->inertiaProps('drawerChecklist.id'))->toBe($allowedChecklist->id);
});

test('hidden onboarding direct objects and mixed bulk sets are concealed before mutation', function () {
    $allowedChecklist = canonicalOnboardingChecklist($this->allowedJoiner, $this->viewer);
    $hiddenChecklist = canonicalOnboardingChecklist($this->hiddenJoiner, $this->hiddenOwner);
    $hiddenTask = $hiddenChecklist->tasks()->firstOrFail();

    $this->actingAs($this->viewer)->get('/hr/onboarding/'.$hiddenChecklist->id)->assertNotFound();
    $this->actingAs($this->viewer)
        ->post('/hr/onboarding/'.$hiddenChecklist->id.'/status', [])
        ->assertNotFound();
    $this->actingAs($this->viewer)
        ->post('/hr/onboarding/tasks/'.$hiddenTask->id.'/complete', [])
        ->assertNotFound();

    $this->actingAs($this->viewer)
        ->post('/hr/onboarding/bulk', [
            'action' => 'archive',
            'checklist_ids' => [$allowedChecklist->id, $hiddenChecklist->id],
        ])
        ->assertNotFound();

    expect($allowedChecklist->fresh()->status)->toBe('in_progress')
        ->and($hiddenChecklist->fresh()->status)->toBe('in_progress');
});

test('hidden and missing joiner Site manager and owner identifiers share concealed paths', function () {
    foreach ([$this->hiddenJoiner->id, 999999999] as $profileId) {
        $this->actingAs($this->viewer)
            ->post('/hr/onboarding', [
                'employee_profile_id' => $profileId,
                'template_id' => $this->template->id,
            ])
            ->assertNotFound();
    }

    foreach ([$this->hiddenSite->id, 999999999] as $siteId) {
        $this->actingAs($this->viewer)
            ->post('/hr/onboarding', [
                'hire_mode' => 'new',
                'name' => 'Concealed Site Joiner',
                'email' => "concealed-site-{$siteId}@example.test",
                'primary_site_id' => $siteId,
                'template_id' => $this->template->id,
            ])
            ->assertNotFound();
    }

    foreach ([$this->hiddenOwner->id, 999999999] as $managerId) {
        $this->actingAs($this->viewer)
            ->post('/hr/onboarding', [
                'hire_mode' => 'new',
                'name' => 'Concealed Manager Joiner',
                'email' => "concealed-manager-{$managerId}@example.test",
                'primary_site_id' => $this->allowedSite->id,
                'manager_user_id' => $managerId,
                'template_id' => $this->template->id,
            ])
            ->assertNotFound();
    }

    $checklist = canonicalOnboardingChecklist($this->allowedJoiner, $this->viewer);
    foreach ([$this->hiddenOwner->id, 999999999] as $ownerId) {
        $this->actingAs($this->viewer)
            ->post('/hr/onboarding/'.$checklist->id.'/reassign', ['owner_id' => $ownerId])
            ->assertNotFound();
    }
    expect($checklist->fresh()->created_by)->toBe($this->viewer->id);
});

test('required template owners fail closed before checklist and IT work are written', function () {
    $profile = canonicalOnboardingJoiner('Ownerless Canonical Joiner', $this->allowedSite);
    $template = HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'house',
        'is_active' => true,
        'tasks' => [[
            'category' => 'it',
            'title' => 'Owner must be canonical',
            'is_required' => true,
            'sort_order' => 1,
            'assigned_to_user_id' => $this->hiddenOwner->id,
        ]],
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'template_id' => $template->id,
        ])
        ->assertSessionHasErrors('tasks');

    expect(HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->exists())->toBeFalse();
});

test('employee-owned tasks resolve to the Site-complete future joiner before login approval', function () {
    $profile = canonicalOnboardingJoiner('Employee Task Joiner', $this->allowedSite);
    $template = HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'facility',
        'is_active' => true,
        'tasks' => [[
            'category' => 'induction',
            'title' => 'Read the induction pack',
            'is_required' => true,
            'sort_order' => 1,
            'assigned_to_role' => 'employee',
        ]],
    ]);

    $this->actingAs($this->viewer)
        ->post('/hr/onboarding', [
            'employee_profile_id' => $profile->id,
            'template_id' => $template->id,
        ])
        ->assertRedirect();

    $checklist = HrOnboardingChecklist::query()
        ->where('employee_profile_id', $profile->id)
        ->firstOrFail();

    expect($checklist->tasks()->firstOrFail()->assigned_to_user_id)
        ->toBe($profile->user_id);
});
