<?php

use App\Domain\Hr\Jobs\EvaluateComplianceMatrixJob;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\HrEvidencePackService;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Notifications\ComplianceReminderNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->runtimeHouse = Site::factory()->create([
        'name' => 'Compliance Runtime House',
        'type' => 'house',
    ]);
    $this->runtimeFacility = Site::factory()->create([
        'name' => 'Compliance Runtime Facility',
        'type' => 'facility',
    ]);
});

function complianceRuntimeStaff(
    string $name,
    Site $site,
    string $role = 'support_worker',
    array $userOverrides = [],
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'role' => $role,
        'approved_at' => now(),
        ...$userOverrides,
    ]);
    $roleModel = Role::query()->firstOrCreate(
        ['name' => $role],
        ['label' => str($role)->replace('_', ' ')->title(), 'level' => 10, 'type' => 'custom'],
    );
    $user->roles()->sync([$roleModel->id]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-RUNTIME-'.$user->id,
        'work_email' => str($name)->slug().'@work.example.test',
        'position_title' => str($role)->replace('_', ' ')->title(),
        'position_role' => $role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        ...$profileOverrides,
    ]);
    $user->setRelation('hrEmployeeProfile', $profile);

    return $user->load('roles', 'hrEmployeeProfile.primarySite');
}

function complianceRuntimeRequirement(
    string $code,
    string $siteType = 'all',
    bool $hardStop = false,
    string $checkType = 'manual',
): HrComplianceRequirement {
    $requirement = HrComplianceRequirement::query()->create([
        'code' => $code,
        'name' => str($code)->replace('_', ' ')->title(),
        'category' => 'runtime',
        'check_type' => $checkType,
        'hard_stop' => $hardStop,
        'is_active' => true,
    ]);
    HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => $siteType,
        'is_mandatory' => $hardStop,
    ]);

    return $requirement;
}

test('application evaluator uses current staff and role plus Site type instead of legacy storage markers', function () {
    $house = complianceRuntimeStaff('Runtime House Worker', $this->runtimeHouse);
    $facility = complianceRuntimeStaff('Runtime Facility Worker', $this->runtimeFacility);
    $ended = complianceRuntimeStaff('Runtime Ended Worker', $this->runtimeHouse, profileOverrides: [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    $future = complianceRuntimeStaff('Runtime Future Worker', $this->runtimeHouse, profileOverrides: [
        'start_date' => now()->addDay()->toDateString(),
    ]);
    $inactive = complianceRuntimeStaff('Runtime Inactive Worker', $this->runtimeHouse, profileOverrides: [
        'is_active' => false,
    ]);
    $unapproved = complianceRuntimeStaff('Runtime Unapproved Worker', $this->runtimeHouse, userOverrides: [
        'approved_at' => null,
    ]);

    $global = complianceRuntimeRequirement('RUNTIME_GLOBAL', 'all');
    $houseOnly = complianceRuntimeRequirement('RUNTIME_HOUSE', 'house');
    $facilityOnly = complianceRuntimeRequirement('RUNTIME_FACILITY', 'facility');

    $service = app(ComplianceMatrixService::class);
    expect($service->evaluateAllStaff())->toBe(2)
        ->and($service->evaluateAllStaff())->toBe(2);

    expect(HrStaffComplianceStatus::query()->where('user_id', $house->id)->pluck('requirement_id')->sort()->values()->all())
        ->toBe(collect([$global->id, $houseOnly->id])->sort()->values()->all())
        ->and(HrStaffComplianceStatus::query()->where('user_id', $facility->id)->pluck('requirement_id')->sort()->values()->all())
        ->toBe(collect([$global->id, $facilityOnly->id])->sort()->values()->all())
        ->and(HrStaffComplianceStatus::query()->whereIn('user_id', [
            $ended->id,
            $future->id,
            $inactive->id,
            $unapproved->id,
        ])->count())->toBe(0)
        ->and(HrStaffComplianceStatus::query()->count())->toBe(4);

    $job = new EvaluateComplianceMatrixJob;
    $legacyPartitionIdentifier = 'ten'.'antId';
    expect(property_exists($job, $legacyPartitionIdentifier))->toBeFalse();
});

test('live hard stops are application global and respect Site type', function () {
    $house = complianceRuntimeStaff('Runtime Live House', $this->runtimeHouse);
    $facility = complianceRuntimeStaff('Runtime Live Facility', $this->runtimeFacility);
    complianceRuntimeRequirement('RUNTIME_HOUSE_CREDENTIAL', 'house', true, 'credential');

    $validator = app(LiveComplianceValidator::class);
    expect($validator->validateHardStops($house)['passed'])->toBeFalse()
        ->and($validator->validateHardStops($facility)['passed'])->toBeTrue();
});

test('overview and detail treat missing materialised rows as not started rather than fully compliant', function () {
    $manager = complianceRuntimeStaff('Runtime Compliance Manager', $this->runtimeHouse, 'hr');
    $worker = complianceRuntimeStaff('Runtime Missing Status', $this->runtimeHouse);
    complianceRuntimeRequirement('RUNTIME_MISSING_STATUS', 'house');

    $response = $this->actingAs($manager)->get('/hr/compliance')->assertOk();
    $row = collect($response->inertiaProps('staffStatuses.data'))->firstWhere('user_id', $worker->id);
    expect($response->inertiaProps('summary.fully_compliant'))->toBe(0)
        ->and($row['total_requirements'])->toBe(1)
        ->and($row['not_started_count'])->toBe(1)
        ->and($row['compliance_percent'])->toBe(0);

    $this->actingAs($manager)
        ->get('/hr/compliance?status=fully_compliant')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('staffStatuses.data', 0));

    $detail = $this->actingAs($manager)
        ->get('/hr/compliance/staff/'.$worker->id)
        ->assertOk();
    expect($detail->inertiaProps('complianceStatuses.0.status'))->toBe('not_started')
        ->and($detail->inertiaProps('complianceStatuses.0.id'))->toBeNull();
});

test('staff detail omits raw status storage and separately protects vetting and driver data', function () {
    $role = Role::query()->create([
        'name' => 'compliance_view_only',
        'label' => 'Compliance view only',
        'level' => 20,
        'type' => 'custom',
    ]);
    $role->permissions()->sync([
        Permission::query()->where('key', 'hr.compliance.view')->firstOrFail()->id,
    ]);
    $viewer = complianceRuntimeStaff('Runtime Restricted Viewer', $this->runtimeHouse);
    $viewer->roles()->sync([$role->id]);
    $viewer->unsetRelation('roles');
    $worker = complianceRuntimeStaff('Runtime Private Detail', $this->runtimeHouse);
    $requirement = complianceRuntimeRequirement('RUNTIME_PRIVATE_STATUS', 'house');
    HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
        'evidence_disk' => 'private',
        'evidence_path' => 'hr-compliance/evidence/private-secret.pdf',
        'evidence_filename' => 'private-secret.pdf',
        'evidence_mime' => 'application/pdf',
    ]);
    StaffBackgroundCheck::query()->create([
        'user_id' => $worker->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'provider' => 'Private Vetting Provider',
        'reference_number' => 'VET-PRIVATE-123',
        'created_by' => $viewer->id,
    ]);
    HrDriverEligibility::query()->create([
        'user_id' => $worker->id,
        'status' => 'eligible',
        'licence_class' => 'Private Class',
        'licence_number' => 'DRV-PRIVATE-456',
        'created_by' => $viewer->id,
    ]);

    $response = $this->actingAs($viewer)
        ->get('/hr/compliance/staff/'.$worker->id)
        ->assertOk();

    expect($response->inertiaProps('statuses'))->toBeNull()
        ->and($response->inertiaProps('vetting'))->toBeNull()
        ->and($response->inertiaProps('driver'))->toBeNull()
        ->and($response->inertiaProps('can.vetting'))->toBeFalse()
        ->and($response->inertiaProps('can.driver'))->toBeFalse()
        ->and($response->getContent())->not->toContain(
            'hr-compliance/evidence/private-secret.pdf',
            'private-secret.pdf',
            'VET-PRIVATE-123',
            'Private Vetting Provider',
            'DRV-PRIVATE-456',
            'Private Class',
        );
});

test('database and model enforce single application compliance identities', function () {
    expect(Schema::hasIndex('hr_compliance_requirements', 'hr_compliance_requirements_code_uq'))->toBeTrue()
        ->and(Schema::hasIndex('hr_compliance_matrix', 'hr_comp_matrix_req_role_site_uq'))->toBeTrue()
        ->and(Schema::hasIndex('hr_staff_compliance_status', 'hr_staff_comp_user_req_uq'))->toBeTrue();

    $worker = complianceRuntimeStaff('Runtime Identity Worker', $this->runtimeHouse);
    $requirement = complianceRuntimeRequirement('RUNTIME_IDENTITY', 'all');
    expect(HrComplianceMatrix::query()->where('requirement_id', $requirement->id)->value('site_type'))
        ->toBe('all');

    HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'not_started',
    ]);
    expect(fn () => HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
    ]))->toThrow(QueryException::class);
});

test('deactivated requirements cannot be assigned back into the matrix', function () {
    $manager = complianceRuntimeStaff('Runtime Matrix Manager', $this->runtimeHouse, 'hr');
    $requirement = complianceRuntimeRequirement('RUNTIME_INACTIVE_MATRIX', 'all');
    $requirement->update(['is_active' => false]);
    HrComplianceMatrix::query()->where('requirement_id', $requirement->id)->delete();

    $this->actingAs($manager)
        ->post('/hr/compliance/matrix', [
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'site_type' => null,
            'is_mandatory' => true,
            'action' => 'assign',
        ])
        ->assertSessionHasErrors('requirement_id');

    expect(HrComplianceMatrix::query()->where('requirement_id', $requirement->id)->count())->toBe(0);
});

test('manual compliance reminders are queued notifications', function () {
    expect(is_a(ComplianceReminderNotification::class, ShouldQueue::class, true))
        ->toBeTrue();
});

test('secondary Site types participate in compliance applicability', function () {
    $worker = complianceRuntimeStaff(
        'Runtime Secondary Facility Worker',
        $this->runtimeHouse,
        profileOverrides: ['secondary_site_ids' => [$this->runtimeFacility->id]],
    );
    $facilityRequirement = complianceRuntimeRequirement(
        'RUNTIME_SECONDARY_FACILITY',
        'facility',
    );

    $applicable = app(ComplianceMatrixService::class)
        ->getApplicableRequirements($worker->fresh('roles'));

    expect($applicable->pluck('id')->all())->toContain($facilityRequirement->id);
});

test('matrix management exposes canonical Site types separately from all Sites rows', function () {
    $manager = complianceRuntimeStaff('Runtime Site Matrix Manager', $this->runtimeHouse, 'hr');
    $global = complianceRuntimeRequirement('RUNTIME_MATRIX_ALL', 'all');
    $facility = complianceRuntimeRequirement('RUNTIME_MATRIX_FACILITY', 'facility');

    $response = $this->actingAs($manager)->get('/hr/compliance/matrix')->assertOk();
    expect($response->inertiaProps('siteTypes'))
        ->toContain('house', 'facility')
        ->not->toContain('all')
        ->and($response->inertiaProps('wizard.siteTypes'))
        ->toContain('house', 'facility')
        ->not->toContain('all')
        ->and(collect($response->inertiaProps('matrixEntries'))->firstWhere('requirement_id', $global->id)['site_type'])
        ->toBe('all')
        ->and(collect($response->inertiaProps('matrixEntries'))->firstWhere('requirement_id', $facility->id)['site_type'])
        ->toBe('facility');
});

test('matrix filters hero links and staff csv include missing applicable rows', function () {
    $manager = complianceRuntimeStaff('Runtime Exact Filter Manager', $this->runtimeHouse, 'hr');
    $worker = complianceRuntimeStaff('Runtime Exact Filter Worker', $this->runtimeHouse);
    $missing = complianceRuntimeRequirement('RUNTIME_FILTER_MISSING', 'house', true);
    $stale = complianceRuntimeRequirement('RUNTIME_FILTER_STALE', 'facility');
    HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $stale->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
    ]);

    $incomplete = $this->actingAs($manager)
        ->get('/hr/compliance?status=incomplete&requirement_id='.$missing->id)
        ->assertOk();
    expect(collect($incomplete->inertiaProps('staffStatuses.data'))->pluck('user_id')->all())
        ->toContain($worker->id);

    $hardStop = $this->actingAs($manager)
        ->get('/hr/compliance?status=hard_stop')
        ->assertOk();
    expect(collect($hardStop->inertiaProps('staffStatuses.data'))->pluck('user_id')->all())
        ->toContain($worker->id)
        ->and($hardStop->inertiaProps('hero.needs.0.status'))->toBe('hard_stop');

    $csv = $this->actingAs($manager)
        ->get('/hr/compliance/export?dataset=staff&format=csv')
        ->assertOk()
        ->streamedContent();
    expect($csv)
        ->toContain('Runtime Exact Filter Worker')
        ->toContain('RUNTIME_FILTER_MISSING')
        ->toContain('not_started')
        ->not->toContain('RUNTIME_FILTER_STALE');
});

test('evidence packs enforce Site privacy dedicated permissions and exact matrix truth', function () {
    $viewerRole = Role::query()->create([
        'name' => 'runtime_pack_viewer',
        'label' => 'Runtime pack viewer',
        'level' => 15,
        'type' => 'custom',
    ]);
    $viewerRole->permissions()->sync(Permission::query()
        ->whereIn('key', ['hr.compliance.view', 'hr.documents.view'])
        ->pluck('id'));
    $viewer = complianceRuntimeStaff('Runtime Pack Viewer', $this->runtimeHouse);
    $viewer->roles()->sync([$viewerRole->id]);
    $viewer->unsetRelation('roles');
    $visible = complianceRuntimeStaff('Runtime Visible Pack Worker', $this->runtimeHouse, profileOverrides: [
        'personal_email' => 'visible.private@example.test',
        'personal_phone' => '021-PRIVATE',
        'date_of_birth' => '1990-01-02',
    ]);
    $hidden = complianceRuntimeStaff('Runtime Hidden Pack Worker', $this->runtimeFacility);
    $missing = complianceRuntimeRequirement('RUNTIME_PACK_MISSING', 'house', true);
    $stale = complianceRuntimeRequirement('RUNTIME_PACK_STALE', 'facility');
    HrStaffComplianceStatus::query()->create([
        'user_id' => $visible->id,
        'requirement_id' => $stale->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
    ]);
    HrDocument::factory()->create([
        'employee_profile_id' => $visible->hrEmployeeProfile->id,
        'title' => 'Visible compliance certificate',
        'category' => 'compliance',
        'is_restricted' => false,
    ]);
    HrDocument::factory()->create([
        'employee_profile_id' => $visible->hrEmployeeProfile->id,
        'title' => 'Restricted investigation evidence',
        'category' => 'background_check',
        'is_restricted' => true,
    ]);

    $service = app(HrEvidencePackService::class);
    expect(fn () => $service->generateEmployeePack($hidden->hrEmployeeProfile, $viewer))
        ->toThrow(AuthorizationException::class);

    $pack = $service->generateEmployeePack($visible->hrEmployeeProfile, $viewer, [
        'redact_pii' => false,
        'include_documents' => true,
    ]);
    expect($pack['redacted'])->toBeTrue()
        ->and($pack['employee'])->not->toHaveKeys(['personal_email', 'personal_phone', 'date_of_birth'])
        ->and(collect($pack['documents'])->pluck('title')->all())
        ->toContain('Visible compliance certificate')
        ->not->toContain('Restricted investigation evidence')
        ->and(collect($pack['compliance'])->pluck('requirement_code')->all())
        ->toContain($missing->code)
        ->not->toContain($stale->code)
        ->and(collect($pack['compliance'])->firstWhere('requirement_code', $missing->code)['status'])
        ->toBe('not_started');
});

test('an active exemption lifts live and cached hard stops until it expires', function () {
    $manager = complianceRuntimeStaff('Runtime Exemption Manager', $this->runtimeHouse, 'hr');
    $worker = complianceRuntimeStaff('Runtime Exempt Worker', $this->runtimeHouse);
    $requirement = complianceRuntimeRequirement(
        'RUNTIME_EXEMPT_CREDENTIAL',
        'house',
        true,
        'credential',
    );
    $status = HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'expired',
    ]);

    $this->actingAs($manager)->post('/hr/compliance/status/'.$status->id.'/exempt', [
        'exemption_reason' => 'Temporary approved cover while renewal is completed.',
        'exempted_until' => now()->addWeek()->toDateString(),
        'acknowledge' => true,
    ])->assertSessionHas('success');

    $service = app(ComplianceMatrixService::class);
    expect($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeTrue();

    $status->refresh()->forceFill(['exempted_until' => now()->subDay()])->save();
    expect($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeFalse();
});

test('manual hard stops are checked against live verification and exemption expiry dates', function () {
    $manager = complianceRuntimeStaff('Runtime Manual Renewal Manager', $this->runtimeHouse, 'hr');
    $worker = complianceRuntimeStaff('Runtime Expired Manual Worker', $this->runtimeHouse);
    $requirement = complianceRuntimeRequirement(
        'RUNTIME_EXPIRED_MANUAL',
        'house',
        true,
        'manual',
    );
    $status = HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'manual',
        'expires_at' => now()->subDay(),
    ]);

    $service = app(ComplianceMatrixService::class);
    expect($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeFalse();

    $status->forceFill([
        'status' => 'expiring_soon',
        'expires_at' => now()->addDays(10),
    ])->save();
    expect($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeTrue();

    $status->forceFill([
        'status' => 'compliant',
        'expires_at' => now()->addMonth(),
        'exemption_reason' => 'Temporary manual exemption',
        'exempted_at' => now()->subMonth(),
        'exempted_until' => now()->subDay(),
    ])->save();
    expect($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeFalse();

    $this->actingAs($manager)
        ->put('/hr/compliance/status/'.$status->id, [
            'status' => 'compliant',
            'valid_from' => now()->toDateString(),
            'expires_at' => now()->addMonth()->toDateString(),
        ])
        ->assertSessionHas('success');
    expect($status->fresh()->exemption_reason)->toBeNull()
        ->and($status->fresh()->exempted_at)->toBeNull()
        ->and($status->fresh()->exempted_until)->toBeNull()
        ->and($service->canAssignToShift($worker->fresh('roles'))['allowed'])->toBeTrue();
});

test('deactivated requirements retain audit rows but reject status edits and exemptions', function () {
    $manager = complianceRuntimeStaff('Runtime Deactivated Status Manager', $this->runtimeHouse, 'hr');
    $worker = complianceRuntimeStaff('Runtime Deactivated Status Worker', $this->runtimeHouse);
    $requirement = complianceRuntimeRequirement('RUNTIME_DEACTIVATED_STATUS', 'house');
    $status = HrStaffComplianceStatus::query()->create([
        'user_id' => $worker->id,
        'requirement_id' => $requirement->id,
        'status' => 'not_started',
    ]);
    $requirement->update(['is_active' => false]);

    $this->actingAs($manager)
        ->put('/hr/compliance/status/'.$status->id, ['status' => 'compliant'])
        ->assertNotFound();
    $this->actingAs($manager)
        ->post('/hr/compliance/status/'.$status->id.'/exempt', [
            'exemption_reason' => 'Must not apply to an inactive requirement.',
            'acknowledge' => true,
        ])
        ->assertNotFound();

    expect($status->fresh()->status)->toBe('not_started')
        ->and($status->fresh()->exemption_reason)->toBeNull();
});
