<?php

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->renewalsDisclosureSite = Site::factory()->create([
        'name' => 'Renewals Disclosure Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->renewalsDisclosureForeignSite = Site::factory()->create([
        'name' => 'Foreign Renewals Disclosure Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
});

function renewalsDisclosureStaff(string $name, Site $site): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'@private.example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    ensureCanonicalHrStaffProfile($user, $site, [
        'work_email' => str($name)->slug().'@work.example.test',
        'position_role' => 'support_worker',
    ]);

    return $user;
}

/** @param list<string> $permissionKeys */
function renewalsDisclosureViewer(array $permissionKeys, Site $site): User
{
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($viewer, $site);

    $role = Role::query()->create([
        'name' => 'renewals_disclosure_viewer_'.$viewer->id,
        'label' => 'Renewals disclosure viewer',
        'level' => 30,
        'type' => 'custom',
    ]);
    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $viewer->roles()->sync([$role->id]);
    $viewer->unsetRelation('roles');

    return $viewer;
}

/**
 * @return array{active:HrComplianceRequirement,inactive:HrComplianceRequirement}
 */
function renewalsDisclosureRequirements(User $creator): array
{
    $active = HrComplianceRequirement::query()->create([
        'code' => 'RENEWALS-DISCLOSURE-ACTIVE',
        'name' => 'Active renewal requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $creator->id,
    ]);
    $inactive = HrComplianceRequirement::query()->create([
        'code' => 'RENEWALS-DISCLOSURE-INACTIVE',
        'name' => 'Inactive renewal requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => false,
        'created_by' => $creator->id,
    ]);

    foreach ([$active, $inactive] as $requirement) {
        HrComplianceMatrix::query()->create([
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'site_type' => 'all',
            'is_mandatory' => true,
        ]);
    }

    return compact('active', 'inactive');
}

function renewalsDisclosureRecords(
    User $staff,
    array $requirements,
    User $creator,
    string $secretPrefix,
): void {
    foreach ($requirements as $requirement) {
        HrStaffComplianceStatus::query()->create([
            'user_id' => $staff->id,
            'requirement_id' => $requirement->id,
            'status' => 'expiring_soon',
            'evidence_type' => 'manual',
            'expires_at' => now()->addDays(20)->toDateString(),
            'notes' => $secretPrefix.'-COMPLIANCE-NOTES',
        ]);
    }

    StaffBackgroundCheck::query()->create([
        'user_id' => $staff->id,
        'check_type' => 'police_check',
        'provider' => $secretPrefix.'-VETTING-PROVIDER',
        'reference_number' => $secretPrefix.'-VETTING-REFERENCE',
        'status' => 'renewal_due',
        'expires_at' => now()->addDays(21)->toDateString(),
        'notes' => $secretPrefix.'-VETTING-NOTES',
        'created_by' => $creator->id,
    ]);
    HrDriverEligibility::query()->create([
        'user_id' => $staff->id,
        'licence_number' => $secretPrefix.'-DRIVER-LICENCE',
        'licence_class' => '6',
        'licence_endorsements' => ['P', 'V'],
        'licence_expires_at' => today()->toDateString(),
        'status' => 'suspended',
        'notes' => $secretPrefix.'-DRIVER-NOTES',
        'created_by' => $creator->id,
    ]);
}

test('every selector declares its emitted models and complete permission envelope', function () {
    expect(ComplianceExportDataset::Staff->emittedModelPermissions())->toBe([
        User::class => 'hr.compliance.view',
        HrEmployeeProfile::class => 'hr.compliance.view',
        HrComplianceRequirement::class => 'hr.compliance.view',
        HrStaffComplianceStatus::class => 'hr.compliance.view',
    ])->and(ComplianceExportDataset::Vetting->emittedModelPermissions())->toBe([
        User::class => 'hr.vetting.view',
        StaffBackgroundCheck::class => 'hr.vetting.view',
    ])->and(ComplianceExportDataset::Drivers->emittedModelPermissions())->toBe([
        User::class => 'hr.driver.view',
        HrDriverEligibility::class => 'hr.driver.view',
    ])->and(ComplianceExportDataset::Renewals->emittedModelPermissions())->toBe([
        User::class => 'hr.employees.viewAny',
        HrComplianceRequirement::class => 'hr.compliance.view',
        HrStaffComplianceStatus::class => 'hr.compliance.view',
        StaffBackgroundCheck::class => 'hr.vetting.view',
        HrDriverEligibility::class => 'hr.driver.view',
    ])->and(ComplianceExportDataset::Renewals->requiredPermissions())->toBe([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ]);
});

test('renewals denies every incomplete action envelope before stream metadata or output', function (array $permissions) {
    config(['app.debug' => false]);
    $viewer = renewalsDisclosureViewer($permissions, $this->renewalsDisclosureSite);

    $response = $this->actingAs($viewer)
        ->getJson('/hr/compliance/export?dataset=renewals')
        ->assertForbidden();

    expect($response->headers->get('content-disposition'))->toBeNull()
        ->and($response->headers->get('content-type'))->not->toContain('text/csv')
        ->and($response->getContent())->not->toContain('Staff member', 'Due date');
})->with([
    'compliance only' => [['hr.compliance.view']],
    'missing driver action' => [[
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
    ]],
    'missing vetting action' => [[
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.driver.view',
    ]],
    'missing compliance action' => [[
        'hr.employees.viewAny',
        'hr.vetting.view',
        'hr.driver.view',
    ]],
    'missing employee action' => [[
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
        'hr.employees.viewAllSites',
    ]],
    'employee Site permissions do not replace mixed-domain actions' => [[
        'hr.employees.viewAny',
        'hr.employees.viewAllSites',
        'hr.compliance.view',
    ]],
]);

test('renewals is Site scoped, excludes inactive requirements, and discloses only renewal essentials', function () {
    $viewer = renewalsDisclosureViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->renewalsDisclosureSite);
    $local = renewalsDisclosureStaff('Local Renewal Worker', $this->renewalsDisclosureSite);
    $foreign = renewalsDisclosureStaff('Foreign Renewal Worker', $this->renewalsDisclosureForeignSite);
    $requirements = renewalsDisclosureRequirements($viewer);
    renewalsDisclosureRecords($local, $requirements, $viewer, 'LOCAL-SECRET');
    renewalsDisclosureRecords($foreign, $requirements, $viewer, 'FOREIGN-SECRET');

    $csv = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain(
            'Local Renewal Worker',
            'Active renewal requirement',
            'Police check',
            'Driver licence',
            'upcoming',
        )
        ->not->toContain(
            'Foreign Renewal Worker',
            'Inactive renewal requirement',
            'Class 6 licence',
            'suspended',
            'LOCAL-SECRET',
            'FOREIGN-SECRET',
            $local->email,
            $foreign->email,
        );
});

test('complete action permissions do not broaden an actor with no current Site access', function () {
    $viewer = renewalsDisclosureViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->renewalsDisclosureSite);
    $viewer->hrEmployeeProfile()->firstOrFail()->forceFill(['is_active' => false])->save();
    $local = renewalsDisclosureStaff('No Scope Renewal Worker', $this->renewalsDisclosureSite);
    $requirements = renewalsDisclosureRequirements($viewer);
    renewalsDisclosureRecords($local, $requirements, $viewer, 'NO-SCOPE-SECRET');

    $csv = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('Type,"Staff member",Item,"Due date",Status')
        ->not->toContain('No Scope Renewal Worker', 'NO-SCOPE-SECRET');
});

test('complete action permissions plus the explicit all-Sites capability disclose foreign Site renewals', function () {
    $viewer = renewalsDisclosureViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
        'hr.employees.viewAllSites',
    ], $this->renewalsDisclosureSite);
    $local = renewalsDisclosureStaff('Global Local Renewal Worker', $this->renewalsDisclosureSite);
    $foreign = renewalsDisclosureStaff('Global Foreign Renewal Worker', $this->renewalsDisclosureForeignSite);
    $requirements = renewalsDisclosureRequirements($viewer);
    renewalsDisclosureRecords($local, $requirements, $viewer, 'GLOBAL-LOCAL-SECRET');
    renewalsDisclosureRecords($foreign, $requirements, $viewer, 'GLOBAL-FOREIGN-SECRET');

    $csv = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('Global Local Renewal Worker', 'Global Foreign Renewal Worker')
        ->not->toContain('GLOBAL-LOCAL-SECRET', 'GLOBAL-FOREIGN-SECRET', 'Class 6 licence');
});

test('unknown renewals selector fails closed without CSV metadata or output', function () {
    $viewer = renewalsDisclosureViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->renewalsDisclosureSite);

    $response = $this->actingAs($viewer)
        ->getJson('/hr/compliance/export?dataset=renewals-unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dataset');

    expect($response->headers->get('content-disposition'))->toBeNull()
        ->and($response->headers->get('content-type'))->not->toContain('text/csv')
        ->and($response->getContent())->not->toContain('Staff member', 'Due date');
});
