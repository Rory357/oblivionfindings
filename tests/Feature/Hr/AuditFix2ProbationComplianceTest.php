<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrICalToken;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ProbationReviewDueNotification;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function af2bProfile(User $user, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-AF2-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonths(2)->toDateString(),
        'is_active' => true,
    ], $overrides));
}

function af2HrUser(): User
{
    $hr = User::factory()->create(['organization_id' => 1, 'role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    return $hr;
}

/* ── Item 1a: probation reminder command ─────────────────────────────────── */

test('probation reminder notifies the manager and dedupes on re-run', function () {
    Notification::fake();

    $manager = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
    $profile = af2bProfile($worker, [
        'manager_user_id' => $manager->id,
        'probation_end_date' => now()->addDays(7)->toDateString(),
    ]);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertSentTo(
        $manager,
        ProbationReviewDueNotification::class,
        function (ProbationReviewDueNotification $notification) use ($worker, $manager) {
            $data = $notification->toArray($manager);

            return $data['employee_user_id'] === $worker->id
                && $data['overdue'] === false
                && in_array('mail', $notification->via($manager), true)
                && in_array('database', $notification->via($manager), true);
        },
    );

    expect($profile->fresh()->probation_reminder_sent_at)->not->toBeNull();

    // Re-run: the sent_at stamp dedupes — still exactly one notification.
    $this->artisan('hr:probation-reminders')->assertExitCode(0);
    Notification::assertSentToTimes($manager, ProbationReviewDueNotification::class, 1);
});

test('probation reminder skips employees with a concluding probation review', function () {
    Notification::fake();

    $manager = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
    af2bProfile($worker, [
        'manager_user_id' => $manager->id,
        'probation_end_date' => now()->subDays(3)->toDateString(),
    ]);

    HrProbationReview::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $worker->id,
        'reviewer_user_id' => $manager->id,
        'review_number' => 1,
        'review_date' => now()->subDays(5)->toDateString(),
        'status' => 'passed',
        'recommendation' => 'pass',
        'created_by' => $manager->id,
    ]);

    $this->artisan('hr:probation-reminders')->assertExitCode(0);

    Notification::assertNotSentTo($manager, ProbationReviewDueNotification::class);
});

/* ── Item 1b: extend recommendation moves the probation end date ─────────── */

test('storeProbation with an extend recommendation moves probation_end_date and clears the reminder stamp', function () {
    $hr = af2HrUser();
    $worker = User::factory()->create(['organization_id' => 1, 'role' => 'support_worker', 'approved_at' => now()]);
    $originalEnd = now()->addDays(5)->startOfDay();
    $profile = af2bProfile($worker, [
        'probation_end_date' => $originalEnd->toDateString(),
        'probation_reminder_sent_at' => now(),
    ]);

    $response = $this->actingAs($hr)->post('/hr/performance/probation', [
        'employee_user_id' => $worker->id,
        'review_number' => 1,
        'review_date' => now()->toDateString(),
        'status' => 'extended',
        'recommendation' => 'extend',
        'extension_weeks' => 4,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $fresh = $profile->fresh();
    expect($fresh->probation_end_date->toDateString())
        ->toBe($originalEnd->copy()->addWeeks(4)->toDateString())
        ->and($fresh->probation_reminder_sent_at)->toBeNull();
});

/* ── Item 4: intake seeds the compliance display matrix ──────────────────── */

test('employee intake seeds hr_staff_compliance_status rows for the new hire', function () {
    $actor = af2HrUser();

    $requirement = HrComplianceRequirement::query()->create([
        'tenant_id' => 1,
        'code' => 'FA-AF2',
        'name' => 'First Aid Certificate',
        'category' => 'Health & Safety',
        'check_type' => 'manual',
        'hard_stop' => true,
        'is_active' => true,
        'validity_months' => 24,
        'created_by' => $actor->id,
    ]);
    HrComplianceMatrix::query()->create([
        'tenant_id' => 1,
        'requirement_id' => $requirement->id,
        'role' => 'support_worker',
        'is_mandatory' => true,
    ]);

    $profile = app(EmployeeIntakeService::class)->intake(
        'New Hire',
        'new.hire.af2@example.test',
        'support_worker',
        ['position_title' => 'Support Worker', 'position_role' => 'support_worker', 'employment_type' => 'full_time', 'start_date' => now()->toDateString()],
        $actor->id,
        1,
        startOnboarding: false,
        sendInvite: false,
    );

    expect(
        HrStaffComplianceStatus::query()
            ->where('tenant_id', 1)
            ->where('user_id', $profile->user_id)
            ->where('requirement_id', $requirement->id)
            ->exists(),
    )->toBeTrue();
});

/* ── Item 2: iCal feed — holidays layer + raised event limit ─────────────── */

test('ical feed includes public holidays and emits more than the old 100-event cap', function () {
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    af2bProfile($user);
    $token = HrICalToken::query()->create(['user_id' => $user->id, 'token' => Str::random(64)]);

    HrPublicHoliday::query()->create([
        'tenant_id' => 1,
        'name' => 'Matariki AF2',
        'date' => now()->addDays(10)->toDateString(),
        'is_national' => true,
        'year' => (int) now()->addDays(10)->format('Y'),
    ]);

    // 110 events — beyond the old limit(100); all must survive the raised cap.
    $rows = collect(range(1, 110))->map(fn (int $i) => [
        'tenant_id' => 1,
        'title' => "AF2 event {$i}",
        'event_type' => 'company',
        'starts_at' => now()->addDays(1)->addMinutes($i),
        'ends_at' => now()->addDays(1)->addMinutes($i + 30),
        'created_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ])->all();
    HrCalendarEvent::query()->insert($rows);

    $response = $this->get("/hr/ical/{$token->token}");

    $response->assertOk();
    $body = $response->getContent();

    expect($body)
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('END:VCALENDAR')
        ->toContain('Public holiday: Matariki AF2')
        ->and(substr_count($body, 'BEGIN:VEVENT'))->toBeGreaterThanOrEqual(111); // 110 events + the holiday
});
