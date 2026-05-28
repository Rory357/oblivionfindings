<?php

namespace Tests\Unit\Catering;

use App\Models\MealProduct;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\SiteMealShoppingList;
use App\Models\User;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\ShoppingListGenerator;
use App\Services\Catering\UnitConverter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CateringTenantFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopping_lists_fall_back_to_the_authenticated_users_organization(): void
    {
        $this->actingAs(User::factory()->create(['organization_id' => 77]));
        $site = Site::factory()->create(['tenant_id' => null]);

        (new ShoppingListGenerator(new UnitConverter()))->generate(
            $site,
            CarbonImmutable::parse('2026-05-25'),
            CarbonImmutable::parse('2026-05-31'),
        );

        $this->assertDatabaseHas(SiteMealShoppingList::class, [
            'site_id' => $site->id,
            'tenant_id' => 77,
        ]);
    }

    public function test_inventory_items_fall_back_to_the_authenticated_users_organization(): void
    {
        $this->actingAs(User::factory()->create(['organization_id' => 77]));
        $site = Site::factory()->create(['tenant_id' => null]);
        $product = MealProduct::create([
            'tenant_id' => 77,
            'name' => 'Rolled oats',
            'category' => 'Pantry',
            'default_unit' => 'kg',
            'is_active' => true,
        ]);

        $recorder = new InventoryMovementRecorder(new UnitConverter());
        $recorder->record($site, $product->id, 3, 'kg', 'delivery');

        $this->assertDatabaseHas(SiteMealInventoryItem::class, [
            'site_id' => $site->id,
            'product_id' => $product->id,
            'tenant_id' => 77,
        ]);
    }
}
