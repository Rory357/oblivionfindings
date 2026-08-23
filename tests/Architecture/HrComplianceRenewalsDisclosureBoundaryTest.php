<?php

use App\Domain\Hr\Enums\ComplianceExportDataset;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;

it('binds every renewals model family to its action permission before stream metadata', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/ComplianceExportController.php');

    expect(ComplianceExportDataset::Renewals->emittedModelPermissions())->toBe([
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
    ])->and(UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS)->toBe([
        'hr.employees.viewAllSites',
    ]);

    $authorization = strpos($controller, 'abort_unless($dataset->allows($user), 403);');
    $filename = strpos($controller, '$filename = "compliance-{$dataset->value}-"');
    $stream = strpos($controller, 'return response()->streamDownload(');

    expect($authorization)->not->toBeFalse()
        ->and($filename)->not->toBeFalse()
        ->and($stream)->not->toBeFalse()
        ->and($authorization)->toBeLessThan($filename)
        ->and($authorization)->toBeLessThan($stream);
});

it('keeps renewals on canonical Site and requirement scope with minimal driver output', function (): void {
    $root = dirname(__DIR__, 2);
    $controller = file_get_contents($root.'/app/Http/Controllers/Hr/ComplianceExportController.php');
    $matrix = file_get_contents($root.'/app/Domain/Hr/Services/ComplianceMatrixService.php');
    $applicability = file_get_contents($root.'/app/Domain/Hr/Services/ComplianceRequirementApplicabilityService.php');
    $renewalsStart = strpos($controller, 'private function streamRenewals(');
    $renewalsEnd = strpos($controller, 'private function renewalTimingStatus(');

    expect($renewalsStart)->not->toBeFalse()
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
