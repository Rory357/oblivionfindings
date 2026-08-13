<?php

namespace Tests\Feature\FleetAssets;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentTransportMedicationTransitTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    }

    public function test_transport_show_includes_medication_transit_context(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $asset = Asset::factory()->vehicle()->forSite($site)->create();

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Twice daily',
            'dose_times' => ['08:00', '20:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);

        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
        ]);

        FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'medication_id' => $medication->id,
            'medication_name' => 'Paracetamol 500mg',
            'is_controlled_drug' => false,
            'packed_by_user_id' => $this->admin->id,
            'packed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("/fleet-assets/transports/{$transport->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/transports/show')
                ->where('transport.resident_id', $client->id)
                ->where('medication_context.client.id', $client->id)
                ->has('medication_context.available_medications', 1)
                ->where('medication_context.available_medications.0.id', $medication->id)
                ->has('medication_context.transit_logs', 1)
                ->where('medication_context.transit_logs.0.medication_id', $medication->id)
            );
    }

    public function test_pack_medication_rejects_mismatched_resident_for_transport(): void
    {
        $site = Site::factory()->create();
        $asset = Asset::factory()->vehicle()->forSite($site)->create();
        $transportClient = Client::factory()->create(['site_id' => $site->id]);
        $otherClient = Client::factory()->create(['site_id' => $site->id]);
        $otherMedication = ClientMedication::query()->create([
            'client_id' => $otherClient->id,
            'name' => 'Other resident medication',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);

        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $transportClient->id,
            'resident_name' => trim(($transportClient->first_name ?? '').' '.($transportClient->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/transports/{$transport->id}")
            ->post("/fleet-assets/transports/{$transport->id}/pack-medication", [
                'client_id' => $otherClient->id,
                'medication_id' => $otherMedication->id,
                'medication_name' => 'Manual packed item',
                'is_controlled_drug' => false,
                'notes' => 'Attempted wrong resident pack',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('fleet_medication_transit_logs', 0);
    }

    public function test_medication_index_can_scope_to_a_transport(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $otherClient = Client::factory()->create(['site_id' => $site->id]);
        $asset = Asset::factory()->vehicle()->forSite($site)->create();

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Scoped medication',
            'active' => true,
            'state' => 'active',
            'version' => 1,
        ]);
        $otherMedication = ClientMedication::query()->create([
            'client_id' => $otherClient->id,
            'name' => 'Other transport medication',
            'active' => true,
            'state' => 'active',
            'version' => 1,
        ]);

        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
        ]);

        $otherTransport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $otherClient->id,
            'resident_name' => trim(($otherClient->first_name ?? '').' '.($otherClient->last_name ?? '')),
            'transport_type' => 'appointment',
            'departed_at' => now()->subHour(),
            'passengers_count' => 1,
            'status' => 'completed',
        ]);

        FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'medication_id' => $medication->id,
            'medication_order_version' => 1,
            'medication_name' => 'Scoped medication',
            'is_controlled_drug' => false,
            'packed_by_user_id' => $this->admin->id,
            'packed_at' => now(),
        ]);

        FleetMedicationTransitLog::query()->create([
            'transport_id' => $otherTransport->id,
            'client_id' => $otherClient->id,
            'site_id' => $site->id,
            'medication_id' => $otherMedication->id,
            'medication_order_version' => 1,
            'medication_name' => 'Other transport medication',
            'is_controlled_drug' => false,
            'packed_by_user_id' => $this->admin->id,
            'packed_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($this->admin)
            ->get("/fleet-assets/transports/medications?transport_id={$transport->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('fleet-assets/transports/medications')
                ->where('filters.transport_id', (string) $transport->id)
                ->where('transport_scope.id', $transport->id)
                ->where('transport_scope.resident_name', $transport->resident_name)
                ->has('logs.data', 1)
                ->where('logs.data.0.transport.id', $transport->id)
                ->where('logs.data.0.medication_name', 'Scoped medication')
            );
    }

    public function test_store_can_create_transport_without_packing_selected_medications(): void
    {
        $site = Site::factory()->create();
        $asset = Asset::factory()->vehicle()->forSite($site)->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Transport-only Medication',
            'dosage' => '250mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->from('/fleet-assets/transports/create')
            ->post('/fleet-assets/transports', [
                'asset_id' => $asset->id,
                'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                'client_id' => $client->id,
                'transport_type' => 'medical',
                'pickup_location' => 'House',
                'dropoff_location' => 'Clinic',
                'departed_at' => now()->toDateTimeString(),
                'passengers_count' => 1,
                'notes' => 'Create without packing medications yet.',
                'medications' => [],
            ]);

        $transport = FleetResidentTransport::query()->latest('id')->first();

        $response
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasNoErrors();

        $this->assertNotNull($transport);
        $this->assertSame($client->id, $transport->resident_id);
        $this->assertDatabaseCount('fleet_medication_transit_logs', 0);
        $this->assertDatabaseHas('client_medications', [
            'id' => $medication->id,
            'client_id' => $client->id,
        ]);
    }

    public function test_store_can_create_transport_and_pack_selected_medications(): void
    {
        $site = Site::factory()->create();
        $asset = Asset::factory()->vehicle()->forSite($site)->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Packed Medication',
            'dosage' => '10mg',
            'frequency' => 'Once daily',
            'dose_times' => ['10:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);

        $scanCode = app(MedicationScanVerificationService::class)
            ->internalCode($client, $medication);

        $response = $this->actingAs($this->admin)
            ->post('/fleet-assets/transports', [
                'asset_id' => $asset->id,
                'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                'client_id' => $client->id,
                'transport_type' => 'medical',
                'pickup_location' => 'House',
                'dropoff_location' => 'Clinic',
                'departed_at' => now()->toDateTimeString(),
                'passengers_count' => 1,
                'notes' => 'Create and pack medication now.',
                'medications' => [[
                    'medication_id' => $medication->id,
                    'medication_name' => 'Packed Medication 10mg',
                    'is_controlled_drug' => false,
                    'witness_name' => '',
                    'scan_code' => $scanCode,
                    'scan_source' => 'manual',
                    'scan_verified' => true,
                    'scan_match_source' => null,
                ]],
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $transport = FleetResidentTransport::query()->latest('id')->firstOrFail();

        $response
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fleet_medication_transit_logs', [
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'medication_id' => $medication->id,
            'medication_name' => 'Packed Medication 10mg',
        ]);
    }

    public function test_controlled_drug_pack_requires_a_witness_name(): void
    {
        [$transport, $client] = $this->createInProgressTransport();

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Controlled transit medication',
            'dosage' => '1 tablet',
            'frequency' => 'PRN',
            'is_prn' => true,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/transports/{$transport->id}")
            ->post("/fleet-assets/transports/{$transport->id}/pack-medication", [
                'client_id' => $client->id,
                'medication_id' => $medication->id,
                'medication_name' => $medication->name,
                'is_controlled_drug' => $medication->controlled_drug,
                'witness_name' => '',
                'notes' => 'Prepared for the appointment.',
            ])
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasErrors(['witness_name']);

        $this->assertDatabaseCount('fleet_medication_transit_logs', 0);
    }

    public function test_controlled_drug_administration_requires_a_witness_user(): void
    {
        [$transport, $client] = $this->createInProgressTransport();

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Controlled transit medication',
            'dosage' => '1 tablet',
            'frequency' => 'PRN',
            'is_prn' => true,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $scanCode = app(MedicationScanVerificationService::class)->internalCode($client, $medication);

        $log = FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $transport->site_id,
            'medication_id' => $medication->id,
            'medication_order_version' => 1,
            'medication_name' => 'Controlled transit medication',
            'is_controlled_drug' => true,
            'packed_witness_name' => 'Packing Witness',
            'packed_by_user_id' => $this->admin->id,
            'packed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/transports/{$transport->id}")
            ->post("/fleet-assets/medication-transit/{$log->id}/administer", [
                'witnessed_by_user_id' => null,
                'notes' => 'Dose given during transport.',
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasErrors(['witnessed_by_user_id']);

        $this->assertNull($log->fresh()->administered_at);
    }

    public function test_pack_and_return_preserve_terminal_transit_consequences(): void
    {
        [$transport, $client] = $this->createInProgressTransport();

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Transit medication',
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

        $this->actingAs($this->admin)
            ->post("/fleet-assets/transports/{$transport->id}/pack-medication", [
                'client_id' => $client->id,
                'medication_id' => $medication->id,
                'medication_name' => $medication->name,
                'is_controlled_drug' => false,
                'witness_name' => null,
                'notes' => 'Packed at the house.',
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertSessionHasNoErrors();

        $log = FleetMedicationTransitLog::query()->sole();
        $this->assertSame('packed', $log->status);
        $this->assertSame($this->admin->id, $log->packed_by_user_id);

        $this->actingAs($this->admin)
            ->post("/fleet-assets/medication-transit/{$log->id}/return", [
                'notes' => 'Returned to house stock.',
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertSessionHasNoErrors();

        $log->refresh();
        $this->assertSame('returned', $log->status);
        $this->assertNotNull($log->returned_to_house_at);
        $this->assertNull($log->administered_at);
        $this->assertSame($this->admin->id, $log->returned_by_user_id);
        $this->assertSame('Returned to house stock.', $log->notes);
    }

    /** @return array{FleetResidentTransport, Client} */
    private function createInProgressTransport(): array
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $asset = Asset::factory()->vehicle()->forSite($site)->create();

        $transport = FleetResidentTransport::query()->create([
            'asset_id' => $asset->id,
            'site_id' => $site->id,
            'driver_user_id' => $this->admin->id,
            'resident_id' => $client->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'transport_type' => 'medical',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
        ]);

        return [$transport, $client];
    }
}
