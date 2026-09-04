<?php

use App\Models\Asset;
use App\Models\FleetWorkOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user cannot view or modify fleet work orders from unauthorized foreign sites', function () {
    $accessibleSite = Site::factory()->create([
        'name' => 'Accessible Station',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $foreignSite = Site::factory()->create([
        'name' => 'Foreign Station',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $user = User::factory()->create();
    \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $accessibleSite->id, 'is_active' => true]);

    foreach (['fleet.viewAny', 'fleet.maintenance.manage'] as $permKey) {
        $perm = Permission::query()->firstOrCreate(
            ['key' => $permKey],
            ['description' => $permKey, 'group' => 'fleet', 'module' => 'Fleet']
        );
        $user->permissionOverrides()->attach($perm, ['allowed' => true]);
    }

    $accessibleAsset = Asset::factory()->create([
        'name' => 'Accessible Van',
        'category' => 'vehicle',
        'site_id' => $accessibleSite->id,
    ]);

    $foreignAsset = Asset::factory()->create([
        'name' => 'Foreign Van',
        'category' => 'vehicle',
        'site_id' => $foreignSite->id,
    ]);

    $accessibleOrder = FleetWorkOrder::create([
        'asset_id' => $accessibleAsset->id,
        'title' => 'Brake service on accessible van',
        'priority' => 'medium',
        'category' => 'maintenance',
        'status' => 'open',
        'reported_by_user_id' => $user->id,
    ]);

    $foreignOrder = FleetWorkOrder::create([
        'asset_id' => $foreignAsset->id,
        'title' => 'Tire change on foreign van',
        'priority' => 'high',
        'category' => 'maintenance',
        'status' => 'open',
        'reported_by_user_id' => $user->id,
    ]);

    // 1. Index should conceal foreign work orders
    $response = $this->actingAs($user)->get(route('fleet-assets.work-orders.index'));
    $response->assertOk();
    $pageData = $response->viewData('page')['props']['work_orders']['data'];
    $ids = collect($pageData)->pluck('id')->all();

    expect($ids)->toContain($accessibleOrder->id)
        ->and($ids)->not->toContain($foreignOrder->id);

    // 2. Direct-object show on foreign work order returns 404
    $this->actingAs($user)
        ->get(route('fleet-assets.work-orders.show', $foreignOrder))
        ->assertNotFound();

    // 3. Direct-object update on foreign work order returns 404
    $this->actingAs($user)
        ->put(route('fleet-assets.work-orders.update', $foreignOrder), [
            'priority' => 'critical',
        ])
        ->assertNotFound();

    // 4. Storing a work order for foreign asset returns 404
    $this->actingAs($user)
        ->post(route('fleet-assets.work-orders.store'), [
            'asset_id' => $foreignAsset->id,
            'title' => 'Unauthorized maintenance',
            'priority' => 'low',
        ])
        ->assertNotFound();
});
