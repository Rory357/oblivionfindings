<?php

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;

it('keeps route admission and response authorization on the dataset policy', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = file_get_contents($root.'/routes/hr.php');
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/ComplianceExportController.php');

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
    ])->and(ComplianceExportDataset::routePermissionEnvelope())
        ->toBe('hr.compliance.view|hr.vetting.view|hr.driver.view|hr.employees.viewAny');

    $exportRoute = strpos($routes, "Route::get('/compliance/export'");
    $complianceGroup = strpos($routes, "Route::middleware('permission:hr.compliance.view')->group");
    $authorization = strpos($controller, 'abort_unless($dataset->allows($user), 403);');
    $filename = strpos($controller, '$filename = "compliance-{$dataset->value}-"');
    $stream = strpos($controller, 'return response()->streamDownload(');

    expect($routes)
        ->toContain("->middleware('permission:'.ComplianceExportDataset::routePermissionEnvelope())")
        ->and($controller)
        ->toContain('Rule::enum(ComplianceExportDataset::class)')
        ->toContain('ComplianceExportDataset::from($validated[\'dataset\'])')
        ->and($exportRoute)->not->toBeFalse()
        ->and($complianceGroup)->not->toBeFalse()
        ->and($exportRoute)->toBeLessThan($complianceGroup)
        ->and($authorization)->not->toBeFalse()
        ->and($filename)->not->toBeFalse()
        ->and($stream)->not->toBeFalse()
        ->and($authorization)->toBeLessThan($filename)
        ->and($authorization)->toBeLessThan($stream);
});

it('binds every mixed renewals model to its action and minimum Site scoped output', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/ComplianceExportController.php');
    $matrix = file_get_contents($root.'/app/Domain/Hr/Services/ComplianceMatrixService.php');
    $applicability = file_get_contents($root.'/app/Domain/Hr/Services/ComplianceRequirementApplicabilityService.php');
    $renewalsStart = strpos($controller, 'private function streamRenewals(');
    $renewalsEnd = strpos($controller, 'private function renewalTimingStatus(');

    expect(ComplianceExportDataset::Renewals->emittedModelPermissions())->toBe([
        User::class => 'hr.employees.viewAny',
        HrComplianceRequirement::class => 'hr.compliance.view',
        HrStaffComplianceStatus::class => 'hr.compliance.view',
        StaffBackgroundCheck::class => 'hr.vetting.view',
        HrDriverEligibility::class => 'hr.driver.view',
    ])->and(UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS)->toBe([
        'hr.employees.viewAllSites',
    ])->and($renewalsStart)->not->toBeFalse()
        ->and($renewalsEnd)->not->toBeFalse()
        ->and($renewalsStart)->toBeLessThan($renewalsEnd);

    $renewals = substr($controller, $renewalsStart, $renewalsEnd - $renewalsStart);
    $driverStart = strpos($renewals, 'HrDriverEligibility::query()');
    expect($driverStart)->not->toBeFalse();
    $driver = substr($renewals, $driverStart);

    expect($renewals)
        ->toContain('$this->siteAccess->applyHrEmployeeStaffScope($staff, $viewer);')
        ->toContain('$this->visibleRenewalStaffIds($viewer)')
        ->toContain('$this->complianceMatrix->snapshotsForUsers($users)')
        ->not->toContain('visibleCurrentStaffIds')
        ->and($driver)
        ->toContain("'Driver licence'")
        ->toContain('$this->renewalTimingStatus($r->licence_expires_at)')
        ->not->toContain(
            'licence_class',
            'licence_number',
            'licence_endorsements',
            'suspension_reason',
            '$r->status',
        )
        ->and($matrix)->toContain('return $this->applicability->snapshotsForUsers($users);')
        ->and($applicability)
        ->toContain("->where('is_active', true)")
        ->toContain("->with('matrixEntries:id,requirement_id,role,site_type')");
});

it('keeps every export affordance on the same server dataset decision', function (): void {
    $root = dirname(__DIR__, 2);
    $presenters = [
        'app/Http/Controllers/Hr/ComplianceController.php' => 'ComplianceExportDataset::Staff->allows($user)',
        'app/Http/Controllers/Hr/ComplianceMatrixController.php' => 'ComplianceExportDataset::Staff->allows($user)',
        'app/Http/Controllers/Hr/ComplianceCalendarController.php' => 'ComplianceExportDataset::Renewals->allows($user)',
        'app/Http/Controllers/Hr/VettingController.php' => 'ComplianceExportDataset::Vetting->allows($user)',
        'app/Http/Controllers/Hr/DriverEligibilityController.php' => 'ComplianceExportDataset::Drivers->allows($user)',
    ];
    $surfaces = [
        'resources/js/pages/hr/compliance/index.tsx' => "complianceExportHref('overview', can.export)",
        'resources/js/pages/hr/vetting/index.tsx' => "complianceExportHref('vetting', can.export)",
        'resources/js/pages/hr/drivers/index.tsx' => "complianceExportHref('drivers', can.export)",
    ];
    $hubSurfaces = [
        'resources/js/pages/hr/compliance/index.tsx' => 'active="overview"',
        'resources/js/pages/hr/compliance/matrix.tsx' => 'active="matrix"',
        'resources/js/pages/hr/compliance/calendar.tsx' => 'active="calendar"',
        'resources/js/pages/hr/vetting/index.tsx' => 'active="vetting"',
        'resources/js/pages/hr/drivers/index.tsx' => 'active="drivers"',
    ];

    foreach ($presenters as $path => $decision) {
        expect(file_get_contents($root.'/'.$path))->toContain("'export' => {$decision}");
    }

    foreach ($surfaces as $path => $decision) {
        $source = file_get_contents($root.'/'.$path);

        expect($source)
            ->toContain($decision)
            ->not->toContain('/hr/compliance/export?dataset=');
    }

    foreach ($hubSurfaces as $path => $activeTab) {
        expect(file_get_contents($root.'/'.$path))
            ->toContain('<ComplianceHubHeader')
            ->toContain($activeTab)
            ->toContain('export: can.export')
            ->not->toContain('/hr/compliance/export?dataset=');
    }

    $header = file_get_contents($root.'/resources/js/pages/hr/compliance/components/compliance-hub-header.tsx');
    $hrefPolicy = file_get_contents($root.'/resources/js/lib/hr/compliance-export.ts');

    expect($header)
        ->toContain('complianceExportHref(active, can.export === true)')
        ->toContain('...(exportHref')
        ->toContain('window.location.href = exportHref')
        ->not->toContain('go(exportHref)')
        ->not->toContain('/hr/compliance/export?dataset=')
        ->and($hrefPolicy)
        ->toContain("overview: 'staff'")
        ->toContain("matrix: 'staff'")
        ->toContain("calendar: 'renewals'")
        ->toContain("vetting: 'vetting'")
        ->toContain("drivers: 'drivers'")
        ->toContain(': string | null')
        ->toContain('return allowed')
        ->toContain(': null;');
});
