<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchIncidentLifecycleSignalOutbox;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\IncidentLifecycleSignal;
use App\Models\IncidentLifecycleSignalOutbox;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncidentAlertLifecycleSignalTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $admin;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->coordinator = $this->userWithRole('coordinator');
        $this->admin = $this->userWithRole('admin');
        $this->assignToSite($this->coordinator, $this->site);
        $this->app->instance(
            NotificationService::class,
            \Mockery::mock(NotificationService::class)->shouldIgnoreMissing(),
        );
        Bus::fake([DispatchIncidentLifecycleSignalOutbox::class]);
    }

    #[DataProvider('incidentOrigins')]
    public function test_reopen_signal_creates_one_active_alert_for_every_incident_origin(string $origin): void
    {
        [$incident, $hsEvent] = $this->closedIncident($origin);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => "New evidence from {$origin} origin.",
            ])
            ->assertRedirect();

        $outbox = IncidentLifecycleSignalOutbox::query()->sole();
        (new DispatchIncidentLifecycleSignalOutbox($outbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));

        $incident->refresh();
        $alert = $incident->controlRoomAlert()->sole();
        $signal = IncidentLifecycleSignal::query()->sole();
        $this->assertSame('reviewed', $incident->status);
        $this->assertSame($origin, $incident->source);
        $this->assertSame($origin, $signal->incident_source);
        $this->assertSame(IncidentLifecycleSignal::TYPE_REOPENED, $signal->signal_type);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertSame($this->site->id, $alert->site_id);
        $this->assertSame($this->client->id, $alert->client_id);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertSame($alert->id, $outbox->fresh()->resulting_alert_id);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_repeated_reopen_and_delivery_replay_have_one_signal_alert_and_effect(): void
    {
        [$incident] = $this->closedIncident('manual');

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New evidence requires renewed response.',
            ])
            ->assertRedirect();

        $outbox = IncidentLifecycleSignalOutbox::query()->sole();
        $job = new DispatchIncidentLifecycleSignalOutbox($outbox->id);
        $job->handle(app(ControlRoomAlertLifecycleService::class));
        $job->handle(app(ControlRoomAlertLifecycleService::class));

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'Duplicate stale request.',
            ])
            ->assertForbidden();

        $this->assertSame(1, IncidentLifecycleSignal::query()->count());
        $this->assertSame(1, IncidentLifecycleSignalOutbox::query()->count());
        $this->assertSame(1, ControlRoomAlert::query()->count());
        $this->assertSame(1, $outbox->fresh()->attempts);
    }

    public function test_lifecycle_signal_remains_immutable_source_evidence(): void
    {
        [$incident] = $this->closedIncident('sensor');

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'The source record must remain independently auditable.',
            ])
            ->assertRedirect();

        $signal = IncidentLifecycleSignal::query()->sole();
        foreach ([
            fn () => $signal->forceFill(['incident_source' => 'forged'])->save(),
            fn () => $signal->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Lifecycle source evidence must reject mutation and deletion.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('Incident lifecycle signal', $exception->getMessage());
            }
        }

        foreach ([
            fn () => DB::table('incident_lifecycle_signals')
                ->where('id', $signal->id)
                ->update(['incident_source' => 'forged']),
            fn () => DB::table('incident_lifecycle_signals')
                ->where('id', $signal->id)
                ->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('The database must reject lifecycle source evidence mutation and deletion.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only and immutable', $exception->getMessage());
            }
        }

        $this->assertSame('sensor', $signal->fresh()->incident_source);
        $this->assertDatabaseHas('incident_lifecycle_signals', ['id' => $signal->id]);
    }

    public function test_delayed_close_is_superseded_by_newer_reopen_without_silencing_the_alert(): void
    {
        [$incident, , $alert] = $this->terminalJourney('control_room');

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'Initial new evidence'])
            ->assertRedirect();
        $initialOutbox = IncidentLifecycleSignalOutbox::query()->sole();
        (new DispatchIncidentLifecycleSignalOutbox($initialOutbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", ['closed_outcome' => 'Initially complete'])
            ->assertRedirect();
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'New evidence arrived'])
            ->assertRedirect();

        $signals = IncidentLifecycleSignal::query()->orderBy('sequence')->get();
        $this->assertCount(3, $signals);
        $closeOutbox = $signals[1]->outbox;
        $reopenOutbox = $signals[2]->outbox;

        (new DispatchIncidentLifecycleSignalOutbox($closeOutbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));
        (new DispatchIncidentLifecycleSignalOutbox($reopenOutbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));

        $this->assertSame('superseded', $closeOutbox->fresh()->status);
        $this->assertSame('sent', $reopenOutbox->fresh()->status);
        $this->assertSame('reviewed', $incident->fresh()->status);
        $this->assertFalse($alert->fresh()->isTerminal());
        $this->assertNull($alert->fresh()->resolved_at);
        $this->assertSame(1, ControlRoomAlert::query()->count());
    }

    public function test_resolve_failure_is_durable_and_replay_applies_one_atomic_control_room_transition(): void
    {
        [$incident, , $alert] = $this->terminalJourney('control_room');
        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'Initial new evidence'])
            ->assertRedirect();
        $reopenOutbox = IncidentLifecycleSignalOutbox::query()->sole();
        (new DispatchIncidentLifecycleSignalOutbox($reopenOutbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));
        $task = AlertTask::query()->create([
            'alert_id' => $alert->id,
            'title' => 'Complete resident welfare follow-up',
            'status' => AlertTask::STATUS_OPEN,
            'priority' => 'high',
            'created_by_user_id' => $this->coordinator->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/close", ['closed_outcome' => 'Incident review complete'])
            ->assertRedirect();

        $outbox = IncidentLifecycleSignalOutbox::query()->latest('id')->firstOrFail();
        try {
            (new DispatchIncidentLifecycleSignalOutbox($outbox->id))
                ->handle(app(ControlRoomAlertLifecycleService::class));
            $this->fail('The active operational task must fail the alert resolution gate.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Complete, cancel with a reason, or transfer', $exception->getMessage());
        }

        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertNull($outbox->fresh()->resulting_alert_id);

        $task->forceFill([
            'status' => AlertTask::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
        app(SafetySignalDeliveryRecoveryService::class)->retry('incident', $outbox->id);
        (new DispatchIncidentLifecycleSignalOutbox($outbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->status);
        $this->assertSame('incident_closed', $alert->resolution_code);
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertCount(1, data_get($alert->context, 'resolution_history', []));
        $this->assertSame(
            $outbox->signal->id,
            data_get($alert->context, 'resolution.incident_lifecycle_signal_id'),
        );
        $this->assertSame(
            $this->coordinator->id,
            data_get($alert->context, 'resolution.incident_lifecycle_actor_user_id'),
        );
        $this->assertSame(
            HsEvent::STATUS_CLOSED,
            data_get($alert->context, 'resolution.hs_event_status'),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'controlRoom.alert.resolve')
                ->where('auditable_id', $alert->id)
                ->count(),
        );
    }

    public function test_reopen_reactivates_terminal_alert_with_new_sla_cycle_and_closed_hs_history(): void
    {
        [$incident, $hsEvent, $alert] = $this->terminalJourney('sensor');
        $sla = $alert->sla;

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'New witness information changes current risk.',
            ])
            ->assertRedirect();
        $outbox = IncidentLifecycleSignalOutbox::query()->sole();
        (new DispatchIncidentLifecycleSignalOutbox($outbox->id))
            ->handle(app(ControlRoomAlertLifecycleService::class));

        $alert->refresh();
        $sla->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertSame(2, $sla->cycle_number);
        $this->assertCount(1, $sla->cycle_history ?? []);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->fresh()->status);
        $this->assertSame($hsEvent->id, data_get($alert->context, 'operational_reopen_history.0.hs_event_id'));
        $this->assertSame('sensor', data_get($alert->context, 'operational_reopen_history.0.incident_origin'));
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'controlRoom.alert.reopenFromIncidentSignal')
                ->where('auditable_id', $alert->id)
                ->count(),
        );
    }

    public function test_site_denial_discloses_no_foreign_alert_and_explicit_global_bypass_is_separate(): void
    {
        $remoteSite = Site::factory()->create();
        $remoteClient = Client::factory()->create(['site_id' => $remoteSite->id]);
        [$incident] = $this->closedIncident('manual', $remoteSite, $remoteClient);

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'Forbidden remote reopen'])
            ->assertForbidden();

        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertSame(0, IncidentLifecycleSignal::query()->count());
        $this->assertSame(0, IncidentLifecycleSignalOutbox::query()->count());

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'Global governance review'])
            ->assertRedirect();

        $this->assertSame('reviewed', $incident->fresh()->status);
        $this->assertSame($remoteSite->id, IncidentLifecycleSignal::query()->sole()->site_id);
    }

    public function test_forged_foreign_alert_link_is_concealed_and_rolls_back_before_signal_emission(): void
    {
        [$incident] = $this->closedIncident('manual');
        $remoteSite = Site::factory()->create();
        $remoteClient = Client::factory()->create(['site_id' => $remoteSite->id]);
        $foreignAlert = ControlRoomAlert::factory()->closed()->create([
            'site_id' => $remoteSite->id,
            'client_id' => $remoteClient->id,
        ]);
        $incident->forceFill(['control_room_alert_id' => $foreignAlert->id])->saveQuietly();

        $this->actingAs($this->coordinator)
            ->post("/incidents/{$incident->id}/reopen", ['reopened_reason' => 'Forged linked alert'])
            ->assertNotFound();

        $this->assertSame('closed', $incident->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $foreignAlert->fresh()->status);
        $this->assertSame(0, IncidentLifecycleSignal::query()->count());
        $this->assertSame(0, IncidentLifecycleSignalOutbox::query()->count());
    }

    public function test_scheduled_recovery_reconciles_an_event_skipping_origin_into_one_outbox(): void
    {
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->coordinator->id,
            'source' => 'automated',
            'status' => 'closed',
            'submitted_at' => now()->subDays(3),
            'reviewed_by' => $this->coordinator->id,
            'reviewed_at' => now()->subDays(2),
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Event-skipping close',
        ]));
        $this->attachClosedHsEvent($incident);
        $journey = app(IncidentJourneyService::class)
            ->ensureForSubmittedIncident($incident, $this->coordinator);

        $result = app(SafetySignalDeliveryRecoveryService::class)->recover();

        $this->assertSame(1, $result['reconciled']['incident']);
        $this->assertSame(1, $result['queued']['incident']);
        $this->assertSame(1, IncidentLifecycleSignal::query()->count());
        $this->assertSame('pending', IncidentLifecycleSignalOutbox::query()->sole()->status);

        app(SafetySignalDeliveryRecoveryService::class)->recover();
        $this->assertSame(1, IncidentLifecycleSignal::query()->count());
        $this->assertSame(1, IncidentLifecycleSignalOutbox::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function incidentOrigins(): array
    {
        return [
            'manual' => ['manual'],
            'control room' => ['control_room'],
            'sensor' => ['sensor'],
            'automated' => ['automated'],
        ];
    }

    /** @return array{0: ClientIncident, 1: HsEvent} */
    private function closedIncident(
        string $origin,
        ?Site $site = null,
        ?Client $client = null,
    ): array {
        $site ??= $this->site;
        $client ??= $this->client;
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'reported_by' => $this->coordinator->id,
            'source' => $origin,
            'status' => 'closed',
            'submitted_at' => now()->subDays(3),
            'reviewed_by' => $this->coordinator->id,
            'reviewed_at' => now()->subDays(2),
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Previously complete',
        ]));
        $this->attachClosedHsEvent($incident);
        $journey = app(IncidentJourneyService::class)
            ->ensureForSubmittedIncident($incident, $this->coordinator);

        return [$journey->incident->fresh(), $journey->hsEvent->fresh()];
    }

    /** @return array{0: ClientIncident, 1: HsEvent, 2: ControlRoomAlert} */
    private function terminalJourney(string $origin): array
    {
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->highSeverity()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'reported_by' => $this->coordinator->id,
            'source' => $origin,
            'status' => 'closed',
            'submitted_at' => now()->subDays(3),
            'reviewed_by' => $this->coordinator->id,
            'reviewed_at' => now()->subDays(2),
            'closed_by' => $this->coordinator->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Previously complete',
            'immediate_action_taken' => 'Resident assessed and immediate hazards controlled.',
        ]));
        $hsEvent = $this->attachClosedHsEvent($incident, investigationRequired: true);
        HsInvestigation::query()->create([
            'hs_event_id' => $hsEvent->id,
            'reference_number' => HsInvestigation::generateReferenceNumber(),
            'investigation_type' => 'standard',
            'status' => HsInvestigation::STATUS_COMPLETED,
            'completed_at' => now()->subDays(2),
        ]);
        $journey = app(IncidentJourneyService::class)
            ->ensureForSubmittedIncident($incident, $this->coordinator);
        $journey->alert->forceFill([
            'status' => ControlRoomAlert::STATUS_CLOSED,
            'resolved_at' => now()->subDays(2),
            'resolved_by_user_id' => $this->coordinator->id,
            'resolution_code' => 'initial_response_complete',
            'closed_at' => now()->subDay(),
            'closed_by_user_id' => $this->coordinator->id,
        ])->saveQuietly();
        $definition = SlaDefinition::query()->create([
            'name' => 'Incident lifecycle signal SLA',
            'code' => 'incident-lifecycle-'.$journey->alert->id,
            'alert_types' => [$journey->alert->alert_type],
            'severities' => [$journey->alert->severity],
            'sources' => [$journey->alert->source],
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $sla = $journey->alert->sla
            ?? AlertSla::createFromDefinition($journey->alert, $definition, now()->subDays(3));
        $sla->forceFill([
            'acknowledged_at' => now()->subDays(3)->addMinutes(2),
            'responded_at' => now()->subDays(3)->addMinutes(5),
            'resolved_at' => now()->subDays(2),
        ])->save();

        return [$journey->incident->fresh(), $journey->hsEvent->fresh(), $journey->alert->fresh()];
    }

    private function attachClosedHsEvent(
        ClientIncident $incident,
        bool $investigationRequired = false,
    ): HsEvent {
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()
            ->forClientIncident($incident)
            ->closed()
            ->handoverAccepted($this->coordinator, $this->coordinator)
            ->worksafeNotNotifiable($this->coordinator)
            ->create([
                'site_id' => $incident->site_id,
                'client_id' => $incident->client_id,
                'staff_id' => $incident->reported_by,
                'severity' => $incident->severity,
                'investigation_required' => $investigationRequired,
                'created_by' => $this->coordinator->id,
                'closed_by' => $this->coordinator->id,
                'closed_at' => now()->subDay(),
                'closure_summary' => 'Closed H&S history preserved before incident lifecycle replay.',
            ]));
        $incident->forceFill(['hs_event_id' => $event->id])->saveQuietly();

        return $event;
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function assignToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }
}
