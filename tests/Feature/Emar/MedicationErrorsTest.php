<?php

namespace Tests\Feature\Emar;

use App\Domain\Governance\Models\IncidentGovernanceEscalation;
use App\Domain\Governance\Services\IncidentEscalationService;
use App\Jobs\Governance\RecoverIncidentGovernanceEscalationsJob;
use App\Jobs\Governance\RegisterIncidentGovernanceEscalationJob;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use App\Services\Timeline\TimelineEmitter;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The redesigned Medication Errors register resolves the active site's brand
 * colour, surfaces near-miss + trend analytics, captures the NCC-MERP fields
 * (reached-client / harm band / open disclosure), and exposes the missing
 * close-out path (resolved → closed).
 */
class MedicationErrorsTest extends TestCase
{
    use RefreshDatabase;

    private function seedErrors(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'medications.administer.correct', 'clients.update']);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        return compact('user', 'site', 'client');
    }

    public function test_page_serves_brand_colour_and_stats(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedErrors();
        MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'omission', 'severity' => 'near_miss', 'description' => 'Dose missed but caught.',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/errors?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MedicationErrors')
                ->where('site_brand_colour', '#5E35B1')
                ->has('errors', 1)
                ->where('errors.0.ref', MedicationError::query()->first()->reference_number)
                ->has('stats.trend', 8)
                ->has('stats.by_severity')
                ->where('stats.near_miss', 1)
            );
    }

    public function test_store_persists_ncc_merp_fields(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post('/emar/errors', [
                'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate',
                'description' => 'Double dose given.', 'reached_client' => 'yes', 'open_disclosure' => 'pending',
            ])
            ->assertSessionHasNoErrors();

        $error = MedicationError::query()->firstOrFail();
        $this->assertSame('yes', $error->reached_client);
        $this->assertSame('pending', $error->open_disclosure);
    }

    public function test_direct_incident_creation_projects_timeline_before_outer_commit_and_escalates_governance_once_after_commit(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        DB::beginTransaction();

        $this->actingAs($user)
            ->post('/emar/errors', $this->majorIncidentPayload($client))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $timelineCountBeforeCommit = TimelineEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->count();
        $governanceCountBeforeCommit = IncidentGovernanceEscalation::query()
            ->where('client_incident_id', $incident->id)
            ->count();

        DB::commit();

        $this->assertSame(1, $timelineCountBeforeCommit);
        $this->assertSame(0, $governanceCountBeforeCommit);
        $this->assertSame(
            1,
            IncidentGovernanceEscalation::query()
                ->where('client_incident_id', $incident->id)
                ->count(),
        );

        app(TimelineEmitter::class)->project($incident->fresh());
        app(IncidentEscalationService::class)->escalateClientIncident($incident->fresh());

        $this->assertSame(
            1,
            TimelineEvent::query()
                ->where('source_type', ClientIncident::class)
                ->where('source_id', $incident->id)
                ->count(),
        );
        $this->assertSame(
            1,
            IncidentGovernanceEscalation::query()
                ->where('client_incident_id', $incident->id)
                ->count(),
        );
    }

    public function test_governance_job_failure_does_not_fail_the_committed_request_and_reconciliation_recovers_once(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $failingGovernance = new class extends IncidentEscalationService
        {
            public int $boundaryCalls = 0;

            public int $legacyCalls = 0;

            public function escalateClientIncident(
                ClientIncident $incident,
                ?int $riskId = null,
                ?string $reasonOverride = null,
            ): ?IncidentGovernanceEscalation {
                $this->legacyCalls++;

                return null;
            }

            public function escalateClientIncidentOrFail(
                ClientIncident $incident,
                ?int $riskId = null,
                ?string $reasonOverride = null,
            ): ?IncidentGovernanceEscalation {
                $this->boundaryCalls++;

                throw new \RuntimeException("Governance escalation failed for incident {$incident->id}");
            }
        };
        $this->app->instance(IncidentEscalationService::class, $failingGovernance);
        DB::beginTransaction();

        $this->actingAs($user)
            ->post('/emar/errors', $this->majorIncidentPayload($client))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $commitException = null;

        try {
            DB::commit();
        } catch (\Throwable $caught) {
            $commitException = $caught;
        }

        $this->assertNull($commitException, 'A synchronous queue failure after commit must not fail the request.');
        $this->assertSame(1, $failingGovernance->boundaryCalls);
        $this->assertSame(0, $failingGovernance->legacyCalls);
        $this->assertDatabaseHas('client_incidents', ['id' => $incident->id]);
        $this->assertDatabaseCount('incident_governance_escalations', 0);

        $registration = new RegisterIncidentGovernanceEscalationJob($incident->id);
        $this->assertInstanceOf(ShouldBeUnique::class, $registration);
        $this->assertSame((string) $incident->id, $registration->uniqueId());
        $jobException = null;

        try {
            $registration->handle($failingGovernance);
        } catch (\Throwable $caught) {
            $jobException = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $jobException);
        $this->assertStringContainsString(
            "Governance escalation failed for incident {$incident->id}",
            $jobException->getMessage(),
        );
        $this->assertSame(2, $failingGovernance->boundaryCalls);
        $this->assertSame(0, $failingGovernance->legacyCalls);

        $this->app->forgetInstance(IncidentEscalationService::class);
        app(RecoverIncidentGovernanceEscalationsJob::class)->handle();
        app(RecoverIncidentGovernanceEscalationsJob::class)->handle();

        $this->assertSame(
            1,
            IncidentGovernanceEscalation::query()
                ->where('client_incident_id', $incident->id)
                ->count(),
        );
    }

    public function test_direct_incident_timeline_and_governance_are_discarded_with_the_outer_transaction(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        DB::beginTransaction();

        $this->actingAs($user)
            ->post('/emar/errors', $this->majorIncidentPayload($client))
            ->assertRedirect();

        $incident = ClientIncident::query()->sole();
        $timelineCountInsideTransaction = TimelineEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->count();
        $governanceCountInsideTransaction = IncidentGovernanceEscalation::query()
            ->where('client_incident_id', $incident->id)
            ->count();

        DB::rollBack();

        $this->assertSame(1, $timelineCountInsideTransaction);
        $this->assertSame(0, $governanceCountInsideTransaction);
        $this->assertNoMedicationErrorJourneyRecords();
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('incident_governance_escalations', 0);
    }

    public function test_required_medication_error_delivery_fails_closed_when_a_new_signal_is_suppressed(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        MaintenanceWindow::query()->create([
            'name' => 'Suppress all operational signals',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $this->withoutExceptionHandling();
        $exception = null;

        try {
            $this->actingAs($user)->post('/emar/errors', $this->majorIncidentPayload($client));
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertSame(
            'App\\Exceptions\\MedicationSignalDeliveryException',
            $exception::class,
        );
        $this->assertSame('suppressed', $exception->reason());
        $this->assertSame(MedicationSignalService::TYPE_ERROR, $exception->signalType());
        $this->assertIsInt($exception->signalId());
        $this->assertNoMedicationErrorJourneyRecords();
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('incident_governance_escalations', 0);
    }

    public function test_required_delivery_rejects_a_retry_of_an_existing_suppressed_signal(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'Existing suppressed delivery must remain fail closed.',
            'status' => 'reported',
            'reported_by' => $user->id,
            'reported_at' => now(),
        ]);
        $source = SignalSource::query()->create([
            'name' => 'Medication / eMAR',
            'slug' => 'medication',
            'vendor' => 'internal',
            'status' => 'active',
            'config' => [],
            'capabilities' => ['event_driven'],
        ]);
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            public function idempotencyKey(string $type, int $clientId, array $context): string
            {
                return $this->buildIdempotencyKey($type, $clientId, $context);
            }
        };
        $signal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => MedicationSignalService::TYPE_ERROR,
            'idempotency_key' => $signals->idempotencyKey(
                MedicationSignalService::TYPE_ERROR,
                $client->id,
                ['medication_error_id' => $error->id],
            ),
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'normalized_data' => [
                'medication_error_id' => $error->id,
                'client_id' => $client->id,
            ],
            'status' => 'suppressed',
            'processing_notes' => 'In maintenance window',
            'processed_at' => now(),
        ]);
        $exception = null;

        try {
            $signals->emitError($error);
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertSame(
            'App\\Exceptions\\MedicationSignalDeliveryException',
            $exception::class,
        );
        $this->assertSame($signal->id, $exception->signalId());
        $this->assertSame('suppressed', $exception->reason());
        $this->assertSame('suppressed', $signal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_store_rolls_back_the_official_journey_when_signal_source_fails_and_retry_is_exact(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            protected function getSignalSource(): ?SignalSource
            {
                return null;
            }
        };
        $this->app->instance(MedicationSignalService::class, $signals);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/emar/errors', $this->majorIncidentPayload($client));
            $this->fail('Signal source failure must abort the medication error request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Medication signal source is unavailable for an incident journey.', $exception->getMessage());
        }

        $this->assertNoMedicationErrorJourneyRecords();
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('incident_governance_escalations', 0);

        $this->app->forgetInstance(MedicationSignalService::class);
        $response = $this->actingAs($user)->post('/emar/errors', $this->majorIncidentPayload($client));

        $response->assertRedirect();
        $this->assertOneCompleteMedicationErrorJourney();
    }

    public function test_store_rolls_back_the_official_journey_when_signal_processing_fails_and_retry_is_exact(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $processor = $this->partialMock(SignalProcessingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('process')
                ->once()
                ->andThrow(new \RuntimeException('Forced medication error processing failure'));
        });
        $this->app->instance(
            MedicationSignalService::class,
            new MedicationSignalService($processor),
        );
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/emar/errors', $this->majorIncidentPayload($client));
            $this->fail('Signal processing failure must abort the medication error request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced medication error processing failure', $exception->getMessage());
        }

        $this->assertNoMedicationErrorJourneyRecords();
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertDatabaseCount('incident_governance_escalations', 0);

        $this->app->forgetInstance(MedicationSignalService::class);
        $this->app->forgetInstance(SignalProcessingService::class);
        $response = $this->actingAs($user)->post('/emar/errors', $this->majorIncidentPayload($client));

        $response->assertRedirect();
        $this->assertOneCompleteMedicationErrorJourney();
    }

    public function test_major_error_without_incident_rolls_back_when_operational_signal_source_is_unavailable(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        Log::spy();
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            protected function getSignalSource(): ?SignalSource
            {
                return null;
            }
        };
        $this->app->instance(MedicationSignalService::class, $signals);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/emar/errors', $this->majorOperationalPayload($client));
            $this->fail('A required event-backed medication alert must fail closed when its source is unavailable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Medication signal source is unavailable for required operational delivery.',
                $exception->getMessage(),
            );
        }

        $this->assertNoMedicationErrorJourneyRecords();
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'medication_operational_alert_delivery_failed'
                && $context['signal_type'] === MedicationSignalService::TYPE_ERROR
                && is_int($context['medication_error_id']),
        );
    }

    public function test_major_error_without_incident_rolls_back_when_operational_signal_processing_fails(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        Log::spy();
        $processor = $this->partialMock(SignalProcessingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('process')
                ->once()
                ->andThrow(new \RuntimeException('Forced unlinked medication error processing failure'));
        });
        $this->app->instance(MedicationSignalService::class, new MedicationSignalService($processor));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/emar/errors', $this->majorOperationalPayload($client));
            $this->fail('A required event-backed medication alert must roll back its error when processing fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced unlinked medication error processing failure', $exception->getMessage());
        }

        $this->assertNoMedicationErrorJourneyRecords();
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'medication_operational_alert_delivery_failed'
                && $context['signal_type'] === MedicationSignalService::TYPE_ERROR,
        );
    }

    public function test_major_error_without_incident_still_creates_its_required_operational_alert(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();

        $this->actingAs($user)
            ->post('/emar/errors', $this->majorOperationalPayload($client))
            ->assertRedirect();

        $error = MedicationError::query()->sole();
        $signal = Signal::query()->sole();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertNull($error->client_incident_id);
        $this->assertSame($error->id, data_get($signal->normalized_data, 'medication_error_id'));
        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertSame('high', $alert->severity);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
    }

    public function test_store_retries_the_outer_transaction_after_a_deadlock(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $attempts = 0;
        $signals = \Mockery::mock(MedicationSignalService::class);
        $signals->shouldReceive('emitError')
            ->twice()
            ->andReturnUsing(function () use (&$attempts): void {
                $attempts++;
                if ($attempts === 1) {
                    throw new QueryException(
                        'mysql',
                        'select 1',
                        [],
                        new \PDOException(
                            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
                            40001,
                        ),
                    );
                }
            });
        $this->app->instance(MedicationSignalService::class, $signals);
        DB::commit();

        $this->actingAs($user)
            ->post('/emar/errors', [
                'client_id' => $client->id,
                'error_type' => 'wrong_dose',
                'severity' => 'moderate',
                'description' => 'Retry the complete reporting transaction.',
            ])
            ->assertRedirect();

        $this->assertSame(2, $attempts);
        $this->assertDatabaseCount('medication_errors', 1);
    }

    public function test_close_out_marks_closed(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate', 'description' => 'x',
            'status' => 'resolved', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/close", ['close_note' => 'Learning embedded.'])
            ->assertSessionHasNoErrors();

        $error->refresh();
        $this->assertSame('closed', $error->status);
        $this->assertNotNull($error->closed_at);
        $this->assertSame($user->id, $error->closed_by);
    }

    public function test_close_rejects_non_resolved_error(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate', 'description' => 'x',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/close", ['close_note' => 'too soon'])
            ->assertSessionHasErrors('status');

        $this->assertSame('reported', $error->refresh()->status);
    }

    public function test_link_incident_creates_and_links_incident(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'critical', 'description' => 'Wrong strength dispensed.',
            'immediate_action' => 'Clinical review completed.',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);
        $this->assertNull($error->client_incident_id);
        $this->assertSame(0, ClientIncident::query()->count());

        $response = $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/link-incident");

        $error->refresh();
        $this->assertNotNull($error->client_incident_id, 'The error should be linked to the new incident.');
        $response->assertRedirect(route('incidents.show', $error->client_incident_id));

        $incident = ClientIncident::query()->findOrFail($error->client_incident_id);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame($client->site_id, $incident->site_id);
        $this->assertSame('medication_error', $incident->type);
        $this->assertSame($user->id, (int) $incident->reported_by);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('Clinical review completed.', $incident->immediate_action_taken);
    }

    public function test_link_incident_is_idempotent_when_already_linked(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $this->actingAs($user)
            ->post('/emar/errors', $this->majorOperationalPayload($client))
            ->assertRedirect();
        $error = MedicationError::query()->sole();
        $originalAlert = ControlRoomAlert::query()->sole();
        $originalSignal = Signal::query()->sole();
        $this->assertNull($error->client_incident_id);

        DB::enableQueryLog();

        // First call creates + links the incident.
        $this->actingAs($user)->from('/emar/errors')->post("/emar/errors/{$error->id}/link-incident");
        $firstIncidentId = $error->refresh()->client_incident_id;
        $this->assertNotNull($firstIncidentId);
        $this->assertSame(1, ClientIncident::query()->count());

        // Second call is idempotent: jumps to the existing incident, creates none.
        $response = $this->actingAs($user)->from('/emar/errors')->post("/emar/errors/{$error->id}/link-incident");

        $this->assertSame($firstIncidentId, $error->refresh()->client_incident_id);
        $this->assertSame(1, ClientIncident::query()->count(), 'A second link must not create a duplicate incident.');
        $response->assertRedirect(route('incidents.show', $firstIncidentId));

        $incident = ClientIncident::query()->sole();
        $event = HsEvent::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $signal = Signal::query()->sole();
        $lockQuery = collect(DB::getQueryLog())->first(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'medication_errors')
                && str_contains(strtolower($query['query']), 'for update'),
        );

        $this->assertNotNull($lockQuery, 'The medication error row must be the transaction lock anchor.');
        $this->assertSame($incident->id, $error->client_incident_id);
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($originalAlert->id, $alert->id);
        $this->assertSame($originalSignal->id, $signal->id);
        $this->assertSame('medication', $alert->source);
        $this->assertSame($error->id, data_get($alert->context, 'normalized_data.medication_error_id'));
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($incident->id, data_get($signal->normalized_data, 'incident_id'));
        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    public function test_link_incident_reuses_processed_correlated_only_signal_and_promotes_canonical_links(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $this->actingAs($user)
            ->post('/emar/errors', $this->majorOperationalPayload($client))
            ->assertRedirect();
        $error = MedicationError::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $signal = Signal::query()->sole();
        $signal->forceFill([
            'status' => 'processed',
            'alert_id' => null,
            'correlated_alert_id' => $alert->id,
            'processed_at' => now(),
            'processing_notes' => 'Correlated with existing alert',
        ])->save();

        $this->assertNull($signal->fresh()->alert_id);
        $this->assertSame($alert->id, $signal->fresh()->correlated_alert_id);

        $this->actingAs($user)
            ->post("/emar/errors/{$error->id}/link-incident")
            ->assertRedirect();

        $error->refresh();
        $signal->refresh();
        $alert->refresh();
        $incident = ClientIncident::query()->findOrFail($error->client_incident_id);
        $event = HsEvent::query()->findOrFail($incident->hs_event_id);

        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertSame($alert->id, $signal->correlated_alert_id);
        $this->assertSame($incident->id, data_get($signal->normalized_data, 'incident_id'));
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    public function test_link_incident_reuses_and_promotes_the_existing_operational_journey_after_severity_edits(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();

        foreach ([
            'moderate' => ['incident' => 'medium', 'operational' => 'high'],
            'critical' => ['incident' => 'critical', 'operational' => 'critical'],
        ] as $editedSeverity => $expected) {
            $this->actingAs($user)
                ->post('/emar/errors', $this->majorOperationalPayload($client))
                ->assertRedirect();

            $error = MedicationError::query()->latest('id')->firstOrFail();
            $signal = Signal::query()
                ->where('normalized_data->medication_error_id', $error->id)
                ->sole();
            $alert = ControlRoomAlert::query()->findOrFail($signal->alert_id);
            $error->update(['severity' => $editedSeverity]);

            $this->actingAs($user)
                ->post("/emar/errors/{$error->id}/link-incident")
                ->assertRedirect();

            $incident = ClientIncident::query()->findOrFail($error->fresh()->client_incident_id);
            $event = HsEvent::query()->findOrFail($incident->hs_event_id);

            $this->assertSame($expected['incident'], $incident->severity);
            $this->assertSame($alert->id, $incident->control_room_alert_id);
            $this->assertSame($alert->id, $event->control_room_alert_id);
            $this->assertSame($expected['operational'], $alert->fresh()->severity);
            $this->assertSame($expected['operational'], $event->severity);
            $this->assertSame($incident->id, data_get($signal->fresh()->normalized_data, 'incident_id'));
            $this->assertSame($incident->id, data_get($alert->fresh()->context, 'incident_id'));
        }

        $this->assertDatabaseCount('medication_errors', 2);
        $this->assertDatabaseCount('client_incidents', 2);
        $this->assertDatabaseCount('hs_events', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertDatabaseCount('control_room_signals', 2);
    }

    public function test_link_incident_rolls_back_without_orphaning_a_journey_when_canonical_attachment_fails(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $this->actingAs($user)
            ->post('/emar/errors', $this->majorOperationalPayload($client))
            ->assertRedirect();
        $error = MedicationError::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $signal = Signal::query()->sole();
        $this->partialMock(IncidentJourneyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('attachAlertToIncident')
                ->once()
                ->andThrow(new \RuntimeException('Forced link journey attachment failure'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post("/emar/errors/{$error->id}/link-incident");
            $this->fail('Canonical attachment failure must roll back the linked incident.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced link journey attachment failure', $exception->getMessage());
        }

        $this->assertNull($error->fresh()->client_incident_id);
        $this->assertSame($alert->id, ControlRoomAlert::query()->sole()->id);
        $this->assertSame($signal->id, Signal::query()->sole()->id);
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
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

    private function majorIncidentPayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'A major medication error requiring an official incident.',
            'immediate_action' => 'Clinical review completed.',
            'create_incident' => true,
        ];
    }

    private function majorOperationalPayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'A major medication error requiring an operational alert.',
            'immediate_action' => 'Clinical review completed.',
            'create_incident' => false,
        ];
    }

    private function assertNoMedicationErrorJourneyRecords(): void
    {
        $this->assertDatabaseCount('medication_errors', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
    }

    private function assertOneCompleteMedicationErrorJourney(): void
    {
        $error = MedicationError::query()->sole();
        $incident = ClientIncident::query()->sole();
        $event = HsEvent::query()->sole();
        $alert = ControlRoomAlert::query()->sole();
        $signal = Signal::query()->sole();

        $this->assertSame($incident->id, $error->client_incident_id);
        $this->assertMatchesRegularExpression(
            '/^INC-\d{4}-\d{4}$/',
            (string) $incident->reference_number,
            'Every medication-created incident must receive an official incident reference.',
        );
        $this->assertSame($event->id, $incident->hs_event_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame($alert->id, $event->control_room_alert_id);
        $this->assertSame($incident->id, data_get($alert->context, 'incident_id'));
        $this->assertSame($alert->id, $signal->alert_id);
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('hs_events', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
    }
}
