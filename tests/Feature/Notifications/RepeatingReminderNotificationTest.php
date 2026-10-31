<?php

namespace Tests\Feature\Notifications;

use App\Models\ClientIncident;
use App\Models\IncidentFollowup;
use App\Models\NotificationEscalationRule;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A repeating reminder reaches a person once while their earlier copy is
 * unread (not once per run), and the one-off clean-up keeps the newest copy
 * per person per item.
 */
class RepeatingReminderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-30 01:00:00', 'UTC'));
        $this->manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reminder-prune-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);

        parent::tearDown();
    }

    public function test_a_repeating_reminder_refreshes_the_unread_copy_instead_of_adding_one(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();
        $item = Site::factory()->create();
        $other = Site::factory()->create();

        $this->remind($item, [$alex, $sam], title: 'Still waiting (1 hour)');
        $firstId = $this->copyRows($alex, $item)->sole()->id;
        $this->travel(1)->hours();
        $this->remind($item, [$alex, $sam], title: 'Still waiting (2 hours)');

        // One unread row each: the same row, with the newest text, moved to now.
        foreach ([$alex, $sam] as $person) {
            $copy = $this->copyRows($person, $item)->sole();
            $this->assertNull($copy->read_at);
            $this->assertSame('Still waiting (2 hours)', json_decode($copy->data, true)['title']);
            $this->assertTrue(Carbon::parse($copy->created_at)->equalTo(now()));
        }
        $this->assertSame($firstId, $this->copyRows($alex, $item)->sole()->id);

        // Sam reads it; the next run sends Sam a new one and refreshes Alex's.
        $sam->unreadNotifications()->update(['read_at' => now()]);
        $this->travel(1)->hours();
        $this->remind($item, [$alex, $sam]);

        $this->assertSame(1, $this->copyRows($alex, $item)->count());
        $this->assertSame(2, $this->copyRows($sam, $item)->count());
        $this->assertSame(1, $sam->unreadNotifications()->count());

        // Another item is its own reminder.
        $this->remind($other, [$alex]);
        $this->assertSame(1, $this->copyRows($alex, $other)->count());
    }

    public function test_a_reminder_an_escalation_rule_watches_keeps_its_original_time(): void
    {
        // Its own event key: NotificationService caches rules per key for the process.
        $eventKey = 'tests.escalated_reminder_'.uniqid();
        NotificationEscalationRule::query()->create([
            'event_key' => $eventKey,
            'enabled' => true,
            'require_ack' => false,
            'remind_after_minutes' => 120,
            'repeat_every_minutes' => 60,
            'max_reminders' => 3,
        ]);
        $alex = User::factory()->create();
        $item = Site::factory()->create();

        $this->remind($item, [$alex], title: 'First', eventKey: $eventKey);
        $sentAt = now()->toDateTimeString();
        $this->travel(1)->hours();
        $this->remind($item, [$alex], title: 'Second', eventKey: $eventKey);

        $copy = $this->copyRows($alex, $item, $eventKey)->sole();
        $this->assertSame('Second', json_decode($copy->data, true)['title']);
        $this->assertSame($sentAt, Carbon::parse($copy->created_at)->toDateTimeString(), 'The escalation clock is not restarted.');
    }

    public function test_the_hourly_incident_reminder_keeps_one_unread_copy_per_person(): void
    {
        $this->seed(RbacSeeder::class);
        $adminRole = Role::query()->where('name', 'admin')->value('id');
        $managers = User::factory()->count(2)->create();
        $managers->each(fn (User $manager) => $manager->roles()->syncWithoutDetaching([$adminRole]));
        $incident = ClientIncident::factory()->create([
            'status' => 'submitted',
            'severity' => 'high',
            'reviewed_at' => null,
            'created_at' => now()->subHours(6),
        ]);
        $reminders = fn (User $manager) => DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('data->event_key', 'incidents.high_unreviewed_reminder')
            ->where('data->entity_id', $incident->id);

        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);
        $recipients = DB::table('notifications')
            ->where('data->event_key', 'incidents.high_unreviewed_reminder')
            ->where('data->entity_id', $incident->id)
            ->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->travel(1)->hours();
        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);

        // The same people, one unread row each, saying how long it has waited.
        $this->assertSame($recipients, DB::table('notifications')
            ->where('data->event_key', 'incidents.high_unreviewed_reminder')
            ->where('data->entity_id', $incident->id)
            ->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all());
        foreach ($managers as $manager) {
            $this->assertContains($manager->id, $recipients);
            $copy = $reminders($manager)->sole();
            $this->assertStringContainsString('(7 hours so far)', json_decode($copy->data, true)['body']);
        }

        $managers[0]->unreadNotifications()->update(['read_at' => now()]);
        $this->travel(1)->hours();
        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);

        $this->assertSame(2, $reminders($managers[0])->count());
        $this->assertSame(1, (clone $reminders($managers[0]))->whereNull('read_at')->count());
        $this->assertSame(1, $reminders($managers[1])->count());

        // Reviewed: no more reminders.
        $incident->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
        $managers[1]->unreadNotifications()->update(['read_at' => now()]);
        $this->travel(1)->hours();
        $this->artisan('incidents:remind-high-unreviewed')->assertExitCode(0);
        $this->assertSame(1, $reminders($managers[1])->count());
    }

    public function test_the_daily_follow_up_reminder_keeps_one_unread_copy_for_the_assignee(): void
    {
        $assignee = User::factory()->create();
        $incident = ClientIncident::factory()->create(['status' => 'submitted']);
        $followup = IncidentFollowup::factory()->create([
            'client_incident_id' => $incident->id,
            'assigned_to_user_id' => $assignee->id,
            'due_at' => now()->subDays(2),
            'completed_at' => null,
        ]);
        $copies = fn () => DB::table('notifications')
            ->where('notifiable_id', $assignee->id)
            ->where('data->event_key', 'followups.overdue_reminder')
            ->where('data->entity_id', $followup->id);

        $this->artisan('followups:remind-overdue')->assertExitCode(0);
        $this->travel(1)->days();
        $this->artisan('followups:remind-overdue')->assertExitCode(0);

        $copy = $copies()->sole();
        $this->assertNull($copy->read_at);
        $this->assertSame('3 days', json_decode($copy->data, true)['context']['Overdue by']);

        $assignee->unreadNotifications()->update(['read_at' => now()]);
        $this->travel(1)->days();
        $this->artisan('followups:remind-overdue')->assertExitCode(0);

        $this->assertSame(2, $copies()->count());
        $this->assertSame(1, (clone $copies())->whereNull('read_at')->count());
    }

    public function test_without_the_option_every_run_still_sends(): void
    {
        $alex = User::factory()->create();
        $item = Site::factory()->create();

        $this->remind($item, [$alex], refresh: false);
        $this->remind($item, [$alex], refresh: false);

        $this->assertSame(2, $this->copyRows($alex, $item)->count());
    }

    public function test_the_clean_up_keeps_the_newest_copy_per_person_per_item(): void
    {
        $alex = User::factory()->create();
        $sam = User::factory()->create();
        foreach (range(1, 5) as $hour) {
            $this->storedReminder($alex, 'incidents.high_unreviewed_reminder', 105, $hour);
            $this->storedReminder($sam, 'incidents.high_unreviewed_reminder', 105, $hour);
        }
        foreach (range(1, 3) as $day) {
            $this->storedReminder($alex, 'followups.overdue_reminder', 7, $day * 24);
        }
        $newestForAlex = $this->storedReminder($alex, 'incidents.high_unreviewed_reminder', 106, 1);
        $unrelated = $this->storedReminder($alex, 'incidents.created', 105, 2);
        $unrelated2 = $this->storedReminder($alex, 'incidents.created', 105, 3);

        $ours = fn () => DB::table('notifications')->whereIn('notifiable_id', [$alex->id, $sam->id])->count();

        $this->artisan('notifications:prune-duplicate-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('Older copies: 10 (10 of them unread)')
            ->assertExitCode(0);
        $this->assertSame(16, $ours());

        $this->artisan('notifications:prune-duplicate-reminders', ['--manifest' => $this->manifest])
            ->expectsOutputToContain('Deleted 10 older reminder copies.')
            ->assertExitCode(0);

        $this->assertSame(6, $ours());
        // The newest (1 hour old) copy of incident 105 is the one kept.
        $kept = DB::table('notifications')
            ->where('notifiable_id', $alex->id)
            ->where('data->event_key', 'incidents.high_unreviewed_reminder')
            ->where('data->entity_id', 105)
            ->sole();
        $this->assertTrue(Carbon::parse($kept->created_at)->equalTo(now()->subHour()));
        $this->assertTrue(DB::table('notifications')->where('id', $newestForAlex)->exists());
        $this->assertTrue(DB::table('notifications')->where('id', $unrelated)->exists());
        $this->assertTrue(DB::table('notifications')->where('id', $unrelated2)->exists());

        $lines = collect(file($this->manifest, FILE_IGNORE_NEW_LINES))->map(fn (string $line) => json_decode($line, true));
        $this->assertSame(4, $lines->whereNotNull('kept_id')->count());
        $this->assertSame(10, $lines->whereNotNull('result')->first()['result']['changed']);
    }

    public function test_mark_read_mode_keeps_the_rows(): void
    {
        $alex = User::factory()->create();
        foreach (range(1, 4) as $hour) {
            $this->storedReminder($alex, 'incidents.high_unreviewed_reminder', 105, $hour);
        }

        $this->artisan('notifications:prune-duplicate-reminders', ['--mode' => 'read', '--manifest' => $this->manifest])
            ->expectsOutputToContain('Marked read 3 older reminder copies.')
            ->assertExitCode(0);

        $this->assertSame(4, DB::table('notifications')->where('notifiable_id', $alex->id)->count());
        $this->assertSame(1, $alex->unreadNotifications()->count());
    }

    /** @param list<User> $recipients */
    private function remind(
        Site $item,
        array $recipients,
        bool $refresh = true,
        string $title = 'Still waiting',
        string $eventKey = 'tests.repeating_reminder',
    ): void {
        app(NotificationService::class)->notifyCrud(null, 'repeating_reminder', 'site', $item, null, [
            'event_key' => $eventKey,
            'title' => $title,
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_user_ids' => collect($recipients)->pluck('id')->all(),
            'refresh_unread_copy' => $refresh,
        ]);
    }

    private function copyRows(User $user, Site $item, string $eventKey = 'tests.repeating_reminder'): Collection
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', AppEventNotification::class)
            ->where('data->event_key', $eventKey)
            ->where('data->entity_id', $item->id)
            ->get();
    }

    private function storedReminder(User $user, string $eventKey, int $entityId, int $hoursAgo): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => AppEventNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'kind' => 'crud',
                'event_key' => $eventKey,
                'entity' => 'incidents',
                'entity_id' => $entityId,
                'title' => 'Reminder',
            ]),
            'read_at' => null,
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ]);

        return $id;
    }
}
