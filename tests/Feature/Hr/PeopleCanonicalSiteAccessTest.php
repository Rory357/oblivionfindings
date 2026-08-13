<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Allowed People Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden People Site']);
    $this->viewer = User::factory()->create([
        'name' => 'Site HR Viewer',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->viewer->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $allSites = Permission::query()
        ->where('key', 'hr.employees.viewAllSites')
        ->firstOrFail();
    $this->viewer->permissionOverrides()->syncWithoutDetaching([
        $allSites->id => ['allowed' => false],
    ]);
    peopleReadProfile($this->viewer, $this->allowedSite, [
        'employee_number' => 'EMP-VIEWER',
        'employment_type' => 'contractor',
        'start_date' => now()->subYear()->toDateString(),
    ]);
});

function peopleReadProfile(User $user, ?Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PEOPLE-'.$user->id,
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

function peopleReadUser(string $name, ?Site $site, array $profileOverrides = [], array $userOverrides = []): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    peopleReadProfile($user, $site, $profileOverrides);

    return $user;
}

function peopleHierarchyNames(array $nodes): array
{
    return collect($nodes)->flatMap(function (array $node): array {
        return [$node['name'], ...peopleHierarchyNames($node['children'] ?? [])];
    })->values()->all();
}

function peopleHierarchyNode(array $nodes, string $name): ?array
{
    foreach ($nodes as $node) {
        if (($node['name'] ?? null) === $name) {
            return $node;
        }

        $match = peopleHierarchyNode($node['children'] ?? [], $name);
        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

test('people list search counts and site filters use one canonical historical Site boundary', function () {
    peopleReadUser('Allowed Current Person', $this->allowedSite);
    peopleReadUser('Allowed Former Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    peopleReadUser('Hidden Current Person', $this->hiddenSite, [
        'employment_type' => 'part_time',
    ]);
    peopleReadUser('Missing Provenance Person', null);
    User::factory()->create([
        'name' => 'Profileless Person',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/people');
    $response->assertOk();

    $names = collect($response->inertiaProps('profiles.data'))->pluck('user.name');
    expect($names)
        ->toContain('Site HR Viewer', 'Allowed Current Person', 'Allowed Former Person')
        ->not->toContain('Hidden Current Person', 'Missing Provenance Person', 'Profileless Person')
        ->and(collect($response->inertiaProps('sites'))->pluck('name')->all())
        ->toBe(['Allowed People Site'])
        ->and($response->inertiaProps('summary.active'))->toBe(2)
        ->and($response->inertiaProps('summary.inactive'))->toBe(1)
        ->and($response->inertiaProps('summary.type_counts'))
        ->toMatchArray(['contractor' => 1, 'full_time' => 1])
        ->not->toHaveKey('part_time');

    $former = $this->actingAs($this->viewer)->get('/hr/people?status=inactive');
    expect(collect($former->inertiaProps('profiles.data'))->pluck('user.name')->all())
        ->toBe(['Allowed Former Person']);

    $hiddenSearch = $this->actingAs($this->viewer)->get('/hr/people?q=Hidden%20Current');
    expect($hiddenSearch->inertiaProps('profiles.data'))->toBe([]);

    $forgedSite = $this->actingAs($this->viewer)
        ->get('/hr/people?site_id='.$this->hiddenSite->id);
    expect($forgedSite->inertiaProps('profiles.data'))->toBe([])
        ->and(collect($forgedSite->inertiaProps('sites'))->pluck('id'))
        ->not->toContain($this->hiddenSite->id);
});

test('people summary and triage conceal hidden Site names and counts', function () {
    $allowedProbation = peopleReadUser('Allowed Probation Person', $this->allowedSite, [
        'probation_end_date' => now()->addMonth()->toDateString(),
    ]);
    $hiddenProbation = peopleReadUser('Hidden Probation Person', $this->hiddenSite, [
        'probation_end_date' => now()->addMonth()->toDateString(),
    ]);
    peopleReadUser('Allowed Invite Person', $this->allowedSite, [], [
        'approved_at' => null,
        'last_login_at' => null,
    ]);
    peopleReadUser('Hidden Invite Person', $this->hiddenSite, [], [
        'approved_at' => null,
        'last_login_at' => null,
    ]);

    $requirement = HrComplianceRequirement::query()->create([
        'code' => 'PEOPLE-SITE-BOUNDARY',
        'name' => 'People Site Boundary Requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->viewer->id,
    ]);
    foreach ([$allowedProbation, $hiddenProbation] as $staff) {
        HrStaffComplianceStatus::query()->create([
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'expired',
            'expires_at' => now()->subDay()->toDateString(),
        ]);
    }

    $response = $this->actingAs($this->viewer)->get('/hr/people');
    $response->assertOk();

    expect($response->inertiaProps('summary.on_probation'))->toBe(1)
        ->and($response->inertiaProps('summary.pending_invites'))->toBe(1)
        ->and($response->inertiaProps('summary.compliance_alerts'))->toBe(1)
        ->and(collect($response->inertiaProps('triage.probation'))->pluck('name')->all())
        ->toBe(['Allowed Probation Person'])
        ->and(collect($response->inertiaProps('triage.invites'))->pluck('name')->all())
        ->toBe(['Allowed Invite Person'])
        ->and(collect($response->inertiaProps('triage.compliance'))->pluck('name')->all())
        ->toBe(['Allowed Probation Person']);
});

test('current manager pickers and org chart exclude non-current and hidden Site staff', function () {
    peopleReadUser('Allowed Current Manager', $this->allowedSite);
    peopleReadUser('Allowed Ended Manager', $this->allowedSite, [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    peopleReadUser('Allowed Future Manager', $this->allowedSite, [
        'start_date' => now()->addDay()->toDateString(),
    ]);
    peopleReadUser('Allowed Inactive Manager', $this->allowedSite, [
        'is_active' => false,
    ]);
    peopleReadUser('Allowed Unapproved Manager', $this->allowedSite, [], [
        'approved_at' => null,
    ]);
    peopleReadUser('Hidden Current Manager', $this->hiddenSite);
    User::factory()->create([
        'name' => 'Profileless Manager',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/people?tab=orgchart');
    $response->assertOk();

    $expected = ['Allowed Current Manager', 'Site HR Viewer'];
    $formManagers = collect($response->inertiaProps('formData.managers'))->pluck('label')->sort()->values()->all();
    $departmentManagers = collect($response->inertiaProps('departmentManagers'))->pluck('name')->sort()->values()->all();
    $orgPeople = collect($response->inertiaProps('orgPeople'))->pluck('name')->sort()->values()->all();
    $hierarchyNames = collect(peopleHierarchyNames($response->inertiaProps('orgHierarchy')))->sort()->values()->all();

    expect($formManagers)->toBe($expected)
        ->and($departmentManagers)->toBe($expected)
        ->and($orgPeople)->toBe($expected)
        ->and($hierarchyNames)->toBe($expected);
});

test('multi Site staff remain visible without serializing an inaccessible primary Site', function () {
    peopleReadUser('Allowed Secondary Person', $this->hiddenSite, [
        'secondary_site_ids' => [$this->allowedSite->id],
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/people?tab=orgchart');
    $response->assertOk();

    $row = collect($response->inertiaProps('profiles.data'))
        ->firstWhere('user.name', 'Allowed Secondary Person');
    $node = peopleHierarchyNode($response->inertiaProps('orgHierarchy'), 'Allowed Secondary Person');

    expect($row)->not->toBeNull()
        ->and($row['primary_site'])->toBeNull()
        ->and($node)->not->toBeNull()
        ->and($node['site'])->toBeNull()
        ->and(json_encode($response->inertiaProps()))
        ->not->toContain('Hidden People Site');

    $forged = $this->actingAs($this->viewer)
        ->get('/hr/people?site_id='.$this->hiddenSite->id);
    expect(collect($forged->inertiaProps('profiles.data'))->pluck('user.name'))
        ->not->toContain('Allowed Secondary Person');
});

test('hidden primary Site does not influence Site sorting for an allowed secondary Site employee', function () {
    $zuluAllowedSite = Site::factory()->create(['name' => 'Zulu Allowed People Site']);
    $viewerProfile = $this->viewer->hrEmployeeProfile;
    $viewerProfile->update(['secondary_site_ids' => [$zuluAllowedSite->id]]);

    peopleReadUser('Allowed Alpha Sort Person', $this->allowedSite);
    peopleReadUser('Allowed Zulu Sort Person', $zuluAllowedSite);
    peopleReadUser('Hidden Primary Sort Person', $this->hiddenSite, [
        'secondary_site_ids' => [$this->allowedSite->id],
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/people?sort=site&dir=asc');
    $response->assertOk();
    $names = collect($response->inertiaProps('profiles.data'))->pluck('user.name');

    expect($names->search('Hidden Primary Sort Person'))
        ->toBeLessThan($names->search('Allowed Alpha Sort Person'))
        ->and($names->search('Allowed Alpha Sort Person'))
        ->toBeLessThan($names->search('Allowed Zulu Sort Person'))
        ->and(json_encode($response->inertiaProps()))
        ->not->toContain('Hidden People Site');
});

test('application global configuration exposes only allowed Site relations and requisition counts', function () {
    $department = HrDepartment::query()->create([
        'name' => 'Application Department',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $department->sites()->attach([$this->allowedSite->id, $this->hiddenSite->id]);

    $position = HrPosition::query()->create([
        'title' => 'Site Count Position',
        'code' => 'SITE-COUNT',
        'employment_type' => 'full_time',
        'headcount_budget' => 2,
        'is_active' => true,
        'created_by' => $this->viewer->id,
    ]);
    peopleReadUser('Allowed Position Person', $this->allowedSite, ['position_id' => $position->id]);
    peopleReadUser('Hidden Position Person', $this->hiddenSite, ['position_id' => $position->id]);

    foreach ([
        [$this->allowedSite, 1, 'allowed-requisition'],
        [$this->hiddenSite, 2, 'hidden-requisition'],
    ] as [$site, $openings, $slug]) {
        HrJobRequisition::query()->create([
            'title' => str($slug)->headline(),
            'slug' => $slug,
            'position_id' => $position->id,
            'site_id' => $site->id,
            'employment_type' => 'full_time',
            'openings' => $openings,
            'status' => 'published',
            'description' => 'Scoped requisition',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
        ]);
    }

    $hiddenOnlyGap = HrPosition::query()->create([
        'title' => 'Hidden Requisition Gap',
        'code' => 'HIDDEN-GAP',
        'employment_type' => 'full_time',
        'headcount_budget' => 1,
        'is_active' => true,
        'created_by' => $this->viewer->id,
    ]);
    HrJobRequisition::query()->create([
        'title' => 'Hidden Gap Requisition',
        'slug' => 'hidden-gap-requisition',
        'position_id' => $hiddenOnlyGap->id,
        'site_id' => $this->hiddenSite->id,
        'employment_type' => 'full_time',
        'openings' => 1,
        'status' => 'published',
        'description' => 'Hidden scoped requisition',
        'created_by' => $this->viewer->id,
        'updated_by' => $this->viewer->id,
    ]);

    $response = $this->actingAs($this->viewer)->get('/hr/people?tab=positions');
    $response->assertOk();

    $departmentRow = collect($response->inertiaProps('departmentsPane.data'))
        ->firstWhere('name', 'Application Department');
    $positionRow = collect($response->inertiaProps('positions.data'))
        ->firstWhere('code', 'SITE-COUNT');

    expect(collect($departmentRow['sites'])->pluck('name')->all())
        ->toBe(['Allowed People Site'])
        ->and($positionRow['current_headcount'])->toBe(1)
        ->and($positionRow['open_requisition_openings'])->toBe(1)
        ->and($response->inertiaProps('summary.understaffed_positions'))->toBe(1);
});

test('soft deleted historical profiles remain inactive in rows filters and summary', function () {
    $former = peopleReadUser('Archived Former Person', $this->allowedSite, [
        'is_active' => false,
        'end_date' => now()->subMonth()->toDateString(),
    ]);
    $profile = $former->hrEmployeeProfile;
    $profile->delete();

    $response = $this->actingAs($this->viewer)->get('/hr/people');
    $response->assertOk();
    $row = collect($response->inertiaProps('profiles.data'))
        ->firstWhere('user.name', 'Archived Former Person');

    expect($row)->not->toBeNull()
        ->and($row['profile_id'])->toBe($profile->id)
        ->and($row['is_active'])->toBeFalse()
        ->and($response->inertiaProps('summary.active'))->toBe(1)
        ->and($response->inertiaProps('summary.inactive'))->toBe(1);

    $active = $this->actingAs($this->viewer)->get('/hr/people?status=active');
    $inactive = $this->actingAs($this->viewer)->get('/hr/people?status=inactive');
    expect(collect($active->inertiaProps('profiles.data'))->pluck('user.name'))
        ->not->toContain('Archived Former Person')
        ->and(collect($inactive->inertiaProps('profiles.data'))->pluck('user.name'))
        ->toContain('Archived Former Person');
});
