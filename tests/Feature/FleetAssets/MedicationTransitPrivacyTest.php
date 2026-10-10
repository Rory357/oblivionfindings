<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * EA-002 / EA-032 / EA-100: the Fleet medication-transit register follows the
 * eMAR person rule and controlled concealment, fleet.manage is not a
 * medication Site bypass, the CSV goes through the P09 export guard, dates are
 * NZ days, and one journey can carry more than one dose of a medicine.
 */
class MedicationTransitPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $houseA;

    private Site $houseB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->houseA = Site::factory()->create(['name' => 'Kowhai House']);
        $this->houseB = Site::factory()->create(['name' => 'Rimu House']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_an_ordinary_support_worker_sees_transit_rows_only_for_residents_they_support(): void
    {
        $worker = $this->staffAt($this->houseA, role: 'support_worker');
        [$assigned] = $this->transitLog($this->houseA, 'Paracetamol 500mg');
        [$other] = $this->transitLog($this->houseA, 'Sertraline 50mg');
        $assigned->supportWorkers()->syncWithoutDetaching([$worker->id]);

        $this->actingAs($worker)
            ->get('/fleet-assets/transports/medications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.client.id', $assigned->id)
                ->where('logs.data', fn ($rows) => collect($rows)->pluck('client.id')->doesntContain($other->id)));
    }

    public function test_fleet_manage_is_not_a_medication_site_bypass(): void
    {
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        [$foreign, $foreignLog, $foreignTransport, $foreignMedication] = $this->transitLog($this->houseB, 'Clozapine 100mg');
        [$own] = $this->transitLog($this->houseA, 'Metformin 500mg');

        $this->actingAs($coordinator)
            ->get('/fleet-assets/transports/medications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.client.id', $own->id));

        // The journey itself is Fleet's (fleet.manage), its medicines are eMAR's.
        $this->actingAs($coordinator)
            ->get("/fleet-assets/transports/{$foreignTransport->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('medication_context.transit_logs', 0)
                ->has('medication_context.available_medications', 0));

        $this->actingAs($coordinator)
            ->get('/fleet-assets/transports?client_id='.$foreign->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('client_medications', 0));
    }

    public function test_controlled_transit_rows_are_concealed_without_controlled_view(): void
    {
        $reader = $this->staffAt($this->houseA, permissions: [
            'fleet.viewAny', 'assets.viewAny', 'clients.viewAny', 'medications.view',
        ]);
        $this->transitLog($this->houseA, 'Paracetamol 500mg');
        $this->transitLog($this->houseA, 'Morphine 10mg', controlled: true);

        $this->actingAs($reader)
            ->get('/fleet-assets/transports/medications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.medication_name', 'Paracetamol 500mg')
                ->where('stats.controlled_drugs_out', 0));
    }

    public function test_the_legacy_csv_query_no_longer_streams_medicines(): void
    {
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        $this->transitLog($this->houseA, 'Metformin 500mg');

        $response = $this->actingAs($coordinator)->get('/fleet-assets/transports/medications?export=csv');

        // The page ignores ?export=csv: it renders as usual (this reader may
        // see the row on screen) and never streams a CSV download (EA-002).
        $this->assertStringNotContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('fleet-assets/transports/medications'));
    }

    public function test_the_transit_csv_needs_a_purpose_records_the_export_and_prints_nz_times(): void
    {
        $this->freezeNz('2026-10-09 14:00:00');
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        [, $log] = $this->transitLog($this->houseA, 'Metformin 500mg');
        $log->forceFill(['packed_at' => CarbonImmutable::parse('2026-10-09 08:15:00', 'Pacific/Auckland')->utc()])->save();
        [, $foreignLog] = $this->transitLog($this->houseB, 'Clozapine 100mg');

        $request = ['type' => 'transit', 'period' => 'custom', 'date_from' => '2026-10-09', 'date_to' => '2026-10-09', 'site_id' => $this->houseA->id];

        $this->actingAs($coordinator)
            ->postJson('/emar/reports/export', $request)
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $csv = $this->actingAs($coordinator)
            ->post('/emar/reports/export', [...$request, 'purpose' => 'audit'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Metformin 500mg', $csv);
        $this->assertStringContainsString('2026-10-09 08:15:00 NZDT', $csv);
        $this->assertStringNotContainsString('Clozapine', $csv);
        $this->assertTrue(MedicationEvent::query()
            ->where('kind', 'export.created')
            ->where('subject_id', 'transit')
            ->where('site_id', $this->houseA->id)
            ->exists());
        $this->assertNotNull($foreignLog->id);
    }

    public function test_packed_today_and_the_date_filter_use_nz_days(): void
    {
        $this->freezeNz('2026-10-09 14:00:00');
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        [, $morning] = $this->transitLog($this->houseA, 'Metformin 500mg');
        $morning->forceFill(['packed_at' => CarbonImmutable::parse('2026-10-09 08:15:00', 'Pacific/Auckland')->utc()])->save();
        [, $yesterday] = $this->transitLog($this->houseA, 'Paracetamol 500mg');
        $yesterday->forceFill(['packed_at' => CarbonImmutable::parse('2026-10-08 15:00:00', 'Pacific/Auckland')->utc()])->save();

        $this->actingAs($coordinator)
            ->get('/fleet-assets/transports/medications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stats.total_packed_today', 1));

        $this->actingAs($coordinator)
            ->get('/fleet-assets/transports/medications?date_from=2026-10-09&date_to=2026-10-09')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.id', $morning->id));
    }

    /** B9 follow-up: the transport index's date filter and "today" are NZ days too. */
    public function test_the_transport_index_date_filter_and_today_use_nz_days(): void
    {
        $this->freezeNz('2026-10-09 14:00:00');
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        [, , $morning] = $this->transitLog($this->houseA, 'Metformin 500mg');
        $morning->forceFill(['departed_at' => CarbonImmutable::parse('2026-10-09 08:00:00', 'Pacific/Auckland')->utc()])->save();
        [, , $yesterday] = $this->transitLog($this->houseA, 'Paracetamol 500mg');
        $yesterday->forceFill(['departed_at' => CarbonImmutable::parse('2026-10-08 15:00:00', 'Pacific/Auckland')->utc()])->save();

        $this->actingAs($coordinator)
            ->get('/fleet-assets/transports?date_from=2026-10-09&date_to=2026-10-09')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transports.data', 1)
                ->where('transports.data.0.id', $morning->id)
                ->where('hero.today', 1));
    }

    public function test_a_journey_can_carry_more_than_one_dose_of_the_same_medicine(): void
    {
        $coordinator = $this->staffAt($this->houseA, role: 'coordinator');
        $client = Client::factory()->create(['site_id' => $this->houseA->id]);
        $transport = $this->transport($this->houseA, $client, $coordinator);
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '1 tablet',
            'frequency' => 'PRN',
            'is_prn' => true,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $scanCode = app(MedicationScanVerificationService::class)->internalCode($client, $medication);
        $pack = fn () => $this->actingAs($coordinator)
            ->from("/fleet-assets/transports/{$transport->id}")
            ->post("/fleet-assets/transports/{$transport->id}/pack-medication", [
                'client_id' => $client->id,
                'medication_id' => $medication->id,
                'medication_name' => $medication->name,
                'is_controlled_drug' => false,
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ]);

        $pack()->assertSessionHasNoErrors();
        $pack()->assertSessionHasNoErrors()->assertStatus(302);

        $this->assertSame(2, FleetMedicationTransitLog::query()
            ->where('transport_id', $transport->id)
            ->where('medication_id', $medication->id)
            ->count());
    }

    /** @return array{Client, FleetMedicationTransitLog, FleetResidentTransport, ClientMedication} */
    private function transitLog(Site $site, string $name, bool $controlled = false): array
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $driver = User::factory()->create(['approved_at' => now()]);
        $transport = $this->transport($site, $client, $driver);
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'is_prn' => true,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $log = FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'medication_id' => $medication->id,
            'medication_order_version' => 1,
            'medication_name' => $name,
            'is_controlled_drug' => $controlled,
            'packed_by_user_id' => $driver->id,
            'packed_at' => now(),
        ]);

        return [$client, $log, $transport, $medication];
    }

    private function transport(Site $site, Client $client, User $driver): FleetResidentTransport
    {
        $asset = Asset::factory()->vehicle()->forSite($site)->create();

        return FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $driver->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'outing',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function staffAt(Site $site, ?string $role = null, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => $role ?? 'support_worker', 'approved_at' => now()]);
        if ($role !== null) {
            $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        } else {
            $custom = Role::query()->create([
                'name' => 'transit_privacy_'.$user->id,
                'label' => 'Transit privacy '.$user->id,
                'level' => 20,
                'type' => 'custom',
            ]);
            $custom->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
            $user->roles()->attach($custom);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    private function freezeNz(string $wallClock): void
    {
        $instant = CarbonImmutable::parse($wallClock, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }
}
