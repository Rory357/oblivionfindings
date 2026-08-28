<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Desktop medication board — scheduled-dose recording (Record Dose wizard),
 * PRN follow-up effects, and the extended board payload.
 */
class WorkerMedsRecordDoseTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($this->worker, ['medications.administer.record']);
        $this->site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Record Dose',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_worker_records_a_scheduled_dose_as_given(): void
    {
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
                'notes' => 'Taken with breakfast.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::sole();
        $this->assertSame('given', $administration->status);
        $this->assertSame($this->worker->id, (int) $administration->administered_by);
        $this->assertSame('1 tablet', $administration->dose_given);
        $this->assertSame(
            $scheduledFor->copy()->utc()->format('Y-m-d H:i'),
            Carbon::parse((string) $administration->getRawOriginal('scheduled_for'), 'UTC')->format('Y-m-d H:i'),
        );

        // The recording lands on the client timeline (same emission as the
        // guided round path), which also feeds the board's activity feed.
        $this->assertDatabaseHas('timeline_events', [
            'type' => 'medication_given',
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $administration->id,
            'client_id' => $this->client->id,
            'actor_user_id' => $this->worker->id,
        ]);

        // The board now shows the slot as recorded, the activity feed
        // carries the event, and the sidebar overdue badge reads zero.
        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.0.status', 'given')
                ->where('schedule.0.recorded.by', $this->worker->name)
                ->count('activity', 1)
                ->where('activity.0.icon', 'check')
                ->where('auth.can.medications.overdueTodayCount', 0)
            );
    }

    public function test_late_recording_outside_the_window_requires_a_reason(): void
    {
        $medication = $this->scheduledMedication(['08:00']);
        $scheduledFor = Carbon::parse('2026-04-30 08:00', config('app.worker_timezone'));

        // 09:30 is 90 minutes after the 08:00 slot — outside the default
        // 60-minute window, so the MAR pipeline demands a reason.
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'reason' => 'Client was out at an appointment — given on return.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_refused_dose_requires_a_not_given_reason_code(): void
    {
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'refused',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('reason_code');

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Declined, offered again at 9:40 and declined again.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);
    }

    public function test_offline_refusal_rolls_back_a_failed_incident_hook_then_replays_without_duplicates(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'high_risk' => true,
            'controlled_drug' => false,
            'witness_required' => false,
        ]);
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            public int $attempts = 0;

            public function emit(
                string $signalType,
                int $clientId,
                string $severity,
                string $message,
                array $context = [],
                bool $requiredDelivery = false,
            ): void {
                if ($signalType === self::TYPE_REFUSED_DOSE && $this->attempts++ === 0) {
                    throw new RuntimeException('Forced worker refusal hook failure');
                }

                parent::emit($signalType, $clientId, $severity, $message, $context, $requiredDelivery);
            }
        };
        $this->app->instance(
            MedicationIncidentIntegrationService::class,
            new MedicationIncidentIntegrationService(
                $signals,
                app(IncidentJourneyService::class),
            ),
        );
        $requestUuid = '28552625-7544-4cee-bfee-66f328a695eb';
        $payload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => now()->toIso8601String(),
            'status' => 'refused',
            'reason_code' => 'refused',
            'reason' => 'Client declined after a second offer.',
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => $requestUuid,
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'worker-meds-board',
            'queued_offline' => true,
        ];
        $this->withoutExceptionHandling();
        $firstException = null;

        try {
            $this->actingAs($this->worker)
                ->from('/meds/today')
                ->post('/meds/today/record', $payload);
        } catch (\Throwable $caught) {
            $firstException = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $firstException);
        $this->assertSame('Forced worker refusal hook failure', $firstException->getMessage());
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');
        Cache::forget("offline:idempotency:dose:{$requestUuid}");
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('warning');

        $this->assertSame(3, $signals->attempts);
        $this->assertSame(
            $requestUuid,
            ClientMedicationAdministration::query()->sole()->client_request_uuid,
        );
        $this->assertSame(
            'refused_dose',
            data_get(ClientIncident::query()->sole()->metadata, 'medication_incident_source.kind'),
        );
        $this->assertSame(MedicationSignalService::TYPE_REFUSED_DOSE, Signal::query()->sole()->signal_type_code);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            ClientIncident::query()->sole()->id,
            (int) data_get(ControlRoomAlert::query()->sole()->context, 'incident_id'),
        );
    }

    public function test_withheld_dose_accepts_the_omitted_in_error_reason_code(): void
    {
        // A genuinely-missed dose is recorded as withheld with the structured
        // "Omitted in error" reason (NotGivenReason::OmittedInError) — the code
        // is validated against the enum, so this proves it is accepted end-to-end.
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'withheld',
                'reason_code' => 'omitted_in_error',
                'reason' => 'Missed on the morning round — escalated to the RN, resident unaffected.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'withheld',
            'reason_code' => 'omitted_in_error',
        ]);
    }

    public function test_controlled_dose_requires_witness_and_writes_register_entry(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Methylphenidate',
            'controlled_drug' => true,
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        // No witness → blocked by the shared witness validation.
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('witnessed_by');

        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'quantity_administered' => 0.015,
                'cd_balance' => 9.999,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors(['quantity_administered', 'cd_balance']);

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10.0, (float) $medication->stock->refresh()->on_hand);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'quantity_administered' => 0.5,
                'cd_balance' => 9.5,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::sole();
        $this->assertSame($witness->id, (int) $administration->witnessed_by);
        $this->assertStringContainsString(
            'CD register balance after dose: 9.5',
            (string) $administration->notes,
        );

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $medication->id,
            'entry_type' => 'administered',
            'witnessed_by' => $witness->id,
        ]);
        $entry = $medication->controlledDrugEntries()->sole();
        $this->assertSame(0.5, (float) $entry->quantity);
        $this->assertSame(10.0, (float) $entry->on_hand_before);
        $this->assertSame(9.5, (float) $entry->on_hand_after);
        $this->assertSame(9.5, (float) $medication->stock->refresh()->on_hand);
    }

    public function test_controlled_dose_rejects_missing_insufficient_and_stale_stock_without_mutation(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Controlled stock provenance',
            'controlled_drug' => true,
        ]);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));
        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);
        $payload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'status' => 'given',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'quantity_administered' => 0.5,
            'cd_balance' => 9.5,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertSessionHasErrors('quantity_administered');

        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 0.25,
            'unit' => 'tablets',
        ]);
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertSessionHasErrors('quantity_administered');

        $stock->update(['on_hand' => 10]);
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                ...$payload,
                'cd_balance' => 8,
            ])
            ->assertSessionHasErrors('cd_balance');

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_offline_controlled_scheduled_dose_preserves_provenance_across_audit_timeline_and_replay(): void
    {
        $this->grantPermissions($this->worker, ['medications.controlled.record']);
        $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Offline controlled scheduled dose',
            'controlled_drug' => true,
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);
        $uuid = '0c51b0f8-6c0a-42c0-a8f2-3fc84303327f';
        $capturedAt = Carbon::parse('2026-04-30 09:25', config('app.worker_timezone'))->toIso8601String();
        $payload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'))->toIso8601String(),
            'status' => 'given',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'quantity_administered' => 0.5,
            'cd_balance' => 9.5,
            'client_request_uuid' => $uuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'worker-device-scheduled',
            'queued_offline' => true,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            now()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('created_at'),
        );
        $this->assertSame(
            Carbon::parse($capturedAt)->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('administered_at'),
        );
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.administration.record')
                ->where('auditable_id', $administration->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-scheduled',
        );
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.controlled.entry.record')
                ->where('client_id', $this->client->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-scheduled',
        );
        $this->assertOfflineProvenance(
            TimelineEvent::query()
                ->where('source_type', ClientMedicationAdministration::class)
                ->where('source_id', $administration->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-scheduled',
        );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.administration.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->where('source_id', $administration->id)
            ->count());
        $this->assertSame('9.50', (string) $stock->refresh()->on_hand);

        $this->actingAs($this->worker)
            ->postJson('/meds/today/record', [
                ...$payload,
                'origin_device_id' => 'worker-device-scheduled-changed',
            ])
            ->assertStatus(409)
            ->assertJsonValidationErrors('client_request_uuid');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.administration.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->where('source_id', $administration->id)
            ->count());
        $this->assertSame('9.50', (string) $stock->refresh()->on_hand);
    }

    public function test_controlled_prn_replay_is_single_effect_and_changed_payload_conflicts(): void
    {
        $this->grantPermissions($this->worker, ['medications.controlled.record']);
        $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $medication = $this->scheduledMedication([], [
            'name' => 'Controlled breakthrough dose',
            'is_prn' => true,
            'prn_reason' => 'Breakthrough pain',
            'max_per_day' => 4,
            'controlled_drug' => true,
            'approval_status' => 'verified',
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 3.5,
            'unit' => 'mL',
        ]);
        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);
        $uuid = 'a063aa67-c560-4efa-aa62-b036a6bab0f2';
        $capturedAt = Carbon::parse('2026-04-30 09:20', config('app.worker_timezone'))->toIso8601String();
        $payload = [
            'client_medication_id' => $medication->id,
            'reason' => 'Breakthrough pain (severe)',
            'dose_given' => '0.25 mL',
            'quantity_administered' => 0.25,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => $uuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'worker-device-prn',
            'queued_offline' => true,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            Carbon::parse($capturedAt)->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('administered_at'),
        );
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.administration.record')
                ->where('auditable_id', $administration->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-prn',
        );
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.controlled.entry.record')
                ->where('client_id', $this->client->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-prn',
        );
        $this->assertOfflineProvenance(
            TimelineEvent::query()
                ->where('source_type', ClientMedicationAdministration::class)
                ->where('source_id', $administration->id)
                ->sole()
                ->meta,
            $uuid,
            $capturedAt,
            'worker-device-prn',
        );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success', 'Already saved — no changes needed.');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.administration.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->where('source_id', $administration->id)
            ->count());
        $this->assertSame('3.25', (string) $stock->refresh()->on_hand);

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                ...$payload,
                'captured_offline_at' => Carbon::parse('2026-04-30 09:21', config('app.worker_timezone'))->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonValidationErrors('client_request_uuid');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.administration.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->where('source_id', $administration->id)
            ->count());
        $this->assertSame('3.25', (string) $stock->refresh()->on_hand);
    }

    public function test_scheduled_dose_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        $medication = $this->scheduledMedication(['09:30']);
        $basePayload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => now()->toIso8601String(),
            'status' => 'given',
        ];
        $validUuid = '4be449ad-aeab-4845-bef1-c10989259629';
        $validCapturedAt = now()->subMinutes(5)->toIso8601String();
        $invalidSubmissions = [
            'missing request UUID' => [[
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => 'worker-device',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            'invalid request UUID' => [[
                'client_request_uuid' => 'not-a-uuid',
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => 'worker-device',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            'missing capture time' => [[
                'client_request_uuid' => $validUuid,
                'origin_device_id' => 'worker-device',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            'non-RFC3339 capture time' => [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'worker-device',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            'missing device' => [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            'blank device' => [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => '   ',
                'queued_offline' => true,
            ], 'origin_device_id'],
            'oversized device' => [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => str_repeat('d', 129),
                'queued_offline' => true,
            ], 'origin_device_id'],
            'online capture provenance' => [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => false,
            ], 'captured_offline_at'],
            'online device provenance' => [[
                'client_request_uuid' => $validUuid,
                'origin_device_id' => 'worker-device',
            ], 'origin_device_id'],
        ];

        foreach ($invalidSubmissions as [$submission, $errorField]) {
            $this->actingAs($this->worker)
                ->postJson('/meds/today/record', [
                    ...$basePayload,
                    ...$submission,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->count());
    }

    public function test_prn_online_uuid_is_idempotent_without_offline_provenance(): void
    {
        $medication = $this->scheduledMedication([], [
            'name' => 'Online PRN idempotency',
            'is_prn' => true,
            'prn_reason' => 'Breakthrough pain',
            'max_per_day' => 4,
        ]);
        $payload = [
            'client_medication_id' => $medication->id,
            'reason' => 'Breakthrough pain',
            'client_request_uuid' => '078391c2-6386-4af2-a568-8cc425303121',
            'queued_offline' => false,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(
            now()->utc()->format('Y-m-d H:i:s'),
            $administration->getRawOriginal('administered_at'),
        );
        $this->assertNull(
            AuditLog::query()
                ->where('action', 'medications.administration.record')
                ->where('auditable_id', $administration->id)
                ->sole()
                ->meta['captured_offline_at'] ?? null,
        );

        $this->travel(5)->minutes();
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success', 'Already saved — no changes needed.');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.administration.record')->count());
        $this->assertSame(1, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->where('source_id', $administration->id)
            ->count());
    }

    public function test_prn_rejects_queued_or_online_provenance_contradictions(): void
    {
        $medication = $this->scheduledMedication([], [
            'name' => 'PRN provenance validation',
            'is_prn' => true,
            'prn_reason' => 'Breakthrough pain',
            'max_per_day' => 4,
        ]);
        $basePayload = [
            'client_medication_id' => $medication->id,
            'reason' => 'Breakthrough pain',
            'client_request_uuid' => '0ff31f40-cde5-4107-a4cc-8011e027d9e3',
        ];

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                ...$basePayload,
                'captured_offline_at' => now()->subMinute()->toIso8601String(),
                'queued_offline' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origin_device_id');

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                ...$basePayload,
                'captured_offline_at' => now()->subMinute()->toIso8601String(),
                'origin_device_id' => 'worker-device',
                'queued_offline' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'captured_offline_at',
                'origin_device_id',
            ]);

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_controlled_dose_strict_audit_failure_rolls_back_administration_and_stock(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Audited controlled administration',
            'controlled_drug' => true,
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.controlled.entry.record') {
                throw new RuntimeException('Injected controlled administration audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->worker)
                ->post('/meds/today/record', [
                    'client_medication_id' => $medication->id,
                    'scheduled_for' => now()->toIso8601String(),
                    'status' => 'given',
                    'witnessed_by' => $witness->id,
                    'witness_credential' => 'password',
                    'quantity_administered' => 0.5,
                    'cd_balance' => 9.5,
                    'client_request_uuid' => '12977c24-0150-452d-8365-52ad214e12df',
                    'captured_offline_at' => now()->subMinutes(5)->toIso8601String(),
                    'origin_device_id' => 'worker-device-audit-failure',
                    'queued_offline' => true,
                ]);
            $this->fail('The injected controlled administration audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected controlled administration audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertSame(0, TimelineEvent::query()
            ->where('source_type', ClientMedicationAdministration::class)
            ->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.entry.record']);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_controlled_dose_conceals_missing_foreign_ended_and_stale_permission_witnesses_and_rejects_bad_credentials(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Witness authority boundary',
            'controlled_drug' => true,
        ]);
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignWitness = $this->currentWitnessAt($foreignSite);
        $endedWitness = $this->currentWitnessAt($this->site, [
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $inactiveWitness = $this->currentWitnessAt($this->site, [
            'is_active' => false,
        ]);
        $stalePermissionWitness = $this->currentWitnessAt($this->site);
        $witnessPermission = Permission::query()
            ->where('key', 'medications.controlled.witness')
            ->sole();
        $stalePermissionWitness->permissionOverrides()->sync([
            $witnessPermission->id => ['allowed' => false],
        ]);
        $validWitness = $this->currentWitnessAt($this->site);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $validWitness->id,
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
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $validWitness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'created_by' => $this->worker->id,
        ]);
        $payload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => now()->toIso8601String(),
            'status' => 'given',
            'quantity_administered' => 0.5,
            'cd_balance' => 9.5,
            'witness_credential' => 'password',
        ];

        $concealedWitnessIds = [
            (int) User::query()->max('id') + 1000,
            $foreignWitness->id,
            $endedWitness->id,
            $inactiveWitness->id,
            $stalePermissionWitness->id,
        ];
        foreach ($concealedWitnessIds as $concealedWitnessId) {
            $this->actingAs($this->worker)
                ->post('/meds/today/record', [
                    ...$payload,
                    'witnessed_by' => $concealedWitnessId,
                ])
                ->assertNotFound();
        }

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                ...$payload,
                'witnessed_by' => $validWitness->id,
                'witness_credential' => 'wrong-password',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('witness_credential');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('witness_credential', $oldInput);
        $this->assertStringNotContainsString(
            'wrong-password',
            json_encode($oldInput, JSON_THROW_ON_ERROR),
        );

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
    }

    public function test_generic_administration_strict_audit_failure_rolls_back_domain_and_audit_state(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Strictly audited administration',
        ]);
        $auditCount = AuditLog::count();
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.administration.record') {
                throw new RuntimeException('Injected generic administration audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->worker)
                ->post('/meds/today/record', [
                    'client_medication_id' => $medication->id,
                    'scheduled_for' => now()->toIso8601String(),
                    'status' => 'given',
                ]);
            $this->fail('The injected generic administration audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected generic administration audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_prn_effect_records_once_per_administration(): void
    {
        $prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
        ]);

        $administration = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        // The board surfaces the un-checked PRN as a follow-up.
        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prn_follow_ups.0.administration_id', $administration->id)
            );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn/effect', [
                'client_medication_administration_id' => $administration->id,
                'effectiveness' => 'effective',
                'observations' => 'Settled within half an hour.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'effective',
            'reviewed_by' => $this->worker->id,
        ]);

        // Re-recording revises the single register entry (updateOrCreate keyed
        // on the administration) rather than blocking or duplicating it — the
        // eMAR "Re-record effectiveness" action. (Updated: the duplicate path now
        // succeeds with a revise message instead of the old "warning" no-op.)
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn/effect', [
                'client_medication_administration_id' => $administration->id,
                'effectiveness' => 'not_effective',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        // Still one row — now updated to the revised effectiveness.
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'not_effective',
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('prn_follow_ups', 0));
    }

    public function test_controlled_prn_effect_requires_the_exact_controlled_record_capability(): void
    {
        $this->denyPermissions($this->worker, ['medications.controlled.record']);
        $prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Controlled PRN follow-up',
            'dosage' => '5mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'controlled_drug' => true,
            'prn_reason' => 'Breakthrough pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);
        $payload = [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'effective',
            'observations' => 'Pain settled after the dose.',
        ];

        foreach (['/meds/today/prn/effect', '/emar/prn/effectiveness'] as $endpoint) {
            $this->actingAs($this->worker)
                ->post($endpoint, $payload)
                ->assertNotFound();
            $this->actingAs($this->worker)
                ->post($endpoint, [
                    ...$payload,
                    'effectiveness' => 'invalid-probe',
                ])
                ->assertNotFound();
            $this->actingAs($this->worker)
                ->post($endpoint, [
                    ...$payload,
                    'client_medication_administration_id' => 999999,
                    'effectiveness' => 'invalid-probe',
                ])
                ->assertNotFound();
        }
        $this->assertDatabaseCount('medication_prn_effectiveness', 0);

        $this->grantPermissions($this->worker, ['medications.controlled.record']);
        $this->worker->unsetRelation('permissionOverrides')->unsetRelation('roles');

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn/effect', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');
        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'effective',
            'reviewed_by' => $this->worker->id,
        ]);
    }

    public function test_board_payload_includes_schedule_and_supports_date_navigation(): void
    {
        $this->scheduledMedication(['08:00', '16:00']);

        $witness = $this->currentWitnessAt($this->site, withGovernanceEvidence: true);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('is_today', true)
                ->count('schedule', 2)
                ->where('schedule.0.status', 'overdue')
                ->where('schedule.0.time', '08:00')
                ->where('schedule.0.round_label', 'Morning')
                ->where('schedule.1.status', 'upcoming')
                ->where('clients.0.name', 'Aroha Ngata')
                ->where('witnesses', fn ($witnesses) => $witnesses->contains('id', $witness->id))
                ->where('has_shift_context', true)
                // Sidebar badge shared prop: the 08:00 slot is overdue at
                // 09:30, the 16:00 one isn't.
                ->where('auth.can.medications.overdueTodayCount', 1)
            );

        // Day navigation: a shift tomorrow gives the worker tomorrow's board.
        // "Tomorrow" must be computed in the worker timezone — now() is UTC
        // and at 09:30 NZ the UTC date is still yesterday.
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $tomorrow = Carbon::now($timezone)->addDay()->toDateString();

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse($tomorrow.' 09:00', $timezone)->utc(),
            'ends_at' => Carbon::parse($tomorrow.' 17:00', $timezone)->utc(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today?date='.$tomorrow)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_today', false)
                ->where('date', $tomorrow)
                ->count('schedule', 2)
                ->where('schedule.0.status', 'upcoming')
            );
    }

    private function scheduledMedication(array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
    }

    private function currentWitnessAt(
        Site $site,
        array $profileOverrides = [],
        bool $withGovernanceEvidence = false,
    ): User {
        $witness = $this->makeRoleUser('support_worker');
        $this->grantPermissions($witness, ['medications.controlled.witness']);
        HrEmployeeProfile::factory()->create(array_merge([
            'user_id' => $witness->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ], $profileOverrides));

        if ($withGovernanceEvidence) {
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
                'client_id' => $this->client->id,
                'site_id' => $site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $witness->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(3),
                'actual_starts_at' => now()->subMinutes(30),
                'actual_ends_at' => null,
                'status' => 'in_progress',
                'created_by' => $this->worker->id,
            ]);
        }

        return $witness;
    }

    /** @param  array<string, mixed>  $meta */
    private function assertOfflineProvenance(
        array $meta,
        string $requestUuid,
        string $capturedAt,
        string $deviceId,
    ): void {
        $this->assertSame($requestUuid, $meta['client_request_uuid'] ?? null);
        $this->assertSame($capturedAt, $meta['captured_offline_at'] ?? null);
        $this->assertSame($deviceId, $meta['origin_device_id'] ?? null);
        $this->assertTrue($meta['queued_offline'] ?? false);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function denyPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
