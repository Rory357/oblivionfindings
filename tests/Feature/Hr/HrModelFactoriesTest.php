<?php

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;

test('core HR workhorse factories create valid records', function () {
    $profile = HrEmployeeProfile::factory()->create();
    $balance = HrLeaveBalance::factory()->create([
        'user_id' => $profile->user_id,
        'leave_type' => 'annual',
        'year' => 2026,
    ]);
    $candidate = HrCandidate::factory()->create();
    $application = HrApplication::factory()->create([
        'candidate_id' => $candidate->id,
    ]);
    $timeEntry = HrTimeEntry::factory()->create([
        'user_id' => $profile->user_id,
    ]);
    $document = HrDocument::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);
    $payrollRun = HrPayrollRun::factory()->create();
    $course = HrCourse::factory()->create();
    $enrollment = HrCourseEnrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $profile->user_id,
    ]);

    expect($profile->exists)->toBeTrue()
        ->and($balance->exists)->toBeTrue()
        ->and($candidate->exists)->toBeTrue()
        ->and($application->exists)->toBeTrue()
        ->and($timeEntry->exists)->toBeTrue()
        ->and($document->exists)->toBeTrue()
        ->and($payrollRun->exists)->toBeTrue()
        ->and($course->exists)->toBeTrue()
        ->and($enrollment->exists)->toBeTrue();
});
