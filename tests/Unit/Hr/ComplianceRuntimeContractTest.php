<?php

use App\Domain\Hr\Jobs\DeliverComplianceReminderJob;
use App\Domain\Hr\Jobs\EvaluateComplianceMatrixJob;
use App\Domain\Hr\Jobs\RecoverComplianceReminderDeliveriesJob;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\HrComplianceReminderDeliveryService;
use App\Domain\Hr\Services\HrEvidencePackService;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Http\Controllers\Hr\ComplianceController;
use App\Http\Controllers\Hr\ComplianceMatrixController;
use App\Notifications\ComplianceReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

function complianceRuntimeContractSource(string $class): string
{
    return file_get_contents((new ReflectionClass($class))->getFileName());
}

/**
 * @return array{partition_where:string,partition_resolver:string,partition_property:string,organization_field:string,legacy_attributes:string,drop_indexes:string}
 */
function complianceRuntimeForbiddenFragments(): array
{
    $partition = 'ten'.'ant';
    $partitionClass = 'Ten'.'ant';
    $partitionIdentifier = $partition.'Id';
    $organization = 'organi'.'zation';

    return [
        'partition_where' => "where('{$partition}_id'",
        'partition_resolver' => $partitionIdentifier.'ForUser',
        'partition_property' => "public ?int \${$partitionIdentifier}",
        'organization_field' => $organization.'_id',
        'legacy_attributes' => 'LegacyStorageContext::'.'attributes()',
        'drop_indexes' => 'dropLegacy'.$partitionClass.'Indexes()',
    ];
}

test('compliance evaluation has no legacy partition behavior', function () {
    $forbidden = complianceRuntimeForbiddenFragments();

    foreach ([
        ComplianceMatrixService::class,
        LiveComplianceValidator::class,
        EvaluateComplianceMatrixJob::class,
        HrEvidencePackService::class,
    ] as $class) {
        expect(complianceRuntimeContractSource($class))
            ->not->toContain($forbidden['partition_where'])
            ->not->toContain($forbidden['partition_resolver'])
            ->not->toContain($forbidden['partition_property'])
            ->not->toContain($forbidden['organization_field']);
    }

    expect(complianceRuntimeContractSource(ComplianceMatrixService::class))
        ->toContain('HrCurrentStaffService')
        ->toContain('ComplianceRequirementApplicabilityService')
        ->toContain('mutationLocks->lock')
        ->toContain('lockForUpdate()')
        ->not->toContain($forbidden['legacy_attributes']);

    foreach ([
        ComplianceController::class,
        ComplianceMatrixController::class,
    ] as $class) {
        expect(complianceRuntimeContractSource($class))
            ->not->toContain($forbidden['legacy_attributes']);
    }
});

test('compliance response shaping and file lifecycle retain private boundaries', function () {
    $controller = complianceRuntimeContractSource(ComplianceController::class);

    expect($controller)
        ->not->toContain("'statuses' => \$statuses")
        ->toContain("\$canViewVetting = \$user->canDo('hr.vetting.view')")
        ->toContain("\$canViewDriver = \$user->canDo('hr.driver.view')")
        ->toContain('DB::afterRollBack')
        ->toContain("deleteEvidencePath('private', \$newEvidencePath)");
});

test('matrix and status identities are enforced globally before legacy indexes are removed', function () {
    $forbidden = complianceRuntimeForbiddenFragments();
    $root = dirname((new ReflectionClass(ComplianceMatrixController::class))->getFileName(), 5);
    $migration = $root.'/database/migrations/2026_07_23_000002_enforce_compliance_single_application_identity.php';

    expect($migration)->toBeFile();
    expect(file_get_contents($migration))
        ->toContain('assertNoCanonicalCollisions()')
        ->toContain('hr_compliance_requirements_code_uq')
        ->toContain('hr_comp_matrix_req_role_site_uq')
        ->toContain('hr_staff_comp_user_req_uq')
        ->toContain($forbidden['drop_indexes'])
        ->toContain("->update(['site_type' => null])");
});

test('manual compliance reminders use the queue', function () {
    expect(is_a(ComplianceReminderNotification::class, ShouldQueue::class, true))->toBeTrue();
});

test('matrix UI exposes a real Site type dimension and canonical Site choices', function () {
    $root = dirname((new ReflectionClass(ComplianceMatrixController::class))->getFileName(), 5);
    $matrix = file_get_contents($root.'/resources/js/pages/hr/compliance/matrix.tsx');
    $wizards = file_get_contents($root.'/resources/js/pages/hr/compliance/components/compliance-wizards.tsx');
    $wizardData = file_get_contents($root.'/app/Http/Controllers/Hr/Concerns/ProvidesComplianceWizardData.php');

    expect($matrix)
        ->toContain("const [siteScope, setSiteScope] = useState('all')")
        ->toContain('entryScope === normalizedScope')
        ->toContain('Matrix Site type')
        ->toContain("scope === 'all' ? 'All Sites'")
        ->and($wizardData)
        ->toContain('Site::query()')
        ->toContain('->pluck(\'type\')')
        ->and($wizards)
        ->not->toContain("['residential', 'respite', 'community', 'day']")
        ->toContain('Choices come')
        ->toContain('from the active Sites register.');
});

test('evidence packs use canonical Site privacy and exact applicability snapshots', function () {
    $source = complianceRuntimeContractSource(HrEvidencePackService::class);

    expect($source)
        ->toContain('applyStaffScope')
        ->toContain('currentStaff->isCurrent')
        ->toContain("canDo('hr.employees.viewRestricted')")
        ->toContain("where('is_restricted', false)")
        ->toContain('snapshotsForUsers');
});

test('compliance reminders use a durable outbox delivery and scheduled recovery boundary', function () {
    $root = dirname((new ReflectionClass(ComplianceMatrixController::class))->getFileName(), 5);
    $migration = $root.'/database/migrations/2026_07_23_000003_create_hr_compliance_reminder_deliveries_table.php';
    $schedule = file_get_contents($root.'/routes/console.php');

    expect($migration)->toBeFile()
        ->and(file_get_contents($migration))
        ->toContain('hr_comp_reminder_delivery_key_uq')
        ->toContain('hr_comp_reminder_recovery_idx')
        ->and(complianceRuntimeContractSource(HrComplianceReminderDeliveryService::class))
        ->toContain('firstOrCreate')
        ->toContain('sendNow')
        ->toContain('recoverPending')
        ->and(is_a(DeliverComplianceReminderJob::class, ShouldQueue::class, true))->toBeTrue()
        ->and(is_a(RecoverComplianceReminderDeliveriesJob::class, ShouldQueue::class, true))->toBeTrue()
        ->and($schedule)->toContain('RecoverComplianceReminderDeliveriesJob');
});

test('manual status writes lock and recheck active requirements', function () {
    $controller = complianceRuntimeContractSource(ComplianceController::class);

    expect(substr_count($controller, "->where('is_active', true)"))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($controller, '->lockForUpdate()'))->toBeGreaterThanOrEqual(8);
});
