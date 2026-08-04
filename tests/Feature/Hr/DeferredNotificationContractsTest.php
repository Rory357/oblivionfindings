<?php

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Notifications\AnnouncementReplyNotification;
use App\Domain\Hr\Notifications\GoalCompletedNotification;
use App\Domain\Hr\Notifications\SupervisionNoteAddedNotification;
use App\Domain\Hr\Notifications\WorkerComplianceExpiryNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Deferred notification Site']);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'DEFERRED-HR',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->employee = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->employee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $this->employee->id,
        'employee_number' => 'DEFERRED-1',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('the performance hub exposes the complete supervision session taxonomy', function () {
    $this->actingAs($this->hr)
        ->get('/hr/performance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sessionTypes', 6)
            ->where('sessionTypes.0.value', 'one_to_one')
            ->where('sessionTypes.3.value', 'check_in'));
});

test('a visible supervision note notifies the employee once while a private note stays private', function () {
    Notification::fake();

    $payload = [
        'employee_user_id' => $this->employee->id,
        'session_date' => now()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Visible follow-up and agreed actions.',
        'is_visible_to_employee' => true,
    ];

    $this->actingAs($this->hr)->post('/hr/performance/supervision', $payload)->assertRedirect();

    Notification::assertSentToTimes($this->employee, SupervisionNoteAddedNotification::class, 1);

    $this->actingAs($this->hr)->post('/hr/performance/supervision', [
        ...$payload,
        'topics_discussed' => 'Private manager record.',
        'is_visible_to_employee' => false,
    ])->assertRedirect();

    Notification::assertSentToTimes($this->employee, SupervisionNoteAddedNotification::class, 1);
});

test('a goal completion transition notifies its manager once', function () {
    Notification::fake();

    $goal = HrGoal::factory()->create([
        'user_id' => $this->employee->id,
        'created_by' => $this->hr->id,
        'status' => 'active',
        'progress_percentage' => 90,
        'completed_at' => null,
    ]);

    $this->actingAs($this->hr)->post("/hr/goals/{$goal->id}/progress", [
        'progress_percentage' => 100,
    ])->assertRedirect();

    Notification::assertSentToTimes($this->hr, GoalCompletedNotification::class, 1);

    $this->actingAs($this->hr)->post("/hr/goals/{$goal->id}/progress", [
        'progress_percentage' => 100,
    ])->assertRedirect();

    Notification::assertSentToTimes($this->hr, GoalCompletedNotification::class, 1);
});

test('an announcement reply notifies its author but not a self-reply', function () {
    Notification::fake();

    $announcement = HrAnnouncement::factory()->create([
        'created_by' => $this->hr->id,
        'status' => 'published',
        'target_audience' => 'all',
        'target_value' => null,
        'published_at' => now(),
    ]);

    $this->actingAs($this->employee)->post('/hr/feed/reply', [
        'subject_type' => 'announcement',
        'subject_id' => $announcement->id,
        'body' => 'Thanks for the update.',
    ])->assertRedirect();

    Notification::assertSentToTimes($this->hr, AnnouncementReplyNotification::class, 1);

    $this->actingAs($this->hr)->post('/hr/feed/reply', [
        'subject_type' => 'announcement',
        'subject_id' => $announcement->id,
        'body' => 'Author clarification.',
    ])->assertRedirect();

    Notification::assertSentToTimes($this->hr, AnnouncementReplyNotification::class, 1);
});

test('worker vetting and licence expiry reminders are stamped and deduplicated', function () {
    Notification::fake();

    $backgroundCheck = StaffBackgroundCheck::query()->create([
        'user_id' => $this->employee->id,
        'check_type' => 'police_check',
        'status' => 'clear',
        'expires_at' => now()->addDays(14)->toDateString(),
        'renewal_reminder_days_before' => 30,
        'created_by' => $this->hr->id,
    ]);

    $driverEligibility = HrDriverEligibility::query()->create([
        'user_id' => $this->employee->id,
        'licence_expires_at' => now()->addDays(14)->toDateString(),
        'status' => 'eligible',
        'created_by' => $this->hr->id,
    ]);

    $this->artisan('hr:send-worker-compliance-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->employee, WorkerComplianceExpiryNotification::class, 2);
    expect($backgroundCheck->fresh()->renewal_reminder_sent_at)->not->toBeNull();
    expect($driverEligibility->fresh()->licence_expiry_reminder_sent_at)->not->toBeNull();

    $this->artisan('hr:send-worker-compliance-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->employee, WorkerComplianceExpiryNotification::class, 2);
});
