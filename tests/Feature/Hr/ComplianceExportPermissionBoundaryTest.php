<?php

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->exportAllowedSite = Site::factory()->create([
        'name' => 'Compliance Export Allowed Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->exportHiddenSite = Site::factory()->create([
        'name' => 'Compliance Export Hidden Site',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $this->exportAllowedStaff = complianceExportBoundaryStaff(
        'Allowed Export Worker',
        $this->exportAllowedSite,
    );
    $this->exportHiddenStaff = complianceExportBoundaryStaff(
        'Hidden Export Worker',
        $this->exportHiddenSite,
    );

    $activeRequirement = HrComplianceRequirement::query()->create([
        'code' => 'EXPORT-BOUNDARY',
        'name' => 'Export boundary requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => true,
        'created_by' => $this->exportAllowedStaff->id,
    ]);
    $inactiveRequirement = HrComplianceRequirement::query()->create([
        'code' => 'EXPORT-BOUNDARY-INACTIVE',
        'name' => 'Inactive export boundary requirement',
        'category' => 'training',
        'check_type' => 'manual',
        'hard_stop' => false,
        'is_active' => false,
        'created_by' => $this->exportAllowedStaff->id,
    ]);

    foreach ([$activeRequirement, $inactiveRequirement] as $requirement) {
        HrComplianceMatrix::query()->create([
            'requirement_id' => $requirement->id,
            'role' => 'support_worker',
            'site_type' => 'all',
            'is_mandatory' => true,
        ]);
    }

    foreach ([$this->exportAllowedStaff, $this->exportHiddenStaff] as $staff) {
        foreach ([$activeRequirement, $inactiveRequirement] as $requirement) {
            HrStaffComplianceStatus::query()->create([
                'user_id' => $staff->id,
                'requirement_id' => $requirement->id,
                'status' => 'expiring_soon',
                'evidence_type' => 'manual',
                'expires_at' => now()->addDays(20)->toDateString(),
                'notes' => str($staff->name)->startsWith('Allowed')
                    ? 'ALLOWED-COMPLIANCE-NOTES'
                    : 'HIDDEN-COMPLIANCE-NOTES',
            ]);
        }
    }

    StaffBackgroundCheck::query()->create([
        'user_id' => $this->exportAllowedStaff->id,
        'check_type' => 'police_check',
        'provider' => 'ALLOWED VETTING PROVIDER',
        'reference_number' => 'ALLOWED-VET-001',
        'status' => 'clear',
        'expires_at' => now()->addMonth()->toDateString(),
        'notes' => 'ALLOWED-VETTING-NOTES',
        'created_by' => $this->exportAllowedStaff->id,
    ]);
    StaffBackgroundCheck::query()->create([
        'user_id' => $this->exportHiddenStaff->id,
        'check_type' => 'police_check',
        'provider' => 'HIDDEN VETTING PROVIDER',
        'reference_number' => 'HIDDEN-VET-001',
        'status' => 'clear',
        'expires_at' => now()->addMonth()->toDateString(),
        'notes' => 'HIDDEN-VETTING-NOTES',
        'created_by' => $this->exportHiddenStaff->id,
    ]);
    HrDriverEligibility::query()->create([
        'user_id' => $this->exportAllowedStaff->id,
        'licence_number' => 'ALLOWED-DRV-001',
        'licence_class' => '1',
        'licence_expires_at' => today()->toDateString(),
        'status' => 'suspended',
        'notes' => 'ALLOWED-DRIVER-NOTES',
        'created_by' => $this->exportAllowedStaff->id,
    ]);
    HrDriverEligibility::query()->create([
        'user_id' => $this->exportHiddenStaff->id,
        'licence_number' => 'HIDDEN-DRV-001',
        'licence_class' => '2',
        'licence_expires_at' => now()->addMonth()->toDateString(),
        'status' => 'suspended',
        'notes' => 'HIDDEN-DRIVER-NOTES',
        'created_by' => $this->exportHiddenStaff->id,
    ]);
});

function complianceExportBoundaryStaff(string $name, Site $site): User
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
function complianceExportBoundaryViewer(array $permissionKeys, Site $site): User
{
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($viewer, $site);

    $role = Role::query()->create([
        'name' => 'compliance_export_viewer_'.$viewer->id,
        'label' => 'Compliance export boundary viewer',
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

test('route and controller share the documented per-dataset permission envelope', function () {
    expect([
        ComplianceExportDataset::Staff->requiredPermissions(),
        ComplianceExportDataset::Renewals->requiredPermissions(),
        ComplianceExportDataset::Vetting->requiredPermissions(),
        ComplianceExportDataset::Drivers->requiredPermissions(),
    ])->toBe([
        ['hr.compliance.view'],
        [
            'hr.employees.viewAny',
            'hr.compliance.view',
            'hr.vetting.view',
            'hr.driver.view',
        ],
        ['hr.vetting.view'],
        ['hr.driver.view'],
    ]);

    $permissionMiddleware = collect(Route::getRoutes()->getByName('hr.compliance.export')?->middleware())
        ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
        ->values()
        ->all();

    expect($permissionMiddleware)->toBe([
        'permission:'.ComplianceExportDataset::routePermissionEnvelope(),
    ]);
});

test('each specific viewer exports only its own dataset without an unrelated permission', function () {
    $matrix = [
        [
            'permission' => 'hr.compliance.view',
            'allowed' => ['staff'],
        ],
        [
            'permission' => 'hr.vetting.view',
            'allowed' => ['vetting'],
        ],
        [
            'permission' => 'hr.driver.view',
            'allowed' => ['drivers'],
        ],
    ];

    foreach ($matrix as $row) {
        $viewer = complianceExportBoundaryViewer([$row['permission']], $this->exportAllowedSite);

        foreach (ComplianceExportDataset::cases() as $dataset) {
            $response = $this->actingAs($viewer)->getJson(route('hr.compliance.export', [
                'dataset' => $dataset->value,
            ]));

            if (in_array($dataset->value, $row['allowed'], true)) {
                $response->assertOk();
                expect($response->headers->get('content-type'))->toContain('text/csv');
            } else {
                $response->assertForbidden();
                expect($response->headers->get('content-disposition'))->toBeNull()
                    ->and((string) $response->headers->get('content-type'))->not->toContain('text/csv')
                    ->and($response->getContent())->not->toContain(
                        'Allowed Export Worker',
                        'ALLOWED VETTING PROVIDER',
                        'ALLOWED-DRV-001',
                    );
            }
        }
    }
});

test('renewals requires every emitted-domain action before CSV metadata', function (array $permissions) {
    config(['app.debug' => false]);
    $viewer = complianceExportBoundaryViewer($permissions, $this->exportAllowedSite);

    $response = $this->actingAs($viewer)
        ->getJson('/hr/compliance/export?dataset=renewals')
        ->assertForbidden();

    expect($response->headers->get('content-disposition'))->toBeNull()
        ->and((string) $response->headers->get('content-type'))->not->toContain('text/csv')
        ->and($response->getContent())->not->toContain(
            'Allowed Export Worker',
            'ALLOWED VETTING PROVIDER',
            'ALLOWED-DRV-001',
        );
})->with([
    'compliance only' => [['hr.compliance.view']],
    'missing employee action' => [[
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ]],
    'missing compliance action' => [[
        'hr.employees.viewAny',
        'hr.vetting.view',
        'hr.driver.view',
    ]],
    'missing vetting action' => [[
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.driver.view',
    ]],
    'missing driver action' => [[
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
    ]],
    'employee Site permissions do not replace mixed-domain actions' => [[
        'hr.employees.viewAny',
        'hr.employees.viewAllSites',
        'hr.compliance.view',
    ]],
]);

test('dataset exports preserve the current canonical Site boundary and minimum data', function () {
    $staffViewer = complianceExportBoundaryViewer(['hr.compliance.view'], $this->exportAllowedSite);
    $renewalsViewer = complianceExportBoundaryViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->exportAllowedSite);
    $vettingViewer = complianceExportBoundaryViewer(['hr.vetting.view'], $this->exportAllowedSite);
    $driverViewer = complianceExportBoundaryViewer(['hr.driver.view'], $this->exportAllowedSite);

    $staffCsv = $this->actingAs($staffViewer)
        ->get('/hr/compliance/export?dataset=staff')
        ->assertOk()
        ->streamedContent();
    expect($staffCsv)
        ->toContain('Allowed Export Worker', 'allowed-export-worker@work.example.test')
        ->not->toContain('Hidden Export Worker', 'hidden-export-worker@work.example.test');

    $renewalsCsv = $this->actingAs($renewalsViewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();
    expect($renewalsCsv)
        ->toContain(
            'Allowed Export Worker',
            'Export boundary requirement',
            'Police check',
            'Driver licence',
            'upcoming',
        )
        ->not->toContain(
            'Hidden Export Worker',
            'Inactive export boundary requirement',
            'Class 1 licence',
            'suspended',
            'ALLOWED-DRV-001',
            'ALLOWED VETTING PROVIDER',
            'ALLOWED-VET-001',
            'ALLOWED-COMPLIANCE-NOTES',
            'ALLOWED-VETTING-NOTES',
            'ALLOWED-DRIVER-NOTES',
            'allowed-export-worker@private.example.test',
            'allowed-export-worker@work.example.test',
        );

    $vettingCsv = $this->actingAs($vettingViewer)
        ->get('/hr/compliance/export?dataset=vetting')
        ->assertOk()
        ->streamedContent();
    expect($vettingCsv)
        ->toContain('Allowed Export Worker', 'ALLOWED VETTING PROVIDER', 'ALLOWED-VET-001')
        ->not->toContain('Hidden Export Worker', 'HIDDEN VETTING PROVIDER', 'HIDDEN-VET-001');

    $driverCsv = $this->actingAs($driverViewer)
        ->get('/hr/compliance/export?dataset=drivers')
        ->assertOk()
        ->streamedContent();
    expect($driverCsv)
        ->toContain('Allowed Export Worker', 'ALLOWED-DRV-001')
        ->not->toContain('Hidden Export Worker', 'HIDDEN-DRV-001');
});

test('renewals broadens Site scope only with the explicit HR all-Sites permission', function () {
    $viewer = complianceExportBoundaryViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
        'hr.employees.viewAllSites',
    ], $this->exportAllowedSite);

    $csv = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain(
            'Allowed Export Worker',
            'Hidden Export Worker',
            'Export boundary requirement',
        )
        ->not->toContain(
            'Inactive export boundary requirement',
            'ALLOWED VETTING PROVIDER',
            'HIDDEN VETTING PROVIDER',
            'ALLOWED-VET-001',
            'HIDDEN-VET-001',
            'ALLOWED-DRV-001',
            'HIDDEN-DRV-001',
            'ALLOWED-COMPLIANCE-NOTES',
            'HIDDEN-COMPLIANCE-NOTES',
            'ALLOWED-VETTING-NOTES',
            'HIDDEN-VETTING-NOTES',
            'ALLOWED-DRIVER-NOTES',
            'HIDDEN-DRIVER-NOTES',
            'Class 1 licence',
            'Class 2 licence',
            'suspended',
        );
});

test('renewals emits no staff rows when a fully authorised actor has no current Site access', function () {
    $viewer = complianceExportBoundaryViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->exportAllowedSite);
    $viewer->hrEmployeeProfile()->firstOrFail()->forceFill(['is_active' => false])->save();

    $csv = $this->actingAs($viewer)
        ->get('/hr/compliance/export?dataset=renewals')
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('Type,"Staff member",Item,"Due date",Status')
        ->not->toContain('Allowed Export Worker', 'Hidden Export Worker');
});

test('page affordance decisions match exact dataset permissions', function () {
    $complianceViewer = complianceExportBoundaryViewer(['hr.compliance.view'], $this->exportAllowedSite);
    $matrixViewer = complianceExportBoundaryViewer([
        'hr.compliance.view',
        'hr.compliance.manage',
    ], $this->exportAllowedSite);
    $renewalsViewer = complianceExportBoundaryViewer([
        'hr.employees.viewAny',
        'hr.compliance.view',
        'hr.vetting.view',
        'hr.driver.view',
    ], $this->exportAllowedSite);
    $vettingViewer = complianceExportBoundaryViewer(['hr.vetting.view'], $this->exportAllowedSite);
    $driverViewer = complianceExportBoundaryViewer(['hr.driver.view'], $this->exportAllowedSite);

    $this->actingAs($complianceViewer)
        ->get('/hr/compliance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', true));
    $this->actingAs($complianceViewer)
        ->get('/hr/compliance/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', false));
    $this->actingAs($renewalsViewer)
        ->get('/hr/compliance/calendar')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', true));
    $this->actingAs($matrixViewer)
        ->get('/hr/compliance/matrix')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', true));
    $this->actingAs($vettingViewer)
        ->get('/hr/compliance/vetting')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', true));
    $this->actingAs($driverViewer)
        ->get('/hr/compliance/drivers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.export', true));

    $this->actingAs($complianceViewer)->get('/hr/compliance/vetting')->assertForbidden();
    $this->actingAs($complianceViewer)->get('/hr/compliance/drivers')->assertForbidden();
    $this->actingAs($vettingViewer)->get('/hr/compliance')->assertForbidden();
    $this->actingAs($driverViewer)->get('/hr/compliance')->assertForbidden();
});

test('global role is admitted while forged and unauthenticated requests fail closed', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    ensureCanonicalHrStaffProfile($admin, $this->exportAllowedSite, [
        'position_role' => 'admin',
    ]);
    $admin->roles()->sync([
        Role::query()->where('name', 'admin')->firstOrFail()->id,
    ]);

    foreach (ComplianceExportDataset::cases() as $dataset) {
        $this->actingAs($admin)
            ->get('/hr/compliance/export?dataset='.$dataset->value)
            ->assertOk();
    }

    $specificViewer = complianceExportBoundaryViewer(['hr.vetting.view'], $this->exportAllowedSite);
    $unknown = $this->actingAs($specificViewer)
        ->getJson('/hr/compliance/export?dataset=renewals-unknown')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dataset');
    expect($unknown->headers->get('content-disposition'))->toBeNull()
        ->and((string) $unknown->headers->get('content-type'))->not->toContain('text/csv')
        ->and($unknown->getContent())->not->toContain('Staff member', 'Due date');
    $this->actingAs($specificViewer)
        ->getJson('/hr/compliance/export')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dataset');

    $noPermission = complianceExportBoundaryViewer([], $this->exportAllowedSite);
    $this->actingAs($noPermission)
        ->getJson('/hr/compliance/export?dataset=vetting')
        ->assertForbidden();

    auth()->logout();
    $this->get('/hr/compliance/export?dataset=staff')
        ->assertRedirect('/login');
});
