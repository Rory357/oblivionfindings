<?php

use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseAssignment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrCourseSession;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrExpenseItem;
use App\Domain\Hr\Services\ExpenseService;
use App\Domain\Hr\Services\TrainingService;
use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create(['organization_id' => 1, 'role' => 'hr', 'approved_at' => now()]);
    $this->manager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    $this->staff = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
});

test('the training hub renders with the expected tab payloads', function () {
    HrCourse::factory()->create(['tenant_id' => 1, 'title' => 'Fire Safety', 'code' => 'FIRE-1']);

    $this->actingAs($this->manager)
        ->get('/hr/training/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/training/catalog')
            ->has('summary')
            ->has('dashboard')
            ->has('courses', 1)
            ->has('assignments')
            ->has('lookups.staff')
            ->where('can.manage', true));
});

test('a manager can create a course with rich metadata', function () {
    $this->actingAs($this->manager)->post('/hr/training/courses', [
        'title' => 'Manual Handling',
        'code' => 'MH-1',
        'delivery_method' => 'in_person',
        'duration_hours' => 4,
        'is_mandatory' => true,
        'requires_renewal' => true,
        'validity_period_months' => 12,
        'cpd_points' => 3,
        'cost' => 120,
        'org_pays_provider' => true,
    ])->assertRedirect();

    $course = HrCourse::where('code', 'MH-1')->first();
    expect($course)->not->toBeNull();
    expect($course->requires_renewal)->toBeTrue();
    expect($course->validity_period_months)->toBe(12);
    expect((int) $course->cpd_points)->toBe(3);
});

test('a manager can update and archive a course', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1, 'is_active' => true]);

    $this->actingAs($this->manager)->put("/hr/training/courses/{$course->id}", [
        'title' => 'Renamed', 'code' => $course->code, 'delivery_method' => 'online', 'duration_hours' => 2,
    ])->assertRedirect();
    expect($course->fresh()->title)->toBe('Renamed');

    $this->actingAs($this->manager)->patch("/hr/training/courses/{$course->id}/toggle")->assertRedirect();
    expect($course->fresh()->is_active)->toBeFalse();
});

test('sessions can be scheduled and cancelled', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);

    $this->actingAs($this->manager)->post("/hr/training/courses/{$course->id}/sessions", [
        'session_date' => now()->addWeek()->toDateString(),
        'start_time' => '09:00',
        'end_time' => '17:00',
        'location' => 'Room A',
        'max_participants' => 20,
    ])->assertRedirect();

    $session = HrCourseSession::where('course_id', $course->id)->firstOrFail();
    expect($session->status)->toBe('scheduled');

    $this->actingAs($this->manager)->delete("/hr/training/sessions/{$session->id}", ['reason' => 'Trainer unavailable'])->assertRedirect();
    $session->refresh();
    expect($session->status)->toBe('cancelled');
    expect($session->cancelled_at)->not->toBeNull();
});

test('assignments expand from an individuals audience and preview the headcount', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);

    $this->actingAs($this->manager)
        ->getJson('/hr/training/assignments/preview?course_ids[]='.$course->id.'&audience_type=individuals&user_ids[]='.$this->staff->id)
        ->assertOk()
        ->assertJson(['count' => 1, 'conflicts' => 0]);

    $this->actingAs($this->manager)->post('/hr/training/assignments', [
        'course_ids' => [$course->id],
        'audience_type' => 'individuals',
        'user_ids' => [$this->staff->id],
        'due_at' => now()->addMonth()->toDateString(),
        'source' => 'manual',
    ])->assertRedirect();

    $assignment = HrCourseAssignment::where('user_id', $this->staff->id)->where('hr_course_id', $course->id)->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->status)->toBe('assigned');

    $this->actingAs($this->manager)->patch("/hr/training/assignments/{$assignment->id}/waive", ['reason' => 'On leave'])->assertRedirect();
    expect($assignment->fresh()->status)->toBe('waived');
});

test('recording completion creates an enrollment, completion and closes the assignment', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1, 'cpd_points' => 4]);
    $assignment = HrCourseAssignment::factory()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'hr_course_id' => $course->id, 'status' => 'assigned',
    ]);

    $this->actingAs($this->manager)->post('/hr/training/record', [
        'course_id' => $course->id,
        'user_ids' => [$this->staff->id],
        'completed_at' => now()->toDateString(),
        'score' => 95,
    ])->assertRedirect();

    $enrollment = HrCourseEnrollment::where('user_id', $this->staff->id)->where('course_id', $course->id)->first();
    expect($enrollment)->not->toBeNull();
    expect($enrollment->status)->toBe('completed');
    expect($assignment->fresh()->status)->toBe('completed');
});

test('a course fee claim creates a submitted training expense with a source link', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1, 'title' => 'First Aid', 'cost' => 185]);

    $this->actingAs($this->manager)->post('/hr/training/claims', [
        'title' => 'First Aid course fee',
        'course_id' => $course->id,
        'items' => [[
            'description' => 'First Aid — enrolment',
            'category' => 'training',
            'amount' => 185,
            'expense_date' => now()->toDateString(),
        ]],
    ])->assertRedirect();

    $claim = HrExpenseClaim::where('title', 'First Aid course fee')->first();
    expect($claim)->not->toBeNull();
    expect($claim->status)->toBe('submitted');

    $item = HrExpenseItem::where('expense_claim_id', $claim->id)->first();
    expect($item->category)->toBe('training');
    expect($item->source_type)->toBe(HrCourse::class);
    expect((int) $item->source_id)->toBe($course->id);
});

test('catalog export streams csv', function () {
    HrCourse::factory()->create(['tenant_id' => 1, 'title' => 'Exported Course', 'code' => 'EXP-1']);

    $response = $this->actingAs($this->manager)->get('/hr/training/export?type=catalog');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

test('a viewer without manage cannot create courses', function () {
    $viewer = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);

    $this->actingAs($viewer)->post('/hr/training/courses', [
        'title' => 'X', 'code' => 'X1', 'delivery_method' => 'online', 'duration_hours' => 1,
    ])->assertForbidden();
});

test('re-assigning does not resurrect a completed or waived assignment', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);
    $staff2 = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

    $done = HrCourseAssignment::factory()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'hr_course_id' => $course->id, 'status' => 'completed', 'score' => 88,
    ]);
    $waived = HrCourseAssignment::factory()->create([
        'tenant_id' => 1, 'user_id' => $staff2->id, 'hr_course_id' => $course->id, 'status' => 'waived', 'waived_reason' => 'Exempt',
    ]);

    $this->actingAs($this->manager)->post('/hr/training/assignments', [
        'course_ids' => [$course->id],
        'audience_type' => 'individuals',
        'user_ids' => [$this->staff->id, $staff2->id],
        'source' => 'manual',
    ])->assertRedirect();

    expect($done->fresh()->status)->toBe('completed');
    expect($waived->fresh()->status)->toBe('waived');
});

test('assignment preview reports conflicts for already-assigned people', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);
    HrCourseAssignment::factory()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'hr_course_id' => $course->id, 'status' => 'assigned',
    ]);

    $this->actingAs($this->manager)
        ->getJson("/hr/training/assignments/preview?course_ids[]={$course->id}&audience_type=individuals&user_ids[]={$this->staff->id}")
        ->assertOk()
        ->assertJson(['count' => 1, 'conflicts' => 1]);
});

test('a manager can send an assignment reminder', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);
    $a = HrCourseAssignment::factory()->create(['tenant_id' => 1, 'user_id' => $this->staff->id, 'hr_course_id' => $course->id]);

    $this->actingAs($this->manager)->post("/hr/training/assignments/{$a->id}/remind")->assertRedirect();
    expect($a->fresh()->reminded_at)->not->toBeNull();
});

test('a manager can update a session', function () {
    $course = HrCourse::factory()->create(['tenant_id' => 1]);
    $session = HrCourseSession::create([
        'tenant_id' => 1, 'course_id' => $course->id, 'session_date' => now()->addWeek()->toDateString(),
        'status' => 'scheduled', 'max_participants' => 10,
    ]);

    $this->actingAs($this->manager)->put("/hr/training/sessions/{$session->id}", [
        'session_date' => now()->addWeeks(2)->toDateString(), 'location' => 'Room B', 'max_participants' => 15,
    ])->assertRedirect();

    expect($session->fresh()->location)->toBe('Room B');
    expect((int) $session->fresh()->max_participants)->toBe(15);
});

test('completing a paid course posts the provider GL event', function () {
    Bus::fake([ProcessFinancialEventJob::class]);
    $course = HrCourse::factory()->create(['tenant_id' => 1, 'cost' => 150, 'org_pays_provider' => true]);
    $enr = HrCourseEnrollment::factory()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'course_id' => $course->id, 'status' => 'enrolled',
    ]);

    app(TrainingService::class)->completeEnrollment($enr->fresh(), ['completed_at' => now()->toDateString()]);

    Bus::assertDispatched(ProcessFinancialEventJob::class);
});

test('a linked staff fee claim suppresses the provider GL posting (no double count)', function () {
    Bus::fake([ProcessFinancialEventJob::class]);
    $course = HrCourse::factory()->create(['tenant_id' => 1, 'cost' => 150, 'org_pays_provider' => true]);

    // Staff files a reimbursement claim linked to the course for the same person.
    app(ExpenseService::class)->createClaim($this->staff, [
        'title' => 'Course fee', 'currency' => 'NZD',
        'items' => [[
            'description' => 'Course fee', 'category' => 'training', 'amount' => 150,
            'expense_date' => now()->toDateString(), 'source_type' => HrCourse::class, 'source_id' => $course->id,
        ]],
    ]);

    $enr = HrCourseEnrollment::factory()->create([
        'tenant_id' => 1, 'user_id' => $this->staff->id, 'course_id' => $course->id, 'status' => 'enrolled',
    ]);

    app(TrainingService::class)->completeEnrollment($enr->fresh(), ['completed_at' => now()->toDateString()]);

    Bus::assertNotDispatched(ProcessFinancialEventJob::class);
});
