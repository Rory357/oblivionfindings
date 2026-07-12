<?php

namespace Tests\Feature\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\Shift as ControlRoomShift;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRecommendationDisposition;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IncidentJourneySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_journey_schema_is_explicit(): void
    {
        $this->assertTrue(
            Schema::hasColumns('client_incidents', ['site_id', 'hs_event_id']),
            'client_incidents is missing the explicit incident journey link columns.'
        );

        $this->assertTrue(Schema::hasColumns('hs_events', [
            'handover_status',
            'owner_user_id',
            'accepted_by_user_id',
            'accepted_at',
            'acceptance_notes',
        ]));

        $this->assertTrue(Schema::hasColumns('control_room_alert_tasks', [
            'transferred_to_hs_corrective_action_id',
            'transferred_at',
            'transferred_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumns('control_room_alert_sla', [
            'cycle_number',
            'cycle_started_at',
            'cycle_history',
            'ended_as',
        ]));

        $this->assertTrue(Schema::hasColumns('control_room_shifts', [
            'handover_status',
            'handover_snapshot',
            'handover_version',
            'handover_prepared_at',
            'handover_accepted_at',
        ]));

        $this->assertFalse(Schema::hasColumn('control_room_shifts', 'incoming_user_id'));

        $this->assertTrue(Schema::hasColumns('hs_recommendation_dispositions', [
            'id',
            'hs_investigation_id',
            'recommendation_index',
            'disposition',
            'reason',
            'hs_corrective_action_id',
            'decided_by_user_id',
            'decided_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_new_journey_records_receive_safe_database_defaults(): void
    {
        $this->assertJourneySchemaAvailable();

        $event = HsEvent::factory()->create();
        $alert = ControlRoomAlert::factory()->create();
        $sla = AlertSla::create(['alert_id' => $alert->id]);
        $shift = ControlRoomShift::create(['starts_at' => now()]);

        $this->assertSame('not_required', $event->fresh()->handover_status);
        $this->assertSame(1, $sla->fresh()->cycle_number);
        $this->assertSame('none', $shift->fresh()->handover_status);
        $this->assertSame(1, $shift->fresh()->handover_version);
    }

    public function test_journey_models_expose_the_approved_constants(): void
    {
        $this->assertJourneySchemaAvailable();

        $this->assertConstantsExist(HsEvent::class, [
            'HANDOVER_NOT_READY',
            'HANDOVER_AWAITING_ACCEPTANCE',
            'HANDOVER_ACCEPTED',
            'HANDOVER_NOT_REQUIRED',
        ]);
        $this->assertSame(
            ['not_ready', 'awaiting_acceptance', 'accepted', 'not_required'],
            [
                HsEvent::HANDOVER_NOT_READY,
                HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
                HsEvent::HANDOVER_ACCEPTED,
                HsEvent::HANDOVER_NOT_REQUIRED,
            ]
        );

        $this->assertConstantsExist(AlertTask::class, [
            'STATUS_OPEN',
            'STATUS_IN_PROGRESS',
            'STATUS_BLOCKED',
            'STATUS_COMPLETED',
            'STATUS_CANCELLED',
            'STATUS_TRANSFERRED',
            'TERMINAL_STATUSES',
        ]);
        $this->assertSame(
            ['completed', 'cancelled', 'transferred'],
            AlertTask::TERMINAL_STATUSES
        );

        $this->assertConstantsExist(ControlRoomShift::class, [
            'HANDOVER_NONE',
            'HANDOVER_PREPARED',
            'HANDOVER_ACCEPTED',
        ]);
        $this->assertSame(
            ['none', 'prepared', 'accepted'],
            [
                ControlRoomShift::HANDOVER_NONE,
                ControlRoomShift::HANDOVER_PREPARED,
                ControlRoomShift::HANDOVER_ACCEPTED,
            ]
        );

        $this->assertTrue(class_exists(HsRecommendationDisposition::class));
        $this->assertConstantsExist(HsRecommendationDisposition::class, [
            'DISPOSITION_CORRECTIVE_ACTION',
            'DISPOSITION_ACCEPTED_RISK',
            'DISPOSITION_DUPLICATE',
            'DISPOSITION_NO_ACTION',
        ]);
        $this->assertSame(
            ['corrective_action', 'accepted_risk', 'duplicate', 'no_action'],
            [
                HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION,
                HsRecommendationDisposition::DISPOSITION_ACCEPTED_RISK,
                HsRecommendationDisposition::DISPOSITION_DUPLICATE,
                HsRecommendationDisposition::DISPOSITION_NO_ACTION,
            ]
        );
    }

    public function test_incident_uses_direct_journey_relationships_before_the_idempotency_fallback(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(method_exists(ClientIncident::class, 'site'));
        $this->assertTrue(method_exists(ClientIncident::class, 'hsEvent'));
        $this->assertTrue(method_exists(HsEvent::class, 'clientIncident'));

        $site = Site::factory()->create();
        $directEvent = HsEvent::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id,
            'hs_event_id' => $directEvent->id,
            'type' => 'injury',
        ]));
        $fallbackEvent = HsEvent::factory()->create([
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                ClientIncident::class,
                $incident->id,
                HsEvent::CATEGORY_INCIDENT
            ),
        ]);

        $this->assertTrue($incident->site->is($site));
        $this->assertTrue($incident->hsEvent->is($directEvent));
        $this->assertTrue($directEvent->clientIncident->is($incident));
        $this->assertTrue($incident->linkedHsEvent()?->is($directEvent));

        DB::table('client_incidents')->where('id', $incident->id)->update(['hs_event_id' => null]);
        $incident->refresh();

        $this->assertTrue($incident->linkedHsEvent()?->is($fallbackEvent));
    }

    public function test_incident_journey_links_are_unique_and_null_when_their_targets_are_deleted(): void
    {
        $this->assertJourneySchemaAvailable();

        $site = Site::factory()->create();
        $event = HsEvent::factory()->create();
        ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id,
            'hs_event_id' => $event->id,
        ]));

        $duplicate = null;
        try {
            ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
                'hs_event_id' => $event->id,
            ]));
        } catch (QueryException $exception) {
            $duplicate = $exception;
        }

        $this->assertDuplicateKeyViolation($duplicate);

        $incident = ClientIncident::query()->where('hs_event_id', $event->id)->firstOrFail();
        $site->forceDelete();
        $event->forceDelete();

        $incident->refresh();
        $this->assertNull($incident->site_id);
        $this->assertNull($incident->hs_event_id);
    }

    public function test_hs_handover_acceptance_fields_are_cast_and_user_links_null_on_delete(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(method_exists(HsEvent::class, 'owner'));
        $this->assertTrue(method_exists(HsEvent::class, 'acceptedBy'));

        $owner = User::factory()->create();
        $acceptor = User::factory()->create();
        $event = HsEvent::factory()->create([
            'handover_status' => 'accepted',
            'owner_user_id' => $owner->id,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => now(),
            'acceptance_notes' => 'Accepted with a clear owner.',
        ])->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $event->accepted_at);
        $this->assertTrue($event->owner->is($owner));
        $this->assertTrue($event->acceptedBy->is($acceptor));

        $owner->delete();
        $acceptor->delete();

        $event->refresh();
        $this->assertNull($event->owner_user_id);
        $this->assertNull($event->accepted_by_user_id);
    }

    public function test_alert_task_transfer_fields_are_cast_and_relationships_null_on_delete(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(method_exists(AlertTask::class, 'transferredCorrectiveAction'));
        $this->assertTrue(method_exists(AlertTask::class, 'transferredBy'));

        $alert = ControlRoomAlert::factory()->create();
        $action = HsCorrectiveAction::factory()->create();
        $actor = User::factory()->create();
        $task = AlertTask::create([
            'alert_id' => $alert->id,
            'title' => 'Transfer investigation action',
            'status' => 'transferred',
            'transferred_to_hs_corrective_action_id' => $action->id,
            'transferred_at' => now(),
            'transferred_by_user_id' => $actor->id,
        ])->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $task->transferred_at);
        $this->assertTrue($task->transferredCorrectiveAction->is($action));
        $this->assertTrue($task->transferredBy->is($actor));

        $action->forceDelete();
        $actor->delete();

        $task->refresh();
        $this->assertNull($task->transferred_to_hs_corrective_action_id);
        $this->assertNull($task->transferred_by_user_id);
    }

    public function test_alert_sla_cycle_history_fields_are_fillable_and_cast(): void
    {
        $this->assertJourneySchemaAvailable();

        $alert = ControlRoomAlert::factory()->create();
        $sla = AlertSla::create([
            'alert_id' => $alert->id,
            'cycle_number' => 3,
            'cycle_started_at' => now(),
            'cycle_history' => [
                ['cycle_number' => 1, 'ended_as' => 'resolved'],
                ['cycle_number' => 2, 'ended_as' => 'reopened'],
            ],
            'ended_as' => 'reopened',
        ])->fresh();

        $this->assertSame(3, $sla->cycle_number);
        $this->assertInstanceOf(CarbonInterface::class, $sla->cycle_started_at);
        $this->assertSame(1, $sla->cycle_history[0]['cycle_number']);
        $this->assertSame('reopened', $sla->ended_as);
    }

    public function test_control_room_shift_handover_fields_are_fillable_cast_and_keep_the_existing_incoming_lead(): void
    {
        $this->assertJourneySchemaAvailable();

        $incomingLead = User::factory()->create();
        $shift = ControlRoomShift::create([
            'starts_at' => now()->subHours(8),
            'handover_status' => 'accepted',
            'handover_snapshot' => ['open_alert_ids' => [11, 12]],
            'handover_version' => 4,
            'handover_prepared_at' => now()->subMinutes(10),
            'handover_accepted_at' => now(),
            'handed_over_to_user_id' => $incomingLead->id,
        ])->fresh();

        $this->assertSame('accepted', $shift->handover_status);
        $this->assertSame([11, 12], $shift->handover_snapshot['open_alert_ids']);
        $this->assertSame(4, $shift->handover_version);
        $this->assertInstanceOf(CarbonInterface::class, $shift->handover_prepared_at);
        $this->assertInstanceOf(CarbonInterface::class, $shift->handover_accepted_at);
        $this->assertTrue($shift->handedOverTo->is($incomingLead));
    }

    public function test_recommendation_disposition_relationships_and_deletion_rules_are_explicit(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(class_exists(HsRecommendationDisposition::class));
        $this->assertTrue(method_exists(HsRecommendationDisposition::class, 'investigation'));
        $this->assertTrue(method_exists(HsRecommendationDisposition::class, 'correctiveAction'));
        $this->assertTrue(method_exists(HsRecommendationDisposition::class, 'decidedBy'));

        $investigation = HsInvestigation::factory()->withFindings()->create();
        $action = HsCorrectiveAction::factory()->create([
            'hs_event_id' => $investigation->hs_event_id,
        ]);
        $decider = User::factory()->create();
        $disposition = HsRecommendationDisposition::create([
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 1,
            'disposition' => 'corrective_action',
            'reason' => 'A corrective action is required.',
            'hs_corrective_action_id' => $action->id,
            'decided_by_user_id' => $decider->id,
            'decided_at' => now(),
        ])->fresh();

        $this->assertSame(1, $disposition->recommendation_index);
        $this->assertInstanceOf(CarbonInterface::class, $disposition->decided_at);
        $this->assertTrue($disposition->investigation->is($investigation));
        $this->assertTrue($disposition->correctiveAction->is($action));
        $this->assertTrue($disposition->decidedBy->is($decider));

        $action->forceDelete();
        $decider->delete();
        $disposition->refresh();

        $this->assertNull($disposition->hs_corrective_action_id);
        $this->assertNull($disposition->decided_by_user_id);

        $investigation->forceDelete();
        $this->assertDatabaseMissing('hs_recommendation_dispositions', ['id' => $disposition->id]);
    }

    public function test_only_one_disposition_can_exist_for_each_investigation_recommendation(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(class_exists(HsRecommendationDisposition::class));

        $investigation = HsInvestigation::factory()->withFindings()->create();
        HsRecommendationDisposition::create([
            'hs_investigation_id' => $investigation->id,
            'recommendation_index' => 0,
            'disposition' => 'accepted_risk',
        ]);

        $duplicate = null;
        try {
            HsRecommendationDisposition::create([
                'hs_investigation_id' => $investigation->id,
                'recommendation_index' => 0,
                'disposition' => 'no_action',
            ]);
        } catch (QueryException $exception) {
            $duplicate = $exception;
        }

        $this->assertDuplicateKeyViolation(
            $duplicate,
            'hs_rec_disp_investigation_recommendation_unique'
        );
        $this->assertDatabaseCount('hs_recommendation_dispositions', 1);
    }

    public function test_journey_factory_states_are_explicit_and_hs_event_defaults_do_not_create_incidents(): void
    {
        $this->assertJourneySchemaAvailable();
        $this->assertTrue(method_exists(ClientIncident::factory(), 'forJourney'));
        $this->assertTrue(method_exists(HsEvent::factory(), 'forClientIncident'));
        $this->assertTrue(method_exists(HsEvent::factory(), 'awaitingHandoverAcceptance'));
        $this->assertTrue(method_exists(HsEvent::factory(), 'handoverAccepted'));
        $this->assertTrue(class_exists(HsRecommendationDisposition::class));

        $incidentCount = ClientIncident::query()->count();
        HsEvent::factory()->create();
        $this->assertSame($incidentCount, ClientIncident::query()->count());

        $site = Site::factory()->create();
        $owner = User::factory()->create();
        $acceptor = User::factory()->create();
        $sourceIncident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create());
        $event = HsEvent::factory()->forClientIncident($sourceIncident)
            ->awaitingHandoverAcceptance($owner)
            ->create();
        $journeyIncident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()
            ->forJourney($site, $event)
            ->create());
        $accepted = HsEvent::factory()->handoverAccepted($owner, $acceptor)->create();
        $factoryDisposition = HsRecommendationDisposition::factory()->create();

        $this->assertSame(ClientIncident::class, $event->source_type);
        $this->assertSame($sourceIncident->id, $event->source_id);
        $this->assertSame('awaiting_acceptance', $event->handover_status);
        $this->assertSame($site->id, $journeyIncident->site_id);
        $this->assertSame($event->id, $journeyIncident->hs_event_id);
        $this->assertSame('accepted', $accepted->handover_status);
        $this->assertSame($acceptor->id, $accepted->accepted_by_user_id);
        $this->assertNotNull($factoryDisposition->id);
    }

    private function assertJourneySchemaAvailable(): void
    {
        $this->assertTrue(
            Schema::hasColumns('client_incidents', ['site_id', 'hs_event_id']),
            'client_incidents is missing the explicit incident journey link columns.'
        );
    }

    /**
     * @param  array<int, string>  $constants
     */
    private function assertConstantsExist(string $class, array $constants): void
    {
        foreach ($constants as $constant) {
            $this->assertTrue(
                defined($class.'::'.$constant),
                "{$class}::{$constant} is missing."
            );
        }
    }

    private function assertDuplicateKeyViolation(
        ?QueryException $exception,
        ?string $expectedIndex = null
    ): void {
        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertSame('23000', $exception->errorInfo[0] ?? null);
        $this->assertSame(1062, $exception->errorInfo[1] ?? null);

        if ($expectedIndex !== null) {
            $this->assertStringContainsString($expectedIndex, $exception->getMessage());
        }
    }
}
