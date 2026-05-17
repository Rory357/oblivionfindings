<?php

use App\Models\Site;
use App\Models\SiteMealShoppingList;
use App\Services\Catering\DeliveryProviders\DeliveryProviderManager;
use App\Services\Catering\DeliveryProviders\NullDeliveryProvider;
use App\Services\Catering\DeliveryProviders\UnsupportedOperationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

it('manager resolves the manual provider by default', function () {
    $manager = app(DeliveryProviderManager::class);
    $provider = $manager->resolve();
    expect($provider)->toBeInstanceOf(NullDeliveryProvider::class);
    expect($provider->key())->toBe('manual');
});

it('null provider returns empty search and no match', function () {
    $provider = app(NullDeliveryProvider::class);
    expect($provider->searchProducts('anything')->isEmpty())->toBeTrue();
    expect($provider->matchProduct(123))->toBeNull();
});

it('submitOrder throws on the null provider', function () {
    $provider = app(NullDeliveryProvider::class);
    $site = Site::factory()->create();
    $list = SiteMealShoppingList::create([
        'site_id' => $site->id,
        'status' => 'draft',
        'covers_from' => '2026-05-18',
        'covers_to' => '2026-05-24',
        'provider_key' => 'manual',
    ]);

    expect(fn () => $provider->submitOrder($site, $list))->toThrow(UnsupportedOperationException::class);
});
