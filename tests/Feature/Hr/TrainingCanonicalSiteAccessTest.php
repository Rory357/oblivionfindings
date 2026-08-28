<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function makeCanonicalTrainingStaff(Site $site, array $profileOverrides = []): User
{
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $staff->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create(array_merge([
        'user_id' => $staff->id,
        'employee_number' => 'TRAINING-SITE-'.$staff->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ], $profileOverrides));

    return $staff;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create([
        'name' => 'Visible Training Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Hidden Training Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->manager->id,
        'employee_number' => 'TRAINING-SITE-'.$this->manager->id,
        'position_role' => 'hr',
        'primary_site_id' => $this->visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $this->visibleStaff = makeCanonicalTrainingStaff($this->visibleSite);
    $this->secondaryStaff = makeCanonicalTrainingStaff($this->hiddenSite, [
        'secondary_site_ids' => [$this->visibleSite->id],
    ]);
    $this->hiddenStaff = makeCanonicalTrainingStaff($this->hiddenSite);
    $this->inactiveStaff = makeCanonicalTrainingStaff($this->visibleSite, ['is_active' => false]);
    $this->course = HrCourse::factory()->create([
        'title' => 'Canonical Site Training',
        'code' => 'CAN-SITE',
    ]);
});

test('training hub rows counts lookups and course detail share the current accessible Site boundary', function () {
    foreach ([$this->visibleStaff, $this->secondaryStaff, $this->hiddenStaff, $this->inactiveStaff] as $staff) {
        HrCourseAssignment::factory()->create([
            'user_id' => $staff->id,
            'hr_course_id' => $this->course->id,
            'status' => 'assigned',
            'due_at' => today()->subDay(),
        ]);
        HrCourseEnrollment::factory()->create([
            'user_id' => $staff->id,
            'course_id' => $this->course->id,
            'status' => 'enrolled',
        ]);
    }

    $response = $this->actingAs($this->manager)->get('/hr/training/catalog');
    $response->assertOk();

    expect(collect($response->inertiaProps('assignments'))->pluck('person')->all())
        ->toEqualCanonicalizing([$this->visibleStaff->name, $this->secondaryStaff->name])
        ->and($response->inertiaProps('summary.total_enrollments'))->toBe(2)
        ->and($response->inertiaProps('summary.overdue_assignments'))->toBe(2)
        ->and(collect($response->inertiaProps('lookups.staff'))->pluck('id')->all())
        ->toEqualCanonicalizing([$this->manager->id, $this->visibleStaff->id, $this->secondaryStaff->id])
        ->and(collect($response->inertiaProps('lookups.sites'))->pluck('value')->all())
        ->toBe([(string) $this->visibleSite->id]);

    $detail = $this->actingAs($this->manager)
        ->getJson("/hr/training/courses/{$this->course->id}/detail");
    $detail->assertOk();
    expect(collect($detail->json('enrollments'))->pluck('name')->all())
        ->toEqualCanonicalizing([$this->visibleStaff->name, $this->secondaryStaff->name])
        ->and($detail->json('metrics.enrol'))->toBe(2);
});

test('competency and induction counts include only current staff at accessible Sites', function () {
    $competency = HrCompetency::query()->create([
        'name' => 'Medication competency',
        'category' => 'clinical',
        'proficiency_levels' => ['Observed', 'Competent'],
        'is_active' => true,
    ]);

    foreach ([$this->visibleStaff, $this->secondaryStaff, $this->hiddenStaff, $this->inactiveStaff] as $staff) {
        HrCompetencyAssessment::query()->create([
            'employee_profile_id' => $staff->hrEmployeeProfile->id,
            'competency_id' => $competency->id,
            'assessed_level' => 2,
            'assessed_by' => $this->manager->id,
            'assessment_date' => today(),
        ]);
    }

    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $this->visibleStaff->hrEmployeeProfile->id,
        'template_key' => 'visible',
        'status' => 'in_progress',
    ]);
    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $this->secondaryStaff->hrEmployeeProfile->id,
        'template_key' => 'secondary',
        'status' => 'completed',
    ]);
    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $this->hiddenStaff->hrEmployeeProfile->id,
        'template_key' => 'hidden',
        'status' => 'not_started',
    ]);
    HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $this->inactiveStaff->hrEmployeeProfile->id,
        'template_key' => 'inactive',
        'status' => 'in_progress',
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/training/catalog');
    $response->assertOk();

    expect($response->inertiaProps('competency.total_assessments'))->toBe(2)
        ->and($response->inertiaProps('competency.assessments_this_month'))->toBe(2)
        ->and($response->inertiaProps('competency.frameworks.0.assessment_count'))->toBe(2)
        ->and($response->inertiaProps('induction.in_progress'))->toBe(1)
        ->and($response->inertiaProps('induction.completed'))->toBe(1)
        ->and($response->inertiaProps('induction.not_started'))->toBe(0);
});

test('session availability counts every occupied seat without exposing hidden staff identities', function () {
    $session = HrCourseSession::query()->create([
        'course_id' => $this->course->id,
        'session_date' => today()->addWeek(),
        'status' => 'scheduled',
        'max_participants' => 3,
    ]);
    foreach ([$this->visibleStaff, $this->hiddenStaff] as $staff) {
        HrCourseEnrollment::factory()->create([
            'user_id' => $staff->id,
            'course_id' => $this->course->id,
            'session_id' => $session->id,
            'status' => 'enrolled',
        ]);
    }

    $detail = $this->actingAs($this->manager)
        ->getJson("/hr/training/courses/{$this->course->id}/detail");
    $detail->assertOk();

    expect($detail->json('sessions.0.seats'))->toBe(1)
        ->and(collect($detail->json('enrollments'))->pluck('name')->all())
        ->toBe([$this->visibleStaff->name]);
});

test('training exports omit hidden and non-current staff records', function () {
    HrCourseAssignment::factory()->create([
        'user_id' => $this->visibleStaff->id,
        'hr_course_id' => $this->course->id,
    ]);
    HrCourseAssignment::factory()->create([
        'user_id' => $this->hiddenStaff->id,
        'hr_course_id' => $this->course->id,
    ]);
    HrCourseEnrollment::factory()->create([
        'user_id' => $this->visibleStaff->id,
        'course_id' => $this->course->id,
    ]);
    HrCourseEnrollment::factory()->create([
        'user_id' => $this->hiddenStaff->id,
        'course_id' => $this->course->id,
    ]);

    $assignments = $this->actingAs($this->manager)
        ->get('/hr/training/export?type=assignments');
    $assignments->assertOk();
    expect($assignments->streamedContent())
        ->toContain($this->visibleStaff->name)
        ->not->toContain($this->hiddenStaff->name);

    $enrolments = $this->actingAs($this->manager)
        ->get('/hr/training/export?type=enrolments');
    $enrolments->assertOk();
    expect($enrolments->streamedContent())
        ->toContain($this->visibleStaff->name)
        ->not->toContain($this->hiddenStaff->name);
});

test('training direct enrollment assignment and certificate objects conceal hidden Site staff', function () {
    $enrollment = HrCourseEnrollment::factory()->create([
        'user_id' => $this->hiddenStaff->id,
        'course_id' => $this->course->id,
        'status' => 'completed',
    ]);
    $assignment = HrCourseAssignment::factory()->create([
        'user_id' => $this->hiddenStaff->id,
        'hr_course_id' => $this->course->id,
        'status' => 'assigned',
    ]);

    $this->actingAs($this->manager)
        ->put("/hr/training/enrollments/{$enrollment->id}/complete", [
            'completed_at' => today()->toDateString(),
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->get("/hr/training/enrollments/{$enrollment->id}/certificate")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post("/hr/training/assignments/{$assignment->id}/remind")
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->patch("/hr/training/assignments/{$assignment->id}/waive", ['reason' => 'Forged'])
        ->assertNotFound();

    expect($assignment->fresh()->status)->toBe('assigned')
        ->and($assignment->fresh()->reminded_at)->toBeNull();
});

test('training recipient and Site selectors reject hidden targets while cohort stays Site scoped', function () {
    $this->actingAs($this->manager)
        ->post('/hr/training/enroll', [
            'user_id' => $this->hiddenStaff->id,
            'course_id' => $this->course->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($this->manager)
        ->post('/hr/training/record', [
            'course_id' => $this->course->id,
            'user_ids' => [$this->hiddenStaff->id],
            'completed_at' => today()->toDateString(),
        ])
        ->assertSessionHasErrors('user_ids');

    $this->actingAs($this->manager)
        ->post('/hr/training/assignments', [
            'course_ids' => [$this->course->id],
            'audience_type' => 'individuals',
            'user_ids' => [$this->hiddenStaff->id],
        ])
        ->assertSessionHasErrors('user_ids');

    $this->actingAs($this->manager)
        ->post('/hr/training/assignments', [
            'course_ids' => [$this->course->id],
            'audience_type' => 'site',
            'site_id' => $this->hiddenSite->id,
        ])
        ->assertSessionHasErrors('site_id');

    $this->actingAs($this->manager)
        ->post('/hr/training/assignments', [
            'course_ids' => [$this->course->id],
            'audience_type' => 'cohort',
        ])
        ->assertRedirect();

    expect(HrCourseAssignment::query()->where('user_id', $this->visibleStaff->id)->exists())->toBeTrue()
        ->and(HrCourseAssignment::query()->where('user_id', $this->secondaryStaff->id)->exists())->toBeTrue()
        ->and(HrCourseAssignment::query()->where('user_id', $this->hiddenStaff->id)->exists())->toBeFalse()
        ->and(HrCourseAssignment::query()->where('user_id', $this->inactiveStaff->id)->exists())->toBeFalse();
});

test('training assignments reauthorise the staff Site boundary after the shared People lock', function () {
    $locks = new class extends PeopleMutationLockService
    {
        public ?Closure $beforeLock = null;

        public function lock(
            iterable $userIds,
            iterable $profileIds = [],
            iterable $additionalRoleIds = [],
        ): array {
            if ($this->beforeLock) {
                ($this->beforeLock)();
                $this->beforeLock = null;
            }

            return parent::lock($userIds, $profileIds, $additionalRoleIds);
        }
    };
    $locks->beforeLock = function (): void {
        $this->visibleStaff->hrEmployeeProfile()->update([
            'primary_site_id' => $this->hiddenSite->id,
            'secondary_site_ids' => [],
        ]);
    };
    $this->app->instance(PeopleMutationLockService::class, $locks);

    $this->actingAs($this->manager)
        ->post('/hr/training/assignments', [
            'course_ids' => [$this->course->id],
            'audience_type' => 'individuals',
            'user_ids' => [$this->visibleStaff->id],
        ])
        ->assertSessionHasErrors('user_ids');

    expect(HrCourseAssignment::query()
        ->where('user_id', $this->visibleStaff->id)
        ->where('hr_course_id', $this->course->id)
        ->exists())->toBeFalse();
});
