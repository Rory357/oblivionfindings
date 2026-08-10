<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Domain\Hr\Services\TrainingService;
use App\Models\Role;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function unifStaff(): User
{
    $staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $staff->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);

    return $staff;
}

function trainingHardStop(int $legacyCourseId): HrComplianceRequirement
{
    $req = HrComplianceRequirement::create([
        'code' => 'REQ-'.$legacyCourseId, 'name' => 'Mandatory training',
        'category' => 'clinical', 'check_type' => 'training_course', 'reference_id' => $legacyCourseId,
        'validity_months' => 12, 'renewal_reminder_days' => 30, 'hard_stop' => true, 'is_active' => true,
    ]);
    HrComplianceMatrix::create([
        'requirement_id' => $req->id, 'role' => 'support_worker',
        'site_type' => 'all', 'is_mandatory' => true,
    ]);

    return $req;
}

test('a catalog completion without a compliance requirement still creates a StaffTrainingRecord keyed by hr_course_id', function () {
    $staff = unifStaff();
    $course = HrCourse::factory()->create(['validity_period_months' => 12]);
    $enr = HrCourseEnrollment::factory()->create([
        'user_id' => $staff->id, 'course_id' => $course->id, 'status' => 'enrolled',
    ]);

    app(TrainingService::class)->completeEnrollment($enr->fresh(), ['score' => 90, 'completed_at' => now()->toDateString()]);

    $rec = StaffTrainingRecord::where('user_id', $staff->id)->where('hr_course_id', $course->id)->first();
    expect($rec)->not->toBeNull();
    expect($rec->status)->toBe('completed');
    expect($rec->training_course_id)->toBeNull();
    expect($rec->expires_at?->toDateString())->toBe(now()->addMonths(12)->toDateString());
});

test('live hard-stop validation resolves training compliance via the HrCourse link', function () {
    $staff = unifStaff();
    $legacy = TrainingCourse::create(['name' => 'First Aid', 'code' => 'FA', 'category' => 'clinical', 'requires_renewal' => true, 'validity_period_months' => 12, 'active' => true]);
    $req = trainingHardStop($legacy->id);
    $course = HrCourse::factory()->create(['compliance_requirement_id' => $req->id]);

    // Not yet completed → the hard stop blocks assignment.
    expect(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])->toBeFalse();

    // Completing the catalog course must clear the hard stop (via the hr_course_id record).
    $enr = HrCourseEnrollment::factory()->create(['user_id' => $staff->id, 'course_id' => $course->id, 'status' => 'enrolled']);
    app(TrainingService::class)->completeEnrollment($enr->fresh(), ['completed_at' => now()->toDateString()]);

    expect(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])->toBeTrue();
});

test('live hard-stop validation still honours a legacy training_course_id record (backward compatible)', function () {
    $staff = unifStaff();
    $legacy = TrainingCourse::create(['name' => 'Manual Handling', 'code' => 'MH', 'category' => 'safety', 'requires_renewal' => true, 'validity_period_months' => 12, 'active' => true]);
    trainingHardStop($legacy->id); // no HrCourse linked — legacy-only requirement

    // A pre-unification record carrying only training_course_id must still satisfy it.
    StaffTrainingRecord::create([
        'user_id' => $staff->id, 'training_course_id' => $legacy->id, 'status' => 'completed', 'completed_at' => now()->subMonths(3),
    ]);

    expect(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])->toBeTrue();
});

test('expired catalog completion is blocked by the live hard stop', function () {
    $staff = unifStaff();
    $legacy = TrainingCourse::create(['name' => 'IPC', 'code' => 'IPC', 'category' => 'clinical', 'requires_renewal' => true, 'validity_period_months' => 12, 'active' => true]);
    $req = trainingHardStop($legacy->id);
    $course = HrCourse::factory()->create(['compliance_requirement_id' => $req->id, 'validity_period_months' => 12]);

    // A completion 18 months ago against a 12-month validity requirement → expired → blocked.
    StaffTrainingRecord::create([
        'user_id' => $staff->id, 'hr_course_id' => $course->id, 'training_course_id' => $legacy->id,
        'status' => 'completed', 'completed_at' => now()->subMonths(18),
    ]);

    expect(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])->toBeFalse();
});
