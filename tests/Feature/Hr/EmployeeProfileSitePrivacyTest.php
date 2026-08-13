<?php

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->siteA = Site::factory()->create(['name' => 'Privacy Site A']);
    $this->siteB = Site::factory()->create(['name' => 'Privacy Site B']);

    $this->local = employeePrivacyUser('Local Privacy Worker', $this->siteA, [
        'notes' => 'LOCAL-RESTRICTED-NOTES',
        'emergency_contacts' => [[
            'name' => 'Local Emergency Contact',
            'relationship' => 'Whānau',
            'phone' => '021 555 0001',
        ]],
    ]);
    $this->foreign = employeePrivacyUser('Foreign Privacy Worker', $this->siteB, [
        'notes' => 'FOREIGN-RESTRICTED-NOTES',
    ]);
    $this->secondary = employeePrivacyUser('Secondary Privacy Worker', $this->siteB, [
        'secondary_site_ids' => [$this->siteA->id],
    ]);
    $this->unproven = employeePrivacyUser('Unproven Privacy Worker', null);

    HrLeaveBalance::factory()->create([
        'user_id' => $this->local->id,
        'leave_type' => 'annual',
        'year' => now()->year,
        'balance_hours' => 64,
    ]);
    HrDocument::factory()->create([
        'employee_profile_id' => $this->local->hrEmployeeProfile->id,
        'title' => 'Local private contract',
    ]);
    HrPerformanceImprovementPlan::query()->create([
        'employee_user_id' => $this->local->id,
        'manager_user_id' => $this->local->id,
        'title' => 'Permission bounded performance plan',
        'reason' => 'Private performance reason',
        'expectations' => 'Complete agreed actions.',
        'start_date' => now()->subWeek()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'active',
        'created_by' => $this->local->id,
    ]);
});

function employeePrivacyUser(
    string $name,
    ?Site $primarySite,
    array $profileOverrides = [],
    ?string $role = 'support_worker',
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-private@example.test',
        'role' => $role,
        'approved_at' => now(),
    ]);
    if ($role !== null) {
        $user->roles()->sync([
            Role::query()->where('name', $role)->firstOrFail()->id,
        ]);
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-PRIVACY-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'work_phone' => '09 555 '.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
        'position_title' => 'Support Worker',
        'position_role' => $role ?? 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $primarySite?->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);

    return $user->fresh()->load('hrEmployeeProfile');
}

function employeePrivacyActor(string $role, Site $site): User
{
    return employeePrivacyUser(str($role)->headline().' Privacy Actor', $site, [
        'position_role' => $role,
    ], $role);
}

test('employee all-sites permission is explicit for central roles only', function (): void {
    foreach (['admin', 'hr', 'auditor'] as $role) {
        expect(employeePrivacyActor($role, $this->siteA)->canDo('hr.employees.viewAllSites'))
            ->toBeTrue();
    }

    foreach (['coordinator', 'team_lead', 'support_worker'] as $role) {
        expect(employeePrivacyActor($role, $this->siteA)->canDo('hr.employees.viewAllSites'))
            ->toBeFalse();
    }
});

test('site-context roles isolate people lists counts search direct IDs and API reads', function (string $role): void {
    $actor = employeePrivacyActor($role, $this->siteA);

    $index = $this->actingAs($actor)->get('/hr/people')->assertOk();
    $names = collect($index->inertiaProps('profiles.data'))->pluck('user.name');
    expect($names)
        ->toContain($actor->name, $this->local->name, $this->secondary->name)
        ->not->toContain($this->foreign->name)
        ->and($index->inertiaProps('profiles.total'))->toBe(3)
        ->and($index->inertiaProps('summary.active'))->toBe(3);

    $search = $this->actingAs($actor)
        ->get('/hr/people?q=Foreign%20Privacy%20Worker')
        ->assertOk();
    expect($search->inertiaProps('profiles.data'))->toBe([]);

    $privateEmailSearch = $this->actingAs($actor)
        ->get('/hr/people?q=local-privacy-worker-private%40example.test')
        ->assertOk();
    expect($privateEmailSearch->inertiaProps('profiles.data'))->toBe([]);

    $missingProfileId = HrEmployeeProfile::query()->max('id') + 1000;
    $hiddenWeb = $this->actingAs($actor)
        ->get('/hr/people/'.$this->foreign->hrEmployeeProfile->id)
        ->assertNotFound();
    $missingWeb = $this->actingAs($actor)
        ->get('/hr/people/'.$missingProfileId)
        ->assertNotFound();
    expect($hiddenWeb->getContent())->toBe($missingWeb->getContent());

    $localDetail = $this->actingAs($actor)
        ->get('/hr/people/'.$this->local->hrEmployeeProfile->id)
        ->assertOk();
    expect($localDetail->inertiaProps('profile.notes'))->toBeNull()
        ->and($localDetail->inertiaProps('profile.emergency_contact_name'))->toBeNull()
        ->and($localDetail->inertiaProps('profile.documents'))->toBe([])
        ->and($localDetail->inertiaProps('leaveBalances'))->toHaveCount(1)
        ->and($localDetail->inertiaProps('pips.0.title'))->toBe('Permission bounded performance plan');

    $secondaryDetail = $this->actingAs($actor)
        ->get('/hr/people/'.$this->secondary->hrEmployeeProfile->id)
        ->assertOk();
    expect($secondaryDetail->inertiaProps('profile.primary_site'))->toBeNull()
        ->and($secondaryDetail->inertiaProps('profile.user.email'))
        ->toBe('secondary-privacy-worker@work.example.test');

    $api = $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees')
        ->assertOk()
        ->assertJsonCount(3, 'data');
    expect(collect($api->json('data'))->pluck('employee_number'))
        ->not->toContain($this->foreign->hrEmployeeProfile->employee_number);

    $apiSearch = $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees?q=Foreign%20Privacy%20Worker')
        ->assertOk()
        ->assertJsonCount(0, 'data');
    $hiddenApi = $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees/'.$this->foreign->hrEmployeeProfile->id)
        ->assertNotFound();
    $missingApi = $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees/'.$missingProfileId)
        ->assertNotFound();
    expect($hiddenApi->json('message'))->toBe($missingApi->json('message'))
        ->and($hiddenApi->json('exception'))->toBe($missingApi->json('exception'));

    $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees/'.$this->secondary->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('primary_site_id', null)
        ->assertJsonPath('primary_site', null)
        ->assertJsonPath('secondary_site_ids', [$this->siteA->id])
        ->assertJsonPath('user.email', 'secondary-privacy-worker@work.example.test');
})->with(['coordinator', 'team_lead']);

test('central HR admin and auditor retain explicit global list and direct-read positives', function (string $role): void {
    $actor = employeePrivacyActor($role, $this->siteA);

    $index = $this->actingAs($actor)->get('/hr/people')->assertOk();
    expect(collect($index->inertiaProps('profiles.data'))->pluck('user.name'))
        ->toContain($this->local->name, $this->foreign->name, $this->secondary->name)
        ->not->toContain($this->unproven->name)
        ->and($index->inertiaProps('profiles.total'))->toBe(4)
        ->and($index->inertiaProps('summary.active'))->toBe(4);

    $this->actingAs($actor)
        ->get('/hr/people/'.$this->foreign->hrEmployeeProfile->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('profile.user.name', $this->foreign->name)
            ->where('profile.primary_site.id', $this->siteB->id));
    $this->actingAs($actor)
        ->get('/hr/people/'.$this->unproven->hrEmployeeProfile->id)
        ->assertNotFound();

    $localDetail = $this->actingAs($actor)
        ->get('/hr/people/'.$this->local->hrEmployeeProfile->id)
        ->assertOk();
    if ($role === 'auditor') {
        expect($localDetail->inertiaProps('profile.notes'))->toBeNull()
            ->and($localDetail->inertiaProps('profile.documents'))->toBe([])
            ->and($localDetail->inertiaProps('leaveBalances'))->toBe([])
            ->and($localDetail->inertiaProps('pips.0.title'))->toBe('Permission bounded performance plan');
    } else {
        expect($localDetail->inertiaProps('profile.notes'))->toBe('LOCAL-RESTRICTED-NOTES')
            ->and($localDetail->inertiaProps('profile.documents'))->toHaveCount(1)
            ->and($localDetail->inertiaProps('leaveBalances'))->toHaveCount(1);
    }

    $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees')
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees/'.$this->foreign->hrEmployeeProfile->id)
        ->assertOk()
        ->assertJsonPath('primary_site.id', $this->siteB->id);
    $this->actingAs($actor, 'sanctum')
        ->getJson('/api/hr/employees/'.$this->unproven->hrEmployeeProfile->id)
        ->assertNotFound();
})->with(['hr', 'admin', 'auditor']);

test('workers retain self service without gaining people or HR API enumeration', function (): void {
    $worker = employeePrivacyActor('support_worker', $this->siteA);

    $this->actingAs($worker)->get('/hr/my/profile')->assertOk();
    $this->actingAs($worker)->get('/hr/people')->assertForbidden();
    $this->actingAs($worker)
        ->get('/hr/people/'.$worker->hrEmployeeProfile->id)
        ->assertForbidden();
    $this->actingAs($worker)
        ->get('/hr/people/'.$this->foreign->hrEmployeeProfile->id)
        ->assertForbidden();
    $this->actingAs($worker, 'sanctum')
        ->getJson('/api/hr/employees')
        ->assertForbidden();
    $this->actingAs($worker, 'sanctum')
        ->getJson('/api/hr/employees/'.$this->foreign->hrEmployeeProfile->id)
        ->assertForbidden();
});
