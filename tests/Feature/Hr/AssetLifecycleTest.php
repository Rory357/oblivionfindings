<?php

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetDocument;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AssetService;
use App\Models\Asset;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        'name' => 'Test Uniform',
        'category' => 'uniform',
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

test('non-HR-owned rows fail closed at every HR lifecycle boundary', function (string $ownership) {
    $makeReadOnly = function (string $status) use ($ownership): HrAsset {
        $attributes = [
            'asset_tag' => strtoupper($ownership).'-'.fake()->unique()->numberBetween(10000, 99999),
            'name' => match ($ownership) {
                'fleet' => 'Fleet-owned vehicle projection',
                'orphaned-fleet' => 'Unreconciled vehicle history',
                default => 'Historical laptop record',
            },
            'category' => $ownership === 'technology' ? 'laptop' : 'vehicle',
            'status' => $status,
            'qr_token' => null,
        ];

        if ($ownership === 'fleet') {
            $attributes['fleet_asset_id'] = Asset::factory()->create([
                'site_id' => $this->site->id,
                'category' => 'vehicle',
            ])->id;
        }

        return HrAsset::query()->create($attributes);
    };

    $service = app(AssetService::class);
    $available = $makeReadOnly('available');

    expect(fn () => $service->assignAsset($available, $this->hrProfile, [
        'assigned_by' => $this->hr->id,
    ]))->toThrow(LogicException::class)
        ->and(fn () => $service->logMaintenance($available, [
            'type' => 'repair',
            'performed_by' => $this->hr->id,
        ]))->toThrow(LogicException::class)
        ->and(fn () => $service->sendToMaintenance($available))->toThrow(LogicException::class)
        ->and(fn () => $service->retireAsset($available))->toThrow(LogicException::class)
        ->and(fn () => $service->ensureQrToken($available))->toThrow(LogicException::class);

    expect($available->fresh()->status)->toBe('available')
        ->and($available->fresh()->qr_token)->toBeNull()
        ->and(HrAssetAssignment::query()->where('asset_id', $available->id)->exists())->toBeFalse()
        ->and(HrAssetMaintenanceLog::query()->where('asset_id', $available->id)->exists())->toBeFalse();

    $maintenance = $makeReadOnly('maintenance');
    expect(fn () => $service->returnToService($maintenance))->toThrow(LogicException::class)
        ->and(fn () => $service->returnFromMaintenance($maintenance))->toThrow(LogicException::class)
        ->and($maintenance->fresh()->status)->toBe('maintenance');

    $assigned = $makeReadOnly('assigned');
    $assignment = HrAssetAssignment::query()->create([
        'asset_id' => $assigned->id,
        'employee_profile_id' => $this->hrProfile->id,
        'assigned_at' => now(),
        'assigned_by' => $this->hr->id,
    ]);

    expect(fn () => $service->returnAsset($assignment, [
        'returned_at' => now(),
    ]))->toThrow(LogicException::class)
        ->and($assignment->fresh()->returned_at)->toBeNull()
        ->and($assigned->fresh()->status)->toBe('assigned');
})->with([
    'Fleet-linked projection' => 'fleet',
    'unreconciled Fleet history' => 'orphaned-fleet',
    'historical technology row' => 'technology',
]);

test('HTTP edit assignment bulk and document paths cannot bypass canonical lifecycle ownership', function (string $ownership) {
    Storage::fake('local');

    $fleetAsset = $ownership === 'fleet'
        ? Asset::factory()->create([
            'site_id' => $this->site->id,
            'category' => 'vehicle',
        ])
        : null;
    $asset = HrAsset::query()->create([
        'asset_tag' => strtoupper($ownership).'-HTTP-GUARD',
        'name' => $ownership === 'fleet' ? 'Fleet projection' : 'Historical laptop',
        'category' => $ownership === 'fleet' ? 'vehicle' : 'laptop',
        'status' => 'available',
        'fleet_asset_id' => $fleetAsset?->id,
    ]);

    $updateResponse = $this->actingAs($this->hr)
        ->put("/hr/assets/{$asset->id}", [
            'asset_tag' => $asset->asset_tag,
            'name' => 'Attempted replacement name',
            'category' => $asset->category,
            'fleet_asset_id' => $fleetAsset?->id,
        ]);

    $assignResponse = $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/assign", [
            'employee_profile_id' => $this->hrProfile->id,
            'assigned_at' => today()->toDateString(),
        ]);

    $bulkResponse = $this->actingAs($this->hr)
        ->post('/hr/assets/bulk', [
            'action' => 'set-category',
            'ids' => [$asset->id],
            'category' => 'uniform',
        ]);

    $documentResponse = $this->actingAs($this->hr)
        ->post("/hr/assets/{$asset->id}/documents", [
            'title' => 'Attempted new document',
            'category' => 'manual',
            'file' => UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf'),
        ]);

    foreach ([$updateResponse, $assignResponse, $bulkResponse, $documentResponse] as $response) {
        if ($ownership === 'fleet') {
            $response->assertNotFound();
        } else {
            $response->assertSessionHas('error');
        }
    }

    expect($asset->fresh()->name)->not->toBe('Attempted replacement name')
        ->and($asset->fresh()->category)->toBe($ownership === 'fleet' ? 'vehicle' : 'laptop')
        ->and($asset->fresh()->status)->toBe('available')
        ->and(HrAssetAssignment::query()->where('asset_id', $asset->id)->exists())->toBeFalse()
        ->and(HrAssetDocument::query()->where('asset_id', $asset->id)->exists())->toBeFalse();
})->with([
    'Fleet-linked projection controller guards' => 'fleet',
    'historical technology controller guards' => 'technology',
]);

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
