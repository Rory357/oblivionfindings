<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GuidedRoundOfflineReplayTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ClientMedication $medication;

    protected MedicationRound $round;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->first());
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        $this->site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Guided Round',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Loratadine',
            'dosage' => '10mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);

        $this->round = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'name' => 'Morning round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'status' => 'in_progress',
            'assigned_to' => $this->worker->id,
            'started_by' => $this->worker->id,
            'started_at' => now(),
            'total_medications' => 1,
        ]);
    }

    public function test_duplicate_round_admin_uuid_is_idempotent(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => '41a75a8a-aa69-4d74-b094-cfbf2925ac6d',
            'queued_offline' => false,
        ];

        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'processed');

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'duplicate');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $this->medication->id,
            'medication_round_id' => $this->round->id,
            'status' => 'given',
        ]);
    }

    public function test_queued_round_admin_requires_complete_strict_offline_provenance(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";
        $uuid = '09fe38a7-4513-4783-aa12-d55af1bd7c4d';
        $capturedAt = now()->subMinutes(10)->toIso8601String();
        $base = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
        ];
        $invalid = [
            [[...$base, 'queued_offline' => true], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => 'not-a-uuid',
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'round-device',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'round-device',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'round-device',
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalid as [$payload, $field]) {
            $this->actingAs($this->worker)
                ->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_exact_queued_round_admin_replay_uses_capture_time_and_reaches_core_idempotency(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $capturedAt = now()->subMinutes(10)->toIso8601String();
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => '40e74941-6d90-47fe-bf00-743f7b0302f6',
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'round-device',
            'queued_offline' => true,
        ];
        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            Carbon::parse($capturedAt)->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('administered_at'),
        );
    }

    public function test_same_round_uuid_with_changed_clinical_payload_reaches_the_core_fingerprint(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $uuid = 'b0d47070-d777-4bea-94f8-913f0296881c';
        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        $this->actingAs($this->worker)
            ->postJson($url, [
                'status' => 'given',
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'client_request_uuid' => $uuid,
                'queued_offline' => false,
            ])
            ->assertOk();

        $this->actingAs($this->worker)
            ->postJson($url, [
                'status' => 'refused',
                'reason' => 'Changed after the first accepted request.',
                'reason_code' => 'refused',
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'client_request_uuid' => $uuid,
                'queued_offline' => false,
            ])
            ->assertStatus(409)
            ->assertJsonValidationErrors('client_request_uuid');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => $uuid,
            'status' => 'given',
        ]);
    }

    public function test_same_slot_round_adoption_preserves_original_provenance_and_durable_replay(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $historicalShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subDay()->subHour(),
            'ends_at' => now()->subDay(),
            'actual_starts_at' => now()->subDay()->subHour(),
            'actual_ends_at' => now()->subDay(),
            'status' => 'completed',
        ]);
        $originalUuid = '20271631-7e7c-4ef5-9d96-4db0a446d2dc';
        $existing = ClientMedicationAdministration::query()->create([
            'client_request_uuid' => $originalUuid,
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'shift_id' => $historicalShift->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
        ]);
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => 'b03847a0-906d-432d-aea2-b1d3f58f4006',
            'queued_offline' => false,
        ];
        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->worker)
                ->postJson($url, $payload)
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('administration.id', $existing->id)
                ->assertJsonPath('sync.status', 'duplicate');
        }

        $existing->refresh();
        $this->assertSame($this->round->id, (int) $existing->medication_round_id);
        $this->assertSame($historicalShift->id, (int) $existing->shift_id);
        $this->assertSame($this->worker->id, (int) $existing->administered_by);
        $this->assertSame($originalUuid, $existing->client_request_uuid);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_guided_round_duplicate_response_uses_the_approved_effective_correction(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $original = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'medication_round_id' => $this->round->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
        ]);
        $approved = ClientMedicationAdministration::query()->create([
            ...$original->only([
                'client_id',
                'client_medication_id',
                'medication_round_id',
                'administered_by',
                'scheduled_for',
                'administered_at',
            ]),
            'corrected_of_id' => $original->id,
            'is_correction' => true,
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
            'correction_requested_by' => $this->worker->id,
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}",
                [
                    'status' => 'given',
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                    'client_request_uuid' => '38fc4c7f-9c7b-4ac4-9423-35b9c2b6ea7a',
                    'queued_offline' => false,
                ],
            )
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('administration.id', $approved->id)
            ->assertJsonPath('administration.status', 'refused')
            ->assertJsonPath('sync.status', 'duplicate');

        $this->assertSame(0, $this->round->fresh()->administered_count);
        $this->assertSame(1, $this->round->fresh()->refused_count);
        $this->assertDatabaseCount('client_medication_administrations', 2);
    }

    public function test_queued_round_admin_conflicts_when_round_dose_already_exists(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'medication_round_id' => $this->round->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
        ]);

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}",
                [
                    'status' => 'given',
                    'scheduled_for' => $scheduledFor->copy()->addSeconds(20)->toIso8601String(),
                    'client_request_uuid' => 'bb1f2ab9-b92c-446d-9189-6feb25b83cce',
                    'captured_offline_at' => now()->subMinutes(10)->toIso8601String(),
                    'origin_device_id' => 'round-device',
                    'queued_offline' => true,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_guided_round_conceals_unassigned_medications_and_foreign_rounds_before_validation(): void
    {
        $unassignedClient = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
        ]);
        $unassignedMedication = ClientMedication::query()->create([
            'client_id' => $unassignedClient->id,
            'name' => 'Same-Site unassigned guided target',
            'dosage' => '10mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);
        $otherWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $otherWorkersRound = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'name' => 'Another worker round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'status' => 'in_progress',
            'assigned_to' => $otherWorker->id,
            'started_by' => $otherWorker->id,
            'started_at' => now(),
            'total_medications' => 1,
        ]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignRound = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $foreignSite->id,
            'name' => 'Foreign Site round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'status' => 'in_progress',
            'assigned_to' => $this->worker->id,
            'started_by' => $this->worker->id,
            'started_at' => now(),
            'total_medications' => 1,
        ]);
        $invalidPayload = [
            'status' => 'invalid',
            'scheduled_for' => 'not-a-date',
        ];

        foreach ([
            [$this->round, $unassignedMedication],
            [$otherWorkersRound, $this->medication],
            [$foreignRound, $this->medication],
        ] as [$round, $medication]) {
            $this->actingAs($this->worker)
                ->postJson("/emar/rounds/{$round->id}/guided/items/{$medication->id}", $invalidPayload)
                ->assertNotFound();
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_guided_round_offline_validation_uses_the_historical_capture_time_for_the_concealment_probe(): void
    {
        $capturedAt = now()->subMinutes(30);
        Shift::query()
            ->where('user_id', $this->worker->id)
            ->where('client_id', $this->client->id)
            ->update([
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subMinutes(20),
                'actual_starts_at' => now()->subHours(2),
                'actual_ends_at' => now()->subMinutes(20),
                'started_by' => $this->worker->id,
                'completed_by' => $this->worker->id,
                'status' => 'completed',
            ]);

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}",
                [
                    'status' => 'invalid',
                    'scheduled_for' => 'not-a-date',
                    // Guided rounds do not accept this field. It must not be
                    // promoted into an authorization hint before validation.
                    'administered_at' => $capturedAt->toIso8601String(),
                ],
            )
            ->assertNotFound();

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}",
                [
                    'status' => 'invalid',
                    'scheduled_for' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
                        ->setTime(8, 0)
                        ->toIso8601String(),
                    'client_request_uuid' => '2058e568-d423-4825-898a-0577bd22e04e',
                    'captured_offline_at' => $capturedAt->toIso8601String(),
                    'origin_device_id' => 'historical-guided-round-device',
                    'queued_offline' => true,
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_guided_round_conceals_missing_and_foreign_witnesses_before_eligible_credentials(): void
    {
        $administerPermission = Permission::query()->where('key', 'medications.administer.record')->firstOrFail();
        $controlledPermission = Permission::query()->where('key', 'medications.controlled.record')->firstOrFail();
        $controlledViewPermission = Permission::query()->where('key', 'medications.controlled.view')->firstOrFail();
        $this->worker->permissionOverrides()->syncWithoutDetaching([
            $administerPermission->id => ['allowed' => true],
            $controlledPermission->id => ['allowed' => true],
            $controlledViewPermission->id => ['allowed' => true],
        ]);
        $this->medication->forceFill([
            'controlled_drug' => true,
            'witness_required' => true,
            'approval_status' => 'verified',
        ])->saveQuietly();
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $this->medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $foreignSite->id,
        ]);
        $foreignWitness = $this->currentWitnessAt($foreignSite, $foreignClient);
        $missingWitnessId = (int) User::query()->max('id') + 1000;
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'reason' => 'Test request may run outside the configured round window.',
            'quantity_administered' => 0.5,
            'witness_credential' => 'wrong-password',
        ];
        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        foreach ([$missingWitnessId, $foreignWitness->id] as $concealedWitnessId) {
            $response = $this->actingAs($this->worker)
                ->postJson($url, [
                    ...$payload,
                    'witnessed_by' => $concealedWitnessId,
                ]);
            $this->assertSame(404, $response->getStatusCode(), json_encode($response->json(), JSON_THROW_ON_ERROR));
        }

        $eligibleWitness = $this->currentWitnessAt($this->site, $this->client);
        $this->actingAs($this->worker)
            ->postJson($url, [
                ...$payload,
                'witnessed_by' => $eligibleWitness->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('witness_credential');

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    private function currentWitnessAt(Site $site, Client $client): User
    {
        $witness = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $witness->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $witnessPermission = Permission::query()->where('key', 'medications.controlled.witness')->firstOrFail();
        $witness->permissionOverrides()->syncWithoutDetaching([
            $witnessPermission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $witness->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $this->worker->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        return $witness;
    }
}
