<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\WitnessPinReminder;
use App\Notifications\Channels\PushChannel;
use App\Notifications\WitnessPinReminderNotification;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 chunk 5 (Q-G) — Settings › Staff & PINs › PIN status: someone who
 * can reset a person's witness PIN can remind them to set one. At most once
 * per person per NZ day (the server decides), in the app always and by push
 * when the person has push on for it, audited, and the list says when and by
 * whom.
 */
class WitnessPinReminderTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $lead;

    private User $noPin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->lead = $this->siteStaff('team_lead', [], name: 'Hana Kereama');
        $this->noPin = $this->siteStaff('support_worker', ['medications.administer.record'], withPin: false, name: 'Ben Carter');
        Notification::fake();
    }

    public function test_a_lead_reminds_someone_without_a_pin_once_a_day_and_it_is_audited(): void
    {
        $this->remind([$this->noPin->id])
            ->assertSessionHas('medication_settings_saved', 'Reminder sent to Ben Carter.');

        $reminder = WitnessPinReminder::query()->sole();
        $this->assertSame($this->noPin->id, (int) $reminder->user_id);
        $this->assertSame($this->lead->id, (int) $reminder->reminded_by);
        $audit = AuditLog::query()->where('action', 'medications.witness_pin.reminded')->sole();
        $this->assertSame($this->lead->id, (int) $audit->meta['actor_id']);
        $this->assertEquals([$this->noPin->id], $audit->meta['user_ids']);
        Notification::assertSentTo($this->noPin, WitnessPinReminderNotification::class, function ($notification, array $channels) {
            $this->assertSame('Hana Kereama', $notification->remindedBy);
            // Push is off until they turn it on.
            $this->assertSame(['database'], $channels);

            return true;
        });

        // Again today (NZ): not sent, and the server says why.
        $this->remind([$this->noPin->id])
            ->assertSessionHas('medication_settings_saved', 'No reminder was sent. Already reminded today: Ben Carter.');
        $this->assertSame(1, WitnessPinReminder::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.reminded')->count());
        Notification::assertSentToTimes($this->noPin, WitnessPinReminderNotification::class, 1);

        // The next NZ day: once more.
        $this->travelTo(now('Pacific/Auckland')->addDay()->startOfDay()->addHours(8)->utc());
        $this->remind([$this->noPin->id])
            ->assertSessionHas('medication_settings_saved', 'Reminder sent to Ben Carter.');
        $this->assertSame(2, WitnessPinReminder::query()->count());
    }

    public function test_the_list_says_when_and_by_whom_and_where_they_work(): void
    {
        $this->travelTo(Carbon::parse('2026-10-02 09:12', 'Pacific/Auckland')->utc());
        $this->remind([$this->noPin->id])->assertSessionHasNoErrors();

        $this->actingAs($this->lead)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('witnessPin.can_reset', true)
                ->where('witnessPin.staff', fn ($staff) => collect($staff)->contains(fn (array $row) => $row['id'] === $this->noPin->id
                    && $row['house'] === 'Kōwhai House'
                    && $row['reminded_by'] === 'Hana Kereama'
                    && $row['reminded_today'] === true
                    && str_starts_with((string) $row['reminded_at'], '2026-10-02T09:12'))));
    }

    public function test_people_who_already_have_a_pin_are_skipped(): void
    {
        $withPin = $this->siteStaff('support_worker', ['medications.administer.record'], name: 'Aroha Ngata');
        $this->remind([$this->noPin->id, $withPin->id])
            ->assertSessionHas('medication_settings_saved', 'Reminder sent to Ben Carter. Already has a PIN: Aroha Ngata.');
        Notification::assertNotSentTo($withPin, WitnessPinReminderNotification::class);
    }

    public function test_only_someone_who_could_reset_their_pin_can_remind_them(): void
    {
        // Without the reset permission.
        $worker = $this->siteStaff('support_worker', ['medications.administer.record']);
        $this->remind([$this->noPin->id], $worker)->assertForbidden();

        // Not at a house this lead can reach.
        $elsewhere = $this->siteStaff('support_worker', ['medications.administer.record'], withPin: false, site: Site::factory()->create(['is_active' => true]));
        $this->remind([$elsewhere->id])->assertNotFound();

        // Not someone with as much authority as a house lead, and not themselves.
        $otherLead = $this->siteStaff('team_lead', [], withPin: false);
        $this->remind([$otherLead->id])->assertForbidden();
        $this->remind([$this->lead->id])->assertForbidden();

        $this->assertSame(0, WitnessPinReminder::query()->count());
        Notification::assertNothingSent();
    }

    public function test_push_goes_only_to_people_who_turned_it_on(): void
    {
        $notification = new WitnessPinReminderNotification('Hana Kereama');
        $this->assertSame(['database'], $notification->via($this->noPin));

        // Their role's default, when they haven't chosen.
        $role = Role::query()->where('name', 'support_worker')->firstOrFail();
        RoleNotificationPreference::query()->create([
            'role_id' => $role->id, 'key' => WitnessPinReminderNotification::PREFERENCE_KEY,
            'enabled' => true, 'channel_inapp' => true, 'channel_email' => false, 'channel_push' => true,
        ]);
        $this->assertSame(['database', PushChannel::class], $notification->via($this->noPin));

        // Their own choice wins.
        UserNotificationPreference::query()->create([
            'user_id' => $this->noPin->id, 'key' => WitnessPinReminderNotification::PREFERENCE_KEY,
            'enabled' => true, 'channel_inapp' => true, 'channel_email' => false, 'channel_push' => false,
        ]);
        $this->assertSame(['database'], $notification->via($this->noPin));

        // The key is one people can set in their notification settings.
        $this->assertContains(WitnessPinReminderNotification::PREFERENCE_KEY, config('notification_events.groups.Medication'));
    }

    /** @param  list<int>  $ids */
    private function remind(array $ids, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->lead)
            ->from('/emar/settings')
            ->post('/emar/settings/witness-pins/remind', ['user_ids' => $ids]);
    }

    /** @param  list<string>  $permissions */
    private function siteStaff(string $role, array $permissions, bool $withPin = true, ?Site $site = null, ?string $name = null): User
    {
        $factory = User::factory();
        $user = ($withPin ? $factory : $factory->withoutWitnessPin())
            ->create(['role' => $role, 'approved_at' => now(), ...($name ? ['name' => $name] : [])]);
        $roleModel = Role::query()->where('name', $role)->first();
        if ($roleModel) {
            $user->roles()->syncWithoutDetaching([$roleModel->id]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
