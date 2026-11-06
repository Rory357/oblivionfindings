<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\HsWorksafeNotToldReminderJob;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\HsWorksafeNotToldNotification;
use App\Services\Tasks\Providers\HsWorksafeNotToldProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Readiness audit O3 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Daily reminder + task"). A safety review marked
 * WorkSafe-notifiable where WorkSafe hasn't been told reminds its owner and its Site's H&S officers each day, and shows
 * a /tasks card, until the notification is recorded.
 */
final class HsWorksafeNotToldReminderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    private function person(Site $site, bool $officer = false, array $keys = ['hazards.view']): User
    {
        $user = User::factory()->create(['role' => $officer ? 'health_safety_officer' : 'support_worker', 'approved_at' => now()]);
        if ($officer) {
            $user->roles()->attach(Role::where('name', 'health_safety_officer')->sole());
            // Keep the officer to their own Sites for this test.
            $user->permissionOverrides()->syncWithoutDetaching([
                Permission::where('key', 'healthSafety.viewAllSites')->sole()->id => ['allowed' => false],
            ]);
        }
        foreach ($keys as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }

    private function review(array $facts = []): HsEvent
    {
        return HsEvent::factory()->create([
            'site_id' => $this->site->id,
            'status' => HsEvent::STATUS_OPEN,
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'worksafe_notified_at' => null,
            ...$facts,
        ]);
    }

    public function test_the_owner_and_the_sites_hs_officers_are_reminded_once_a_day_until_worksafe_is_told(): void
    {
        Notification::fake();
        $owner = $this->person($this->site, false, ['hazards.view', 'hazards.manage']);
        $officer = $this->person($this->site, true);
        $elsewhereOfficer = $this->person(Site::factory()->create(['is_active' => true]), true);
        $review = $this->review(['owner_user_id' => $owner->id]);
        $told = $this->review(['worksafe_status' => HsEvent::WORKSAFE_NOTIFIED, 'worksafe_notified_at' => now()]);
        $notNotifiable = $this->review(['worksafe_notifiable' => false, 'worksafe_status' => null]);
        $closed = $this->review(['status' => HsEvent::STATUS_CLOSED]);

        (new HsWorksafeNotToldReminderJob)->handle(app(\App\Services\UserSiteAccessService::class));
        (new HsWorksafeNotToldReminderJob)->handle(app(\App\Services\UserSiteAccessService::class));

        foreach ([$owner, $officer] as $user) {
            Notification::assertSentToTimes($user, HsWorksafeNotToldNotification::class, 1);
            Notification::assertSentTo($user, HsWorksafeNotToldNotification::class,
                fn ($n) => $n->toDatabase($user)['hs_event_id'] === $review->id
                    && $n->toDatabase($user)['action_url'] === "/health-safety/events/{$review->id}?action=worksafe-notify");
        }
        Notification::assertNotSentTo($elsewhereOfficer, HsWorksafeNotToldNotification::class);
        foreach ([$told, $notNotifiable, $closed] as $quiet) {
            Notification::assertSentTo($officer, HsWorksafeNotToldNotification::class,
                fn ($n) => $n->toDatabase($officer)['hs_event_id'] !== $quiet->id);
        }

        // Once WorkSafe is told, nobody is reminded again.
        $review->update(['worksafe_status' => HsEvent::WORKSAFE_NOTIFIED, 'worksafe_notified_at' => now()]);
        Cache::flush();
        Notification::fake();
        (new HsWorksafeNotToldReminderJob)->handle(app(\App\Services\UserSiteAccessService::class));
        Notification::assertNothingSent();
    }

    public function test_the_tasks_card_shows_until_worksafe_is_told_for_people_who_manage_hs(): void
    {
        $manager = $this->person($this->site, false, ['hazards.view', 'hazards.manage']);
        $review = $this->review();

        $tasks = (new HsWorksafeNotToldProvider)->authorizedTasks($manager);
        $this->assertCount(1, $tasks);
        $this->assertSame('hs_worksafe_not_told-'.$review->id, $tasks[0]->id);
        $this->assertSame('Tell WorkSafe', $tasks[0]->title);
        $this->assertSame("/health-safety/events/{$review->id}?action=worksafe-notify", $tasks[0]->link);

        $this->assertSame([], (new HsWorksafeNotToldProvider)->authorizedTasks($this->person($this->site)));

        $review->update(['worksafe_status' => HsEvent::WORKSAFE_NOTIFIED, 'worksafe_notified_at' => now()]);
        $this->assertSame([], (new HsWorksafeNotToldProvider)->authorizedTasks($manager));
    }
}
