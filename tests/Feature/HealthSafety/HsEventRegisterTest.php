<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/events — the governance register (Step 1 foundation): hero +
 * tab counts + standardised rows, the over-the-list detail (?event=), the
 * deep-link shell, tab scoping, and permission gating.
 */
class HsEventRegisterTest extends TestCase
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

    public function test_register_renders_governance_payload(): void
    {
        $site = Site::factory()->create();
        HsEvent::factory()->high()->create(['site_id' => $site->id]);
        HsEvent::factory()->closed()->create();
        HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/events/index')
                ->has('events.data', 3)
                ->has('tabCounts.all')
                ->has('hero.live.open')
                ->has('hero.attention.worksafe_due')
                ->has('sites')
                ->has('can.manage')
                ->where('tab', 'all')
            );
    }

    public function test_worksafe_tab_scopes_to_notifiable(): void
    {
        HsEvent::factory()->create();                 // not notifiable
        HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?tab=worksafe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('tabCounts.worksafe', 1)
            );
    }

    public function test_event_query_param_returns_detail_over_list(): void
    {
        $event = HsEvent::factory()->high()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('detail')
                ->where('detail.id', $event->id)
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.investigations')
                ->has('detail.corrective_actions')
                ->has('detail.risk_assessments')
            );
    }

    public function test_show_renders_thin_deeplink_shell(): void
    {
        $client = Client::factory()->create();
        $event = HsEvent::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events/'.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/events/show')
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.can.manage')
            );
    }

    public function test_detail_resolves_the_originating_source_link(): void
    {
        $event = HsEvent::factory()->create(); // the factory's source is a ClientIncident

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/events?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.source.type', 'ClientIncident')
                ->where('detail.source.url', "/incidents/{$event->source_id}")
                ->where('detail.source.unwired', false)
            );
    }

    public function test_register_requires_hazards_view(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/health-safety/events')
            ->assertForbidden();
    }
}
