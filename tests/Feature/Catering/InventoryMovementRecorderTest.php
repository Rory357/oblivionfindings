<?php

use App\Models\MealProduct;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealInventoryMovement;
use App\Models\User;
use App\Services\Catering\InventoryMovementRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function makeSite(): Site
{
    return Site::factory()->create(['type' => 'house']);
}

function makeProduct(string $unit = 'each'): MealProduct
{
    return MealProduct::create([
        'name' => 'Test Product ' . uniqid(),
        'default_unit' => $unit,
        'is_active' => true,
    ]);
}

it('records a movement and updates current_qty inside a transaction', function () {
    $site = makeSite();
    $product = makeProduct('each');
    $recorder = app(InventoryMovementRecorder::class);

    $movement = $recorder->record(
        site: $site,
        productId: $product->id,
        delta: 5,
        unit: 'each',
        reason: 'delivery',
    );

    expect($movement)->toBeInstanceOf(SiteMealInventoryMovement::class);
    $item = SiteMealInventoryItem::where('site_id', $site->id)->where('product_id', $product->id)->first();
    expect((float) $item->current_qty)->toBe(5.0);
    expect(SiteMealInventoryMovement::count())->toBe(1);
});

it('stocktake writes a single signed delta movement to reach the target', function () {
    $site = makeSite();
    $product = makeProduct('each');
    $recorder = app(InventoryMovementRecorder::class);

    $recorder->record(site: $site, productId: $product->id, delta: 4, unit: 'each', reason: 'delivery');
    $recorder->stocktake(site: $site, productId: $product->id, newQty: 6, unit: 'each');

    $item = SiteMealInventoryItem::where('site_id', $site->id)->where('product_id', $product->id)->first();
    expect((float) $item->current_qty)->toBe(6.0);
    expect($item->last_counted_at)->not->toBeNull();

    $movements = SiteMealInventoryMovement::orderBy('id')->get();
    expect($movements)->toHaveCount(2);
    expect((float) $movements->last()->delta)->toBe(2.0);
    expect($movements->last()->reason)->toBe('stocktake');
});

it('quick negative adjust drops on-hand below zero (no validation here)', function () {
    $site = makeSite();
    $product = makeProduct('each');
    $recorder = app(InventoryMovementRecorder::class);

    $recorder->record(site: $site, productId: $product->id, delta: 2, unit: 'each', reason: 'delivery');
    $recorder->record(site: $site, productId: $product->id, delta: -5, unit: 'each', reason: 'consumption');

    $item = SiteMealInventoryItem::where('product_id', $product->id)->first();
    expect((float) $item->current_qty)->toBe(-3.0);
});
