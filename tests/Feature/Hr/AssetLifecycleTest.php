<?php

use App\Domain\Hr\Models\HrAsset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

function makeAsset(array $overrides = []): HrAsset
{
    return HrAsset::query()->create(array_merge([
        'tenant_id' => 1,
        'asset_tag' => 'AT-'.fake()->unique()->numberBetween(1000, 999999),
        'name' => 'Test Laptop',
        'category' => 'laptop',
        'status' => 'available',
    ], $overrides));
}

test('an available asset can be sent to maintenance and back to service', function () {
    $asset = makeAsset();

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/maintenance")
        ->assertRedirect()
        ->assertSessionHas('success');
    expect($asset->fresh()->status)->toBe('maintenance');

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/return-from-maintenance")
        ->assertSessionHas('success');
    expect($asset->fresh()->status)->toBe('available');
});

test('an available asset can be retired', function () {
    $asset = makeAsset();

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire")
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('retired');
});

test('a maintenance asset can be retired', function () {
    $asset = makeAsset(['status' => 'maintenance']);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire")
        ->assertSessionHas('success');

    expect($asset->fresh()->status)->toBe('retired');
});

test('an assigned asset cannot be retired (must be returned first)', function () {
    $asset = makeAsset(['status' => 'assigned']);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/retire")
        ->assertSessionHas('error');

    expect($asset->fresh()->status)->toBe('assigned');
});

test('an assigned asset cannot be sent to maintenance', function () {
    $asset = makeAsset(['status' => 'assigned']);

    $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/maintenance")
        ->assertSessionHas('error');

    expect($asset->fresh()->status)->toBe('assigned');
});

test('a user without hr.assets.manage cannot transition an asset', function () {
    $asset = makeAsset();
    $worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->actingAs($worker)
        ->post("/hr/assets/{$asset->id}/maintenance")
        ->assertForbidden();

    expect($asset->fresh()->status)->toBe('available');
});

test('the assets index resolves the tenant and lists tenant-1 assets', function () {
    // Regression: the controller used $user->tenant_id (always null) → forTenant(null)
    // → the seeded tenant-1 assets were never shown. ResolvesHrTenant fixes it.
    $asset = makeAsset(['asset_tag' => 'AT-INDEX-1']);

    $response = $this->actingAs($this->hr)->get('/hr/assets');
    $response->assertOk();

    $tags = collect($response->inertiaProps('assets.data'))->pluck('asset_tag');
    expect($tags)->toContain('AT-INDEX-1');
});
