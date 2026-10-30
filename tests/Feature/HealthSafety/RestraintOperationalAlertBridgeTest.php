<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Internal source convergence; entry-point actor denial lives in source suites. */
class RestraintOperationalAlertBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_distinct_events_for_same_person_inside_thirty_minutes_have_separate_responses(): void
    {
        $first = $this->event();
        $second = $this->event(['client_id' => $first->client_id, 'site_id' => $first->site_id]);
        $firstAlert = $this->deliver($first);
        $secondAlert = $this->deliver($second);

        $this->assertNotSame($firstAlert->id, $secondAlert->id);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertSame($first->id, $firstAlert->context['restraint_event_id']);
        $this->assertSame($second->id, $secondAlert->context['restraint_event_id']);
    }

    public function test_identical_delivery_replays_exact_terminal_identity_without_repeat_audit_or_reopening(): void
    {
        $event = $this->event();
        $uuid = (string) Str::uuid();
        $alert = $this->deliver($event, $uuid);
        $closedAt = now();
        $alert->forceFill(['status' => 'closed', 'closed_at' => $closedAt])->saveQuietly();
        $before = $alert->fresh()->getRawOriginal();

        $retry = $this->deliver($event, $uuid);

        $this->assertSame($alert->id, $retry->id);
        $this->assertSame($before, $retry->getRawOriginal());
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(1, AuditLog::where('action', 'control_room.restraint_source_accepted')->count());
    }

    public function test_changed_delivery_uuid_for_same_source_revision_does_not_create_another_response(): void
    {
        $event = $this->event();
        $alert = $this->deliver($event);
        $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->saveQuietly();
        $second = $this->deliver($event);

        $this->assertSame($alert->id, $second->id);
        $this->assertSame('resolved', $second->status);
        $this->assertCount(2, $second->context['source_deliveries']);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_source_retry_after_time_window_preserves_original_trigger_time_and_response(): void
    {
        $event = $this->event();
        $uuid = (string) Str::uuid();
        $alert = $this->deliver($event, $uuid);
        $this->travel(3)->hours();

        $retry = $this->deliver($event, $uuid);

        $this->assertSame($alert->id, $retry->id);
        $this->assertTrue($alert->triggered_at->equalTo($retry->triggered_at));
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_same_risk_later_revision_preserves_closed_response_and_retains_each_acceptance(): void
    {
        $event = $this->event();
        $originalUuid = (string) Str::uuid();
        $alert = $this->deliver($event, $originalUuid);
        $alert->forceFill(['status' => 'closed', 'closed_at' => now()])->saveQuietly();
        $this->revise($event, ['source_revision' => 2]);
        $updated = $this->deliver($event);

        $this->assertSame($alert->id, $updated->id);
        $this->assertSame('closed', $updated->status);
        $this->assertSame(1, $updated->context['creation_source_revision']);
        $this->assertSame(2, $updated->context['source_revision']);
        $this->assertSame([1, 2], array_column($updated->context['source_deliveries'], 'source_revision'));
        $historical = app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event', 'medium', [
            ...$this->context($event, $originalUuid), 'source_revision' => 1,
        ]);
        $this->assertSame($alert->id, $historical->id);
        $this->assertCount(2, $historical->context['source_deliveries']);
    }

    public function test_new_stale_revision_is_rejected_without_mutating_an_existing_response(): void
    {
        $event = $this->event();
        $alert = $this->deliver($event);
        $this->revise($event, ['source_revision' => 2]);
        $before = $alert->fresh()->getRawOriginal();

        try {
            app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event', 'medium', [
                ...$this->context($event), 'source_revision' => 1,
            ]);
            $this->fail('A novel stale delivery must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $alert->fresh()->getRawOriginal());
            $this->assertSame(1, AuditLog::where('action', 'control_room.restraint_source_accepted')->count());
        }
    }

    public function test_legacy_response_keeps_unknown_creation_revision_across_later_acceptances(): void
    {
        $event = $this->event();
        $legacy = ControlRoomAlert::create([
            'source' => 'operations', 'alert_type' => 'operations.restraint_event', 'severity' => 'medium',
            'status' => 'closed', 'client_id' => $event->client_id, 'site_id' => $event->site_id,
            'triggered_at' => now()->subHours(3), 'closed_at' => now()->subHour(),
            'context' => ['restraint_event_id' => $event->id],
        ]);
        $first = $this->deliver($event);
        $this->revise($event, ['source_revision' => 2]);
        $second = $this->deliver($event);

        $this->assertSame($legacy->id, $first->id);
        $this->assertSame($legacy->id, $second->id);
        $this->assertNull($second->context['creation_source_revision']);
        $this->assertSame('closed', $second->status);
        $this->assertSame([1, 2], array_column($second->context['source_deliveries'], 'source_revision'));
    }

    public function test_uuid_payload_collision_cannot_change_risk_or_accepted_revision(): void
    {
        $event = $this->event();
        $uuid = (string) Str::uuid();
        $alert = $this->deliver($event, $uuid);
        $this->revise($event, ['source_revision' => 2, 'injury_occurred' => true]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->deliver($event, $uuid);
        } finally {
            $this->assertSame('medium', $alert->fresh()->severity);
            $this->assertSame(1, $alert->fresh()->context['source_revision']);
            $this->assertDatabaseCount('control_room_alerts', 1);
        }
    }

    public function test_active_higher_risk_preserves_owner_status_clock_completed_milestones_and_historical_breach(): void
    {
        $this->travelTo(now()->startOfHour());
        $mediumQueue = $this->queue('medium', 2);
        $highQueue = $this->queue('high', 1);
        $this->sla('medium', 10, 20, 60);
        $highSla = $this->sla('high', 5, 10, 30);
        $event = $this->event();
        $alert = $this->deliver($event);
        $owner = $event->created_by;
        $ackAt = $alert->triggered_at->copy()->addMinutes(12);
        $responseAt = $alert->triggered_at->copy()->addMinutes(18);
        $firstBreach = $alert->triggered_at->copy()->addMinutes(10);
        $alert->forceFill(['status' => 'triaging', 'assigned_to_user_id' => $owner, 'priority' => 'normal'])->saveQuietly();
        $alert->sla->forceFill(['acknowledged_at' => $ackAt, 'responded_at' => $responseAt,
            'acknowledge_breached' => true, 'first_breach_at' => $firstBreach])->save();
        $this->assertSame($mediumQueue->id, $alert->queue_id);
        $this->revise($event, ['source_revision' => 2, 'injury_occurred' => true]);

        $escalated = $this->deliver($event);
        $sla = $escalated->sla;

        $this->assertSame($alert->id, $escalated->id);
        $this->assertSame('triaging', $escalated->status);
        $this->assertSame($owner, $escalated->assigned_to_user_id);
        $this->assertSame('high', $escalated->priority);
        $this->assertSame($highQueue->id, $escalated->queue_id);
        $this->assertTrue($alert->triggered_at->equalTo($escalated->triggered_at));
        $this->assertSame($highSla->id, $sla->sla_definition_id);
        $this->assertTrue($ackAt->equalTo($sla->acknowledged_at));
        $this->assertTrue($responseAt->equalTo($sla->responded_at));
        $this->assertTrue($firstBreach->equalTo($sla->first_breach_at));
        $this->assertTrue($sla->acknowledge_breached);
        $this->assertSame(1, $sla->cycle_number);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_newly_discovered_injury_after_terminal_response_creates_linked_response_without_reopening_previous(): void
    {
        $event = $this->event();
        $first = $this->deliver($event);
        $first->forceFill(['status' => 'closed', 'closed_at' => now()])->saveQuietly();
        $original = $first->fresh()->getRawOriginal();
        $this->revise($event, ['source_revision' => 2, 'injury_occurred' => true]);

        $second = $this->deliver($event);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('open', $second->status);
        $this->assertSame('high', $second->severity);
        $this->assertSame($first->id, $second->context['previous_alert_id']);
        $this->assertSame($original, $first->fresh()->getRawOriginal());
        $this->assertDatabaseCount('control_room_alerts', 2);
    }

    public function test_forged_client_or_site_scope_cannot_attach_or_create_a_response(): void
    {
        $event = $this->event();
        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        foreach ([['client_id' => $otherClient->id], ['site_id' => $otherSite->id]] as $forged) {
            try {
                app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event', 'medium', [
                    ...$this->context($event), ...$forged,
                ]);
                $this->fail('A mismatched canonical source scope must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('control_room_alerts', 0);
            }
        }
    }

    public function test_input_narrative_does_not_enter_the_control_room_source_receipt(): void
    {
        $event = $this->event();
        $alert = app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event', 'medium', [
            ...$this->context($event), 'restraint_description' => 'PRIVATE source narrative', 'person_response' => 'PRIVATE response',
        ]);

        $this->assertStringNotContainsString('PRIVATE', json_encode($alert->context));
        $this->assertArrayNotHasKey('restraint_description', $alert->context);
    }

    public function test_unconfirmed_audit_rolls_back_alert_routing_and_source_acceptance(): void
    {
        $event = $this->event();
        $queue = $this->queue('medium', 1);
        $this->sla('medium', 10, 20, 60);
        AuditLog::creating(fn () => false);
        $this->expectException(RuntimeException::class);
        try {
            $this->deliver($event);
        } finally {
            $this->assertDatabaseCount('control_room_alerts', 0);
            $this->assertDatabaseCount('control_room_alert_queue', 0);
            $this->assertDatabaseCount('control_room_alert_sla', 0);
            $this->assertDatabaseHas('control_room_triage_queues', ['id' => $queue->id]);
        }
    }

    private function event(array $attributes = []): RestraintEvent
    {
        return RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->outsideSupportPlan()->create([
            'source_revision' => 1, ...$attributes,
        ]));
    }

    private function revise(RestraintEvent $event, array $changes): void
    {
        $event->forceFill($changes)->saveQuietly();
        $event->refresh();
    }

    private function context(RestraintEvent $event, ?string $uuid = null): array
    {
        return ['restraint_event_id' => (int) $event->id, 'client_id' => $event->client_id,
            'site_id' => $event->site_id, 'source_revision' => (int) $event->source_revision,
            'delivery_id' => $uuid ?? (string) Str::uuid()];
    }

    private function deliver(RestraintEvent $event, ?string $uuid = null): ControlRoomAlert
    {
        return app(ComprehensiveAlertBridgeService::class)->bridgeOperationalAlert('restraint_event',
            $event->injury_occurred ? 'high' : 'medium', $this->context($event, $uuid));
    }

    private function queue(string $severity, int $tier): TriageQueue
    {
        return TriageQueue::create(['name' => $severity, 'code' => 'restraint-'.$severity, 'tier' => $tier,
            'handle_severities' => [$severity], 'handle_sources' => ['operations'],
            'handle_alert_types' => ['operations.restraint_event'], 'assigned_roles' => [], 'assigned_users' => [], 'is_active' => true]);
    }

    private function sla(string $severity, int $ack, int $response, int $resolve): SlaDefinition
    {
        return SlaDefinition::create(['name' => $severity, 'code' => 'restraint-'.$severity, 'severities' => [$severity],
            'sources' => ['operations'], 'alert_types' => ['operations.restraint_event'],
            'acknowledge_target_minutes' => $ack, 'response_target_minutes' => $response,
            'resolution_target_minutes' => $resolve, 'is_active' => true]);
    }
}
