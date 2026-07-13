<?php

namespace Tests\Feature\Medication;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationIncidentJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_distinct_medication_errors_on_one_incident_emit_distinct_retry_safe_signals(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'reported_by' => $actor->id,
            'type' => 'medication',
            'status' => 'draft',
            'submitted_at' => null,
        ]));
        $errors = collect(['wrong_dose', 'wrong_time'])->map(
            fn (string $errorType): MedicationError => MedicationError::create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'client_incident_id' => $incident->id,
                'error_type' => $errorType,
                'severity' => 'major',
                'description' => "Distinct {$errorType} error.",
                'reported_by' => $actor->id,
                'reported_at' => now()->startOfSecond(),
                'status' => 'reported',
            ]),
        );
        $signals = app(MedicationSignalService::class);

        foreach ($errors as $error) {
            $signals->emitError($error);
        }
        foreach ($errors as $error) {
            $signals->emitError($error->fresh());
        }

        $alert = ControlRoomAlert::query()->sole();
        $signalRows = Signal::query()->orderBy('id')->get();

        $this->assertCount(2, $signalRows);
        $this->assertEqualsCanonicalizing(
            $errors->pluck('id')->all(),
            $signalRows->pluck('normalized_data')->map(
                fn (array $normalized): int => (int) $normalized['medication_error_id'],
            )->all(),
        );
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($signalRows->last()->id, data_get($alert->context, 'signal_id'));
        $this->assertSame(
            [$signalRows->last()->id],
            collect(data_get($alert->context, 'correlated_signals', []))->pluck('signal_id')->all(),
        );
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    #[DataProvider('nonCanonicalIncidentIds')]
    public function test_medication_signal_rejects_non_canonical_incident_identifiers(mixed $incidentId): void
    {
        [, $client] = $this->medicationFixture();
        $source = $this->medicationSignalSource();
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => MedicationSignalService::TYPE_MISSED_DOSE,
            'client_id' => $client->id,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'normalized_data' => ['incident_id' => $incidentId],
            'status' => 'pending',
        ]);

        try {
            app(SignalProcessingService::class)->process($signal);
            $this->fail('A non-canonical incident identifier must not be trusted.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('valid incident', $exception->getMessage());
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_untrusted_signal_source_cannot_attach_an_incident_claim(): void
    {
        [, $client] = $this->medicationFixture();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
        ]));
        $source = SignalSource::create([
            'name' => 'Untrusted third-party source',
            'slug' => 'third_party',
            'vendor' => 'external',
            'status' => 'active',
            'capabilities' => ['event_driven'],
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'third_party_event',
            'client_id' => $client->id,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'normalized_data' => ['incident_id' => $incident->id],
            'status' => 'pending',
        ]);

        try {
            app(SignalProcessingService::class)->process($signal);
            $this->fail('An untrusted source must not claim an incident journey.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('trusted source', $exception->getMessage());
        }

        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_malformed_incident_claim_is_logged_rethrown_and_rolled_back(): void
    {
        Log::spy();
        [, $client] = $this->medicationFixture();

        try {
            app(MedicationSignalService::class)->emit(
                MedicationSignalService::TYPE_MISSED_DOSE,
                $client->id,
                'medium',
                'Malformed incident ownership claim.',
                ['incident_id' => 'not-a-canonical-incident-id'],
            );
            $this->fail('A malformed incident claim must fail closed to its owner.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('valid incident', $exception->getMessage());
        }

        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'incident_journey_repair_required'
                && $context['incident_id'] === 'not-a-canonical-incident-id'
                && $context['incident_id_type'] === 'string'
                && is_int($context['signal_id'])
                && $context['signal_type'] === MedicationSignalService::TYPE_MISSED_DOSE
                && $context['exception'] === \DomainException::class,
        );
    }

    public function test_internal_medication_source_can_attach_a_canonical_incident_claim(): void
    {
        [, $client] = $this->medicationFixture();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
        ]));
        $source = $this->medicationSignalSource();
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => MedicationSignalService::TYPE_MISSED_DOSE,
            'client_id' => $client->id,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'normalized_data' => ['incident_id' => (string) $incident->id],
            'status' => 'pending',
        ]);

        $alert = app(SignalProcessingService::class)->process($signal);

        $this->assertNotNull($alert);
        $this->assertSame($alert->id, $incident->fresh()->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame('processed', $signal->fresh()->status);
    }

    public function test_generic_signal_without_incident_claim_remains_processable_from_untrusted_source(): void
    {
        [, $client] = $this->medicationFixture();
        $source = SignalSource::create([
            'name' => 'Generic third-party source',
            'slug' => 'generic_third_party',
            'vendor' => 'external',
            'status' => 'active',
            'capabilities' => ['event_driven'],
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'generic_event',
            'client_id' => $client->id,
            'severity_hint' => 'low',
            'occurred_at' => now(),
            'normalized_data' => ['title' => 'Generic operational event'],
            'status' => 'pending',
        ]);

        $alert = app(SignalProcessingService::class)->process($signal);

        $this->assertNotNull($alert);
        $this->assertSame($alert->id, $signal->fresh()->alert_id);
        $this->assertSame('processed', $signal->fresh()->status);
    }

    public function test_controlled_discrepancy_signal_enriches_one_official_journey_and_retry_is_exact(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Morphine sulfate',
            'controlled_drug' => true,
            'high_risk' => true,
        ]);
        $reportedAt = now()->subMinutes(10)->startOfSecond();
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 12,
            'on_hand_after' => 10,
            'difference' => -2,
            'reason' => 'Count did not reconcile at handover.',
            'reported_at' => $reportedAt,
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleControlledDiscrepancy($discrepancy, $actor->id);

        $this->travel(31)->minutes();
        $second = $service->handleControlledDiscrepancy($discrepancy->fresh(), $actor->id);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();
        $signal = Signal::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($incident->id, $discrepancy->fresh()->incident_id);
        $this->assertSame('submitted', $incident->status);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame(ClientIncident::class, $event->source_type);
        $this->assertSame($incident->id, $event->source_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($incident->id, data_get($alert->context, 'normalized_data.incident_id'));
        $this->assertSame($discrepancy->id, data_get($alert->context, 'normalized_data.discrepancy_id'));
        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertSame('processed', $signal->status);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);

        $service->resolveControlledDiscrepancy(
            $discrepancy->fresh(),
            'Count reconciled and signed off.',
            $actor->id,
        );

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_controlled_loss_reuses_its_persisted_official_journey_across_signal_windows(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Oxycodone',
            'controlled_drug' => true,
        ]);
        $discoveredAt = now()->subMinutes(15)->startOfSecond();
        $report = ControlledDrugLossReport::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 2,
            'unit' => 'tablets',
            'circumstances' => 'Count was short during the controlled-drug handover.',
            'discovered_by' => $actor->id,
            'discovered_at' => $discoveredAt,
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleControlledLossReport($report, $actor->id);
        $this->travel(31)->minutes();
        $second = $service->handleControlledLossReport($report->fresh(), $actor->id);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($incident->id, $report->fresh()->incident_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($report->id, data_get($alert->context, 'normalized_data.loss_report_id'));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    public function test_distinct_draft_incident_signals_never_fuzzy_correlate_to_one_alert(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $source = SignalSource::create([
            'name' => 'Medication / eMAR',
            'slug' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $type = SignalType::create([
            'code' => MedicationSignalService::TYPE_MISSED_DOSE,
            'name' => 'Medication missed dose',
            'category' => 'medication',
            'default_severity' => 'medium',
        ]);
        SignalRule::create([
            'name' => 'Medication missed dose rule',
            'signal_source_id' => $source->id,
            'signal_type_id' => $type->id,
            'conditions' => [],
            'alert_type' => 'Medication missed dose',
            'is_active' => true,
            'priority' => 1,
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
        ]);
        $first = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication',
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $second = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication',
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $signals = app(MedicationSignalService::class);

        foreach ([$first, $second] as $incident) {
            $signals->emit(
                MedicationSignalService::TYPE_MISSED_DOSE,
                $client->id,
                'medium',
                'Medication dose was missed.',
                [
                    'incident_id' => $incident->id,
                    'site_id' => $site->id,
                ],
            );
        }

        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_signals', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            ControlRoomAlert::query()
                ->get()
                ->map(fn (ControlRoomAlert $alert): int => (int) data_get($alert->context, 'incident_id'))
                ->all(),
        );

        $firstAlert = ControlRoomAlert::query()
            ->where('context->incident_id', $first->id)
            ->sole();
        $first->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
        ])->saveQuietly();
        $actor = User::query()->findOrFail($first->reported_by);
        $submittedJourney = app(IncidentJourneyService::class)
            ->ensureForSubmittedIncident($first->fresh(), $actor);

        $this->assertNotNull($submittedJourney->hsEvent);
        $this->assertSame($firstAlert->id, $submittedJourney->incident->control_room_alert_id);
        $this->assertSame($firstAlert->id, $submittedJourney->hsEvent->control_room_alert_id);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 2);
    }

    public function test_refusal_escalation_reuses_the_exact_followup_incident_on_retry(): void
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Warfarin',
            'high_risk' => true,
        ]);
        $administration = ClientMedicationAdministration::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'refused',
            'administered_at' => now()->subHour(),
        ]);
        $followup = MedicationRefusalFollowup::create([
            'client_id' => $client->id,
            'client_medication_administration_id' => $administration->id,
            'reason_category' => 'personal_choice',
            'detailed_reason' => 'Client declined after risks were explained.',
            'client_capacity_at_time' => 'has_capacity',
            'gp_notification_required' => true,
            'follow_up_due_at' => now()->addDay(),
            'created_by' => $actor->id,
        ]);

        $service = app(MedicationIncidentIntegrationService::class);
        $first = $service->handleRefusalEscalation($followup, 3);
        $this->travel(31)->minutes();
        $second = $service->handleRefusalEscalation($followup->fresh(), 3);

        $incident = ClientIncident::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $event = HsEvent::query()->sole();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($first->is($incident));
        $this->assertTrue($second->is($incident));
        $this->assertSame($followup->id, data_get($incident->metadata, 'medication_refusal_followup_id'));
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($followup->id, data_get($alert->context, 'normalized_data.followup_id'));
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    public function test_source_backed_draft_handlers_reuse_one_incident_per_locked_source_kind(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $missed = $this->administration($client, $medication, $actor, [
            'status' => 'missed',
            'scheduled_for' => now()->subHours(4),
            'reason' => 'Client was away from the service.',
        ]);
        $original = $this->administration($client, $medication, $actor, [
            'status' => 'given',
            'administered_at' => now()->subHours(30),
            'dose_given' => '5 mg',
        ]);
        $original->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $correction = $this->administration($client, $medication, $actor, [
            'corrected_of_id' => $original->id,
            'is_correction' => true,
            'status' => 'given',
            'administered_at' => now(),
            'correction_reason' => 'Late documentation correction.',
        ]);
        $late = $this->administration($client, $medication, $actor, [
            'status' => 'given',
            'scheduled_for' => now()->subHours(6),
            'administered_at' => now(),
            'reason' => 'Delayed return from appointment.',
        ]);
        $refused = $this->administration($client, $medication, $actor, [
            'status' => 'refused',
            'scheduled_for' => now()->subHour(),
            'administered_at' => now(),
            'reason' => 'Client declined after discussion.',
        ]);
        $sourceLocks = [];
        DB::listen(function ($query) use (&$sourceLocks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'client_medication_administrations')
                && str_contains($sql, 'for update')
            ) {
                $sourceLocks[] = $query->bindings;
            }
        });

        $service = app(MedicationIncidentIntegrationService::class);
        $first = [
            'missed_dose' => $service->handleMissedDose($missed, $actor->id),
            'unsafe_correction' => $service->handleUnsafeCorrection(
                $original,
                ['status' => 'given', 'dose_given' => '5 mg', 'correction_reason' => 'Late documentation correction.'],
                $actor->id,
                $correction,
            ),
            'late_dose' => $service->handleLateDose($late, 360),
            'refused_dose' => $service->handleRefusedDose($refused),
        ];
        $second = [
            'missed_dose' => $service->handleMissedDose($missed->fresh(), $actor->id),
            'unsafe_correction' => $service->handleUnsafeCorrection(
                $original->fresh(),
                ['status' => 'given', 'dose_given' => '5 mg', 'correction_reason' => 'Late documentation correction.'],
                $actor->id,
                $correction->fresh(),
            ),
            'late_dose' => $service->handleLateDose($late->fresh(), 360),
            'refused_dose' => $service->handleRefusedDose($refused->fresh()),
        ];
        $expectedSourceIds = [
            'missed_dose' => $missed->id,
            'unsafe_correction' => $correction->id,
            'late_dose' => $late->id,
            'refused_dose' => $refused->id,
        ];

        foreach ($first as $kind => $incident) {
            $this->assertNotNull($incident);
            $this->assertTrue($incident->is($second[$kind]));
            $this->assertSame($kind, data_get($incident->fresh()->metadata, 'medication_incident_source.kind'));
            $this->assertSame(
                ClientMedicationAdministration::class,
                data_get($incident->fresh()->metadata, 'medication_incident_source.type'),
            );
            $this->assertSame(
                $expectedSourceIds[$kind],
                data_get($incident->fresh()->metadata, 'medication_incident_source.id'),
            );
        }

        $this->assertGreaterThanOrEqual(8, count($sourceLocks));
        $this->assertDatabaseCount('client_incidents', 4);
        $this->assertDatabaseCount('control_room_alerts', 4);
        $this->assertDatabaseCount('control_room_signals', 4);
    }

    public function test_different_incident_kinds_on_one_administration_remain_distinct_and_retry_safe(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $administration = $this->administration($client, $medication, $actor, [
            'status' => 'refused',
            'scheduled_for' => now()->subHours(5),
            'administered_at' => now(),
            'reason' => 'Client declined the delayed dose.',
        ]);
        $service = app(MedicationIncidentIntegrationService::class);

        $missed = $service->handleMissedDose($administration, $actor->id);
        $refused = $service->handleRefusedDose($administration);
        $missedRetry = $service->handleMissedDose($administration->fresh(), $actor->id);
        $refusedRetry = $service->handleRefusedDose($administration->fresh());

        $this->assertNotNull($missed);
        $this->assertNotNull($refused);
        $this->assertTrue($missed->is($missedRetry));
        $this->assertTrue($refused->is($refusedRetry));
        $this->assertFalse($missed->is($refused));
        $this->assertEqualsCanonicalizing(
            ['missed_dose', 'refused_dose'],
            ClientIncident::query()
                ->get()
                ->map(fn (ClientIncident $incident) => data_get($incident->metadata, 'medication_incident_source.kind'))
                ->all(),
        );
        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('control_room_signals', 2);
    }

    public function test_prn_attempts_without_a_stable_source_event_remain_distinct(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $medication->update(['max_per_day' => '4']);
        $service = app(MedicationIncidentIntegrationService::class);

        $first = $service->handlePrnOverLimit($client, $medication->fresh(), $actor->id);
        $second = $service->handlePrnOverLimit($client, $medication->fresh(), $actor->id);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertFalse($first->is($second));
        $this->assertNotNull(data_get($first->metadata, 'medication_prn_attempt.id'));
        $this->assertNotNull(data_get($second->metadata, 'medication_prn_attempt.id'));
        $this->assertNotSame(
            data_get($first->metadata, 'medication_prn_attempt.id'),
            data_get($second->metadata, 'medication_prn_attempt.id'),
        );
        $this->assertSame($actor->id, data_get($first->metadata, 'medication_prn_attempt.attempted_by'));
        $this->assertSame($actor->id, data_get($second->metadata, 'medication_prn_attempt.attempted_by'));
        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('control_room_signals', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            ControlRoomAlert::query()
                ->get()
                ->map(fn (ControlRoomAlert $alert): int => (int) data_get($alert->context, 'incident_id'))
                ->all(),
        );
    }

    public function test_prn_attempt_rolls_back_when_signal_source_is_unavailable(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $medication->update(['max_per_day' => '4']);
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            protected function getSignalSource(): ?SignalSource
            {
                return null;
            }
        };
        $service = new MedicationIncidentIntegrationService(
            $signals,
            app(IncidentJourneyService::class),
        );

        try {
            $service->handlePrnOverLimit($client, $medication->fresh(), $actor->id);
            $this->fail('Missing PRN signal source must roll back the whole attempt.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Medication signal source is unavailable for an incident journey.', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('medication_dashboard_alerts', 0);
    }

    public function test_prn_attempt_rolls_back_when_signal_processing_fails(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $medication->update(['max_per_day' => '4']);
        $processor = $this->partialMock(SignalProcessingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('process')
                ->once()
                ->andThrow(new \RuntimeException('Forced PRN signal processing failure'));
        });
        $service = new MedicationIncidentIntegrationService(
            new MedicationSignalService($processor),
            app(IncidentJourneyService::class),
        );

        try {
            $service->handlePrnOverLimit($client, $medication->fresh(), $actor->id);
            $this->fail('PRN signal processing failure must roll back the whole attempt.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced PRN signal processing failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('medication_dashboard_alerts', 0);
    }

    public function test_submitted_controlled_discrepancy_rejects_a_missing_actor_before_writes(): void
    {
        [, $client, $medication] = $this->medicationFixture();
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'difference' => -1,
            'reason' => 'No accountable reporter is available.',
            'reported_at' => now()->subMinute(),
            'reported_by' => null,
            'status' => 'open',
        ]);

        try {
            app(MedicationIncidentIntegrationService::class)->handleControlledDiscrepancy($discrepancy);
            $this->fail('A submitted medication journey requires an accountable actor.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('explicit actor', $exception->getMessage());
        }

        $this->assertNull($discrepancy->fresh()->incident_id);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('medication_dashboard_alerts', 0);
    }

    public function test_submitted_source_journey_rolls_back_when_incident_signal_processing_fails(): void
    {
        Log::spy();
        [$actor, $client, $medication] = $this->medicationFixture();
        $reportedAt = now()->subMinutes(5)->startOfSecond();
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 20,
            'on_hand_after' => 19,
            'difference' => -1,
            'reason' => 'Forced atomicity test.',
            'reported_at' => $reportedAt,
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);
        $processor = $this->partialMock(SignalProcessingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('process')
                ->once()
                ->andThrow(new \RuntimeException('Forced medication signal processing failure'));
        });
        $service = new MedicationIncidentIntegrationService(
            new MedicationSignalService($processor),
            app(IncidentJourneyService::class),
        );

        try {
            $service->handleControlledDiscrepancy($discrepancy, $actor->id);
            $this->fail('Incident-tagged processing failure must abort the owning transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced medication signal processing failure', $exception->getMessage());
        }

        $this->assertNull($discrepancy->fresh()->incident_id);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('medication_dashboard_alerts', 0);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'incident_journey_repair_required'
                && is_int($context['incident_id'])
                && is_int($context['signal_id'])
                && $context['signal_type'] === MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY
                && $context['exception'] === \RuntimeException::class,
        );
    }

    public function test_submitted_source_journey_rolls_back_when_signal_source_is_unavailable(): void
    {
        Log::spy();
        [$actor, $client, $medication] = $this->medicationFixture();
        $discrepancy = ClientControlledDrugDiscrepancy::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'on_hand_before' => 15,
            'on_hand_after' => 14,
            'difference' => -1,
            'reason' => 'Forced signal-source failure test.',
            'reported_at' => now()->subMinutes(5)->startOfSecond(),
            'reported_by' => $actor->id,
            'status' => 'open',
        ]);
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            protected function getSignalSource(): ?SignalSource
            {
                return null;
            }
        };
        $service = new MedicationIncidentIntegrationService(
            $signals,
            app(IncidentJourneyService::class),
        );

        try {
            $service->handleControlledDiscrepancy($discrepancy, $actor->id);
            $this->fail('Missing incident journey signal source must abort the owning transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Medication signal source is unavailable for an incident journey.', $exception->getMessage());
        }

        $this->assertNull($discrepancy->fresh()->incident_id);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('medication_dashboard_alerts', 0);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'incident_journey_repair_required'
                && is_int($context['incident_id'])
                && $context['signal_id'] === null
                && $context['signal_type'] === MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY
                && $context['exception'] === \RuntimeException::class,
        );
    }

    public function test_medication_signal_rejects_ambiguous_incident_alert_claims_without_attachment(): void
    {
        [, $client] = $this->medicationFixture();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);
        $first = ControlRoomAlert::factory()->open()->create([
            'client_id' => $client->id,
            'context' => ['incident_id' => $incident->id],
        ]);
        $second = ControlRoomAlert::factory()->open()->create([
            'client_id' => $client->id,
            'context' => ['normalized_data' => ['incident_id' => $incident->id]],
        ]);

        try {
            app(MedicationSignalService::class)->emit(
                MedicationSignalService::TYPE_MISSED_DOSE,
                $client->id,
                'medium',
                'Ambiguous medication incident signal.',
                ['incident_id' => $incident->id],
            );
            $this->fail('Ambiguous incident claims must propagate to the medication owner.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('multiple alerts claim the same incident', $exception->getMessage());
        }

        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertNull(data_get($first->fresh()->context, 'signal_id'));
        $this->assertNull(data_get($second->fresh()->context, 'signal_id'));
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 2);
    }

    public function test_medication_signal_rejects_client_mismatch_without_partial_alert_write(): void
    {
        [, $incidentClient] = $this->medicationFixture();
        $signalClient = Client::factory()->create();
        $incident = ClientIncident::factory()->create(['client_id' => $incidentClient->id]);

        try {
            app(MedicationSignalService::class)->emit(
                MedicationSignalService::TYPE_MISSED_DOSE,
                $signalClient->id,
                'medium',
                'Mismatched medication incident signal.',
                ['incident_id' => $incident->id],
            );
            $this->fail('Client mismatch must propagate to the medication owner.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('does not match the signal client', $exception->getMessage());
        }

        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    /** @return array{User, Client, ClientMedication} */
    private function medicationFixture(): array
    {
        $actor = User::factory()->create();
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'High-risk test medication',
            'controlled_drug' => true,
            'high_risk' => true,
        ]);

        return [$actor, $client, $medication];
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $actor,
        array $attributes = [],
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::create(array_merge([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'given',
            'scheduled_for' => now()->subHour(),
            'administered_at' => now(),
        ], $attributes));
    }

    private function medicationSignalSource(): SignalSource
    {
        return SignalSource::create([
            'name' => 'Medication / eMAR',
            'slug' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
            'capabilities' => ['scheduled_checks', 'event_driven', 'incident_correlation'],
        ]);
    }

    public static function nonCanonicalIncidentIds(): array
    {
        return [
            'zero integer' => [0],
            'negative integer' => [-1],
            'decimal text' => ['1.0'],
            'scientific text' => ['1e0'],
            'signed text' => ['+1'],
            'leading zero text' => ['01'],
            'whitespace text' => [' 1 '],
        ];
    }
}
