<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create();
    $this->hrProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
});

function makeAsset(array $overrides = []): HrAsset
{
    return HrAsset::query()->create(array_merge([
        'asset_tag' => 'AT-'.fake()->unique()->numberBetween(1000, 999999),
        'name' => 'Test Laptop',
        'category' => 'laptop',
        'status' => 'available',
    ], $overrides));
}

test('logging a repair moves an available asset into maintenance and records a log', function () {
    $asset = makeAsset();

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/maintenance", [
            'type' => 'repair',
            'vendor' => 'iFix Repairs',
            'cost' => 240,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('maintenance');
    expect(HrAssetMaintenanceLog::where('asset_id', $asset->id)->count())->toBe(1);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/return-to-service", [
            'outcome' => 'repaired',
        ])
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('available');
    $log = HrAssetMaintenanceLog::where('asset_id', $asset->id)->first();
    expect($log->completed_at)->not->toBeNull();
});

test('an available asset can be retired', function () {
    $asset = makeAsset();

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire", ['disposal_reason' => 'end-of-life'])
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('retired');
});

test('a maintenance asset can be retired', function () {
    $asset = makeAsset(['status' => 'maintenance']);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire", ['disposal_reason' => 'damaged'])
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('retired');
});

test('an assigned asset cannot be retired (must be returned first)', function () {
    $asset = makeAsset();
    app(AssetService::class)->assignAsset($asset, $this->hrProfile, [
        'assigned_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire")
        ->assertSessionHas('error');

    expect($asset->fresh()->status)->toBe('assigned');
});

test('an assigned asset cannot be sent for repair', function () {
    $asset = makeAsset();
    app(AssetService::class)->assignAsset($asset, $this->hrProfile, [
        'assigned_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/maintenance", ['type' => 'repair'])
        ->assertSessionHas('error');

    expect($asset->fresh()->status)->toBe('assigned');
});

test('a user without hr.assets.manage cannot transition an asset', function () {
    $asset = makeAsset();
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($worker)
        ->post("/hr/assets/{$asset->id}/maintenance", ['type' => 'repair'])
        ->assertForbidden();

    expect($asset->fresh()->status)->toBe('available');
});

test('vehicles and keys cannot be hand-typed — they must federate to Fleet', function () {
    $this->actingAs($this->hr)
        ->post('/hr/assets', [
            'asset_tag' => 'VH-9999',
            'name' => 'Rogue van',
            'category' => 'vehicle',
        ])
        ->assertStatus(422);

    expect(HrAsset::where('asset_tag', 'VH-9999')->exists())->toBeFalse();
});

test('the assets hub lists access-approved inventory with complete aggregates', function () {
    $asset = makeAsset(['asset_tag' => 'AT-INDEX-1']);

    $response = $this->actingAs($this->hr)->get('/hr/assets');
    $response->assertOk();

    $tags = collect($response->inertiaProps('inventory'))->pluck('tag');
    expect($tags)->toContain('AT-INDEX-1');
    expect($response->inertiaProps('hero.total'))->toBeGreaterThanOrEqual(1);
});

test('an asset can be assigned with a return-by date then returned', function () {
    $asset = makeAsset();
    $profile = $this->hrProfile;

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/assign", [
            'employee_profile_id' => $profile->id,
            'assigned_at' => now()->toDateString(),
            'due_at' => now()->addMonths(3)->toDateString(),
            'condition_on_assign' => 'good',
            'acknowledged' => true,
        ])
        ->assertSessionHas('success');

    $asset->refresh();
    expect($asset->status)->toBe('assigned');
    $assignment = $asset->currentAssignment;
    expect($assignment)->not->toBeNull();
    expect($assignment->due_at)->not->toBeNull();
    expect($assignment->acknowledged_at)->not->toBeNull();

    $this->actingAs($this->hr)
        ->post("/hr/assets/assignments/{$assignment->id}/return", [
            'returned_at' => now()->toDateString(),
            'condition_on_return' => 'good',
        ])
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('available');
});

test('asset lifecycle transitions re-read locked state instead of trusting stale models', function () {
    $asset = makeAsset(['asset_tag' => 'AT-STALE-STATE']);
    $staleAvailableAsset = HrAsset::query()->findOrFail($asset->id);

    HrAsset::query()->whereKey($asset->id)->update(['status' => 'assigned']);

    expect(fn () => app(AssetService::class)->assignAsset(
        $staleAvailableAsset,
        $this->hrProfile,
        ['assigned_by' => $this->hr->id],
    ))->toThrow(LogicException::class);

    expect(HrAssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(0)
        ->and($asset->fresh()->status)->toBe('assigned');
});

test('duplicate maintenance and inconsistent active-assignment retirement fail closed', function () {
    $asset = makeAsset(['asset_tag' => 'AT-LOCKED-LIFECYCLE']);
    $staleAvailableAsset = HrAsset::query()->findOrFail($asset->id);

    app(AssetService::class)->logMaintenance($asset, [
        'type' => 'repair',
        'performed_by' => $this->hr->id,
    ]);

    expect(fn () => app(AssetService::class)->logMaintenance($staleAvailableAsset, [
        'type' => 'repair',
        'performed_by' => $this->hr->id,
    ]))->toThrow(LogicException::class)
        ->and(HrAssetMaintenanceLog::query()->where('asset_id', $asset->id)->count())->toBe(1);

    $inconsistent = makeAsset(['asset_tag' => 'AT-INCONSISTENT-ASSIGNMENT']);
    HrAssetAssignment::query()->create([
        'asset_id' => $inconsistent->id,
        'employee_profile_id' => $this->hrProfile->id,
        'assigned_at' => now(),
        'assigned_by' => $this->hr->id,
    ]);

    expect(fn () => app(AssetService::class)->retireAsset($inconsistent))
        ->toThrow(LogicException::class)
        ->and($inconsistent->fresh()->status)->toBe('available');
});

test('asset tags are one application-wide identity', function () {
    makeAsset([
        'asset_tag' => 'AT-GLOBAL-IDENTITY',
    ]);

    expect(fn () => makeAsset([
        'asset_tag' => 'AT-GLOBAL-IDENTITY',
    ]))->toThrow(QueryException::class);
});
