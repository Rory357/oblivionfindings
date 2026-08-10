<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->complianceIntegritySite = Site::factory()->create([
        'name' => 'Compliance Integrity House',
        'type' => 'house',
    ]);
    $this->complianceIntegrityManager = complianceIntegrityStaff(
        'Compliance Integrity Manager',
        $this->complianceIntegritySite,
        'hr',
    );
    $this->complianceIntegrityWorker = complianceIntegrityStaff(
        'Compliance Integrity Worker',
        $this->complianceIntegritySite,
    );
    $this->complianceIntegrityRequirement = HrComplianceRequirement::query()->create([
        'code' => 'INTEGRITY_BASE',
        'name' => 'Integrity base requirement',
        'category' => 'access_readiness',
        'check_type' => 'manual',
        'hard_stop' => true,
        'is_active' => true,
        'created_by' => $this->complianceIntegrityManager->id,
    ]);
});

function complianceIntegrityStaff(
    string $name,
    Site $site,
    string $role = 'support_worker',
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => $role,
        'approved_at' => now(),
    ]);
    $roleModel = Role::query()->where('name', $role)->firstOrFail();
    $user->roles()->sync([$roleModel->id]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-INTEGRITY-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => str($role)->replace('_', ' ')->title(),
        'position_role' => $role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
    $user->setRelation('hrEmployeeProfile', $profile);

    return $user;
}

test('matrix mutations accept only Site types represented by the active Sites register', function () {
    $this->actingAs($this->complianceIntegrityManager)
        ->post('/hr/compliance/requirements', [
            'code' => 'INTEGRITY_SITE_TYPES',
            'name' => 'Site type integrity requirement',
            'category' => 'access_readiness',
            'check_type' => 'manual',
            'hard_stop' => true,
            'roles' => ['support_worker'],
            'site_types' => ['house', 'respite'],
        ])
        ->assertSessionHasErrors('site_types.1');

    expect(HrComplianceRequirement::query()->where('code', 'INTEGRITY_SITE_TYPES')->exists())
        ->toBeFalse();

    $this->actingAs($this->complianceIntegrityManager)
        ->post('/hr/compliance/matrix', [
            'requirement_id' => $this->complianceIntegrityRequirement->id,
            'role' => 'support_worker',
            'site_type' => 'respite',
            'is_mandatory' => true,
            'action' => 'assign',
        ])
        ->assertSessionHasErrors('site_type');

    $this->actingAs($this->complianceIntegrityManager)
        ->post('/hr/compliance/assign', [
            'requirement_ids' => [$this->complianceIntegrityRequirement->id],
            'roles' => ['support_worker'],
            'site_types' => ['house', 'respite'],
            'is_mandatory' => true,
        ])
        ->assertSessionHasErrors('site_types.1');

    expect(HrComplianceMatrix::query()
        ->where('requirement_id', $this->complianceIntegrityRequirement->id)
        ->exists())->toBeFalse();
});

test('manual compliance status mutations reject contradictory dates atomically', function () {
    $existing = HrStaffComplianceStatus::query()->create([
        'user_id' => $this->complianceIntegrityWorker->id,
        'requirement_id' => $this->complianceIntegrityRequirement->id,
        'status' => 'not_started',
    ]);

    $this->actingAs($this->complianceIntegrityManager)
        ->put('/hr/compliance/status/'.$existing->id, [
            'status' => 'compliant',
            'valid_from' => now()->addDay()->toDateString(),
            'expires_at' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('expires_at');

    expect($existing->fresh()->status)->toBe('not_started')
        ->and($existing->fresh()->valid_from)->toBeNull()
        ->and($existing->fresh()->expires_at)->toBeNull();

    $second = complianceIntegrityStaff(
        'Compliance Integrity Second Worker',
        $this->complianceIntegritySite,
    );
    $this->actingAs($this->complianceIntegrityManager)
        ->post('/hr/compliance/bulk-record', [
            'user_ids' => [$this->complianceIntegrityWorker->id, $second->id],
            'requirement_id' => $this->complianceIntegrityRequirement->id,
            'status' => 'compliant',
            'valid_from' => now()->subMonth()->toDateString(),
            'expires_at' => now()->subDay()->toDateString(),
        ])
        ->assertSessionHasErrors('expires_at');

    expect(HrStaffComplianceStatus::query()
        ->where('requirement_id', $this->complianceIntegrityRequirement->id)
        ->count())->toBe(1);

    $this->actingAs($this->complianceIntegrityManager)
        ->post('/hr/compliance/staff/'.$second->id.'/status', [
            'requirement_id' => $this->complianceIntegrityRequirement->id,
            'status' => 'expiring_soon',
        ])
        ->assertSessionHasErrors('expires_at');

    expect(HrStaffComplianceStatus::query()
        ->where('user_id', $second->id)
        ->where('requirement_id', $this->complianceIntegrityRequirement->id)
        ->exists())->toBeFalse();
});
