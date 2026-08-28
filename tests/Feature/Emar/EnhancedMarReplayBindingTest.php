<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationIdempotencyResult;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class EnhancedMarReplayBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    private ClientMedication $medication;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $context = ServiceContext::factory()->create([
            'name' => 'Replay binding',
            'type' => 'residential',
            'is_active' => true,
            'site_id' => $site->id,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $this->actor = $this->recorder();
        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Replay-bound medicine',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => [],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $this->shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
            'status' => 'in_progress',
        ]);
    }

    public function test_replay_is_bound_to_actor_canonical_target_shift_and_normalized_full_clinical_payload(): void
    {
        $uuid = '86bd6f16-ed4e-4146-b6f9-cdc84caa2acd';
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'quantity_administered' => '1.0',
            'administered_at' => '2026-08-28T09:00:00+12:00',
            'notes' => 'Taken with breakfast.',
            'queued_offline' => false,
            'client_request_uuid' => $uuid,
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);

        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $equivalentRetry = $service->recordAdministration(
            $this->client,
            $this->medication,
            [
                'scope_authorized' => true,
                'reason' => '',
                'notes' => '  Taken with breakfast.  ',
                'administered_at' => '2026-08-27T21:00:00Z',
                'quantity_administered' => '1.00',
                'dose_given' => '1 tablet',
                'status' => 'given',
                'queued_offline' => false,
                'client_request_uuid' => $uuid,
            ],
            $this->actor->id,
            $this->shift->id,
        );

        $this->assertTrue($first['success']);
        $this->assertTrue($equivalentRetry['success']);
        $this->assertTrue($equivalentRetry['duplicate']);
        $this->assertSame($first['administration']->id, $equivalentRetry['administration']->id);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);

        $materialChanges = [
            'notes' => ['notes' => 'Taken after breakfast.'],
            'dose given' => ['dose_given' => '2 tablets'],
            'quantity administered' => ['quantity_administered' => '1.25'],
            'administered at' => ['administered_at' => '2026-08-27T21:01:00Z'],
            'witness' => ['witnessed_by' => User::factory()->create()->id],
            'scan code' => ['scan_code' => 'DIFFERENT-SCAN'],
            'blood glucose level' => ['blood_glucose_level' => '7.4'],
            'origin device' => ['origin_device_id' => 'another-device'],
            'queued offline' => ['queued_offline' => true],
            'status and reason' => ['status' => 'refused', 'reason_code' => 'refused'],
        ];
        foreach ($materialChanges as $label => $change) {
            $this->assertReplayConflict(fn () => $service->recordAdministration(
                $this->client,
                $this->medication,
                array_replace($payload, $change),
                $this->actor->id,
                $this->shift->id,
            ), $label);
        }

        // An unauthorised override is rejected on its own action-authority
        // boundary before idempotency details are disclosed.
        $overrideAttempt = $service->recordAdministration(
            $this->client,
            $this->medication,
            array_replace($payload, [
                'safety_override' => [
                    'reason' => 'Changed clinical basis',
                    'reason_code' => 'other',
                ],
            ]),
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertFalse($overrideAttempt['success']);
        $this->assertSame(403, $overrideAttempt['status']);
        $this->assertSame('safety_override', $overrideAttempt['error_field']);

        $otherActor = $this->recorder();
        $foreignShiftAttempt = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $otherActor->id,
            $this->shift->id,
        );
        $this->assertFalse($foreignShiftAttempt['success']);
        $this->assertSame('shift_id', $foreignShiftAttempt['error_field']);

        $otherActorShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $otherActor->id,
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addHours(2),
            'status' => 'in_progress',
        ]);
        $this->assertReplayConflict(fn () => $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $otherActor->id,
            $otherActorShift->id,
        ), 'actor and canonical shift');

        $otherMedication = $this->medication->replicate(['client_request_uuid']);
        $otherMedication->name = 'Different canonical medicine';
        $otherMedication->save();
        $this->assertReplayConflict(fn () => $service->recordAdministration(
            $this->client,
            $otherMedication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        ), 'canonical medication');

        $otherShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addHours(2),
            'status' => 'in_progress',
        ]);
        $this->assertReplayConflict(fn () => $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $otherShift->id,
        ), 'canonical shift');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
        $idempotencyResult = MedicationIdempotencyResult::query()->sole();
        $this->assertNull($idempotencyResult->expires_at);
        $binding = $idempotencyResult->response_payload;
        $this->assertSame($this->actor->id, $binding['request_actor_id']);
        $this->assertSame($this->client->id, $binding['client_id']);
        $this->assertSame($this->medication->id, $binding['client_medication_id']);
        $this->assertSame($this->shift->id, $binding['request_shift_id']);
        $this->assertSame($first['administration']->id, $binding['administration_id']);
        $this->assertArrayHasKey('_request_fingerprint', $binding);
        $this->assertArrayNotHasKey('notes', $binding);
        $this->assertArrayNotHasKey('witness_credential', $binding);
    }

    public function test_replay_rechecks_locked_current_rule_observations_before_returning_success(): void
    {
        $uuid = 'ef61404e-32de-4c48-b265-cf6a40c51693';
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'quantity_administered' => '1',
            'administered_at' => now()->toIso8601String(),
            'queued_offline' => false,
            'client_request_uuid' => $uuid,
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);
        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertTrue($first['success']);

        MedicationAdminRule::query()->create([
            'site_id' => $this->client->site_id,
            'match_type' => 'medicine_name',
            'match_value' => 'replay-bound medicine',
            'requires_countersign' => false,
            'required_observations' => ['pulse'],
            'active' => true,
            'created_by' => $this->actor->id,
        ]);

        $replay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );

        $this->assertFalse($replay['success']);
        $this->assertSame('pulse_bpm', $replay['error_field']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_identity_only_legacy_uuid_cannot_be_replayed_without_a_payload_binding(): void
    {
        $uuid = '05db5e87-8c8d-4e2d-bfb3-2daead8e32be';
        ClientMedicationAdministration::query()->create([
            'client_request_uuid' => $uuid,
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'shift_id' => $this->shift->id,
            'administered_by' => $this->actor->id,
            'administered_at' => now(),
            'status' => 'given',
        ]);

        $this->assertReplayConflict(fn () => app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $this->medication,
            [
                'status' => 'given',
                'dose_given' => '1 tablet',
                'client_request_uuid' => $uuid,
            ],
            $this->actor->id,
            $this->shift->id,
        ));

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
    }

    public function test_administration_rejects_a_same_site_shift_for_another_client_before_replay_or_write(): void
    {
        $foreignClient = Client::factory()->create([
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'status' => 'active',
        ]);
        $foreignShift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addHours(2),
            'status' => 'in_progress',
        ]);

        $result = app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $this->medication,
            [
                'status' => 'given',
                'dose_given' => '1 tablet',
                'quantity_administered' => '1.00',
                'administered_at' => now()->toIso8601String(),
                'client_request_uuid' => '0a6a75ba-8fd0-4f2f-bcfa-8f9950ead793',
                'scope_authorized' => true,
            ],
            $this->actor->id,
            $foreignShift->id,
        );

        $this->assertFalse($result['success']);
        $this->assertSame('shift_id', $result['error_field']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
    }

    public function test_administration_replay_rechecks_current_actor_competency(): void
    {
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'quantity_administered' => '1.00',
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => '4aab4143-1de1-40e3-ad32-c1a5b2158787',
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);

        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertTrue($first['success']);

        $assessment = MedicationCompetencyAssessment::query()
            ->where('user_id', $this->actor->id)
            ->sole();
        $assessment->forceFill(['status' => 'failed'])->save();

        $replay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );

        $this->assertFalse($replay['success']);
        $this->assertSame('status', $replay['error_field']);
        $this->assertSame('failed', $replay['competency_state']);

        $assessment->forceFill([
            'status' => 'passed',
            'expiry_date' => today()->subDay(),
        ])->save();
        $expiredReplay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertFalse($expiredReplay['success']);
        $this->assertSame('expired', $expiredReplay['competency_state']);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_administration_replay_rechecks_current_actor_permission(): void
    {
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'quantity_administered' => '1.00',
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => 'b19bdd50-fcec-4ecf-aa9a-b02a49a32934',
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);

        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertTrue($first['success']);

        $permission = Permission::query()->where('key', 'medications.administer.record')->sole();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => false],
        ]);
        $this->actor->unsetRelation('permissionOverrides')->unsetRelation('roles');

        try {
            $service->recordAdministration(
                $this->client,
                $this->medication,
                $payload,
                $this->actor->id,
                $this->shift->id,
            );
            $this->fail('A durable replay must recheck the current actor permission.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_controlled_administration_replay_rechecks_current_witness_authority(): void
    {
        $controlledRecord = Permission::query()->where('key', 'medications.controlled.record')->sole();
        $this->actor->permissionOverrides()->syncWithoutDetaching([
            $controlledRecord->id => ['allowed' => true],
        ]);
        $this->actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->medication->forceFill(['controlled_drug' => true])->saveQuietly();
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $this->medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $witness = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $witnessPermission = Permission::query()->where('key', 'medications.controlled.witness')->sole();
        $witness->permissionOverrides()->syncWithoutDetaching([
            $witnessPermission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $witness->id,
            'primary_site_id' => $this->client->site_id,
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $this->actor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ]);
        $payload = [
            'status' => 'given',
            'dose_given' => '1 tablet',
            'quantity_administered' => 1,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => 'e60df342-917a-4f2b-b97e-c1b478550514',
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);

        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertTrue($first['success']);

        $witness->permissionOverrides()->syncWithoutDetaching([
            $witnessPermission->id => ['allowed' => false],
        ]);
        $witness->unsetRelation('permissionOverrides')->unsetRelation('roles');

        try {
            $service->recordAdministration(
                $this->client,
                $this->medication,
                $payload,
                $this->actor->id,
                $this->shift->id,
            );
            $this->fail('A durable controlled replay must recheck current witness authority.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(1, ClientControlledDrugEntry::query()->count());
        $this->assertSame(9.0, (float) $stock->refresh()->on_hand);
    }

    public function test_scheduled_slot_duplicates_ignore_pending_and_rejected_correction_rows(): void
    {
        $service = app(EnhancedMarService::class);
        $uuids = [
            'pending' => '014fd5b3-13df-4dd7-8c56-f5dc67f39a86',
            'rejected' => '6fd0dfe3-82ef-4145-96f1-d3a7dad9198f',
        ];

        foreach (array_keys($uuids) as $offset => $correctionStatus) {
            $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
                ->startOfMinute()
                ->addHours($offset + 1);
            $original = $this->administrationAt($scheduledFor, [
                'status' => 'given',
            ]);
            $this->correctionFor($original, $correctionStatus, [
                'status' => 'refused',
            ]);

            $duplicate = $service->recordAdministration(
                $this->client,
                $this->medication,
                [
                    'status' => 'given',
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                    'administered_at' => $scheduledFor->toIso8601String(),
                    'client_request_uuid' => $uuids[$correctionStatus],
                    'scope_authorized' => true,
                ],
                $this->actor->id,
                $this->shift->id,
            );

            $this->assertTrue($duplicate['success']);
            $this->assertTrue($duplicate['duplicate']);
            $this->assertSame($original->id, $duplicate['administration']->id);
            $this->assertSame('given', $duplicate['administration']->status);
        }

        $this->assertDatabaseCount('client_medication_administrations', 4);
        $this->assertDatabaseCount('medication_idempotency_results', 2);
    }

    public function test_scheduled_slot_duplicate_returns_the_approved_effective_correction(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
            ->startOfMinute()
            ->addHours(3);
        $original = $this->administrationAt($scheduledFor, [
            'status' => 'given',
        ]);
        $approved = $this->correctionFor($original, 'approved', [
            'status' => 'refused',
            'correction_approved_at' => now(),
        ]);

        $duplicate = app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $this->medication,
            [
                'status' => 'given',
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'administered_at' => $scheduledFor->toIso8601String(),
                'client_request_uuid' => '15d13cba-4396-4635-b2c3-6251044d59f6',
                'scope_authorized' => true,
            ],
            $this->actor->id,
            $this->shift->id,
        );

        $this->assertTrue($duplicate['success']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($approved->id, $duplicate['administration']->id);
        $this->assertSame('refused', $duplicate['administration']->status);
        $this->assertSame(
            $approved->id,
            MedicationIdempotencyResult::query()->sole()->response_payload['administration_id'],
        );
    }

    public function test_durable_replay_validates_its_bound_row_then_returns_the_current_effective_winner(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))
            ->startOfMinute()
            ->addHours(4);
        $uuid = '8549884f-597f-407e-bf68-60b10901a2eb';
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => $uuid,
            'scope_authorized' => true,
        ];
        $service = app(EnhancedMarService::class);
        $first = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $original = $first['administration'];
        $correction = $this->correctionFor($original, 'pending', [
            'status' => 'refused',
        ]);

        $originalBindingReplay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertSame($original->id, $originalBindingReplay['administration']->id);

        $idempotency = MedicationIdempotencyResult::query()->sole();
        $historicalBinding = $idempotency->response_payload;
        $historicalBinding['administration_id'] = $correction->id;
        $historicalBinding['administration_actor_id'] = $correction->administered_by;
        $historicalBinding['administration_shift_id'] = $correction->shift_id;
        $historicalBinding['administration_request_uuid'] = $correction->client_request_uuid;
        $idempotency->forceFill(['response_payload' => $historicalBinding])->save();

        $pendingReplay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertSame($original->id, $pendingReplay['administration']->id);

        $correction->forceFill(['correction_status' => 'rejected'])->save();
        $rejectedReplay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertSame($original->id, $rejectedReplay['administration']->id);

        $correction->forceFill([
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
        ])->save();
        $approvedReplay = $service->recordAdministration(
            $this->client,
            $this->medication,
            $payload,
            $this->actor->id,
            $this->shift->id,
        );
        $this->assertSame($correction->id, $approvedReplay['administration']->id);
        $this->assertSame('refused', $approvedReplay['administration']->status);

        $this->assertDatabaseCount('client_medication_administrations', 2);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_shift_summary_rejects_a_shift_whose_client_belongs_to_another_site(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Hidden',
            'last_name' => 'Resident',
        ]);
        $forgedShift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $this->client->site_id,
            'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
        ]);
        $service = app(EnhancedMarService::class);

        try {
            $service->getShiftSummary(
                $forgedShift->id,
                false,
                $foreignClient->id,
                (int) $this->client->site_id,
                $this->actor->id,
            );
            $this->fail('A Shift linked to a Client at another Site must be concealed.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $canonical = $service->getShiftSummary(
            $this->shift->id,
            false,
            $this->client->id,
            (int) $this->client->site_id,
            $this->actor->id,
        );
        $this->assertSame($this->shift->id, $canonical['shift_id']);
        $this->assertSame($this->client->full_name, $canonical['client_name']);
        $this->assertSame(0, $canonical['total_administrations']);

        $this->shift->forceFill(['user_id' => User::factory()->create()->id])->saveQuietly();
        try {
            $service->getShiftSummary(
                $this->shift->id,
                false,
                $this->client->id,
                (int) $this->client->site_id,
                $this->actor->id,
            );
            $this->fail('A Shift reassigned after authorization must be concealed.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_forged_cross_client_rows_cannot_complete_a_slot_or_alter_prn_history_and_limits(): void
    {
        $workerNow = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $this->medication->forceFill([
            'dose_times' => [$workerNow->format('H:i')],
            'approval_status' => 'verified',
        ])->saveQuietly();
        $foreignClient = Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'service_context_id' => $this->client->service_context_id,
        ]);
        $forgedScheduled = ClientMedicationAdministration::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $this->medication->id,
            'administered_by' => $this->actor->id,
            'scheduled_for' => $workerNow->copy()->utc(),
            'administered_at' => $workerNow->copy()->utc(),
            'status' => 'given',
        ]);

        $mar = app(EnhancedMarService::class)->build(
            $this->client,
            $workerNow->copy()->startOfDay(),
            $workerNow,
        );
        $scheduledRow = collect($mar['scheduled'])
            ->firstWhere('client_medication_id', $this->medication->id);

        $this->assertNotNull($scheduledRow);
        $this->assertNull($scheduledRow['administration']);
        $this->assertNotSame($forgedScheduled->id, $scheduledRow['id']);

        $prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Canonical PRN ownership',
            'dosage' => '1 tablet',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 1,
            'min_hours_between_doses' => 6,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->actor->id,
            'administered_at' => now()->subMinutes(10),
            'status' => 'given',
            'reason' => 'Forged foreign-client dose',
        ]);
        $safety = app(MedicationSafetyService::class);

        $this->assertSame(0, $prn->prnCountLast24Hours);
        $this->assertFalse($safety->checkPrnLimits($prn)['blocked']);
        $this->assertSame([], $safety->getPrnHistory($prn)['history']);
        $this->assertFalse($safety->checkPrnInterval($prn)['blocked']);
    }

    private function recorder(): User
    {
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permission = Permission::query()->where('key', 'medications.administer.record')->sole();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $this->client->site_id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $actor->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subYear(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subYear(),
            'staff_acknowledged_at' => now()->subYear()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        return $actor;
    }

    private function administrationAt(Carbon $scheduledFor, array $overrides = []): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'shift_id' => $this->shift->id,
            'administered_by' => $this->actor->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
            ...$overrides,
        ]);
    }

    private function correctionFor(
        ClientMedicationAdministration $original,
        string $correctionStatus,
        array $overrides = [],
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            ...$original->only([
                'client_id',
                'client_medication_id',
                'shift_id',
                'service_context_id',
                'administered_by',
                'witnessed_by',
                'scheduled_for',
                'administered_at',
                'status',
                'reason',
                'reason_code',
                'dose_given',
                'notes',
            ]),
            'corrected_of_id' => $original->id,
            'is_correction' => true,
            'correction_status' => $correctionStatus,
            'correction_requested_by' => $this->actor->id,
            ...$overrides,
        ]);
    }

    private function assertReplayConflict(callable $attempt, string $label = 'changed replay'): void
    {
        try {
            $attempt();
            $this->fail("A changed replay must be rejected: {$label}.");
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This submission identifier was already used with different medication administration details.',
                $exception->errors()['client_request_uuid'][0] ?? null,
            );
        }
    }
}
