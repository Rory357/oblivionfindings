<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Models\Role;
use App\Models\StaffTrainingRecord;
use App\Models\User;

/**
 * Seam S7 — Training → Compliance. A `training_course` hard-stop compliance
 * requirement is satisfied by the staff member's COMPLETED training record:
 * TrainingService::syncComplianceTrainingRecord writes a StaffTrainingRecord
 * (keyed by user + hr_course_id) on every catalog completion ("EVERY catalog
 * completion is compliance-visible"), and LiveComplianceValidator::
 * validateTrainingRequirements reads it (via the HrCourse.compliance_requirement_id
 * back-link) to pass/fail the requirement — which then gates rostering (S3/S6).
 * This proves the consumer end: no completion → hard-stop failure; a completed
 * record → passes. The requirement must be mapped to the user's role via
 * HrComplianceMatrix — the live validator is role-scoped (S6 lesson).
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $supportRole = Role::query()->where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->req = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'TRN-01',
        'name' => 'Manual Handling Training',
        'category' => 'Clinical',
        'check_type' => 'training_course',
        'hard_stop' => true,
        'is_active' => true,
        'created_by' => $this->staff->id,
    ]);
    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $this->req->id,
        'role' => 'support_worker',
        'site_type' => null,
        'is_mandatory' => true,
    ]);
    $this->course = HrCourse::factory()->create([
        'compliance_requirement_id' => $this->req->id,
    ]);
});

test('S7 seam: a training_course requirement with no completed record hard-stops compliance', function () {
    $result = app(LiveComplianceValidator::class)->validateHardStops($this->staff->fresh());

    expect($result['passed'])->toBeFalse();
    expect(collect($result['failures'])->pluck('requirement')->implode(' '))
        ->toContain('Manual Handling Training');
});

test('S7 seam: completing the linked training course satisfies the compliance requirement', function () {
    // What TrainingService::syncComplianceTrainingRecord writes on completion.
    StaffTrainingRecord::query()->create([
        'user_id' => $this->staff->id,
        'hr_course_id' => $this->course->id,
        'status' => 'completed',
        'enrolled_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
        'completion_date' => now()->subDay()->toDateString(),
    ]);

    $result = app(LiveComplianceValidator::class)->validateHardStops($this->staff->fresh());

    expect(collect($result['failures'])->pluck('requirement')->implode(' '))
        ->not->toContain('Manual Handling Training');
});
