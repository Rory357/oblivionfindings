<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetResidentTransportEvent;
use App\Models\FleetVehicleBooking;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationOrderVersion;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\ResidentTransportJourneyService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ResidentTransportJourneySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_viewer_gets_only_their_transport_matrix_and_global_transport_role_is_explicitly_positive(): void
    {
        $siteA = Site::factory()->create(['name' => 'Transport Site A']);
        $siteB = Site::factory()->create(['name' => 'Transport Site B']);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'first_name' => 'Local', 'last_name' => 'Resident']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'first_name' => 'Restricted', 'last_name' => 'Resident']);
        $vehicleA = $this->vehicle($siteA, 'Local vehicle');
        $vehicleB = $this->vehicle($siteB, 'Restricted vehicle');
        $viewer = $this->siteUser($siteA, ['fleet.viewAny']);
        $globalManager = $this->siteUser($siteA, ['fleet.viewAny', 'fleet.manage']);
        $transportA = $this->transport($siteA, $clientA, $vehicleA, $viewer);
        $transportB = $this->transport($siteB, $clientB, $vehicleB, $globalManager);

        $this->actingAs($viewer)
            ->get('/fleet-assets/transports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transports.meta.total', 1)
                ->where('transports.data.0.id', $transportA->id)
                ->where('stats.total_this_month', 1)
                ->where('hero.today', 1));

        $this->actingAs($viewer)->get("/fleet-assets/transports/{$transportA->id}")->assertOk();
        $this->actingAs($viewer)->get("/fleet-assets/transports/{$transportB->id}")->assertNotFound();
        $this->actingAs($viewer)->get("/fleet-assets/transports/{$transportB->id}/pre-check")->assertNotFound();
        $this->actingAs($viewer)->get('/fleet-assets/transports?asset_id='.$vehicleB->id)->assertNotFound();

        $this->actingAs($globalManager)
            ->get('/fleet-assets/transports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transports.meta.total', 2)
                ->where('stats.total_this_month', 2));
        $this->actingAs($globalManager)->get("/fleet-assets/transports/{$transportB->id}")->assertOk();
    }

    public function test_every_picker_search_and_medication_list_is_site_scoped_before_serialization(): void
    {
        $siteA = Site::factory()->create(['name' => 'Picker Site A']);
        $siteB = Site::factory()->create(['name' => 'Picker Site B']);
        $viewer = $this->siteUser($siteA, ['fleet.viewAny', 'medications.view']);
        $otherDriver = $this->siteUser($siteB);
        $localOtherDriver = $this->siteUser($siteA);
        $clientA = Client::factory()->create(['site_id' => $siteA->id, 'first_name' => 'PickerA', 'last_name' => 'Resident']);
        $clientAOther = Client::factory()->create(['site_id' => $siteA->id, 'first_name' => 'PickerOther', 'last_name' => 'Resident']);
        $clientB = Client::factory()->create(['site_id' => $siteB->id, 'first_name' => 'PickerB', 'last_name' => 'Resident']);
        $vehicleA = $this->vehicle($siteA, 'Picker vehicle A');
        $vehicleAOtherResident = Asset::factory()->vehicle()->forSite($siteA)->create([
            'name' => 'Picker resident-bound vehicle',
            'status' => 'active',
            'client_id' => $clientAOther->id,
        ]);
        $vehicleB = $this->vehicle($siteB, 'Picker vehicle B');
        $shiftA = $this->shift($siteA, $clientA, $viewer);
        $shiftAOtherDriver = $this->shift($siteA, $clientA, $localOtherDriver);
        $shiftB = $this->shift($siteB, $clientB, $otherDriver);
        $transportA = $this->transport($siteA, $clientA, $vehicleA, $viewer);
        $transportB = $this->transport($siteB, $clientB, $vehicleB, $otherDriver);
        $medicationA = $this->medication($clientA, 'Local transit medication');
        $medicationB = $this->medication($clientB, 'Restricted transit medication');
        $this->log($transportA, $clientA, $medicationA, $viewer);
        $this->log($transportB, $clientB, $medicationB, $otherDriver);

        $this->actingAs($viewer)
            ->get('/fleet-assets/transports?new=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicles', fn ($vehicles) => collect($vehicles)->pluck('id')->contains($vehicleA->id)
                    && ! collect($vehicles)->pluck('id')->contains($vehicleAOtherResident->id)
                    && ! collect($vehicles)->pluck('id')->contains($vehicleB->id))
                ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($clientA->id)
                    && collect($clients)->pluck('id')->contains($clientAOther->id)
                    && ! collect($clients)->pluck('id')->contains($clientB->id))
                ->where('shifts', fn ($shifts) => collect($shifts)->pluck('id')->all() === [$shiftA->id])
                ->where('recent_residents', fn ($names) => collect($names)->contains($transportA->resident_name)
                    && ! collect($names)->contains($transportB->resident_name)));

        $this->actingAs($viewer)->get('/fleet-assets/transports?new=1&client_id='.$clientB->id)->assertNotFound();
        $this->actingAs($viewer)->get('/fleet-assets/transports?new=1&shift_id='.$shiftAOtherDriver->id)->assertNotFound();
        $this->actingAs($viewer)->get('/fleet-assets/transports?new=1&shift_id='.$shiftB->id)->assertNotFound();

        $this->actingAs($viewer)
            ->get('/fleet-assets/transports/medications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.meta.total', 1)
                ->where('logs.data.0.medication_id', $medicationA->id)
                ->where('clients', fn ($clients) => collect($clients)->pluck('id')->contains($clientA->id)
                    && collect($clients)->pluck('id')->contains($clientAOther->id)
                    && ! collect($clients)->pluck('id')->contains($clientB->id))
                ->where('stats.total_packed_today', 1));
        $this->actingAs($viewer)
            ->get('/fleet-assets/transports/medications?transport_id='.$transportB->id)
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get('/fleet-assets/transports/medications?client_id='.$clientB->id)
            ->assertNotFound();
    }

    public function test_foreign_direct_mutation_ids_return_not_found_before_payload_validation_and_change_nothing(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $actor = $this->siteUser($siteA, ['fleet.viewAny', 'medications.administer.record']);
        $localOtherDriver = $this->siteUser($siteA);
        $otherDriver = $this->siteUser($siteB);
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        $vehicleA = $this->vehicle($siteA, 'Other actor direct-ID vehicle');
        $vehicleB = $this->vehicle($siteB, 'Foreign direct-ID vehicle');
        $transportAOtherDriver = $this->transport($siteA, $clientA, $vehicleA, $localOtherDriver);
        $transportB = $this->transport($siteB, $clientB, $vehicleB, $otherDriver);
        $medicationB = $this->medication($clientB, 'Foreign direct-ID medication');
        $logB = $this->log($transportB, $clientB, $medicationB, $otherDriver);

        $this->actingAs($actor)->postJson("/fleet-assets/transports/{$transportB->id}/complete", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/transports/{$transportB->id}/pre-check", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/transports/{$transportB->id}/pack-medication", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/medication-transit/{$logB->id}/administer", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/medication-transit/{$logB->id}/return", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/transports/{$transportAOtherDriver->id}/complete", [])->assertNotFound();
        $this->actingAs($actor)->postJson("/fleet-assets/transports/{$transportAOtherDriver->id}/pre-check", [])->assertNotFound();

        $this->assertSame('in_progress', $transportB->fresh()->status);
        $this->assertSame('in_progress', $transportAOtherDriver->fresh()->status);
        $this->assertNull($logB->fresh()->administered_at);
        $this->assertNull($logB->fresh()->returned_to_house_at);
        $this->assertDatabaseCount('fleet_resident_transport_events', 0);
    }

    public function test_store_rejects_every_mixed_related_id_class_without_side_effects(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $actor = $this->siteUser($siteA, ['fleet.viewAny', 'medications.administer.record']);
        $otherDriver = $this->siteUser($siteB);
        $localOtherDriver = $this->siteUser($siteA);
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientAOther = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        $vehicleA = $this->vehicle($siteA, 'Matrix vehicle A');
        $vehicleAOtherResident = Asset::factory()->vehicle()->forSite($siteA)->create([
            'name' => 'Matrix resident-bound vehicle',
            'status' => 'active',
            'client_id' => $clientAOther->id,
        ]);
        $vehicleB = $this->vehicle($siteB, 'Matrix vehicle B');
        $shiftB = $this->shift($siteB, $clientB, $otherDriver);
        $shiftAOtherDriver = $this->shift($siteA, $clientA, $localOtherDriver);
        $bookingB = FleetVehicleBooking::factory()->create([
            'asset_id' => $vehicleB->id,
            'user_id' => $otherDriver->id,
            'pickup_site_id' => $siteB->id,
            'return_site_id' => $siteB->id,
        ]);
        $bookingAOtherDriver = FleetVehicleBooking::factory()->create([
            'asset_id' => $vehicleA->id,
            'user_id' => $localOtherDriver->id,
            'pickup_site_id' => $siteA->id,
            'return_site_id' => $siteA->id,
        ]);
        $medicationA = $this->medication($clientA, 'Matrix medication A');
        $medicationB = $this->medication($clientB, 'Matrix medication B');
        $versionA = $this->orderVersion($medicationA);
        $versionB = $this->orderVersion($medicationB);
        $scanA = $this->scanPayload($clientA, $medicationA);

        $base = [
            'asset_id' => $vehicleA->id,
            'client_id' => $clientA->id,
            'resident_name' => trim($clientA->first_name.' '.$clientA->last_name),
            'transport_type' => 'medical',
            'pickup_location' => 'House',
            'dropoff_location' => 'Clinic',
            'departed_at' => now()->toDateTimeString(),
            'passengers_count' => 1,
            'client_request_uuid' => (string) Str::uuid(),
            'medications' => [],
        ];

        $attempts = [
            'resident' => ['client_id' => $clientB->id],
            'shift' => ['shift_id' => $shiftB->id],
            'shift_actor' => ['shift_id' => $shiftAOtherDriver->id],
            'asset' => ['asset_id' => $vehicleB->id],
            'asset_resident' => ['asset_id' => $vehicleAOtherResident->id],
            'booking' => ['booking_id' => $bookingB->id],
            'booking_actor' => ['booking_id' => $bookingAOtherDriver->id],
            'medication' => ['medications' => [[
                ...$this->medicationPayload($clientB, $medicationB),
                'medication_order_version_id' => $versionB->id,
            ]]],
            'plan' => ['medications' => [[
                ...$this->medicationPayload($clientA, $medicationA),
                'medication_order_version_id' => $versionB->id,
                ...$scanA,
            ]]],
        ];

        foreach ($attempts as $class => $override) {
            $payload = array_replace($base, $override, ['client_request_uuid' => (string) Str::uuid()]);
            $this->actingAs($actor)
                ->post('/fleet-assets/transports', $payload)
                ->assertNotFound();
            $this->assertDatabaseCount('fleet_resident_transports', 0);
            $this->assertDatabaseCount('fleet_medication_transit_logs', 0);
            $this->assertDatabaseCount('fleet_resident_transport_events', 0);
        }

        $this->assertSame($versionA->id, $this->orderVersion($medicationA)->id);
    }

    public function test_corrupt_mixed_journey_and_custody_rows_fail_closed_for_reads_and_mutations(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $actor = $this->siteUser($siteA, ['fleet.viewAny', 'medications.administer.record']);
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        $vehicleA = $this->vehicle($siteA, 'Integrity vehicle A');
        $medicationB = $this->medication($clientB, 'Foreign integrity medication');
        $corrupt = FleetResidentTransport::query()->create([
            'journey_uuid' => (string) Str::uuid(),
            'asset_id' => $vehicleA->id,
            'site_id' => $siteA->id,
            'driver_user_id' => $actor->id,
            'resident_id' => $clientB->id,
            'resident_name' => 'Mixed resident',
            'transport_type' => 'medical',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
            'version' => 1,
        ]);
        $corruptLog = FleetMedicationTransitLog::query()->create([
            'transport_id' => $corrupt->id,
            'client_id' => $clientB->id,
            'site_id' => $siteA->id,
            'medication_id' => $medicationB->id,
            'medication_order_version' => $medicationB->version,
            'medication_name' => $medicationB->name,
            'is_controlled_drug' => false,
            'packed_by_user_id' => $actor->id,
            'packed_at' => now(),
        ]);

        $this->actingAs($actor)->get("/fleet-assets/transports/{$corrupt->id}")->assertNotFound();
        $this->actingAs($actor)->get("/fleet-assets/transports/{$corrupt->id}/pre-check")->assertNotFound();
        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$corruptLog->id}/return", [
                ...$this->scanPayload($clientB, $medicationB),
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertNotFound();
        $this->assertNull($corruptLog->fresh()->returned_to_house_at);
        $this->assertDatabaseCount('fleet_resident_transport_events', 0);
    }

    public function test_journey_creation_is_atomic_durable_and_replay_safe(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteUser($site, ['fleet.viewAny']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $vehicle = $this->vehicle($site, 'Replay-safe journey vehicle');
        $requestUuid = (string) Str::uuid();
        $payload = [
            'asset_id' => $vehicle->id,
            'client_id' => $client->id,
            'resident_name' => trim($client->first_name.' '.$client->last_name),
            'transport_type' => 'medical',
            'pickup_location' => 'House',
            'dropoff_location' => 'Clinic',
            'departed_at' => now()->toDateTimeString(),
            'passengers_count' => 1,
            'client_request_uuid' => $requestUuid,
            'medications' => [],
        ];

        $this->actingAs($actor)->post('/fleet-assets/transports', $payload)->assertRedirect();
        $transport = FleetResidentTransport::query()->sole();
        $this->actingAs($actor)->post('/fleet-assets/transports', $payload)->assertRedirect(
            route('fleet-assets.transports.show', $transport),
        );

        $this->assertDatabaseCount('fleet_resident_transports', 1);
        $this->assertDatabaseCount('fleet_resident_transport_events', 1);
        $this->assertDatabaseHas('fleet_resident_transport_events', [
            'transport_id' => $transport->id,
            'action' => 'created',
            'request_uuid' => $requestUuid,
            'actor_user_id' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->postJson('/fleet-assets/transports', [
                ...$payload,
                'dropoff_location' => 'Different destination',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_request_uuid');
        $this->assertDatabaseCount('fleet_resident_transports', 1);
        $this->assertDatabaseCount('fleet_resident_transport_events', 1);
    }

    public function test_detail_discloses_only_operational_context_without_separate_care_or_medication_permission(): void
    {
        $site = Site::factory()->create();
        $fleetViewer = $this->siteUser($site, ['fleet.viewAny']);
        $clinicalViewer = $this->siteUser($site, ['fleet.viewAny', 'clients.viewAny', 'medications.view']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $vehicle = $this->vehicle($site, 'Disclosure vehicle');
        $transport = $this->transport($site, $client, $vehicle, $fleetViewer, [
            'supervisor_name' => 'Private supervisor',
            'notes' => 'Private clinical travel note',
        ]);
        $medication = $this->medication($client, 'Private medication');
        $this->log($transport, $client, $medication, $fleetViewer);

        $this->actingAs($fleetViewer)
            ->get("/fleet-assets/transports/{$transport->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('care_needs', [])
                ->where('medication_context.client', null)
                ->where('medication_context.available_medications', [])
                ->where('medication_context.transit_logs', [])
                ->where('medication_context.witnesses', [])
                ->where('medication_context.can_manage', false)
                ->where('transport.supervisor_name', null)
                ->where('transport.notes', null));
        $this->actingAs($fleetViewer)->get('/fleet-assets/transports/medications')->assertForbidden();

        $this->actingAs($clinicalViewer)
            ->get("/fleet-assets/transports/{$transport->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medication_context.client.id', $client->id)
                ->where('medication_context.transit_logs.0.medication_id', $medication->id));
    }

    public function test_pack_is_durable_replay_safe_and_rejects_uuid_payload_reuse(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteUser($site, ['fleet.viewAny', 'medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $vehicle = $this->vehicle($site, 'Replay vehicle');
        $transport = $this->transport($site, $client, $vehicle, $actor);
        $medication = $this->medication($client, 'Replay medication');
        $medication->forceFill(['witness_required' => true])->save();
        $uuid = (string) Str::uuid();
        $payload = [
            ...$this->medicationPayload($client, $medication),
            ...$this->scanPayload($client, $medication),
            'client_request_uuid' => $uuid,
            'notes' => 'Original custody request',
            'captured_offline_at' => now()->subMinute()->toIso8601String(),
            'origin_device_id' => 'fleet-tablet-07',
            'queued_offline' => true,
        ];

        $this->actingAs($actor)
            ->postJson("/fleet-assets/transports/{$transport->id}/pack-medication", $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $this->actingAs($actor)
            ->postJson("/fleet-assets/transports/{$transport->id}/pack-medication", $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);
        $this->actingAs($actor)
            ->postJson("/fleet-assets/transports/{$transport->id}/pack-medication", [
                ...$payload,
                'queued_offline' => false,
            ])
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('fleet_medication_transit_logs', 1);
        $this->assertDatabaseHas('fleet_medication_transit_logs', [
            'medication_id' => $medication->id,
            'is_controlled_drug' => false,
            'witness_required' => true,
            'packed_witness_name' => 'Packing witness',
        ]);
        $this->assertDatabaseCount('fleet_resident_transport_events', 1);
        $this->assertDatabaseHas('fleet_resident_transport_events', [
            'transport_id' => $transport->id,
            'action' => 'medication_packed',
            'request_uuid' => $uuid,
        ]);
        $eventContext = FleetResidentTransportEvent::query()->sole()->context;
        $this->assertSame('fleet-tablet-07', $eventContext['origin_device_id']);
        $this->assertTrue($eventContext['queued_offline']);
        $this->assertSame($payload['captured_offline_at'], $eventContext['captured_offline_at']);

        $log = FleetMedicationTransitLog::query()->sole();
        $medication->forceFill(['witness_required' => false])->save();
        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", [
                ...$this->scanPayload($client, $medication),
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witnessed_by_user_id');
        $this->assertNull($log->fresh()->administered_at);
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->actingAs($actor)
            ->postJson("/fleet-assets/transports/{$transport->id}/pack-medication", [
                ...$payload,
                'notes' => 'Changed replay payload',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_request_uuid');
        $this->assertDatabaseCount('fleet_medication_transit_logs', 1);
        $this->assertDatabaseCount('fleet_resident_transport_events', 1);
    }

    public function test_controlled_administration_reconciles_emar_stock_and_provenance_once_and_is_terminal(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteUser($site, [
            'fleet.viewAny',
            'medications.administer.record',
            'medications.controlled.record',
        ]);
        $witness = $this->siteUser($site, ['medications.controlled.witness'], ['password' => Hash::make('witness-secret')]);
        $foreignWitness = $this->siteUser(
            Site::factory()->create(),
            ['medications.controlled.witness'],
            ['password' => Hash::make('foreign-witness-secret')],
        );
        $this->recordCompetency($actor);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $vehicle = $this->vehicle($site, 'eMAR vehicle');
        $transport = $this->transport($site, $client, $vehicle, $actor);
        $medication = $this->medication($client, 'Controlled transit dose', true);
        $this->orderVersion($medication);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 5,
            'unit' => 'tablets',
        ]);
        $log = $this->log($transport, $client, $medication, $actor, ['packed_witness_name' => 'Packing witness']);
        $uuid = (string) Str::uuid();
        $payload = [
            ...$this->scanPayload($client, $medication),
            'witnessed_by_user_id' => $witness->id,
            'witness_credential' => 'witness-secret',
            'notes' => 'Given during the authorised journey.',
            'client_request_uuid' => $uuid,
        ];

        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", [
                ...$payload,
                'witnessed_by_user_id' => $foreignWitness->id,
                'witness_credential' => 'foreign-witness-secret',
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertNotFound();
        $this->assertNull($log->fresh()->administered_at);
        $this->assertSame(5.0, (float) $stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('fleet_resident_transport_events', 0);

        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $log->refresh();
        $this->assertNotNull($log->administered_at);
        $this->assertNull($log->returned_to_house_at);
        $this->assertSame($actor->id, $log->administered_by_user_id);
        $this->assertSame($witness->id, $log->witnessed_by_user_id);
        $this->assertNotNull($log->medication_administration_id);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(4.0, (float) $stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, ClientControlledDrugEntry::query()->where('client_medication_id', $medication->id)->count());
        $this->assertSame(1, FleetResidentTransportEvent::query()->where('action', 'medication_administered')->count());

        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/return", [
                ...$this->scanPayload($client, $medication),
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409);
        $this->assertNull($log->fresh()->returned_to_house_at);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_return_is_an_actor_attributed_terminal_alternative_and_replays_once(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteUser($site, ['fleet.viewAny', 'medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $transport = $this->transport($site, $client, $this->vehicle($site, 'Return vehicle'), $actor);
        $medication = $this->medication($client, 'Return medication');
        $log = $this->log($transport, $client, $medication, $actor);
        $uuid = (string) Str::uuid();
        $payload = [
            ...$this->scanPayload($client, $medication),
            'notes' => 'Returned and reconciled at the house.',
            'client_request_uuid' => $uuid,
        ];

        $this->actingAs($actor)->postJson("/fleet-assets/medication-transit/{$log->id}/return", $payload)->assertOk();
        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/return", $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $log->refresh();
        $this->assertNotNull($log->returned_to_house_at);
        $this->assertSame($actor->id, $log->returned_by_user_id);
        $this->assertNull($log->administered_at);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(1, FleetResidentTransportEvent::query()->where('action', 'medication_returned')->count());

        $this->actingAs($actor)
            ->postJson("/fleet-assets/medication-transit/{$log->id}/administer", [
                ...$this->scanPayload($client, $medication),
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertStatus(409);
    }

    public function test_late_failure_rolls_back_custody_emar_stock_and_provenance_together(): void
    {
        $site = Site::factory()->create();
        $actor = $this->siteUser($site, ['fleet.viewAny', 'medications.administer.record']);
        $this->recordCompetency($actor);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $transport = $this->transport($site, $client, $this->vehicle($site, 'Rollback vehicle'), $actor);
        $medication = $this->medication($client, 'Rollback medication');
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 5,
            'unit' => 'tablets',
        ]);
        $log = $this->log($transport, $client, $medication, $actor);

        $incidents = Mockery::mock(MedicationIncidentIntegrationService::class);
        $incidents->shouldReceive('resolveTransitException')->once()->andThrow(new RuntimeException('forced late failure'));
        $this->app->instance(MedicationIncidentIntegrationService::class, $incidents);

        try {
            app(ResidentTransportJourneyService::class)->administerMedication($actor, $log->id, [
                ...$this->scanPayload($client, $medication),
                'client_request_uuid' => (string) Str::uuid(),
            ]);
            $this->fail('The late failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced late failure', $exception->getMessage());
        }

        $log->refresh();
        $this->assertNull($log->administered_at);
        $this->assertNull($log->medication_administration_id);
        $this->assertSame(5.0, (float) $stock->fresh()->on_hand);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('fleet_resident_transport_events', 0);
    }

    public function test_concurrent_replay_serializes_one_terminal_custody_transition(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create();
        $actor = $this->siteUser($site, ['fleet.viewAny', 'medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $vehicle = $this->vehicle($site, 'Concurrent replay vehicle');
        $transport = $this->transport($site, $client, $vehicle, $actor);
        $medication = $this->medication($client, 'Concurrent replay medication');
        $orderVersion = $this->orderVersion($medication);
        $log = $this->log($transport, $client, $medication, $actor);
        $requestUuid = (string) Str::uuid();
        $payload = [
            ...$this->scanPayload($client, $medication),
            'notes' => 'Concurrent return replay.',
            'client_request_uuid' => $requestUuid,
        ];
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fleet-transport-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fleet-transport-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fleet-transport-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fleet-transport-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fleet-transport-attempt-b-{$token}",
        ];
        $processes = [];

        $connection->commit();

        try {
            $connection->beginTransaction();
            FleetResidentTransport::query()->whereKey($transport->id)->lockForUpdate()->firstOrFail();

            $processes[] = $this->startReturnWorker(
                $log->id,
                $actor->id,
                $payload,
                $readyPaths[0],
                $attemptPaths[0],
                $releasePath,
                $database,
            );
            $processes[] = $this->startReturnWorker(
                $log->id,
                $actor->id,
                $payload,
                $readyPaths[1],
                $attemptPaths[1],
                $releasePath,
                $database,
            );

            $this->waitForFiles($readyPaths, 'Both transport workers did not become ready.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Both transport workers did not reach the custody service.');
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A worker exited before the journey row lock was released.',
                );
            }

            $connection->commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A transport custody concurrency worker failed.',
                );
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame([false, true], collect($results)->pluck('replayed')->sort()->values()->all());
            $this->assertNotNull($log->fresh()->returned_to_house_at);
            $this->assertSame($actor->id, $log->fresh()->returned_by_user_id);
            $this->assertSame(1, FleetResidentTransportEvent::query()
                ->where('action', 'medication_returned')
                ->where('request_uuid', $requestUuid)
                ->count());
            $this->assertDatabaseCount('client_medication_administrations', 0);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('fleet_resident_transport_events')->where('transport_id', $transport->id)->delete();
                DB::table('fleet_medication_transit_logs')->where('transport_id', $transport->id)->delete();
                DB::table('medication_order_versions')->where('id', $orderVersion->id)->delete();
                DB::table('client_medications')->where('id', $medication->id)->delete();
                DB::table('fleet_resident_transports')->where('id', $transport->id)->delete();
                DB::table('assets')->where('id', $vehicle->id)->delete();
                DB::table('clients')->where('id', $client->id)->delete();
                DB::table('hr_employee_profiles')->where('user_id', $actor->id)->delete();
                DB::table('users')->where('id', $actor->id)->delete();
                DB::table('sites')->where('id', $site->id)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    private function startReturnWorker(
        int $logId,
        int $actorId,
        array $payload,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
$connectionId = Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($argv[5], (string) $connectionId);
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the transport concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
$result = $app->make(App\Services\Fleet\ResidentTransportJourneyService::class)
    ->returnMedication($actor, (int) $argv[2], $payload);
echo json_encode([
    'log_id' => $result['log']->id,
    'replayed' => $result['replayed'],
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $logId,
            (string) $actorId,
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            $readyPath,
            $attemptPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($message);
    }

    private function vehicle(Site $site, string $name): Asset
    {
        return Asset::factory()->vehicle()->forSite($site)->create([
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function shift(Site $site, Client $client, User $user): Shift
    {
        return Shift::factory()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'status' => 'in_progress',
        ]);
    }

    private function transport(Site $site, Client $client, Asset $vehicle, User $driver, array $overrides = []): FleetResidentTransport
    {
        return FleetResidentTransport::query()->create(array_replace([
            'journey_uuid' => (string) Str::uuid(),
            'asset_id' => $vehicle->id,
            'site_id' => $site->id,
            'driver_user_id' => $driver->id,
            'resident_id' => $client->id,
            'resident_name' => trim($client->first_name.' '.$client->last_name),
            'transport_type' => 'medical',
            'pickup_location' => 'House',
            'dropoff_location' => 'Clinic',
            'departed_at' => now(),
            'passengers_count' => 1,
            'status' => 'in_progress',
            'version' => 1,
        ], $overrides));
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'PRN',
            'dose_times' => ['12:00'],
            'is_prn' => true,
            'prn_reason' => 'During travel',
            'controlled_drug' => $controlled,
            'witness_required' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'version' => 1,
        ]);
    }

    private function orderVersion(ClientMedication $medication): MedicationOrderVersion
    {
        return MedicationOrderVersion::query()->firstOrCreate([
            'client_medication_id' => $medication->id,
            'version_number' => $medication->version,
        ], [
            'client_id' => $medication->client_id,
            'name' => $medication->name,
            'dosage' => $medication->dosage,
            'frequency' => $medication->frequency,
            'dose_times' => $medication->dose_times,
            'is_prn' => $medication->is_prn,
            'controlled_drug' => $medication->controlled_drug,
            'witness_required' => $medication->witness_required,
            'active' => true,
            'state' => 'active',
            'changed_by' => User::query()->value('id'),
            'changed_at' => now(),
        ]);
    }

    private function log(FleetResidentTransport $transport, Client $client, ClientMedication $medication, User $packer, array $overrides = []): FleetMedicationTransitLog
    {
        $version = $this->orderVersion($medication);

        return FleetMedicationTransitLog::query()->create(array_replace([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'site_id' => $transport->site_id,
            'shift_id' => $transport->shift_id,
            'medication_id' => $medication->id,
            'medication_order_version' => $medication->version,
            'medication_order_version_id' => $version->id,
            'medication_name' => trim($medication->name.' '.$medication->dosage),
            'is_controlled_drug' => $medication->controlled_drug,
            'witness_required' => $medication->requiresWitness(),
            'packed_by_user_id' => $packer->id,
            'packed_at' => now(),
        ], $overrides));
    }

    private function medicationPayload(Client $client, ClientMedication $medication): array
    {
        return [
            'client_id' => $client->id,
            'medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'is_controlled_drug' => $medication->controlled_drug,
            'witness_name' => $medication->requiresWitness() ? 'Packing witness' : null,
        ];
    }

    private function scanPayload(Client $client, ClientMedication $medication): array
    {
        return [
            'scan_code' => app(MedicationScanVerificationService::class)->internalCode($client, $medication),
            'scan_source' => 'manual',
            'scan_verified' => true,
            'scan_match_source' => 'internal_emar',
        ];
    }

    private function recordCompetency(User $user): void
    {
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
    }

    private function siteUser(Site $site, array $permissionKeys = [], array $attributes = []): User
    {
        $user = User::factory()->create(array_replace([
            'approved_at' => now(),
            'role' => 'manager',
        ], $attributes));

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $permissionMap = collect($permissionKeys)
            ->map(function (string $key): int {
                $module = str($key)->before('.')->value() ?: 'fleet';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    ['description' => $key, 'group' => $module, 'module' => $module],
                )->id;
            })
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);

        return $user;
    }
}
