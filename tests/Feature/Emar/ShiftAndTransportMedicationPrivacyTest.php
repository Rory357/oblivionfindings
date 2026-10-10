<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * EA-022: the client profile Transport tab shows medication-transit rows only
 * with the Medical section, and controlled rows only to controlled readers.
 * EA-069 / EA-166: the Shift page does not serialise the medical profile,
 * and the shift medication card and its API follow the per-person rule.
 */
class ShiftAndTransportMedicationPrivacyTest extends TestCase
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
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'medical_history' => 'Epilepsy since childhood',
            'mental_health_history' => 'Anxiety disorder',
            'allergies' => ['Peanuts'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_transport_tab_shows_no_medication_rows_without_the_medical_section(): void
    {
        $this->transitLogs();
        $viewer = $this->staff(['clients.viewAny' => true, 'fleet.viewAny' => true]);

        $logs = $this->transportTab($viewer)['medication_logs'];

        $this->assertSame([], $logs);
    }

    public function test_transport_tab_hides_controlled_rows_from_readers_without_controlled_view(): void
    {
        $this->transitLogs();
        $auditor = $this->staff(['clients.viewAny' => true, 'assets.viewAny' => true, 'medications.view' => true]);

        $names = collect($this->transportTab($auditor)['medication_logs'])->pluck('medication_name')->all();

        $this->assertSame(['Paracetamol 500mg'], $names);
    }

    public function test_the_shift_page_does_not_serialise_the_medical_profile_or_the_card_for_an_unassigned_relief_worker(): void
    {
        $relief = $this->staff(['shifts.viewAssigned' => true, 'medications.view' => true, 'medications.administer.record' => true]);
        $shift = $this->shiftFor($relief, 'in_progress');
        ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Sertraline', 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', 'is_prn' => true, 'controlled_drug' => false, 'start_date' => '2026-10-01',
        ]);

        $response = $this->actingAs($relief)->get(route('operations.shifts.show', $shift))->assertOk();

        $this->assertArrayNotHasKey('medical_profile', $response->inertiaProps('shift.client'));
        $this->assertNull($response->inertiaProps('medications'));
        $encoded = json_encode($response->inertiaProps());
        foreach (['Epilepsy since childhood', 'Anxiety disorder', 'Sertraline'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $this->actingAs($relief)
            ->getJson('/api/medications/shifts/'.$shift->id.'/medication-summary')
            ->assertNotFound();
    }

    public function test_an_assigned_worker_still_gets_the_shift_medication_card(): void
    {
        $worker = $this->staff(['shifts.viewAssigned' => true, 'medications.view' => true, 'medications.administer.record' => true]);
        $worker->assignedClients()->attach($this->client->id);
        $shift = $this->shiftFor($worker, 'in_progress');

        $response = $this->actingAs($worker)->get(route('operations.shifts.show', $shift))->assertOk();

        $this->assertNotNull($response->inertiaProps('medications'));
        $this->actingAs($worker)
            ->getJson('/api/medications/shifts/'.$shift->id.'/medication-summary')
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function transportTab(User $viewer): array
    {
        $response = $this->actingAs($viewer)
            ->get(route('operations.clients.show', $this->client), $this->inertiaPartialHeaders('operations/clients/show', 'transport'))
            ->assertOk();

        return $response->json('props.transport');
    }

    private function transitLogs(): void
    {
        $driver = User::factory()->create(['approved_at' => now()]);
        $asset = Asset::factory()->vehicle()->forSite($this->site)->create();
        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id, 'site_id' => $this->site->id, 'driver_user_id' => $driver->id,
            'resident_id' => $this->client->id, 'resident_name' => 'Resident', 'transport_type' => 'outing',
            'departed_at' => now(), 'passengers_count' => 1, 'status' => 'in_progress',
        ]);
        foreach ([['Paracetamol 500mg', false], ['Oxycodone 5mg', true]] as [$name, $controlled]) {
            $medication = ClientMedication::query()->create([
                'client_id' => $this->client->id, 'name' => $name, 'controlled_drug' => $controlled,
                'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 1,
            ]);
            FleetMedicationTransitLog::query()->create([
                'transport_id' => $transport->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
                'medication_id' => $medication->id, 'medication_order_version' => 1, 'medication_name' => $name,
                'is_controlled_drug' => $controlled, 'packed_by_user_id' => $driver->id, 'packed_at' => now(),
            ]);
        }
    }

    private function shiftFor(User $worker, string $status): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $worker->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2), 'status' => $status,
            'actual_starts_at' => null,
        ]);
    }

    /** @param array<string, bool> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-09-01', 'end_date' => null,
        ]);
        foreach ($permissions as $key => $allowed) {
            $permission = Permission::query()->where('key', $key)->firstOrFail();
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }

        return $user->fresh();
    }
}
