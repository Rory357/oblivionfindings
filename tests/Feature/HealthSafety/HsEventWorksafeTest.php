<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkSafe NZ notification workflow (E-Gap 2) — record notification
 * (pending → notified, date/method/reference + site preservation) and
 * acknowledgement (notified → acknowledged), with guards + permission gating.
 */
class HsEventWorksafeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_record_notification_transitions_pending_to_notified(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
                'reference' => 'WS-2026-0099',
                'site_preserved' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::WORKSAFE_NOTIFIED, $event->worksafe_status);
        $this->assertNotNull($event->worksafe_notified_at);
        $this->assertEquals('phone', $event->worksafe_method);
        $this->assertEquals('WS-2026-0099', $event->worksafe_reference);
        $this->assertTrue($event->worksafe_site_preserved);
    }

    public function test_acknowledge_transitions_notified_to_acknowledged(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create([
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/acknowledge", [
                'acknowledged_at' => '2026-06-19',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->worksafe_status);
        $this->assertNotNull($event->worksafe_acknowledged_at);
    }

    public function test_cannot_notify_a_non_notifiable_event(): void
    {
        $event = HsEvent::factory()->create(); // worksafe_notifiable = false

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($event->fresh()->worksafe_notified_at);
    }

    public function test_cannot_acknowledge_before_notifying(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create(); // pending

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/acknowledge", [
                'acknowledged_at' => '2026-06-18',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->fresh()->worksafe_status);
    }

    public function test_notify_requires_method_and_date(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [])
            ->assertSessionHasErrors(['notified_at', 'method']);
    }

    public function test_worksafe_actions_require_hazards_manage(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertForbidden();
    }
}
