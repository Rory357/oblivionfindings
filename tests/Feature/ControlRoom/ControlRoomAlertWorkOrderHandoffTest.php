<?php

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('telemetry maintenance alert can be handed off to create a canonical fleet work order', function () {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $operator = User::factory()->create([]);
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $operator->id, 'primary_site_id' => $site->id, 'is_active' => true]);

    foreach (['controlRoom.alerts.view', 'controlRoom.alerts.manage', 'fleet.work_orders.create'] as $permKey) {
        $perm = Permission::query()->firstOrCreate(
            ['key' => $permKey],
            ['description' => $permKey, 'group' => 'cr', 'module' => 'ControlRoom']
        );
        $operator->permissionOverrides()->attach($perm, ['allowed' => true]);
    }

    $asset = Asset::factory()->create([
        'name' => 'Support Van 05',
        'category' => 'vehicle',
        'site_id' => $site->id,
    ]);

    $alert = ControlRoomAlert::create([
        'site_id' => $site->id,
        'asset_id' => $asset->id,
        'alert_type' => 'telemetry.engine.overheat',
        'severity' => 'high',
        'status' => 'new',
        'triggered_at' => now(),
        'source' => 'integration_telemetry',
        'notes' => 'Engine coolant temperature spike detected via OBD-II',
    ]);

    $response = $this->actingAs($operator)
        ->post(route('control-room.alerts.create-work-order', $alert), [
            'title' => 'Investigate engine coolant overheating',
            'priority' => 'high',
            'estimated_cost' => 350.00,
        ]);

    $response->assertRedirect();

    // Verify FleetWorkOrder created
    $workOrder = FleetWorkOrder::where('asset_id', $asset->id)->first();
    expect($workOrder)->not->toBeNull()
        ->and($workOrder->title)->toBe('Investigate engine coolant overheating')
        ->and($workOrder->priority)->toBe('high')
        ->and((int) $workOrder->reported_by_user_id)->toBe($operator->id);

    // Verify alert transitioned to triaged with notes
    $alert->refresh();
    expect($alert->status)->toBe('triaged')
        ->and($alert->notes)->toContain('Handed off to Fleet Work Order');
});
