<?php

namespace Tests\Feature\FleetAssets;

use App\Models\AuditLog;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Asset $asset;

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
        $this->site = Site::factory()->create(['tenant_id' => 1]);
        $this->asset = Asset::factory()->vehicle()->create([
            'site_id' => $this->site->id,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_index_exposes_control_room_manage_flag_only_for_canonical_permission(): void
    {
        ControlRoomAlert::factory()->fromFleet()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->get('/fleet-assets/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/alerts/index')
                ->where('can.manage', true)
                ->has('control_room_alerts.data', 1)
                ->has('archived_asset_alerts')
            );
    }

    public function test_acknowledge_uses_canonical_status_actor_and_audit(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post("/fleet-assets/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => ControlRoomAlert::STATUS_ACK,
            'acknowledged_by_user_id' => $this->admin->id,
        ]);
        $this->assertNotNull($alert->fresh()->acknowledged_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'controlRoom.alert.acknowledge',
            'auditable_type' => (new ControlRoomAlert())->getMorphClass(),
            'auditable_id' => $alert->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_start_triage_uses_canonical_status_note_and_audit(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->acknowledged()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post("/fleet-assets/alerts/{$alert->id}/triage", [
                'notes' => 'Fleet lead has taken over and is checking the vehicle.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);

        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.triage')
            ->where('auditable_id', $alert->id)
            ->firstOrFail();

        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(
            'Fleet lead has taken over and is checking the vehicle.',
            $audit->meta['operator_note'] ?? null,
        );
    }

    public function test_open_and_acknowledged_alerts_cannot_skip_straight_to_resolution(): void
    {
        foreach ([
            ControlRoomAlert::factory()->fromFleet()->open()->create($this->alertProvenance()),
            ControlRoomAlert::factory()->fromFleet()->acknowledged()->create($this->alertProvenance()),
        ] as $alert) {
            $this->actingAs($this->admin)
                ->post("/fleet-assets/alerts/{$alert->id}/resolve", [
                    'resolution_notes' => 'This must not skip the Control Room handoff.',
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('alert');

            $this->assertNotSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        }
    }

    public function test_resolve_requires_resolution_notes(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post("/fleet-assets/alerts/{$alert->id}/resolve")
            ->assertSessionHasErrors('resolution_notes');

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_resolve_uses_canonical_status_actor_notes_and_audit(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->triaging()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post("/fleet-assets/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Vehicle checked and alert cleared.',
            ])
            ->assertRedirect();

        $alert->refresh();

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->status);
        $this->assertSame($this->admin->id, $alert->resolved_by_user_id);
        $this->assertSame('Vehicle checked and alert cleared.', $alert->notes);
        $this->assertNotNull($alert->resolved_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'controlRoom.alert.resolve',
            'auditable_type' => $alert->getMorphClass(),
            'auditable_id' => $alert->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_bulk_acknowledge_uses_canonical_status_and_actor(): void
    {
        $alerts = ControlRoomAlert::factory()->fromFleet()->open()->count(2)->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'acknowledge',
                'ids' => $alerts->pluck('id')->all(),
            ])
            ->assertRedirect();

        foreach ($alerts as $alert) {
            $this->assertDatabaseHas('control_room_alerts', [
                'id' => $alert->id,
                'status' => ControlRoomAlert::STATUS_ACK,
                'acknowledged_by_user_id' => $this->admin->id,
            ]);
        }

        $this->assertSame(
            2,
            AuditLog::where('action', 'controlRoom.alert.acknowledge')->count(),
        );
    }

    public function test_bulk_start_triage_advances_only_acknowledged_alerts(): void
    {
        $acknowledged = ControlRoomAlert::factory()
            ->fromFleet()
            ->acknowledged()
            ->count(2)
            ->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'triage',
                'ids' => $acknowledged->pluck('id')->all(),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        foreach ($acknowledged as $alert) {
            $this->assertDatabaseHas('control_room_alerts', [
                'id' => $alert->id,
                'status' => ControlRoomAlert::STATUS_TRIAGING,
            ]);
        }

        $this->assertSame(
            2,
            AuditLog::where('action', 'controlRoom.alert.triage')->count(),
        );
    }

    public function test_bulk_resolve_requires_resolution_notes(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'resolve',
                'ids' => [$alert->id],
            ])
            ->assertSessionHasErrors('resolution_notes');

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_bulk_resolve_uses_canonical_status_actor_and_notes(): void
    {
        $alerts = ControlRoomAlert::factory()->fromFleet()->triaging()->count(2)->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post('/fleet-assets/alerts/bulk-action', [
                'action' => 'resolve',
                'ids' => $alerts->pluck('id')->all(),
                'resolution_notes' => 'Bulk fleet alert review complete.',
            ])
            ->assertRedirect();

        foreach ($alerts as $alert) {
            $this->assertDatabaseHas('control_room_alerts', [
                'id' => $alert->id,
                'status' => ControlRoomAlert::STATUS_RESOLVED,
                'resolved_by_user_id' => $this->admin->id,
                'notes' => 'Bulk fleet alert review complete.',
            ]);
        }
    }

    public function test_fleet_manage_alone_cannot_mutate_alerts(): void
    {
        $alert = ControlRoomAlert::factory()->fromFleet()->open()->create($this->alertProvenance());
        $user = User::factory()->create(['approved_at' => now()]);
        $this->grantPermission($user, 'fleet.manage');

        $this->actingAs($user)
            ->post("/fleet-assets/alerts/{$alert->id}/acknowledge")
            ->assertForbidden();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_fleet_bridge_does_not_mutate_non_fleet_alerts(): void
    {
        $alert = ControlRoomAlert::factory()->fromCompliance()->open()->create($this->alertProvenance());

        $this->actingAs($this->admin)
            ->post("/fleet-assets/alerts/{$alert->id}/acknowledge")
            ->assertNotFound();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'tests', 'module' => 'tests'],
        );

        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    /** @return array{site_id: int, asset_id: int} */
    private function alertProvenance(): array
    {
        return [
            'site_id' => $this->site->id,
            'asset_id' => $this->asset->id,
        ];
    }
}
