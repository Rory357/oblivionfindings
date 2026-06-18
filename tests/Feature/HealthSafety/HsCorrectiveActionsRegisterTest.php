<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/corrective-actions — sibling register for corrective actions,
 * cross-linked back into the H&S event governance modal.
 */
class HsCorrectiveActionsRegisterTest extends TestCase
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

    public function test_corrective_actions_register_renders_sibling_governance_payload(): void
    {
        $site = Site::factory()->create(['name' => 'Rata House']);
        $event = HsEvent::factory()->high()->create([
            'site_id' => $site->id,
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);

        HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'title' => 'Replace failed evacuation light',
            'priority' => HsCorrectiveAction::PRIORITY_HIGH,
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'due_date' => now()->addDays(2),
        ]);
        HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $event->id,
            'priority' => HsCorrectiveAction::PRIORITY_CRITICAL,
        ]);
        HsCorrectiveAction::factory()->closed()->create();

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/corrective-actions/index')
                ->has('actions.data', 3)
                ->has('tabCounts.all')
                ->where('tabCounts.awaiting_verification', 1)
                ->has('hero.live.open')
                ->has('hero.attention.overdue')
                ->has('sites')
                ->has('can.manage')
                ->where('filters.tab', 'all')
                ->where('actions.data.0.event.id', $event->id)
                ->where('actions.data.0.event.url', "/health-safety/events?event={$event->id}")
                ->where('actions.data.0.event.site_name', 'Rata House')
            );
    }

    public function test_corrective_actions_register_can_open_event_detail_over_list(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_CORRECTIVE_ACTION,
        ]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)
                ->where('detail.reference_number', $event->reference_number)
                ->has('detail.corrective_actions', 1)
            );
    }

    public function test_corrective_actions_register_reflects_parent_event_monitoring_status(): void
    {
        $event = HsEvent::factory()->high()->create([
            'status' => HsEvent::STATUS_MONITORING,
        ]);
        $action = HsCorrectiveAction::factory()->closed()->create([
            'hs_event_id' => $event->id,
            'title' => 'Confirm monitoring checks are scheduled',
        ]);

        $this->actingAs($this->hsOfficer())
            ->get('/health-safety/corrective-actions?tab=closed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.id', $action->id)
                ->where('actions.data.0.event.status', HsEvent::STATUS_MONITORING)
                ->where('actions.data.0.event.monitoring', true)
            );
    }

    public function test_corrective_actions_register_requires_hazards_view(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions')
            ->assertForbidden();
    }
}
