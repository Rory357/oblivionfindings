<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverDirectObjectConcealmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_emar_handover_mutation_returns_the_same_not_found_for_foreign_and_missing_ids(): void
    {
        $this->seed([
            RbacSeeder::class,
            OperationsPermissionsSeeder::class,
        ]);
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Handover concealment',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionMap = Permission::query()
            ->whereIn('key', ['handovers.create', 'handovers.viewAny'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $actor->permissionOverrides()->sync($permissionMap);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $localSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);
        $foreignHandover = ShiftHandover::factory()->draft()->create([
            'client_id' => $foreignClient->id,
        ]);
        $missingHandoverId = (int) ShiftHandover::query()->max('id') + 1000;

        $requests = [
            ['PUT', fn (int $id): string => route('emar.handovers.update', $id)],
            ['POST', fn (int $id): string => route('emar.handovers.submit', $id)],
            ['POST', fn (int $id): string => route('emar.handovers.acknowledge', $id)],
            ['POST', fn (int $id): string => route('emar.handovers.lock', $id)],
            ['POST', fn (int $id): string => route('emar.handovers.unlock', $id)],
            ['DELETE', fn (int $id): string => route('emar.handovers.destroy', $id)],
        ];

        foreach ($requests as [$method, $uri]) {
            foreach ([$foreignHandover->id, $missingHandoverId] as $handoverId) {
                $this->actingAs($actor)
                    ->call($method, $uri((int) $handoverId))
                    ->assertNotFound();
            }
        }

        $this->assertDatabaseHas('shift_handovers', [
            'id' => $foreignHandover->id,
            'status' => $foreignHandover->status,
            'locked_by' => null,
            'locked_at' => null,
        ]);
    }
}
