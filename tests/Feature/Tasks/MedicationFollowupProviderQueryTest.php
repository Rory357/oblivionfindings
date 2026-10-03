<?php

namespace Tests\Feature\Tasks;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\MedicationFollowupProvider;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MedicationReadQueryInventory;
use Tests\TestCase;

class MedicationFollowupProviderQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reoffer_administrations_are_loaded_once_after_canonical_person_and_order_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $actor = User::factory()->frontlineWorker()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        ]);
        $this->permissions($actor, ['medications.view', 'clients.viewAssigned'], true);
        $this->permissions($actor, [
            'clients.viewAny', 'sites.viewAll', 'clinical.accessAllSites', 'medications.audit.view',
            'medications.stock.update', 'medications.reports.view', 'medications.reports.export',
            'medications.controlled.view',
        ], false);
        $assigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $assigned->supportWorkers()->attach($actor->id);
        $visible = [$this->reoffer($assigned, $actor)];

        $unassigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreign = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active',
        ]);
        $foreign->supportWorkers()->attach($actor->id);
        $hidden = [
            $this->reoffer($unassigned, $actor),
            $this->reoffer($foreign, $actor),
            $this->reoffer($assigned, $actor, controlled: true),
        ];
        $hidden[] = MedicationFollowup::query()->create([
            'source_key' => 'misbound:'.$hidden[0]->id, 'type' => 'reoffer', 'client_id' => $assigned->id,
            'client_medication_id' => $hidden[0]->client_medication_id,
            'administration_id' => $hidden[0]->administration_id, 'owner_id' => $actor->id,
            'original_owner_id' => $actor->id, 'state' => 'open', 'revision' => 1, 'due_at' => now(),
        ]);

        foreach ([1, 6] as $count) {
            while (count($visible) < $count) {
                $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
                $client->supportWorkers()->attach($actor->id);
                $visible[] = $this->reoffer($client, $actor);
            }
            $before = ClientMedicationAdministration::query()->orderBy('id')->get()->map->getRawOriginal()->all();
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $items = app(MedicationFollowupProvider::class)->authorizedTasks($actor->fresh());
                $reads = MedicationReadQueryInventory::fromLog(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $expectedIds = collect($visible)->map(fn ($row) => 'medication-followup-'.$row->id)->sort()->values()->all();
            $this->assertSame($expectedIds, collect($items)->pluck('id')->sort()->values()->all());
            $this->assertSame([
                'scheduled_window' => 0, 'board_day' => 0, 'prn_unresolved' => 0,
                'administration_batch' => 1, 'followup_scope' => 1, 'refusal_scope' => 0, 'unexpected' => 0,
            ], MedicationReadQueryInventory::counts($reads), MedicationReadQueryInventory::describe($reads));
            $expectedAdministrationIds = collect($visible)->pluck('administration_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $batch = $reads['administration_batch'][0];
            $this->assertSame(1, preg_match('/(?:^|[.\s(])[\x60"]?id[\x60"]?\s+in\s*\(([^)]+)\)/i', $batch['query'], $idList));
            // Eloquent integer eager loads may inline IDs instead of binding them.
            $actualIds = str_contains($idList[1], '?') ? $batch['bindings'] : explode(',', $idList[1]);
            $this->assertSame(
                $expectedAdministrationIds,
                collect($actualIds)->map(fn ($id) => (int) trim((string) $id))->sort()->values()->all(),
            );
            $this->assertSame($before, ClientMedicationAdministration::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        }

        foreach ($hidden as $row) {
            $this->actingAs($actor->fresh())->getJson('/tasks/detail?'.http_build_query([
                'source' => 'medication-followup', 'id' => $row->id,
            ]))->assertNotFound();
        }
        $this->permissions($actor, ['medications.view'], false);
        app()->forgetScopedInstances();
        $this->assertSame([], app(MedicationFollowupProvider::class)->authorizedTasks($actor->fresh()));
        $this->actingAs($actor->fresh())->getJson('/tasks/detail?'.http_build_query([
            'source' => 'medication-followup', 'id' => $visible[0]->id,
        ]))->assertNotFound();
    }

    private function reoffer(Client $client, User $actor, bool $controlled = false): MedicationFollowup
    {
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => $controlled ? 'Hidden controlled medicine' : 'Synthetic ordinary medicine',
            'dosage' => '1 tablet', 'frequency' => 'Daily', 'dose_times' => ['09:00'],
            'is_prn' => false, 'controlled_drug' => $controlled, 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', 'start_date' => today()->subMonth(), 'end_date' => null,
        ]);
        $administration = ClientMedicationAdministration::withoutEvents(fn () => ClientMedicationAdministration::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $medication->id,
            'administered_by' => $actor->id, 'administered_at' => now()->subMinutes(20),
            'scheduled_for' => now()->subMinutes(30), 'status' => 'refused',
        ]));

        return MedicationFollowup::query()->create([
            'source_key' => 'refusal:'.$administration->id, 'type' => 'reoffer', 'client_id' => $client->id,
            'client_medication_id' => $medication->id, 'administration_id' => $administration->id,
            'owner_id' => $actor->id, 'original_owner_id' => $actor->id,
            'state' => 'open', 'revision' => 1, 'due_at' => now()->addMinutes(30),
        ]);
    }

    private function permissions(User $actor, array $keys, bool $allowed): void
    {
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        $this->assertCount(count($keys), $ids);
        $actor->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => $allowed]])->all());
        $actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
