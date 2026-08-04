<?php

use App\Domain\Hr\Jobs\SendWellbeingRemindersJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Notifications\WellbeingFollowUpDueNotification;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 08:00:00'));
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Wellbeing reminder Site']);
    $this->otherSite = Site::factory()->create(['name' => 'Other wellbeing reminder Site']);
    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->otherManager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->staff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->otherStaff = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);

    wellbeingReminderProfile($this->manager, $this->site);
    wellbeingReminderProfile($this->staff, $this->site);
    wellbeingReminderProfile($this->otherManager, $this->otherSite);
    wellbeingReminderProfile($this->otherStaff, $this->otherSite);
});

afterEach(fn () => Carbon::setTestNow());

function wellbeingReminderProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

test('wellbeing sweep closes every Site survey and sends follow-ups only across an authorised Site relationship', function () {
    $survey = HrEngagementSurvey::query()->create([
        'title' => 'Expired wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$this->site->id],
        'ends_at' => today(),
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
    $otherSurvey = HrEngagementSurvey::query()->create([
        'title' => 'Other Site expired wellbeing pulse',
        'survey_type' => 'pulse',
        'status' => 'published',
        'is_anonymous' => true,
        'audience_type' => 'site',
        'audience_site_ids' => [$this->otherSite->id],
        'ends_at' => today(),
        'created_by' => $this->otherManager->id,
        'updated_by' => $this->otherManager->id,
    ]);

    $visibleCheckin = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->staff->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'follow_up_date' => today(),
        'is_private' => true,
    ]);
    $otherSiteCheckin = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->otherStaff->id,
        'manager_user_id' => $this->otherManager->id,
        'type' => 'welfare',
        'follow_up_date' => today(),
        'is_private' => true,
    ]);
    $inaccessibleCheckin = HrWellbeingCheckin::query()->create([
        'staff_user_id' => $this->otherStaff->id,
        'manager_user_id' => $this->manager->id,
        'type' => 'welfare',
        'follow_up_date' => today(),
        'is_private' => true,
    ]);

    app()->call([new SendWellbeingRemindersJob, 'handle']);

    expect($survey->fresh()->status)->toBe('closed')
        ->and($otherSurvey->fresh()->status)->toBe('closed')
        ->and($visibleCheckin->fresh()->follow_up_reminder_sent_at)->not->toBeNull()
        ->and($otherSiteCheckin->fresh()->follow_up_reminder_sent_at)->not->toBeNull()
        ->and($inaccessibleCheckin->fresh()->follow_up_reminder_sent_at)->not->toBeNull();
    Notification::assertSentToTimes($this->manager, WellbeingFollowUpDueNotification::class, 1);
    Notification::assertSentToTimes($this->otherManager, WellbeingFollowUpDueNotification::class, 1);

    app()->call([new SendWellbeingRemindersJob, 'handle']);

    Notification::assertSentToTimes($this->manager, WellbeingFollowUpDueNotification::class, 1);
    Notification::assertSentToTimes($this->otherManager, WellbeingFollowUpDueNotification::class, 1);
});
