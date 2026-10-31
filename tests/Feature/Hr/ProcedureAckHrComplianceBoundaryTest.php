<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Permission;
use App\Models\ProcedureAcknowledgement;
use App\Models\ProcedureAcknowledgementReceipt;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Database\Seeders\RbacSeeder;

/**
 * HR displays source-owned exact publication reading receipts. These receipts do
 * not imply training competence, role compliance, or a rostering clearance.
 */
test('S16 seam: an exact procedure reading receipt federates into HR without writing HR compliance', function () {
    $this->seed(RbacSeeder::class);
    $site = Site::factory()->create(['type' => 'house']);
    $staff = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
    $permission = Permission::where('key', 'procedures.view')->firstOrFail();
    $staff->permissionOverrides()->sync([$permission->id => ['allowed' => true]]);
    HrEmployeeProfile::factory()->create(['user_id' => $staff->id, 'primary_site_id' => $site->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
    $procedure = SafeWorkProcedure::factory()->approved()->create([
        'applicable_roles' => [], 'applicable_sites' => [$site->id],
    ])->refresh();

    // Preserve old evidence, but an old numeric stamp cannot prove this exact copy was read.
    ProcedureAcknowledgement::create(['safe_work_procedure_id' => $procedure->id,
        'user_id' => $staff->id, 'version_acknowledged' => $procedure->current_version,
        'acknowledged_at' => now()->subDay()]);
    $before = SafeWorkProcedurePresenter::applicableRows($staff, '/hr/my', null, $staff)->firstWhere('id', $procedure->id);
    expect($before['acknowledged'])->toBeFalse()
        ->and($before['href'])->toBe(SafeWorkProcedureReturn::href($procedure->id, '/hr/my'));

    $service = app(SafeWorkProcedureService::class);
    $receipt = $service->acknowledge($procedure, $staff, ['published_version_id' => $procedure->published_version_id]);
    $retry = $service->acknowledge($procedure, $staff, ['published_version_id' => $procedure->published_version_id]);
    expect($retry->id)->toBe($receipt->id)
        ->and(ProcedureAcknowledgementReceipt::where('user_id', $staff->id)->count())->toBe(1)
        ->and($receipt->version_id)->toBe($procedure->published_version_id)
        ->and($receipt->provenance)->toBe('controlled');

    $row = SafeWorkProcedurePresenter::applicableRows($staff, '/hr/my', null, $staff)->firstWhere('id', $procedure->id);
    expect($row['acknowledged'])->toBeTrue()
        ->and($row['published_version_id'])->toBe($receipt->version_id)
        ->and(ProcedureAcknowledgement::where('user_id', $staff->id)->count())->toBe(1)
        ->and(HrStaffComplianceStatus::where('user_id', $staff->id)->count())->toBe(0);
});
