<?php

namespace Tests\Unit\Catering;

use App\Models\MealProduct;
use App\Models\Site;
use App\Models\SiteMealInventoryItem;
use App\Models\User;
use App\Services\Catering\InventoryMovementRecorder;
use App\Services\Catering\ShoppingListGenerator;
use App\Services\Catering\UnitConverter;
use App\Support\LegacyStorageContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CateringApplicationStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopping_lists_share_inert_storage_while_site_and_generator_ownership_remain_canonical(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $generator = new ShoppingListGenerator(new UnitConverter);
        $from = CarbonImmutable::parse('2026-05-25');
        $to = CarbonImmutable::parse('2026-05-31');

        $firstList = $generator->generate($firstSite, $from, $to);
        $secondList = $generator->generate($secondSite, $from, $to);

        $this->assertNotSame($firstList->id, $secondList->id);
        $this->assertTrue($firstList->site->is($firstSite));
        $this->assertTrue($secondList->site->is($secondSite));
        $this->assertSame($operator->id, $firstList->generated_by);
        $this->assertSame($operator->id, $secondList->generated_by);
        $this->assertTrue($firstList->generatedBy->is($operator));
        $this->assertSame(
            LegacyStorageContext::id(),
            $firstList->getAttribute(LegacyStorageContext::column()),
        );
        $this->assertSame(
            $firstList->getAttribute(LegacyStorageContext::column()),
            $secondList->getAttribute(LegacyStorageContext::column()),
        );
        $this->assertContains(LegacyStorageContext::column(), $firstList->getHidden());
    }

    public function test_inventory_is_owned_by_site_and_product_while_actor_identity_remains_provenance(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $product = MealProduct::create([
            'name' => 'Rolled oats',
            'category' => 'Pantry',
            'default_unit' => 'kg',
            'is_active' => true,
        ]);

        $recorder = new InventoryMovementRecorder(new UnitConverter);
        $firstMovement = $recorder->record($firstSite, $product->id, 3, 'kg', 'delivery');
        $secondMovement = $recorder->record($secondSite, $product->id, 5, 'kg', 'delivery');
        $firstItem = SiteMealInventoryItem::query()
            ->where('site_id', $firstSite->id)
            ->where('product_id', $product->id)
            ->sole();
        $secondItem = SiteMealInventoryItem::query()
            ->where('site_id', $secondSite->id)
            ->where('product_id', $product->id)
            ->sole();

        $this->assertNotSame($firstItem->id, $secondItem->id);
        $this->assertTrue($firstItem->site->is($firstSite));
        $this->assertTrue($secondItem->site->is($secondSite));
        $this->assertTrue($firstItem->product->is($product));
        $this->assertSame(3.0, (float) $firstItem->current_qty);
        $this->assertSame(5.0, (float) $secondItem->current_qty);
        $this->assertSame($operator->id, $firstMovement->performed_by);
        $this->assertSame($operator->id, $secondMovement->performed_by);
        $this->assertSame(
            LegacyStorageContext::id(),
            $firstItem->getAttribute(LegacyStorageContext::column()),
        );
        $this->assertSame(
            $firstItem->getAttribute(LegacyStorageContext::column()),
            $secondItem->getAttribute(LegacyStorageContext::column()),
        );
        $this->assertContains(LegacyStorageContext::column(), $firstItem->getHidden());
    }
}
