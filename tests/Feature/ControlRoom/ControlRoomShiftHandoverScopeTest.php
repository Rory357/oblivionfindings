<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertProvenanceService;
use App\Services\ControlRoom\ControlRoomHandoverScopeService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomShiftHandoverScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-17 10:00:00');
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create([
            'type' => 'house',
        ]);
        $this->viewer = $this->coordinatorAt($this->site);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scope_includes_every_changed_or_decision_relevant_reason_and_summarises_unchanged_work(): void
    {
        $shift = $this->activeShift();
        $queue = TriageQueue::query()->create([
            'name' => 'Clinical response',
            'code' => 'handover-clinical',
            'tier' => 1,
            'is_active' => true,
        ]);

        $created = $this->activeAlert('medium');

        $lifecycle = $this->preExistingAlert('low');
        $this->recordAlertAudit($lifecycle, 'controlRoom.alert.triage');

        $snoozed = $this->preExistingAlert('medium', [
            'snoozed_until' => now()->addHour(),
        ]);
        $this->recordAlertAudit($snoozed, 'controlRoom.alert.snooze');

        $assigned = $this->preExistingAlert('medium', [
            'assigned_to_user_id' => $this->viewer->id,
            'assigned_at' => now()->subHour(),
        ]);
        $assignedBeforeShift = $this->preExistingAlert('low', [
            'assigned_to_user_id' => $this->viewer->id,
            'assigned_at' => now()->subHours(12),
        ]);

        $watched = $this->preExistingAlert('medium');
        AlertWatcher::query()->create([
            'alert_id' => $watched->id,
            'user_id' => $this->viewer->id,
            'added_by_user_id' => $this->viewer->id,
        ]);
        $watchedBeforeShift = $this->preExistingAlert('low');
        $oldWatcher = AlertWatcher::query()->create([
            'alert_id' => $watchedBeforeShift->id,
            'user_id' => $this->viewer->id,
            'added_by_user_id' => $this->viewer->id,
        ]);
        $oldWatcher->forceFill([
            'created_at' => now()->subHours(12),
            'updated_at' => now()->subHours(12),
        ])->saveQuietly();

        $breached = $this->preExistingAlert('high');
        $this->attachSla($breached, [
            'acknowledge_deadline' => now()->subHours(2),
            'acknowledge_breached' => true,
            'first_breach_at' => now()->subHour(),
        ]);
        $breachedBeforeShift = $this->preExistingAlert('high');
        $this->attachSla($breachedBeforeShift, [
            'acknowledge_deadline' => now()->subHours(11),
            'acknowledge_breached' => true,
            'first_breach_at' => now()->subHours(10),
        ]);

        $atRisk = $this->preExistingAlert('medium');
        $this->attachSla($atRisk, [
            'acknowledge_deadline' => now()->addMinutes(3),
        ]);

        $taskDue = $this->preExistingAlert('low');
        $task = AlertTask::query()->create([
            'alert_id' => $taskDue->id,
            'title' => 'Confirm the temporary control',
            'status' => AlertTask::STATUS_OPEN,
            'priority' => 'high',
            'due_at' => now()->addHours(4),
            'created_by_user_id' => $this->viewer->id,
        ]);

        $incidentClient = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $incidentState = $this->preExistingAlert('medium', [
            'client_id' => $incidentClient->id,
        ]);
        ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $incidentClient->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => $incidentState->id,
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
        ]));

        $accepted = $this->preExistingAlert('medium');
        $this->eventFor($accepted, [
            'accepted_at' => now()->subHour(),
            'accepted_by_user_id' => $this->viewer->id,
            'owner_user_id' => $this->viewer->id,
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
        ]);

        $worksafe = $this->preExistingAlert('medium');
        $this->eventFor($worksafe, [
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => now()->subHour(),
            'worksafe_decided_by_user_id' => $this->viewer->id,
            'worksafe_decision_reason' => 'Below the statutory threshold.',
            'worksafe_decision_source' => 'manual',
        ]);

        $verification = $this->preExistingAlert('medium');
        $verificationEvent = $this->eventFor($verification);
        HsCorrectiveAction::factory()->verified()->create([
            'hs_event_id' => $verificationEvent->id,
            'verified_at' => now()->subHour(),
            'verified_by_user_id' => $this->viewer->id,
        ]);

        $closure = $this->preExistingAlert('medium');
        $this->eventFor($closure, [
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now()->subHour(),
            'closed_by' => $this->viewer->id,
            'closure_summary' => 'Governance complete.',
        ]);

        $pinned = $this->preExistingAlert('low');
        OperatorNote::query()->create([
            'alert_id' => $pinned->id,
            'shift_id' => $shift->id,
            'type' => OperatorNote::TYPE_HANDOVER,
            'purpose' => OperatorNote::PURPOSE_ESCALATION_HANDOVER,
            'content' => 'Incoming lead must retain this alert.',
            'is_pinned' => true,
            'user_id' => $this->viewer->id,
        ]);
        $pinnedBeforeShift = $this->preExistingAlert('low');
        $oldPin = OperatorNote::query()->create([
            'alert_id' => $pinnedBeforeShift->id,
            'shift_id' => $shift->id,
            'type' => OperatorNote::TYPE_HANDOVER,
            'purpose' => OperatorNote::PURPOSE_ESCALATION_HANDOVER,
            'content' => 'This earlier pin remains decision-relevant.',
            'is_pinned' => true,
            'user_id' => $this->viewer->id,
        ]);
        $oldPin->forceFill([
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ])->saveQuietly();

        $unchanged = $this->preExistingAlert('high', ['queue_id' => $queue->id]);
        $unchangedCritical = $this->preExistingAlert('critical');

        $foreignSite = Site::factory()->create();
        $foreign = $this->activeAlert('critical', ['site_id' => $foreignSite->id]);

        $scope = app(ControlRoomHandoverScopeService::class)->build($shift, $this->viewer);
        $required = collect($scope['required_alerts']);
        $requiredIds = $required->pluck('id')->all();

        $this->assertEqualsCanonicalizing([
            $created->id,
            $lifecycle->id,
            $snoozed->id,
            $assigned->id,
            $assignedBeforeShift->id,
            $watched->id,
            $watchedBeforeShift->id,
            $breached->id,
            $breachedBeforeShift->id,
            $atRisk->id,
            $taskDue->id,
            $incidentState->id,
            $accepted->id,
            $worksafe->id,
            $verification->id,
            $closure->id,
            $pinned->id,
            $pinnedBeforeShift->id,
        ], $requiredIds);
        $this->assertNotContains($unchanged->id, $requiredIds);
        $this->assertNotContains($unchangedCritical->id, $requiredIds);
        $this->assertNotContains($foreign->id, $requiredIds);

        $this->assertReason($required, $created, 'created_during_shift');
        $this->assertReason($required, $lifecycle, 'lifecycle_changed');
        $this->assertReason($required, $snoozed, 'lifecycle_changed');
        $this->assertReason($required, $assigned, 'shift_member_ownership');
        $this->assertReason($required, $assignedBeforeShift, 'shift_member_ownership');
        $this->assertReason($required, $watched, 'shift_member_ownership');
        $this->assertReason($required, $watchedBeforeShift, 'shift_member_ownership');
        $this->assertReason($required, $breached, 'sla_breached_or_at_risk');
        $this->assertReason($required, $breachedBeforeShift, 'sla_breached_or_at_risk');
        $this->assertReason($required, $atRisk, 'sla_breached_or_at_risk');
        $this->assertReason($required, $taskDue, 'task_due_before_next_shift');
        $this->assertReason($required, $incidentState, 'governance_state_changed');
        $this->assertReason($required, $accepted, 'governance_state_changed');
        $this->assertReason($required, $worksafe, 'governance_state_changed');
        $this->assertReason($required, $verification, 'governance_state_changed');
        $this->assertReason($required, $closure, 'governance_state_changed');
        $this->assertReason($required, $pinned, 'pinned_by_outgoing_lead');
        $this->assertReason($required, $pinnedBeforeShift, 'pinned_by_outgoing_lead');

        $this->assertSame(now()->toIso8601String(), $scope['criteria_at']);
        $this->assertSame(now()->addHours(8)->toIso8601String(), $scope['next_expected_shift_at']);
        $this->assertCount(7, $scope['criteria']);
        $this->assertSame(2, data_get($scope, 'carry_forward.total'));
        $this->assertSame(1, data_get($scope, 'carry_forward.by_severity.critical'));
        $this->assertSame(1, data_get($scope, 'carry_forward.by_severity.high'));
        $this->assertSame(
            1,
            collect(data_get($scope, 'carry_forward.by_queue'))
                ->firstWhere('name', 'Clinical response')['total'],
        );
        $this->assertSame(0, data_get($scope, 'carry_forward.breached_count'));
        $this->assertSame(
            '/control-room/alerts?lens=active&handover=carry-forward',
            data_get($scope, 'carry_forward.href'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($scope, 'carry_forward.signature'),
        );
        $this->assertSame($task->id, data_get(
            $required->firstWhere('id', $taskDue->id),
            'tasks.0.id',
        ));
    }

    public function test_every_material_alert_action_during_the_shift_is_individually_required(): void
    {
        $shift = $this->activeShift();
        $actions = [
            'controlRoom.alert.acknowledge',
            'controlRoom.alert.triage',
            'controlRoom.alert.escalate',
            'controlRoom.alert.snooze',
            'controlRoom.alert.unsnooze',
            'controlRoom.alert.updateMeta',
        ];
        $alerts = collect($actions)->map(function (string $action): ControlRoomAlert {
            $alert = $this->preExistingAlert('low');
            $this->recordAlertAudit($alert, $action);

            return $alert;
        });

        $required = collect(
            app(ControlRoomHandoverScopeService::class)
                ->build($shift, $this->viewer)['required_alerts'],
        );

        $this->assertEqualsCanonicalizing($alerts->pluck('id')->all(), $required->pluck('id')->all());
        foreach ($alerts as $alert) {
            $this->assertReason($required, $alert, 'lifecycle_changed');
        }
    }

    public function test_required_alert_query_has_no_pagination_or_presentation_cap(): void
    {
        $shift = $this->activeShift();
        $alerts = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()
            ->count(305)
            ->open()
            ->create([
                'site_id' => $this->site->id,
                'severity' => 'critical',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ]));

        $scope = app(ControlRoomHandoverScopeService::class)->build($shift, $this->viewer);
        $requiredIds = collect($scope['required_alerts'])->pluck('id');

        $this->assertCount(305, $requiredIds);
        $this->assertTrue($requiredIds->contains($alerts->last()->id));
        $this->assertSame(0, data_get($scope, 'carry_forward.total'));
    }

    public function test_review_gap_scope_resolves_safe_legacy_incident_and_health_safety_links_canonically(): void
    {
        $shift = $this->activeShift();
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => null,
            'hs_event_id' => null,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]));
        $alert = $this->preExistingAlert('medium', [
            'client_id' => $client->id,
            'context' => ['incident_id' => $incident->id],
        ]);
        $event = HsEvent::factory()->forClientIncident($incident)->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'control_room_alert_id' => null,
            'accepted_at' => now()->subHour(),
            'accepted_by_user_id' => $this->viewer->id,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        $required = collect(
            app(ControlRoomHandoverScopeService::class)
                ->build($shift, $this->viewer)['required_alerts'],
        );
        $snapshot = $required->firstWhere('id', $alert->id);

        $this->assertNotNull($snapshot);
        $this->assertReason($required, $alert, 'governance_state_changed');
        $this->assertSame($incident->reference_number, data_get($snapshot, 'journey.incident_reference'));
        $this->assertSame($event->reference_number, data_get($snapshot, 'journey.health_safety_reference'));
        $this->assertNull($incident->fresh()->control_room_alert_id);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertNull($event->fresh()->control_room_alert_id);
    }

    public function test_review_gap_scope_preserves_the_historical_site_for_the_same_direct_client_after_a_move(): void
    {
        $shift = $this->activeShift();
        $currentSite = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $currentSite->id,
        ]);
        $alert = $this->preExistingAlert('medium', [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);
        $event = $this->eventFor($alert, [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'accepted_at' => now()->subHour(),
            'accepted_by_user_id' => $this->viewer->id,
        ]);

        $required = collect(
            app(ControlRoomHandoverScopeService::class)
                ->build($shift, $this->viewer)['required_alerts'],
        );
        $snapshot = $required->firstWhere('id', $alert->id);

        $this->assertNotNull($snapshot);
        $this->assertReason($required, $alert, 'governance_state_changed');
        $this->assertSame(
            $event->reference_number,
            data_get($snapshot, 'journey.health_safety_reference'),
        );
        $this->assertSame($this->site->id, $event->fresh()->site_id);
        $this->assertSame($currentSite->id, $client->fresh()->site_id);
    }

    public function test_historical_health_safety_tuple_fails_closed_when_the_direct_client_does_not_match(): void
    {
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $alert = $this->preExistingAlert('medium', [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);
        $event = $this->eventFor($alert, [
            'client_id' => $otherClient->id,
            'site_id' => $this->site->id,
            'accepted_at' => now()->subHour(),
            'accepted_by_user_id' => $this->viewer->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provenance conflict');

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);
    }

    public function test_historical_health_safety_tuple_uses_the_direct_client_id_not_a_stale_loaded_relation(): void
    {
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $alert = $this->preExistingAlert('medium', [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);
        $event = $this->eventFor($alert, [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);
        $alert->load('client');
        $alert->update(['client_id' => $otherClient->id]);

        $this->assertTrue($alert->relationLoaded('client'));
        $this->assertSame($client->id, $alert->client->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provenance conflict');

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);
    }

    public function test_historical_site_exception_rejects_context_only_client_identity(): void
    {
        $currentSite = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $currentSite->id,
        ]);
        $alert = $this->preExistingAlert('medium', [
            'client_id' => null,
            'site_id' => $this->site->id,
            'context' => ['client_id' => $client->id],
        ]);
        $event = $this->eventFor($alert, [
            'client_id' => $client->id,
            'site_id' => $this->site->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provenance conflict');

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);
    }

    public function test_historical_health_safety_tuple_allows_an_exact_clientless_pair(): void
    {
        $alert = $this->preExistingAlert('medium', [
            'client_id' => null,
            'site_id' => $this->site->id,
            'context' => [],
        ]);
        $event = $this->eventFor($alert, [
            'client_id' => null,
            'site_id' => $this->site->id,
        ]);

        app(ControlRoomAlertProvenanceService::class)
            ->assertHealthSafetyEventTuple($alert, $event);

        $this->assertNull($event->client_id);
    }

    public function test_review_gap_scope_fails_closed_when_multiple_health_safety_events_claim_one_alert(): void
    {
        $shift = $this->activeShift();
        $alert = $this->preExistingAlert('medium');

        HsEvent::factory()->count(2)->create([
            'site_id' => $this->site->id,
            'control_room_alert_id' => $alert->id,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('multiple H&S events');

        app(ControlRoomHandoverScopeService::class)->build($shift, $this->viewer);
    }

    public function test_review_gap_scope_fails_closed_when_a_foreign_health_safety_event_claims_an_alert(): void
    {
        $shift = $this->activeShift();
        $alert = $this->preExistingAlert('medium');
        $foreignSite = Site::factory()->create();
        HsEvent::factory()->create([
            'site_id' => $foreignSite->id,
            'control_room_alert_id' => $alert->id,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('provenance conflict');

        app(ControlRoomHandoverScopeService::class)->build($shift, $this->viewer);
    }

    public function test_review_gap_canonical_governance_resolution_is_batched_for_an_uncapped_backlog(): void
    {
        $alerts = collect();
        foreach (range(1, 40) as $index) {
            $alert = $this->preExistingAlert('medium');
            $this->eventFor($alert, [
                'accepted_at' => now()->subHour(),
                'accepted_by_user_id' => $this->viewer->id,
            ]);
            $alerts->push($alert);
        }
        $loadedAlerts = ControlRoomAlert::query()
            ->whereIn('id', $alerts->pluck('id'))
            ->with([
                'site:id,name',
                'client:id,site_id',
                'client.site:id',
            ])
            ->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $records = app(IncidentJourneyService::class)
            ->governanceRecordsForAlerts($loadedAlerts);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(40, $records);
        $this->assertLessThanOrEqual(
            8,
            $queryCount,
            "Canonical batch resolution issued {$queryCount} queries for 40 alerts.",
        );
    }

    public function test_review_gap_batches_direct_client_and_site_provenance_for_an_uncapped_backlog(): void
    {
        $alerts = collect();
        foreach (range(1, 40) as $index) {
            $client = Client::factory()->create([
                'site_id' => $this->site->id,
            ]);
            $alert = $this->preExistingAlert('medium', [
                'client_id' => $client->id,
            ]);
            $this->eventFor($alert, [
                'client_id' => $client->id,
                'accepted_at' => now()->subHour(),
                'accepted_by_user_id' => $this->viewer->id,
            ]);
            $alerts->push($alert);
        }
        $loadedAlerts = ControlRoomAlert::query()
            ->whereIn('id', $alerts->pluck('id'))
            ->with([
                'site:id,name',
                'client:id,site_id',
                'client.site:id',
            ])
            ->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $records = app(IncidentJourneyService::class)
            ->governanceRecordsForAlerts($loadedAlerts);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(40, $records);
        $this->assertLessThanOrEqual(
            9,
            $queryCount,
            "Client-bearing canonical batch resolution issued {$queryCount} queries for 40 alerts.",
        );
    }

    public function test_review_gap_full_scope_build_is_bounded_for_an_sla_backlog(): void
    {
        $shift = $this->activeShift();
        foreach (range(1, 40) as $index) {
            $alert = $this->preExistingAlert('high');
            $this->attachSla($alert, [
                'acknowledge_deadline' => now()->subHours(11),
                'acknowledge_breached' => true,
                'first_breach_at' => now()->subHours(10),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $scope = app(ControlRoomHandoverScopeService::class)->build($shift, $this->viewer);
        $queryLog = DB::getQueryLog();
        $queryCount = count($queryLog);
        DB::disableQueryLog();
        $querySummary = collect($queryLog)
            ->map(fn (array $query): string => preg_replace('/\\b\\d+\\b/', '?', $query['query']))
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->all();

        $this->assertCount(40, $scope['required_alerts']);
        $this->assertSame(0, data_get($scope, 'carry_forward.total'));
        $this->assertLessThanOrEqual(
            30,
            $queryCount,
            'Full SLA-backed handover scope issued '
                .$queryCount
                .' queries for 40 alerts: '
                .json_encode($querySummary, JSON_THROW_ON_ERROR),
        );
    }

    private function activeShift(): Shift
    {
        return Shift::query()->create([
            'name' => 'Outgoing control desk',
            'starts_at' => now()->subHours(8),
            'status' => 'active',
            'shift_lead_user_id' => $this->viewer->id,
            'team_members' => [$this->viewer->id],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function activeAlert(string $severity, array $overrides = []): ControlRoomAlert
    {
        return ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->open()->create(
            array_replace([
                'site_id' => $this->site->id,
                'severity' => $severity,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ], $overrides),
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function preExistingAlert(string $severity, array $overrides = []): ControlRoomAlert
    {
        return $this->activeAlert($severity, array_replace([
            'triggered_at' => now()->subHours(10),
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function attachSla(ControlRoomAlert $alert, array $overrides): AlertSla
    {
        $definition = SlaDefinition::query()->create([
            'name' => 'Handover SLA '.$alert->id,
            'code' => 'handover-'.$alert->id,
            'acknowledge_target_minutes' => 60,
            'is_active' => true,
        ]);

        return AlertSla::query()->create(array_replace([
            'alert_id' => $alert->id,
            'sla_definition_id' => $definition->id,
            'acknowledge_target_minutes' => 60,
            'acknowledge_breached' => false,
            'response_breached' => false,
            'resolution_breached' => false,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function eventFor(ControlRoomAlert $alert, array $overrides = []): HsEvent
    {
        $event = HsEvent::factory()->create(array_replace([
            'site_id' => $this->site->id,
            'control_room_alert_id' => $alert->id,
            'created_at' => now()->subHours(10),
            'updated_at' => now()->subHours(10),
        ], $overrides));

        return $event;
    }

    private function recordAlertAudit(ControlRoomAlert $alert, string $action): void
    {
        $audit = AuditLog::query()->create([
            'user_id' => $this->viewer->id,
            'action' => $action,
            'auditable_type' => ControlRoomAlert::class,
            'auditable_id' => $alert->id,
            'meta' => [],
        ]);
        $audit->forceFill([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->saveQuietly();
    }

    private function assertReason($required, ControlRoomAlert $alert, string $key): void
    {
        $reasonKeys = collect(data_get($required->firstWhere('id', $alert->id), 'handover_reasons'))
            ->pluck('key');

        $this->assertTrue(
            $reasonKeys->contains($key),
            "Alert {$alert->id} is missing handover reason {$key}.",
        );
    }

    private function coordinatorAt(Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'coordinator',
            'approved_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'coordinator')->value('id'),
        ]);
        HrEmployeeProfile::query()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-HANDOVER-SCOPE-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Control Room Coordinator',
            'position_role' => 'coordinator',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
