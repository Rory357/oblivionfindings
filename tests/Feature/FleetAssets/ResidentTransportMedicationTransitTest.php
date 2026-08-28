<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_medication_transport_commands_enforce_the_shared_v4_provenance_envelope(): void
    {
        [$transport, $client] = $this->createInProgressTransport();
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Envelope medication',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['09:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
        $scanCode = app(MedicationScanVerificationService::class)
            ->internalCode($client, $medication);
        $scan = [
            'scan_code' => $scanCode,
            'scan_source' => 'manual',
            'scan_verified' => true,
            'scan_match_source' => 'internal_emar',
        ];
        $log = FleetMedicationTransitLog::query()->create([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $transport->site_id,
            'medication_id' => $medication->id,
            'medication_order_version' => 1,
            'medication_name' => $medication->name,
            'is_controlled_drug' => false,
            'packed_by_user_id' => $this->admin->id,
            'packed_at' => now(),
        ]);
        $pack = [
            'client_id' => $client->id,
            'medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'is_controlled_drug' => false,
            ...$scan,
        ];
        $v4 = (string) Str::uuid();
        $invalidEnvelopes = [
            [['client_request_uuid' => '11111111-1111-1111-8111-111111111111'], 'client_request_uuid'],
            [['client_request_uuid' => '55555555-5555-5555-8555-555555555555'], 'client_request_uuid'],
            [[
                'client_request_uuid' => $v4,
                'queued_offline' => true,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $v4,
                'queued_offline' => true,
                'captured_offline_at' => '2026-08-28 09:30:00',
                'origin_device_id' => 'fleet-device-01',
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $v4,
                'queued_offline' => true,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
                'origin_device_id' => '   ',
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $v4,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
            ], 'captured_offline_at'],
        ];
        $commands = [
            ["/fleet-assets/transports/{$transport->id}/pack-medication", $pack],
            ["/fleet-assets/medication-transit/{$log->id}/administer", [
                ...$scan,
                'quantity_administered' => '1.00',
            ]],
            ["/fleet-assets/medication-transit/{$log->id}/return", $scan],
        ];

        foreach ($commands as [$uri, $payload]) {
            foreach ($invalidEnvelopes as [$envelope, $errorField]) {
                $this->actingAs($this->admin)
                    ->postJson($uri, [...$payload, ...$envelope])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors($errorField);
            }
        }

        foreach ([
            [['client_request_uuid' => '11111111-1111-1111-8111-111111111111'], 'client_request_uuid'],
            [['client_request_uuid' => '55555555-5555-5555-8555-555555555555'], 'client_request_uuid'],
            [[
                'client_request_uuid' => $v4,
                'queued_offline' => true,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
                'origin_device_id' => 'fleet-device-01',
            ], 'captured_offline_at'],
        ] as [$envelope, $errorField]) {
            $this->actingAs($this->admin)
                ->postJson("/fleet-assets/medication-transit/{$log->id}/correct-packing-attestation", [
                    'witnessed_by_user_id' => $this->admin->id,
                    'witness_credential' => 'not-persisted',
                    'correction_reason' => 'Envelope validation must run first.',
                    ...$envelope,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $asset = Asset::factory()->vehicle()->forSite(Site::query()->findOrFail($transport->site_id))->create();
        $createBase = [
            'asset_id' => $asset->id,
            'resident_name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'client_id' => $client->id,
            'transport_type' => 'medical',
            'departed_at' => now()->addHour()->toDateTimeString(),
            'passengers_count' => 1,
        ];
        $medicationSelection = [[
            'medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'is_controlled_drug' => false,
            ...$scan,
        ]];

        foreach ([
            [['client_request_uuid' => '11111111-1111-1111-8111-111111111111'], 'client_request_uuid'],
            [['client_request_uuid' => '55555555-5555-5555-8555-555555555555'], 'client_request_uuid'],
            [[
                'client_request_uuid' => $v4,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $v4,
                'queued_offline' => true,
                'captured_offline_at' => '2026-08-28T09:30:00+12:00',
                'origin_device_id' => 'fleet-device-01',
            ], 'queued_offline'],
        ] as [$envelope, $errorField]) {
            $this->actingAs($this->admin)
                ->postJson('/fleet-assets/transports', [
                    ...$createBase,
                    'medications' => $medicationSelection,
                    ...$envelope,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->actingAs($this->admin)
            ->post('/fleet-assets/transports', [
                ...$createBase,
                'medications' => [],
                // Non-medication journeys retain the pre-existing generic
                // UUID contract and are not forced into the clinical envelope.
                'client_request_uuid' => '11111111-1111-1111-8111-111111111111',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('fleet_medication_transit_logs', 1);
        $this->assertDatabaseCount('fleet_resident_transport_events', 1);
        $this->assertNull($log->fresh()->administered_at);
        $this->assertNull($log->fresh()->returned_to_house_at);
    }

    public function test_store_can_create_transport_and_pack_selected_medications(): void
    {
        $site = Site::factory()->create();
        $this->assignAdminToSite($site);
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
                'client_request_uuid' => (string) Str::uuid(),
                'queued_offline' => false,
                'medications' => [[
                    'medication_id' => $medication->id,
                    'medication_name' => 'Packed Medication 10mg',
                    'is_controlled_drug' => false,
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

    public function test_controlled_drug_pack_requires_an_authenticated_second_checker(): void
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
        $scanCode = app(MedicationScanVerificationService::class)
            ->internalCode($client, $medication);

        $this->actingAs($this->admin)
            ->from("/fleet-assets/transports/{$transport->id}")
            ->post("/fleet-assets/transports/{$transport->id}/pack-medication", [
                'client_id' => $client->id,
                'medication_id' => $medication->id,
                'medication_name' => $medication->name,
                'is_controlled_drug' => $medication->controlled_drug,
                'attestation_state' => 'accepted',
                'witness_name' => 'Unrelated free-text staff label',
                'notes' => 'Prepared for the appointment.',
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => null,
            ])
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasErrors(['witnessed_by_user_id']);

        $this->assertDatabaseCount('fleet_medication_transit_logs', 0);
    }

    public function test_controlled_drug_administration_rejects_label_only_packing_evidence(): void
    {
        [$transport, $client] = $this->createInProgressTransport(withMedicationCompetency: true);

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
                'quantity_administered' => '1.00',
                'notes' => 'Dose given during transport.',
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertRedirect("/fleet-assets/transports/{$transport->id}")
            ->assertSessionHasErrors(['packing_attestation']);

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
    private function createInProgressTransport(bool $withMedicationCompetency = false): array
    {
        $site = Site::factory()->create();
        $this->assignAdminToSite($site, $withMedicationCompetency);
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

    private function assignAdminToSite(Site $site, bool $withMedicationCompetency = false): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        if (! $withMedicationCompetency) {
            return;
        }

        $assessor = User::factory()->create();
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->admin->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
        ]);
    }
}
