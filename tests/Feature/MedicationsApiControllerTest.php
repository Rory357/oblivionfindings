<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationScheduledStockCount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationsApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected Client $client;

    protected Client $otherClient;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $site = Site::factory()->create(['name' => 'Medication API Site']);
        $this->site = $site;
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'API Test Residential',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
        $this->client->supportWorkers()->attach($this->actor->id);

        $this->otherClient = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
    }

    protected function createMedicationForClient(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::create(array_merge([
            'client_id' => $client->id,
            'name' => 'Cross Client Test Medication',
            'dosage' => '5mg',
            'frequency' => 'Once daily',
            'dose_times' => ['09:00'],
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    public function test_safety_check_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/safety-check")
            ->assertNotFound();
    }

    public function test_prn_history_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient, [
            'is_prn' => true,
            'frequency' => null,
            'dose_times' => null,
            'prn_reason' => 'As needed for pain',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/prn-history")
            ->assertNotFound();
    }

    public function test_record_administration_returns_404_for_medication_from_other_client(): void
    {
        $medication = $this->createMedicationForClient($this->otherClient);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations", [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();
    }

    public function test_record_administration_validates_parseable_non_rfc_offline_capture_at_the_current_authorized_instant(): void
    {
        $medication = $this->createMedicationForClient($this->client, [
            'is_prn' => true,
            'frequency' => 'PRN',
            'dose_times' => [],
            'approval_status' => 'verified',
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->actor->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson(route('api.medications.administrations.record', [$this->client, $medication]), [
                'status' => 'refused',
                'reason_code' => 'refused',
                'client_request_uuid' => '1d0ac66d-1c97-42d9-b695-4363f62e2d01',
                'captured_offline_at' => now()->subYear()->format('Y-m-d H:i:s'),
                'origin_device_id' => 'api-invalid-capture-device',
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('captured_offline_at');

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_controlled_api_administration_conceals_missing_and_foreign_witnesses_before_eligible_credentials_without_mutation(): void
    {
        $administrationPermission = Permission::query()->where('key', 'medications.administer.record')->firstOrFail();
        $controlledPermission = Permission::query()->where('key', 'medications.controlled.record')->firstOrFail();
        $controlledViewPermission = Permission::query()->where('key', 'medications.controlled.view')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $administrationPermission->id => ['allowed' => true],
            $controlledPermission->id => ['allowed' => true],
            $controlledViewPermission->id => ['allowed' => true],
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->actor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);
        $medication = $this->createMedicationForClient($this->client, [
            'name' => 'API controlled witness boundary',
            'controlled_drug' => true,
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $witness = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $witness->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $witnessPermission = Permission::query()->where('key', 'medications.controlled.witness')->firstOrFail();
        $witness->permissionOverrides()->sync([
            $witnessPermission->id => ['allowed' => true],
        ]);
        $foreignSite = Site::factory()->create(['name' => 'Foreign API Witness Site']);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
        ]);
        $profile = HrEmployeeProfile::factory()->create([
            'user_id' => $witness->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $this->actor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        $witnessShift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);
        $scheduledFor = now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(9, 0);
        $payload = [
            'status' => 'given',
            'dose_given' => '5mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'reason' => 'Test request may run outside the configured administration window.',
            'quantity_administered' => 0.5,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ];
        $url = "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations";

        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertNotFound();
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, [
                ...$payload,
                'witnessed_by' => (int) User::query()->max('id') + 1000,
            ])
            ->assertNotFound();

        $profile->update(['primary_site_id' => $this->site->id]);
        $witnessShift->update([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
        ]);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, [...$payload, 'witness_credential' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witness_credential');

        $witness->permissionOverrides()->sync([
            $witnessPermission->id => ['allowed' => false],
        ]);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertNotFound();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_client_update_without_exact_administration_capability_is_denied_by_shared_scope_service(): void
    {
        $administer = Permission::query()->where('key', 'medications.administer.record')->firstOrFail();
        $clientUpdate = Permission::query()->where('key', 'clients.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $administer->id => ['allowed' => false],
            $clientUpdate->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client, [
            'approval_status' => 'verified',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/administrations", [
                'status' => 'given',
                'dose_given' => '5mg',
                'scheduled_for' => now()->toIso8601String(),
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_scheduled_stock_count_preserves_half_units_and_rejects_excess_scale_without_mutation(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client, [
            'barcode' => 'SCHEDULED-COUNT-HALF-001',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $createResponse = $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/scheduled-counts", [
                'scheduled_date' => now()->toDateString(),
                'scheduled_time' => null,
                'expected_quantity' => 10,
            ])
            ->assertOk();
        $count = MedicationScheduledStockCount::query()->findOrFail($createResponse->json('count.id'));

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete", [
                'actual_quantity' => 9.999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actual_quantity');

        $this->assertSame('pending', $count->refresh()->status);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete", [
                'actual_quantity' => 9.5,
                ...$this->scheduledCountScanPayload($this->client, $medication),
            ])
            ->assertOk();

        $this->assertSame(9.5, (float) $stock->refresh()->on_hand);
        $this->assertSame(10.0, (float) $count->refresh()->expected_quantity);
        $this->assertSame(9.5, (float) $count->actual_quantity);
        $this->assertSame(-0.5, (float) $count->discrepancy);

        $this->actingAs($this->actor, 'sanctum')
            ->getJson("/api/medications/clients/{$this->client->id}/medications/{$medication->id}/scheduled-counts")
            ->assertOk()
            ->assertJsonPath('medication.on_hand', 9.5)
            ->assertJsonPath('counts.0.expected_quantity', 10)
            ->assertJsonPath('counts.0.actual_quantity', 9.5)
            ->assertJsonPath('counts.0.discrepancy', -0.5);
    }

    public function test_scheduled_stock_count_create_and_complete_reject_invalid_offline_provenance(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $count = MedicationScheduledStockCount::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);
        $uuid = '70177fe1-9171-4e86-a48c-eb5c217c6fbb';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $createUrl = "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/scheduled-counts";
        $completeUrl = "/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete";

        $this->actingAs($this->actor, 'sanctum')
            ->postJson($createUrl, [
                'scheduled_date' => now()->toDateString(),
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_request_uuid');
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($createUrl, [
                'scheduled_date' => now()->toDateString(),
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'stock-count-device',
                'queued_offline' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'captured_offline_at',
                'origin_device_id',
            ]);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($completeUrl, [
                'actual_quantity' => 10,
                ...$this->scheduledCountScanPayload($this->client, $medication),
                'client_request_uuid' => $uuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'stock-count-device',
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('captured_offline_at');
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($completeUrl, [
                'actual_quantity' => 10,
                ...$this->scheduledCountScanPayload($this->client, $medication),
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origin_device_id');

        $this->assertDatabaseCount('medication_scheduled_stock_counts', 1);
        $this->assertSame('pending', $count->refresh()->status);
    }

    public function test_scheduled_stock_count_rejects_controlled_medication_and_strict_audit_failure_rolls_back_non_controlled_count(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $controlledMedication = $this->createMedicationForClient($this->client, [
            'name' => 'Controlled scheduled count',
            'controlled_drug' => true,
        ]);
        $controlledStock = ClientMedicationStock::create([
            'client_medication_id' => $controlledMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $controlledCount = MedicationScheduledStockCount::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $controlledMedication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/scheduled-counts/{$controlledCount->id}/complete", [
                'actual_quantity' => 9.5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_medication_id');

        $this->assertSame('pending', $controlledCount->refresh()->status);
        $this->assertSame(10.0, (float) $controlledStock->refresh()->on_hand);

        $medication = $this->createMedicationForClient($this->client, [
            'name' => 'Strictly audited scheduled count',
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $count = MedicationScheduledStockCount::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.stock.count.completed') {
                throw new RuntimeException('Injected scheduled stock-count audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor, 'sanctum')
                ->postJson("/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete", [
                    'actual_quantity' => 9.5,
                    ...$this->scheduledCountScanPayload($this->client, $medication),
                ]);
            $this->fail('The injected scheduled stock-count audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected scheduled stock-count audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertSame('pending', $count->refresh()->status);
        $this->assertNull($count->actual_quantity);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.count.completed']);
    }

    public function test_scheduled_stock_count_requires_exact_stock_capability_and_conceals_foreign_site_ids(): void
    {
        $stockPermission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $clientPermission = Permission::query()->where('key', 'clients.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $stockPermission->id => ['allowed' => false],
            $clientPermission->id => ['allowed' => true],
        ]);
        $localMedication = $this->createMedicationForClient($this->client);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$localMedication->id}/scheduled-counts", [
                'scheduled_date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $stockPermission->id => ['allowed' => true],
        ]);
        $this->actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
        ]);
        $foreignMedication = $this->createMedicationForClient($foreignClient);
        $foreignCount = MedicationScheduledStockCount::create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$foreignClient->id}/medications/{$foreignMedication->id}/scheduled-counts", [
                'scheduled_date' => now()->toDateString(),
            ])
            ->assertNotFound();
        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$foreignClient->id}/scheduled-counts/{$foreignCount->id}/complete", [
                'actual_quantity' => 4.5,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_scheduled_stock_counts', 1);
        $this->assertSame('pending', $foreignCount->refresh()->status);
    }

    public function test_scheduled_stock_count_uuid_is_durable_scoped_and_payload_bound(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $firstMedication = $this->createMedicationForClient($this->client, ['name' => 'Replay stock one']);
        $secondMedication = $this->createMedicationForClient($this->client, ['name' => 'Replay stock two']);
        foreach ([$firstMedication, $secondMedication] as $medication) {
            ClientMedicationStock::create([
                'client_medication_id' => $medication->id,
                'on_hand' => 10,
                'unit' => 'tablets',
            ]);
        }

        $uuid = '81b60abe-acde-4ff3-9f80-b15f9b7a7862';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $payload = [
            'scheduled_date' => now()->toDateString(),
            'expected_quantity' => 10,
            'client_request_uuid' => $uuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'scheduled-count-device',
            'queued_offline' => true,
        ];
        $url = "/api/medications/clients/{$this->client->id}/medications/{$firstMedication->id}/scheduled-counts";
        $first = $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('count.id', $first->json('count.id'))
            ->assertJsonPath('sync.duplicate', true);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, [...$payload, 'expected_quantity' => 9.5])
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict');

        $this->actingAs($this->actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/medications/{$secondMedication->id}/scheduled-counts",
                $payload,
            )
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $secondActor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $secondActor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $secondActor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $secondActor->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $this->actingAs($secondActor, 'sanctum')
            ->postJson($url, $payload)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $completePayload = [
            'actual_quantity' => 9.5,
            'client_request_uuid' => $uuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'scheduled-count-device',
            'queued_offline' => true,
            ...$this->scheduledCountScanPayload($this->client, $firstMedication),
        ];
        $this->actingAs($this->actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/scheduled-counts/{$first->json('count.id')}/complete",
                $completePayload,
            )
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');

        foreach ([
            'medications.stock.count.scheduled',
            'medications.stock.count.completed',
        ] as $action) {
            $meta = AuditLog::query()->where('action', $action)->sole()->meta;
            $this->assertSame($uuid, $meta['client_request_uuid'] ?? null);
            $this->assertSame($capturedAt, $meta['captured_offline_at'] ?? null);
            $this->assertSame('scheduled-count-device', $meta['origin_device_id'] ?? null);
            $this->assertTrue($meta['queued_offline'] ?? false);
        }

        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'scheduled-count:create',
            'request_uuid' => $uuid,
            'expires_at' => null,
        ]);
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'scheduled-count:complete',
            'request_uuid' => $uuid,
            'expires_at' => null,
        ]);

        $this->travel(8)->days();
        $this->assertSame(0, (new MedicationIdempotencyResult)->prunable()->delete());
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('count.id', $first->json('count.id'))
            ->assertJsonPath('sync.duplicate', true);
        $this->actingAs($this->actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/scheduled-counts/{$first->json('count.id')}/complete",
                $completePayload,
            )
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('medication_scheduled_stock_counts', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.stock.count.scheduled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.stock.count.completed')->count());
    }

    public function test_scheduled_count_create_audit_failure_rolls_back_domain_and_durable_replay_state(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client);
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $payload = [
            'scheduled_date' => now()->toDateString(),
            'expected_quantity' => 10,
            'client_request_uuid' => '7f1a17a7-a4ad-4efe-b265-1e2b6817698a',
        ];
        $url = "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/scheduled-counts";
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.stock.count.scheduled') {
                throw new RuntimeException('Injected scheduled-count creation audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor, 'sanctum')->postJson($url, $payload);
            $this->fail('The injected scheduled-count creation audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected scheduled-count creation audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('medication_scheduled_stock_counts', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.stock.count.scheduled']);

        $first = $this->actingAs($this->actor, 'sanctum')->postJson($url, $payload)->assertOk();
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('count.id', $first->json('count.id'))
            ->assertJsonPath('sync.duplicate', true);
        $this->assertDatabaseCount('medication_scheduled_stock_counts', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_scheduled_count_completion_replay_is_serialized_and_audit_failure_publishes_no_result(): void
    {
        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $count = MedicationScheduledStockCount::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);
        $uuid = 'f460ff20-8b0f-412e-aa9d-ae2caa936bc7';
        $payload = [
            'actual_quantity' => 9.5,
            'client_request_uuid' => $uuid,
            ...$this->scheduledCountScanPayload($this->client, $medication),
        ];
        $url = "/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete";

        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.stock.count.completed') {
                throw new RuntimeException('Injected replay audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor, 'sanctum')->postJson($url, $payload);
            $this->fail('The injected audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected replay audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertSame('pending', $count->refresh()->status);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseCount('medication_idempotency_results', 0);

        $first = $this->actingAs($this->actor, 'sanctum')->postJson($url, $payload)->assertOk();
        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('count.id', $first->json('count.id'))
            ->assertJsonPath('sync.duplicate', true);

        $this->assertSame(9.5, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_scheduled_count_locks_parent_and_aggregate_before_stock(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Lock-order evidence requires MySQL FOR UPDATE statements.');
        }

        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client);
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $count = MedicationScheduledStockCount::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'pending',
            'expected_quantity' => 10,
        ]);
        $lockOrder = [];
        DB::listen(function ($event) use (&$lockOrder): void {
            $sql = strtolower($event->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach (['clients', 'client_medications', 'medication_scheduled_stock_counts', 'client_medication_stocks'] as $table) {
                if (str_contains($sql, "`{$table}`")) {
                    $lockOrder[] = $table;
                    break;
                }
            }
        });

        $this->actingAs($this->actor, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/scheduled-counts/{$count->id}/complete", [
                'actual_quantity' => 9.5,
                'client_request_uuid' => 'f304ba14-a145-4590-b6a6-31d963645cab',
                ...$this->scheduledCountScanPayload($this->client, $medication),
            ])
            ->assertOk();

        $this->assertSame(
            ['clients', 'client_medications', 'medication_scheduled_stock_counts', 'client_medication_stocks'],
            array_values(array_unique($lockOrder)),
        );
    }

    public function test_concurrent_same_uuid_scheduled_count_create_publishes_one_durable_result(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $permission = Permission::query()->where('key', 'medications.stock.update')->firstOrFail();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $medication = $this->createMedicationForClient($this->client, ['name' => 'Concurrent scheduled count']);
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $payload = [
            'scheduled_date' => now()->toDateString(),
            'expected_quantity' => 10,
            'client_request_uuid' => 'a9eeb76a-56a2-48b9-9919-ea7422e42c61',
        ];
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-count-ready-{$token}";
        $process = null;

        // Publish fixtures, then make the independent request wait behind the
        // same canonical Client lock while this connection commits the winner.
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($this->client->id)->lockForUpdate()->firstOrFail();
            $process = $this->startScheduledCountRaceWorker(
                $readyPath,
                $database,
                $medication,
                $payload,
            );
            $this->waitForScheduledCountRaceWorker($readyPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Concurrent count request did not wait for the canonical Client lock.');

            $winner = $this->actingAs($this->actor, 'sanctum')
                ->postJson(
                    "/api/medications/clients/{$this->client->id}/medications/{$medication->id}/scheduled-counts",
                    $payload,
                )
                ->assertOk();
            $connection->commit();

            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The scheduled-count concurrency worker failed.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($winner->json('count.id'), $result['count']['id']);
            $this->assertTrue($result['sync']['duplicate']);
            $this->assertDatabaseCount('medication_scheduled_stock_counts', 1);
            $this->assertDatabaseCount('medication_idempotency_results', 1);
            $this->assertSame(1, AuditLog::query()
                ->where('action', 'medications.stock.count.scheduled')
                ->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if (is_file($readyPath)) {
                unlink($readyPath);
            }

            $this->cleanCommittedScheduledCountFixtures($medication);
            $connection->beginTransaction();
        }
    }

    /** @return array<string, mixed> */
    private function scheduledCountScanPayload(Client $client, ClientMedication $medication): array
    {
        return [
            'scan_code' => app(MedicationScanVerificationService::class)->internalCode($client, $medication),
            'scan_source' => 'manual',
            'scan_verified' => true,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function startScheduledCountRaceWorker(
        string $readyPath,
        string $database,
        ClientMedication $medication,
        array $payload,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
$client = App\Models\Client::query()->findOrFail((int) $argv[3]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[4]);
$payload = json_decode($argv[5], true, flags: JSON_THROW_ON_ERROR);
$request = Illuminate\Http\Request::create('/scheduled-counts', 'POST', $payload);
$request->setUserResolver(fn () => $actor);
file_put_contents($argv[6], 'ready');
$response = $app->make(App\Http\Controllers\Api\MedicationsApiController::class)
    ->createScheduledStockCount($request, $client, $medication);
echo json_encode($response->getData(true), JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $this->actor->id,
                (string) $this->client->id,
                (string) $medication->id,
                json_encode($payload, JSON_THROW_ON_ERROR),
                $readyPath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function waitForScheduledCountRaceWorker(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The scheduled-count concurrency worker did not start.');
            }

            usleep(10_000);
        }
    }

    private function cleanCommittedScheduledCountFixtures(ClientMedication $medication): void
    {
        DB::table('audit_logs')
            ->where('action', 'medications.stock.count.scheduled')
            ->where('client_id', $this->client->id)
            ->delete();
        DB::table('medication_idempotency_results')
            ->where('request_uuid', 'a9eeb76a-56a2-48b9-9919-ea7422e42c61')
            ->delete();
        DB::table('medication_scheduled_stock_counts')->where('client_medication_id', $medication->id)->delete();
        DB::table('client_medication_stocks')->where('client_medication_id', $medication->id)->delete();
        DB::table('client_medications')->where('id', $medication->id)->delete();
        DB::table('client_user')->whereIn('client_id', [$this->client->id, $this->otherClient->id])->delete();
        DB::table('timeline_events')->whereIn('client_id', [$this->client->id, $this->otherClient->id])->delete();
        DB::table('clients')->whereIn('id', [$this->client->id, $this->otherClient->id])->delete();
        DB::table('permission_user')->where('user_id', $this->actor->id)->delete();
        DB::table('role_user')->where('user_id', $this->actor->id)->delete();
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->delete();
        DB::table('users')->where('id', $this->actor->id)->delete();
        DB::table('sites')->where('id', $this->site->id)->delete();
        DB::table('service_contexts')->where('id', $this->client->service_context_id)->delete();
    }
}
