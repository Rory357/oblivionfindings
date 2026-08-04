<?php

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Services\CertificateService;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\LiveComplianceValidator;
use App\Domain\Hr\Services\TrainingService;
use App\Models\Role;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('completing a mapped hr course creates the compliance training record', function () {
    $staff = User::factory()->create([
        'role' => 'support_worker',
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
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_mandatory' => true,
    ]);

    $hrCourse = HrCourse::factory()->create([
        'title' => 'First Aid Refresher',
        'code' => 'HR-FIRST-AID',
        'is_mandatory' => true,
        'compliance_requirement_id' => $requirement->id,
    ]);

    $enrollment = HrCourseEnrollment::factory()->create([
        'user_id' => $staff->id,
        'course_id' => $hrCourse->id,
        'status' => 'enrolled',
        'enrolled_at' => now()->subDay(),
    ]);

    app(TrainingService::class)->completeEnrollment($enrollment, [
        'score' => 92,
        'certificate_number' => 'FIRST-AID-2026-001',
        'certificate_path' => 'certificates/first-aid.pdf',
    ]);

    $record = StaffTrainingRecord::query()
        ->where('user_id', $staff->id)
        ->where('training_course_id', $legacyCourse->id)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe('completed');
    expect((float) $record->assessment_score)->toBe(92.0);
    expect($record->certificate_number)->toBe('FIRST-AID-2026-001');
    expect($record->certificate_path)->toBe('certificates/first-aid.pdf');
    expect($record->expires_at?->toDateString())->toBe(now()->addMonths(12)->toDateString());

    app(ComplianceMatrixService::class)->evaluateStaff($staff->fresh('roles'));

    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'user_id' => $staff->id,
        'requirement_id' => $requirement->id,
        'status' => 'compliant',
        'evidence_type' => 'training_record',
        'evidence_id' => $record->id,
    ]);
});

test('generated certificates persist one number and private path across both training records', function () {
    Storage::fake('private');
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $course = HrCourse::factory()->create([
        'title' => 'Safe practice',
        'code' => 'SAFE-PRACTICE',
    ]);
    $enrollment = HrCourseEnrollment::factory()->create([
        'user_id' => $staff->id,
        'course_id' => $course->id,
        'status' => 'enrolled',
    ]);
    $enrollment = app(TrainingService::class)->completeEnrollment($enrollment);

    $path = app(CertificateService::class)->generateCertificate($enrollment);
    $fresh = $enrollment->fresh();
    $record = StaffTrainingRecord::query()
        ->where('user_id', $staff->id)
        ->where('hr_course_id', $course->id)
        ->firstOrFail();

    expect($path)->toStartWith("hr/training/certificates/{$enrollment->id}/")
        ->and($fresh->certificate_number)->toStartWith('CERT-')
        ->and($fresh->certificate_path)->toBe($path)
        ->and($record->certificate_number)->toBe($fresh->certificate_number)
        ->and($record->certificate_path)->toBe($path);
    Storage::disk('private')->assertExists($path);
});

test('a failed required assessment never clears compliance or its training assignment', function () {
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $staff->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    $legacyCourse = TrainingCourse::query()->create([
        'name' => 'Medication assessment',
        'code' => 'MED-ASSESSMENT',
        'category' => 'clinical',
        'requires_renewal' => true,
        'validity_period_months' => 12,
        'active' => true,
    ]);
    $requirement = HrComplianceRequirement::query()->create([
        'code' => 'MED_ASSESSMENT',
        'name' => 'Medication assessment',
        'category' => 'clinical',
        'check_type' => 'training_course',
        'reference_id' => $legacyCourse->id,
        'validity_months' => 12,
        'renewal_reminder_days' => 30,
        'hard_stop' => true,
        'is_active' => true,
    ]);
    HrComplianceMatrix::query()->create([
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_mandatory' => true,
    ]);
    $course = HrCourse::factory()->create([
        'title' => 'Medication assessment',
        'code' => 'HR-MED-ASSESSMENT',
        'requires_assessment' => true,
        'pass_mark_percentage' => 80,
        'validity_period_months' => 12,
        'compliance_requirement_id' => $requirement->id,
    ]);
    $assignment = HrCourseAssignment::factory()->create([
        'user_id' => $staff->id,
        'hr_course_id' => $course->id,
        'status' => 'assigned',
    ]);
    $enrollment = HrCourseEnrollment::factory()->create([
        'user_id' => $staff->id,
        'course_id' => $course->id,
        'status' => 'enrolled',
    ]);

    $failed = app(TrainingService::class)->completeEnrollment($enrollment, ['score' => 65]);
    $record = StaffTrainingRecord::query()
        ->where('user_id', $staff->id)
        ->where('hr_course_id', $course->id)
        ->firstOrFail();

    expect($failed->status)->toBe('failed')
        ->and($record->status)->toBe('failed')
        ->and($record->assessment_passed)->toBeFalse()
        ->and($assignment->fresh()->status)->toBe('assigned')
        ->and(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])
        ->toBeFalse();

    app(ComplianceMatrixService::class)->evaluateStaff($staff->fresh('roles'));
    $this->assertDatabaseHas('hr_staff_compliance_status', [
        'user_id' => $staff->id,
        'requirement_id' => $requirement->id,
        'status' => 'not_started',
    ]);

    $passed = app(TrainingService::class)->completeEnrollment($failed->fresh(), ['score' => 85]);

    expect($passed->status)->toBe('completed')
        ->and($record->fresh()->status)->toBe('completed')
        ->and($record->fresh()->assessment_passed)->toBeTrue()
        ->and($assignment->fresh()->status)->toBe('completed')
        ->and(app(LiveComplianceValidator::class)->validateHardStops($staff->fresh('roles'))['passed'])
        ->toBeTrue();
});

test('completion evidence rejects missing assessment scores and future dates', function () {
    $staff = User::factory()->create(['approved_at' => now()]);
    $course = HrCourse::factory()->create([
        'requires_assessment' => true,
        'pass_mark_percentage' => 80,
    ]);
    $enrollment = HrCourseEnrollment::factory()->create([
        'user_id' => $staff->id,
        'course_id' => $course->id,
        'status' => 'enrolled',
    ]);

    expect(fn () => app(TrainingService::class)->completeEnrollment($enrollment))
        ->toThrow(ValidationException::class);
    expect(fn () => app(TrainingService::class)->completeEnrollment($enrollment->fresh(), [
        'score' => 90,
        'completed_at' => now()->addDay(),
    ]))->toThrow(ValidationException::class);

    expect($enrollment->fresh()->status)->toBe('enrolled');
    $this->assertDatabaseMissing('staff_training_records', [
        'user_id' => $staff->id,
        'hr_course_id' => $course->id,
    ]);
});

test('course policy requires one normalized application code and an assessment pass mark', function () {
    $service = app(TrainingService::class);

    expect(fn () => $service->createCourse([
        'title' => 'Unsafe assessment policy',
        'code' => 'UNSAFE-ASSESSMENT',
        'requires_assessment' => true,
    ]))->toThrow(ValidationException::class);

    $course = $service->createCourse([
        'title' => 'Safe practice',
        'code' => ' SAFE-PRACTICE ',
        'requires_assessment' => true,
        'pass_mark_percentage' => 80,
    ]);

    expect($course->code)->toBe('SAFE-PRACTICE');
    expect(fn () => $service->createCourse([
        'title' => 'Duplicate safe practice',
        'code' => 'safe-practice',
    ]))->toThrow(ValidationException::class);
    expect(fn () => $service->updateCourse($course, [
        'requires_assessment' => true,
        'pass_mark_percentage' => null,
    ]))->toThrow(ValidationException::class);

    expect(HrCourse::query()->count())->toBe(1);
});
