<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->matrixApplicationSite = Site::factory()->create([
        'name' => 'Matrix Application Site',
        'type' => 'residential',
    ]);
    $this->matrixApplicationManager = User::factory()->create([
        'name' => 'Matrix Application Manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->matrixApplicationManager->id,
        'employee_number' => 'EMP-MATRIX-'.$this->matrixApplicationManager->id,
        'work_email' => 'matrix.manager@work.example.test',
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->matrixApplicationSite->id,
        'secondary_site_ids' => [],
    ]);
    $this->matrixApplicationManager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
});

function matrixApplicationRequirement(
    User $creator,
    string $code,
    array $overrides = [],
): HrComplianceRequirement {
    return HrComplianceRequirement::query()->create([
        'code' => $code,
        'name' => str($code)->replace('_', ' ')->title()->toString(),
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $creator->id,
        ...$overrides,
    ]);
}

test('matrix definitions and entries share one application catalogue', function () {
    $first = matrixApplicationRequirement($this->matrixApplicationManager, 'APP_GLOBAL_FIRST');
    $second = matrixApplicationRequirement($this->matrixApplicationManager, 'APP_GLOBAL_SECOND');
    $firstEntry = HrComplianceMatrix::query()->create([
        'requirement_id' => $first->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $secondEntry = HrComplianceMatrix::query()->create([
        'requirement_id' => $second->id,
        'role' => 'team_lead',
        'site_type' => 'residential',
        'is_mandatory' => false,
    ]);

    $response = $this->actingAs($this->matrixApplicationManager)
        ->get('/hr/compliance/matrix')
        ->assertOk();

    expect(collect($response->inertiaProps('requirements'))->pluck('code')->all())
        ->toContain($first->code, $second->code);
    expect(collect($response->inertiaProps('matrixEntries'))->pluck('id')->all())
        ->toContain($firstEntry->id, $secondEntry->id);
});

test('requirement direct mutations are application global and conceal invalid identifiers', function () {
    config(['app.debug' => false]);
    $requirement = matrixApplicationRequirement($this->matrixApplicationManager, 'DIRECT_GLOBAL');
    HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'team_lead',
        'site_type' => 'residential',
        'is_mandatory' => true,
    ]);

    $this->actingAs($this->matrixApplicationManager)
        ->put('/hr/compliance/requirements/'.$requirement->id, ['name' => 'Updated globally'])
        ->assertSessionHas('success');
    expect($requirement->fresh()->name)->toBe('Updated globally');

    $responses = [
        $this->actingAs($this->matrixApplicationManager)
            ->putJson('/hr/compliance/requirements/99999999', []),
        $this->actingAs($this->matrixApplicationManager)
            ->putJson('/hr/compliance/requirements/not-a-number', []),
        $this->actingAs($this->matrixApplicationManager)
            ->putJson('/hr/compliance/requirements/'.str_repeat('9', 80), []),
    ];
    expect(collect($responses)->map(fn ($response) => [
        'status' => $response->status(),
        'body' => $response->json(),
    ])->unique()->values()->all())->toBe([
        ['status' => 404, 'body' => ['message' => '']],
    ]);

    $this->actingAs($this->matrixApplicationManager)
        ->delete('/hr/compliance/requirements/'.$requirement->id)
        ->assertSessionHas('success');
    expect($requirement->fresh()->is_active)->toBeFalse()
        ->and(HrComplianceMatrix::query()->where('requirement_id', $requirement->id)->count())->toBe(0);
});

test('unassigning an all Sites matrix row preserves Site type specific rows', function () {
    $requirement = matrixApplicationRequirement($this->matrixApplicationManager, 'NULL_SITE_UNASSIGN');
    $allSites = HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $residential = HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => 'residential',
        'is_mandatory' => true,
    ]);

    $this->actingAs($this->matrixApplicationManager)
        ->post('/hr/compliance/matrix', [
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'site_type' => null,
            'is_mandatory' => true,
            'action' => 'unassign',
        ])
        ->assertSessionHas('success');

    expect($allSites->fresh())->toBeNull()
        ->and($residential->fresh())->not->toBeNull();
});

test('requirement creation and bulk assignment enforce application global identities', function () {
    matrixApplicationRequirement($this->matrixApplicationManager, 'GLOBAL_UNIQUE_CODE');

    $this->actingAs($this->matrixApplicationManager)
        ->post('/hr/compliance/requirements', [
            'code' => 'global_unique_code',
            'name' => 'Duplicate global code',
            'category' => 'training',
            'check_type' => 'manual',
            'hard_stop' => false,
        ])
        ->assertSessionHasErrors('code');
    expect(HrComplianceRequirement::query()->where('code', 'GLOBAL_UNIQUE_CODE')->count())->toBe(1);

    $requirement = matrixApplicationRequirement($this->matrixApplicationManager, 'BULK_GLOBAL_ASSIGN');
    $this->actingAs($this->matrixApplicationManager)
        ->post('/hr/compliance/assign', [
            'requirement_ids' => [$requirement->id],
            'roles' => ['support_worker', 'team_lead'],
            'site_types' => ['residential'],
            'is_mandatory' => false,
        ])
        ->assertSessionHas('success');

    expect(HrComplianceMatrix::query()
        ->where('requirement_id', $requirement->id)
        ->where('site_type', 'residential')
        ->where('is_mandatory', false)
        ->pluck('role')->sort()->values()->all())
        ->toBe(['support_worker', 'team_lead']);
});
