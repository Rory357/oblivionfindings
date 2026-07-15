<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomSlaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->get('/control-room/sla')
            ->assertForbidden();
    }

    public function test_index_renders_sla_definitions(): void
    {
        SlaDefinition::create([
            'name' => 'Critical 5min Ack',
            'code' => 'crit-5',
            'severities' => ['critical'],
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/sla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/sla/index')
                ->has('slaDefinitions', 1)
                ->where('slaDefinitions.0.name', 'Critical 5min Ack')
                ->has('can')
            );
    }

    public function test_store_creates_sla_definition(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/sla', [
                'name' => 'High 15min Ack',
                'code' => 'high-15',
                'severities' => ['high'],
                'acknowledge_target_minutes' => 15,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_sla_definitions', [
            'name' => 'High 15min Ack',
            'code' => 'high-15',
        ]);
    }

    public function test_store_blocked_without_manage_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/control-room/sla', [
                'name' => 'Should fail',
                'code' => 'fail',
            ])
            ->assertForbidden();
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/sla', [])
            ->assertSessionHasErrors(['name', 'code']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        SlaDefinition::create([
            'name' => 'Existing',
            'code' => 'taken',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post('/control-room/sla', [
                'name' => 'Duplicate',
                'code' => 'taken',
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_update_modifies_sla_definition(): void
    {
        $sla = SlaDefinition::create([
            'name' => 'Original',
            'code' => 'orig',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put("/control-room/sla/{$sla->id}", [
                'name' => 'Renamed',
                'code' => 'orig',
                'acknowledge_target_minutes' => 10,
            ])
            ->assertRedirect();

        $sla->refresh();
        $this->assertSame('Renamed', $sla->name);
        $this->assertSame(10, $sla->acknowledge_target_minutes);
    }

    public function test_toggle_active_flips_state(): void
    {
        $sla = SlaDefinition::create([
            'name' => 'Toggle Test',
            'code' => 'toggle',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/sla/{$sla->id}/toggle-active")
            ->assertRedirect();

        $this->assertFalse($sla->fresh()->is_active);
    }

    public function test_breach_report_renders_breached_records_only(): void
    {
        $sla = SlaDefinition::create([
            'name' => 'Test SLA',
            'code' => 'tst',
            'is_active' => true,
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
        ]);

        AlertSla::create([
            'alert_id' => $alert->id,
            'sla_definition_id' => $sla->id,
            'acknowledge_deadline' => now()->subMinutes(30),
            'acknowledge_breached' => true,
            'first_breach_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/sla/breaches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/sla/breaches')
                ->has('breaches.data', 1)
                ->where('breaches.data.0.alert_reference', $alert->reference_number)
                ->where('stats.total', 1)
                ->where('stats.acknowledge', 1)
            );
    }
}
