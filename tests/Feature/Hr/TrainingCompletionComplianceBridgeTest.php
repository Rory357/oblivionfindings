<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Services\ComplianceMatrixService;
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

test('completing a mapped hr course creates the compliance training record', function () {
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $role = Role::query()->where('name', 'support_worker')->firstOrFail();
    $staff->roles()->syncWithoutDetaching([$role->id]);

    $legacyCourse = TrainingCourse::query()->create([
        'name' => 'First Aid Refresher',
        'code' => 'FIRST-AID',
        'category' => 'clinical',
        'requires_renewal' => true,
        'validity_period_months' => 12,
        'active' => true,
    ]);

    $requirement = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'FIRST_AID',
        'name' => 'Current first aid certificate',
        'category' => 'clinical',
        'check_type' => 'training_course',
        'reference_id' => $legacyCourse->id,
        'validity_months' => 12,
        'renewal_reminder_days' => 30,
        'hard_stop' => true,
        'is_active' => true,
    ]);

    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_mandatory' => true,
    ]);

    $hrCourse = HrCourse::factory()->create([
        'tenant_id' => 1,
        'title' => 'First Aid Refresher',
        'code' => 'HR-FIRST-AID',
        'is_mandatory' => true,
        'compliance_requirement_id' => $requirement->id,
    ]);

    $enrollment = HrCourseEnrollment::factory()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'course_id' => $hrCourse->id,
        'status' => 'enrolled',
        'enrolled_at' => now()->subDay(),
    ]);

    app(TrainingService::class)->completeEnrollment($enrollment, [
        'score' => 92,
        'certificate_path' => 'certificates/first-aid.pdf',
    ]);

    $record = StaffTrainingRecord::query()
        ->where('user_id', $staff->id)
        ->where('training_course_id', $legacyCourse->id)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe('completed');
    expect((float) $record->assessment_score)->toBe(92.0);
    expect($record->certificate_path)->toBe('certificates/first-aid.pdf');
    expect($record->expires_at?->toDateString())->toBe(now()->addMonths(12)->toDateString());

    app(ComplianceMatrixService::class)->evaluateStaff($staff->fresh('roles'));

    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'training_record',
        'evidence_id' => $record->id,
    ]);
});
