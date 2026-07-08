<?php

use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\ProcedureAcknowledgement;
use App\Models\SafeWorkProcedure;
use App\Models\User;

/**
 * Seam S16 — Safe Work Procedures (H&S-owned) ↔ HR compliance/training.
 *
 * Source-audited relationship: H&S OWNS Safe Work Procedures and their
 * acknowledgements (`SafeWorkProcedure` / `ProcedureAcknowledgement` /
 * `SafeWorkProcedureController`, `procedures.*` permission). HR FEDERATES the
 * procedure DISPLAY read-only — `MyHrController::index` (/hr/my) and
 * `EmployeeProfileController::employeeProcedures` (the employee profile) both
 * surface `SafeWorkProcedure::applicableToRoles(...)` + the viewer's ack status
 * from `ProcedureAcknowledgement`, gated on the H&S `procedures.view` permission,
 * deep-linking to the H&S register to actually acknowledge. HR never writes an ack.
 *
 * Procedure acks do NOT feed HR compliance: `LiveComplianceValidator` dispatches on
 * check_types `training_course` / `credential` / `background_check` /
 * `policy_attestation` / `driver_licence` / `manual` — there is no `procedure`
 * type. So procedure compliance is tracked by H&S; HR compliance is a separate
 * owner. One owner per fact; a clean read-only federation (like S2 Assets /
 * S4 Injuries), not a fork or a broken link.
 *
 * This test guards both halves of the boundary. Whether a mandatory safe-work
 * procedure ack SHOULD also count toward HR role compliance / rostering hard-stops
 * is Decision D-11 for Chane — not wired unilaterally (cross-module + policy).
 */
test('S16 seam: a Safe Work Procedure ack is H&S-owned, federates read-only into HR, and never writes HR compliance', function () {
    $procedure = SafeWorkProcedure::factory()->create();
    $staff = User::factory()->create(['organization_id' => 1]);

    // What SafeWorkProcedureController writes when the worker acknowledges (H&S path).
    ProcedureAcknowledgement::create([
        'safe_work_procedure_id' => $procedure->id,
        'user_id' => $staff->id,
        'version_acknowledged' => 1,
        'acknowledged_at' => now(),
    ]);

    // The ack lives entirely in H&S…
    expect(ProcedureAcknowledgement::where('user_id', $staff->id)->count())->toBe(1);

    // …HR federates it read-only via the exact query its two surfaces use
    // (pluck version_acknowledged keyed by procedure id) — the shared H&S ack row…
    $ackedVersions = ProcedureAcknowledgement::query()
        ->where('user_id', $staff->id)
        ->pluck('version_acknowledged', 'safe_work_procedure_id');
    expect($ackedVersions[$procedure->id] ?? null)->toBe(1);

    // …and the ack never created an HR compliance status. 'procedure' is not an HR
    // compliance check_type, so procedure acks are H&S-tracked, not HR compliance.
    expect(HrStaffComplianceStatus::where('user_id', $staff->id)->count())->toBe(0);
});
