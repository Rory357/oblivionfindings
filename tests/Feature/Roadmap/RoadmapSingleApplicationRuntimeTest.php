<?php

namespace Tests\Feature\Roadmap;

use App\Support\LegacyStorageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapSingleApplicationRuntimeTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
    }

    public function test_authorized_users_read_one_shared_roadmap_register(): void
    {
        $admin = $this->createAdminUser();
        $category = $this->roadmapCategory();
        $first = $this->createInitiative($admin, [
            'title' => 'Network resilience programme',
            'category_id' => $category->id,
        ]);
        $second = $this->createInitiative($admin, [
            'title' => 'Device lifecycle programme',
            'category_id' => $category->id,
        ]);
        $storageColumn = LegacyStorageContext::column();
        DB::table('roadmap_initiatives')->where('id', $first->id)->update([$storageColumn => 41]);
        DB::table('roadmap_initiatives')->where('id', $second->id)->update([$storageColumn => 73]);

        $response = $this->actingAs($admin)
            ->getJson('/roadmap/initiatives?'.$storageColumn.'=41')
            ->assertOk()
            ->assertJsonCount(2, 'items.data');

        $ids = collect($response->json('items.data'))->pluck('id');
        $this->assertTrue($ids->contains($first->id));
        $this->assertTrue($ids->contains($second->id));
        $this->assertFalse(collect($response->json('items.data'))->contains(
            fn (array $row): bool => array_key_exists($storageColumn, $row),
        ));
    }

    public function test_quick_add_uses_the_canonical_write_only_storage_value(): void
    {
        $admin = $this->createAdminUser();
        $storageColumn = LegacyStorageContext::column();
        $this->createInitiative($admin, [
            'code' => now()->year.'-RI-0099',
            'title' => 'Existing imported roadmap item',
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/roadmap/initiatives', [
                'title' => 'Unified monitoring coverage',
                'category_key' => 'it',
                'stream' => 'it',
                $storageColumn => 999999,
            ])
            ->assertCreated()
            ->assertJsonPath('item.code', now()->year.'-RI-0100');

        $initiativeId = (int) $response->json('item.id');
        $this->assertSame(
            LegacyStorageContext::id(),
            (int) DB::table('roadmap_initiatives')->where('id', $initiativeId)->value($storageColumn),
        );
        $this->assertArrayNotHasKey($storageColumn, $response->json('item'));
    }
}
