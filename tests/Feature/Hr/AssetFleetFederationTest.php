<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Seam S2 — HR Assets ↔ Fleet. HR federates vehicles/keys from the canonical
 * Fleet `Asset` register via HrAsset.fleet_asset_id (a read-through pointer),
 * but Fleet OWNS them: HR must never hand-type a duplicate fleet record and
 * must never retire/dispose a Fleet-owned asset. These tests prove the seam at
 * runtime rather than by code-reading (see Run 21, F-67/F-68).
 */
beforeEach(function () {
    $this->seed(RbacSeeder::class);
    // RbacSeeder (the demo seed) does not create the hr.assets.* permissions —
    // SeedHrPermissionsSeeder does, and attaches them to the `hr` role.
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    $this->site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->manager->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
    $this->manager->permissionOverrides()->attach(
        Permission::query()->where('key', 'assets.viewAny')->firstOrFail()->id,
        ['allowed' => true],
    );
});

test('S2 seam: HR cannot hand-type a fleet-category asset — vehicles/keys must link to the Fleet register', function () {
    $this->actingAs($this->manager)
        ->post('/hr/assets', [
            'asset_tag' => 'VEH-001',
            'name' => 'Van',
            'category' => 'vehicle', // fleet category, but no fleet_asset_id
        ])
        ->assertStatus(422);

    expect(HrAsset::query()->where('asset_tag', 'VEH-001')->exists())->toBeFalse();
});

test('S2 seam: an HR asset linked to a Fleet asset reads THROUGH to the canonical Fleet record', function () {
    $fleetVehicle = Asset::factory()->create([
        'site_id' => $this->site->id,
        'name' => 'Fleet Van 7',
        'category' => 'vehicle',
    ]);

    $hrAsset = HrAsset::query()->create([
        'asset_tag' => 'VEH-FED-1',
        'name' => 'Van 7 (HR view)',
        'category' => 'vehicle',
        'status' => 'available',
        'fleet_asset_id' => $fleetVehicle->id,
        'qr_token' => (string) Str::uuid(),
    ]);

    expect($hrAsset->isFleetLinked())->toBeTrue();
    expect($hrAsset->fleetAsset)->not->toBeNull();
    expect($hrAsset->fleetAsset->id)->toBe($fleetVehicle->id);
    // HR reads the canonical name through the pointer — it does not own a copy.
    expect($hrAsset->fleetAsset->name)->toBe('Fleet Van 7');
});

test('S2 seam: bulk-retire skips Fleet-linked rows — Fleet owns disposal, HR must not retire it', function () {
    $fleetVehicle = Asset::factory()->create([
        'site_id' => $this->site->id,
        'category' => 'vehicle',
    ]);

    $fleetLinked = HrAsset::query()->create([
        'asset_tag' => 'VEH-FED-2',
        'name' => 'Fleet-linked van',
        'category' => 'vehicle',
        'status' => 'available',
        'fleet_asset_id' => $fleetVehicle->id,
        'qr_token' => (string) Str::uuid(),
    ]);

    $hrOwned = HrAsset::query()->create([
        'asset_tag' => 'LAP-1',
        'name' => 'MacBook',
        'category' => 'laptop',
        'status' => 'available',
        'qr_token' => (string) Str::uuid(),
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/assets/bulk', [
            'action' => 'retire',
            'ids' => [$fleetLinked->id, $hrOwned->id],
        ])
        ->assertSessionHas('success');

    // Fleet-linked row untouched (Fleet owns disposal); the HR-owned one retires.
    expect($fleetLinked->fresh()->status)->toBe('available');
    expect($hrOwned->fresh()->status)->toBe('retired');
});
