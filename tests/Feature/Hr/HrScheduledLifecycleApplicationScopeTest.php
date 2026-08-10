<?php

use App\Domain\Hr\Jobs\ArchiveCandidateDataJob;
use App\Domain\Hr\Jobs\SendOfferExpiryRemindersJob;
use App\Domain\Hr\Jobs\SendPipRemindersJob;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrTalentPool;
use App\Domain\Hr\Notifications\OfferExpiryInternalNotification;
use App\Domain\Hr\Notifications\OfferExpiryReminderNotification;
use App\Domain\Hr\Notifications\PipEndingNotification;
use App\Domain\Hr\Notifications\PipMilestoneOverdueNotification;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->travelTo(now()->setDate(2026, 8, 2)->setTime(10, 0));

    $this->northSite = Site::factory()->create(['name' => 'North scheduled lifecycle Site']);
    $this->southSite = Site::factory()->create(['name' => 'South scheduled lifecycle Site']);

    $this->currentStaffAt = function (Site $site, array $profile = []): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            ...$profile,
        ]);

        return $user;
    };
});

test('scheduled HR lifecycle jobs are application-wide contracts without a partition argument', function () {
    foreach ([
        ArchiveCandidateDataJob::class,
        SendOfferExpiryRemindersJob::class,
        SendPipRemindersJob::class,
    ] as $jobClass) {
        $constructor = (new ReflectionClass($jobClass))->getConstructor();

        expect($constructor?->getNumberOfParameters() ?? 0)->toBe(0);
    }
});

test('PIP reminders require current staff and exact Site-visible management ownership', function () {
    $employee = ($this->currentStaffAt)($this->northSite);
    $manager = ($this->currentStaffAt)($this->northSite);
    $wrongSiteManager = ($this->currentStaffAt)($this->southSite);
    $formerEmployee = ($this->currentStaffAt)($this->northSite, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $makePlan = function (User $subject, User $owner, string $title): HrPerformanceImprovementPlan {
        $plan = HrPerformanceImprovementPlan::query()->create([
            'employee_user_id' => $subject->id,
            'manager_user_id' => $owner->id,
            'title' => $title,
            'reason' => 'A bounded support plan is required.',
            'expectations' => 'Complete the agreed actions.',
            'support_offered' => 'Weekly coaching.',
            'status' => 'active',
            'start_date' => today()->subMonth(),
            'end_date' => today()->addDays(3),
            'created_by' => $owner->id,
        ]);
        $plan->milestones()->create([
            'title' => 'Review progress',
            'due_date' => today()->subDay(),
            'status' => 'pending',
        ]);

        return $plan;
    };

    $valid = $makePlan($employee, $manager, 'Current staff plan');
    $wrongSite = $makePlan($employee, $wrongSiteManager, 'Wrong Site manager plan');
    $former = $makePlan($formerEmployee, $manager, 'Former staff plan');

    app()->call([(new SendPipRemindersJob), 'handle']);

    Notification::assertSentToTimes($employee, PipMilestoneOverdueNotification::class, 2);
    Notification::assertSentToTimes($manager, PipMilestoneOverdueNotification::class, 1);
    Notification::assertSentToTimes($manager, PipEndingNotification::class, 1);
    Notification::assertNotSentTo($wrongSiteManager, PipMilestoneOverdueNotification::class);
    Notification::assertNotSentTo($wrongSiteManager, PipEndingNotification::class);
    Notification::assertNotSentTo($formerEmployee, PipMilestoneOverdueNotification::class);

    expect($valid->fresh()->end_reminder_sent_at)->not->toBeNull()
        ->and($valid->milestones()->firstOrFail()->overdue_reminder_sent_at)->not->toBeNull()
        ->and($wrongSite->fresh()->end_reminder_sent_at)->toBeNull()
        ->and($wrongSite->milestones()->firstOrFail()->overdue_reminder_sent_at)->not->toBeNull()
        ->and($former->fresh()->end_reminder_sent_at)->toBeNull()
        ->and($former->milestones()->firstOrFail()->overdue_reminder_sent_at)->toBeNull();
});

test('offer reminders fail closed on conflicting Site provenance and conceal internal recipients outside the Site', function () {
    $northManager = ($this->currentStaffAt)($this->northSite);
    $southAuthor = ($this->currentStaffAt)($this->southSite);

    $makeOffer = function (Site $applicationSite, Site $requisitionSite, Site $offerSite, string $email) use ($northManager, $southAuthor): HrOffer {
        $candidate = HrCandidate::factory()->create([
            'status' => 'offer_sent',
            'personal_email' => $email,
            'created_by' => $northManager->id,
        ]);
        $requisition = HrJobRequisition::query()->create([
            'title' => 'Support Worker',
            'slug' => 'scheduled-'.str()->uuid(),
            'position_role' => 'support_worker',
            'site_id' => $requisitionSite->id,
            'employment_type' => 'full_time',
            'status' => 'published',
            'hiring_manager_user_id' => $northManager->id,
            'created_by' => $northManager->id,
        ]);
        $application = HrApplication::query()->create([
            'candidate_id' => $candidate->id,
            'requisition_id' => $requisition->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'target_site_id' => $applicationSite->id,
            'status' => 'offer_sent',
        ]);

        return HrOffer::query()->create([
            'application_id' => $application->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'proposed_start_date' => today()->addMonth(),
            'employment_type' => 'full_time',
            'primary_site_id' => $offerSite->id,
            'approval_status' => 'approved',
            'sent_at' => now()->subDay(),
            'portal_expires_at' => now()->addDays(2),
            'created_by' => $southAuthor->id,
        ]);
    };

    $valid = $makeOffer($this->northSite, $this->northSite, $this->northSite, 'valid.offer@example.test');
    $conflicting = $makeOffer($this->northSite, $this->southSite, $this->northSite, 'conflict.offer@example.test');

    app()->call([(new SendOfferExpiryRemindersJob), 'handle']);

    Notification::assertSentOnDemand(
        OfferExpiryReminderNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'valid.offer@example.test',
    );
    Notification::assertSentToTimes($northManager, OfferExpiryInternalNotification::class, 1);
    Notification::assertNotSentTo($southAuthor, OfferExpiryInternalNotification::class);
    Notification::assertSentOnDemandTimes(OfferExpiryReminderNotification::class, 1);
    expect($valid->fresh()->expiry_reminder_sent_at)->not->toBeNull()
        ->and($conflicting->fresh()->expiry_reminder_sent_at)->toBeNull();
});

test('candidate retention runs once across the application and preserves the talent pool', function () {
    config(['hr.candidate_retention_months' => 1]);

    $owner = ($this->currentStaffAt)($this->northSite);
    $makeCandidate = function (Site $site, string $email) use ($owner): HrCandidate {
        $candidate = HrCandidate::factory()->create([
            'status' => 'rejected',
            'personal_email' => $email,
            'created_by' => $owner->id,
        ]);
        $application = HrApplication::query()->create([
            'candidate_id' => $candidate->id,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'target_site_id' => $site->id,
            'status' => 'rejected',
            'cv_storage_path' => "hr/recruitment/{$candidate->id}/cv.pdf",
            'screening_answers' => ['availability' => 'Private candidate answer'],
        ]);
        Storage::disk('private')->put($application->cv_storage_path, 'private CV');
        HrCandidate::query()->whereKey($candidate->id)->update(['updated_at' => now()->subMonths(6)]);

        return $candidate;
    };

    $northCandidate = $makeCandidate($this->northSite, 'north.candidate@example.test');
    $southCandidate = $makeCandidate($this->southSite, 'south.candidate@example.test');
    $pooledCandidate = $makeCandidate($this->southSite, 'pooled.candidate@example.test');
    HrTalentPool::query()->create([
        'candidate_id' => $pooledCandidate->id,
        'reason' => 'Approved for a future vacancy.',
        'pooled_by' => $owner->id,
    ]);

    (new ArchiveCandidateDataJob)->handle();

    foreach ([$northCandidate, $southCandidate] as $archived) {
        expect(HrCandidate::withTrashed()->findOrFail($archived->id)->trashed())->toBeTrue()
            ->and($archived->applications()->firstOrFail()->screening_answers)->toBeNull()
            ->and($archived->applications()->firstOrFail()->cv_storage_path)->toBeNull();
    }
    expect($pooledCandidate->fresh())->not->toBeNull()
        ->and($pooledCandidate->fresh()->first_name)->not->toBe('Archived')
        ->and(Storage::disk('private')->allFiles())->toHaveCount(1);
});
