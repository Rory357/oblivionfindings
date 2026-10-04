<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShiftMedicationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_controlled_recording_never_replaces_exact_controlled_view_permission(): void
    {
        [$actor, $shift, $history] = $this->context();
        $this->assertTrue($actor->canDo('medications.controlled.record'));
        $this->assertFalse($actor->canDo('medications.controlled.view'));
        $before = MedicationEvent::count();
        $response = $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk();
        $this->assertStringContainsString('Ordinary PRN', json_encode($response->inertiaProps('medications')));
        $this->assertSame([[
            'id' => $history['Ordinary']->id,
            'medication_name' => 'Ordinary PRN',
            'status' => 'given',
            'administered_at' => $history['Ordinary']->administered_at->toIso8601String(),
            'is_controlled' => false,
            'is_prn' => true,
        ]], $response->inertiaProps('medications.recent_history'));
        foreach (['Controlled scheduled', 'Controlled PRN', 'Controlled history note'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($response->inertiaProps()));
        }
        $this->assertSame($before, MedicationEvent::count());
    }

    public function test_record_only_frontline_reader_keeps_ordinary_medication_visibility(): void
    {
        [$actor, $shift] = $this->context(['medications.view' => false]);
        $this->assertFalse($actor->canDo('medications.view'));
        $this->assertTrue($actor->canDo('medications.administer.record'));
        $before = MedicationEvent::count();
        $response = $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk();
        $this->assertTrue($response->inertiaProps('can.view_medication'));
        $this->assertStringContainsString('Ordinary PRN', json_encode($response->inertiaProps('medications')));
        $this->assertStringNotContainsString('Controlled PRN', json_encode($response->inertiaProps()));
        $this->assertSame($before, MedicationEvent::count());
    }

    public function test_exact_controlled_reader_keeps_scheduled_prn_and_history_evidence(): void
    {
        [$actor, $shift, $history] = $this->context(['medications.controlled.view' => true]);
        $before = MedicationEvent::count();
        $response = $this->actingAs($actor)->get(route('operations.shifts.show', $shift))->assertOk();
        foreach (['Controlled scheduled', 'Controlled PRN'] as $visible) {
            $this->assertStringContainsString($visible, json_encode($response->inertiaProps('medications')));
        }
        $rows = $response->inertiaProps('medications.recent_history');
        usort($rows, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        $this->assertSame([
            [
                'id' => $history['Ordinary']->id,
                'medication_name' => 'Ordinary PRN',
                'status' => 'given',
                'administered_at' => $history['Ordinary']->administered_at->toIso8601String(),
                'is_controlled' => false,
                'is_prn' => true,
            ],
            [
                'id' => $history['Controlled']->id,
                'medication_name' => 'Controlled PRN',
                'status' => 'given',
                'administered_at' => $history['Controlled']->administered_at->toIso8601String(),
                'is_controlled' => true,
                'is_prn' => true,
            ],
        ], $rows);
        $this->assertSame($before, MedicationEvent::count());
    }

    public function test_shift_readers_cannot_use_the_medication_lens_after_site_access_is_removed(): void
    {
        [$actor, $shift] = $this->context(['medications.controlled.view' => true]);
        $actor->hrEmployeeProfile->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $before = MedicationEvent::count();
        $this->actingAs($actor->fresh())->get(route('operations.shifts.show', $shift))->assertForbidden();
        $this->assertSame($before, MedicationEvent::count());
    }

    private function context(array $permissions = []): array
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-09-01', 'end_date' => null,
        ]);
        foreach ($permissions + [
            'shifts.viewAssigned' => true, 'shifts.manageAny' => false, 'reports.viewAny' => false,
            'medications.view' => true, 'medications.administer.record' => true,
            'medications.controlled.record' => true, 'medications.controlled.view' => false,
            'clinical.accessAllSites' => false, 'sites.viewAll' => false,
        ] as $key => $allowed) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }
        $actor->assignedClients()->attach($this->client->id);
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $actor->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'status' => 'in_progress',
        ]);
        $history = [];
        foreach ([false, true] as $controlled) {
            $prefix = $controlled ? 'Controlled' : 'Ordinary';
            ClientMedication::factory()->create([
                'client_id' => $this->client->id, 'name' => $prefix.' scheduled', 'active' => true, 'state' => 'active',
                'created_at' => now()->subHours(2), 'approval_status' => 'verified', 'is_prn' => false, 'controlled_drug' => $controlled,
                'start_date' => '2026-10-01', 'end_date' => null, 'dose_times' => ['11:00'],
            ]);
            $prn = ClientMedication::factory()->create([
                'client_id' => $this->client->id, 'name' => $prefix.' PRN', 'active' => true, 'state' => 'active',
                'created_at' => now()->subHours(2), 'approval_status' => 'verified', 'is_prn' => true, 'controlled_drug' => $controlled,
                'start_date' => '2026-10-01', 'end_date' => null,
            ]);
            $history[$prefix] = ClientMedicationAdministration::query()->create([
                'client_id' => $this->client->id, 'client_medication_id' => $prn->id,
                'shift_id' => $shift->id, 'administered_by' => $actor->id,
                'administered_at' => now()->subMinutes(15), 'status' => 'given',
                'notes' => $prefix.' history note',
            ]);
        }

        return [$actor->fresh(), $shift, $history];
    }
}
