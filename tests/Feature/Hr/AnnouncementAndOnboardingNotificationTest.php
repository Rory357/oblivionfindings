<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\AnnouncementPublishedNotification;
use App\Domain\Hr\Notifications\OnboardingChecklistAssignedNotification;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    $this->supportWorker = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    $this->coordinator = User::factory()->create([
        'role' => 'coordinator',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $this->supportWorker->id,
        'employee_number' => 'ANN-001',
        'work_email' => "ann-support-{$this->supportWorker->id}@example.test",
        'position_role' => 'support_worker',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $this->coordinator->id,
        'employee_number' => 'ANN-002',
        'work_email' => "ann-coordinator-{$this->coordinator->id}@example.test",
        'position_role' => 'coordinator',
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);
});

test('publishing an announcement notifies the targeted staff audience', function () {
    Notification::fake();

    $this->actingAs($this->hr)
        ->post('/hr/announcements', [
            'title' => 'Team meeting',
            'content' => 'Support worker briefing at 9am.',
            'priority' => 'normal',
            'target_audience' => 'role',
            'target_value' => 'support_worker',
            'is_pinned' => false,
            'requires_acknowledgement' => true,
        ])
        ->assertRedirect(route('hr.announcements.index'));

    Notification::assertSentTo(
        $this->supportWorker,
        AnnouncementPublishedNotification::class,
        fn (AnnouncementPublishedNotification $notification) => ($notification->toArray($this->supportWorker)['type'] ?? null) === 'announcement_published'
    );

    Notification::assertNotSentTo(
        $this->coordinator,
        AnnouncementPublishedNotification::class,
        fn (AnnouncementPublishedNotification $notification) => ($notification->toArray($this->coordinator)['type'] ?? null) === 'announcement_published'
    );
});

test('generating an onboarding checklist notifies the subject user', function () {
    Notification::fake();

    HrOnboardingTemplate::query()->create([
        'tenant_id' => 1,
        'role' => 'support_worker',
        'site_type' => 'all',
        'is_active' => true,
        'tasks' => [
            [
                'category' => 'paperwork',
                'title' => 'Complete starter forms',
                'description' => 'Upload signed starter paperwork.',
                'is_required' => true,
                'sort_order' => 1,
            ],
        ],
        'created_by' => $this->hr->id,
    ]);

    $profile = HrEmployeeProfile::query()
        ->where('user_id', $this->supportWorker->id)
        ->firstOrFail();

    app(OnboardingService::class)->generateChecklist($profile, $this->hr->id);

    Notification::assertSentTo(
        $this->supportWorker,
        OnboardingChecklistAssignedNotification::class,
        fn (OnboardingChecklistAssignedNotification $notification) => ($notification->toArray($this->supportWorker)['type'] ?? null) === 'onboarding_checklist_assigned'
    );
});
