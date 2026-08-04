<?php

use App\Http\Controllers\Hr\ComplianceCalendarController;
use App\Http\Controllers\Hr\ComplianceController;
use App\Http\Controllers\Hr\ComplianceMatrixController;

function complianceContractSource(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();

    return file_get_contents($file);
}

/** @return array{storage_context:string,partition_where:string,partition_field:string} */
function complianceContractForbiddenFragments(): array
{
    $partition = 'ten'.'ant';

    return [
        'storage_context' => 'hrApplicationStorage'.'ContextId',
        'partition_where' => "where('{$partition}_id'",
        'partition_field' => $partition.'_id',
    ];
}

test('compliance controllers do not use legacy storage context as an access boundary', function () {
    $forbidden = complianceContractForbiddenFragments();

    foreach ([
        ComplianceController::class,
        ComplianceCalendarController::class,
        ComplianceMatrixController::class,
    ] as $controller) {
        expect(complianceContractSource($controller))
            ->not->toContain('ResolvesHrOrganisationContext')
            ->not->toContain($forbidden['storage_context'])
            ->not->toContain($forbidden['partition_where']);
    }
});

test('calendar and renewal actions retain canonical Site scope and durable snoozes', function () {
    $calendar = complianceContractSource(ComplianceCalendarController::class);
    $compliance = complianceContractSource(ComplianceController::class);

    expect($calendar)
        ->toContain('applyStaffScope')
        ->toContain("activeSnoozedIds('compliance')")
        ->toContain("activeSnoozedIds('vetting')")
        ->toContain("activeSnoozedIds('driver')")
        ->and($compliance)
        ->toContain('lockAndReauthoriseRenewable')
        ->toContain('HrComplianceRenewalSnooze')
        ->not->toContain('function assign(');
});

test('new renewal snooze persistence is single application and matrix owns assignment', function () {
    $forbidden = complianceContractForbiddenFragments();
    $root = dirname((new ReflectionClass(ComplianceController::class))->getFileName(), 5);
    $migration = $root.'/database/migrations/2026_07_23_000001_create_hr_compliance_renewal_snoozes_table.php';
    $routes = file_get_contents($root.'/routes/hr.php');

    expect($migration)->toBeFile();
    expect(file_get_contents($migration))
        ->toContain("Schema::create('hr_compliance_renewal_snoozes'")
        ->not->toContain($forbidden['partition_field'])
        ->and($routes)
        ->toContain("[ComplianceMatrixController::class, 'assign']")
        ->toContain("->whereNumber('requirement')->name('compliance.requirements.update')")
        ->toContain("->whereNumber('requirement')->name('compliance.requirements.destroy')")
        ->toContain('compliance.invalid.requirements.update')
        ->toContain('compliance.invalid.requirements.destroy');
});
