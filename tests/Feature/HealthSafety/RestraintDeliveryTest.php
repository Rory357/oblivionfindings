<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\RestraintEventReview;
use App\Models\RestraintSourceDelivery;
use App\Models\UserNotificationPreference;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\RestraintService;
use App\Services\HealthSafety\RestraintSourceDeliveryService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;

class RestraintDeliveryTest extends RestraintEventCommandTestCase
{
    public function test_source_review_is_durable_exact_and_retry_does_not_duplicate_canonical_source(): void
    {
        $row = $this->event();
        $intent = RestraintSourceDelivery::where('purpose', 'source_review')->sole();
        $this->assertSame('pending', $intent->status);
        $service = app(RestraintSourceDeliveryService::class);
        $service->deliver($intent->id);
        $service->deliver($intent->id);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertSame(1, HsEvent::where('source_type', RestraintEvent::class)->where('source_id', $row->id)->count());
        $this->assertNotNull($intent->fresh()->delivered_at);
    }

    public function test_revoked_initiator_or_changed_tuple_cannot_receive_a_source_delivery_success(): void
    {
        $row = $this->event();
        $intent = RestraintSourceDelivery::where('purpose', 'source_review')->sole();
        $this->revoke($this->actor, 'restraints.create');
        $this->revoke($this->actor, 'restraints.manage');
        app(RestraintSourceDeliveryService::class)->deliver($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertSame('authority_changed', $intent->fresh()->failure_code);
        $second = $this->eventWithFreshActor();
        $delivery = RestraintSourceDelivery::where('restraint_event_id', $second->id)->where('purpose', 'source_review')->sole();
        // site_id is a real foreign key, so the changed tuple moves to another existing Site.
        $second->updateQuietly(['site_id' => \App\Models\Site::factory()->create(['is_active' => true])->id]);
        app(RestraintSourceDeliveryService::class)->deliver($delivery->id);
        $this->assertSame('source_changed', $delivery->fresh()->failure_code);
    }

    private function eventWithFreshActor(): RestraintEvent
    {
        $this->actor = $this->staff(['restraints.create', 'restraints.view', 'restraints.review', 'restraints.manage', 'clients.viewAny']);

        return $this->event();
    }

    public function test_reconciliation_cannot_confirm_an_incident_moved_to_another_stay(): void
    {
        $row = $this->event();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => null, 'reported_by' => $this->actor->id, 'status' => 'draft']));
        $this->service()->linkIncident($row, $this->actor, $this->command($row, ['related_incident_id' => $incident->id]));
        $intent = RestraintSourceDelivery::where('restraint_event_id', $row->id)->where('purpose', 'source_reconcile')->latest('created_at')->firstOrFail();
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        $stay = RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay()]);
        $incident->updateQuietly(['respite_stay_id' => $stay->id]);
        try {
            app(RestraintSourceDeliveryService::class)->deliver($intent->id);
            $this->fail('A different stay cannot be confirmed by source reconciliation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Source incident reconciliation is not canonical.', $error->getMessage());
        }
        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertNull($intent->fresh()->linked_incident_id);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertSame('draft', $incident->fresh()->status);
    }

    public function test_assignment_rechecks_named_owner_and_preferences_before_minimal_inbox_serialization(): void
    {
        $row = $this->event();
        $owner = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->service()->assignReview($row, $this->actor, $this->command($row, ['review_owner_id' => $owner->id, 'reason' => 'Named review']));
        $intent = RestraintSourceDelivery::where('purpose', 'review_assignment')->sole();
        app(RestraintSourceDeliveryService::class)->deliver($intent->id);
        $data = json_decode(DB::table('notifications')->where('id', $intent->id)->value('data'), true);
        $this->assertSame('delivered', $intent->fresh()->status);
        $this->assertStringNotContainsString('Sensitive factual source', json_encode($data));
        $this->assertSame('/health-safety/restraints/events/'.$row->id.'?section=review', $data['url']);
        $other = $this->staff(['restraints.review', 'clients.viewAny']);
        UserNotificationPreference::create(['user_id' => $other->id, 'key' => 'tasks.assigned', 'enabled' => false, 'channel_inapp' => false]);
        $this->service()->assignReview($row, $this->actor, $this->command($row, ['review_owner_id' => $other->id, 'reason' => 'Current reassignment']));
        $next = RestraintSourceDelivery::where('purpose', 'review_assignment')->latest('created_at')->get()->firstWhere('recipient_user_id', $other->id);
        app(RestraintSourceDeliveryService::class)->deliver($next->id);
        $this->assertSame('suppressed', $next->fresh()->status);
        $this->assertSame('preference_suppressed', $next->fresh()->failure_code);
    }

    public function test_old_assignment_is_suppressed_after_reassignment_and_no_private_count_is_sent(): void
    {
        $row = $this->event();
        $owner = $this->staff(['restraints.view', 'clients.viewAny']);
        $next = $this->staff(['restraints.view', 'clients.viewAny']);
        $followup = $this->service()->addFollowup($row, $this->actor, $this->command($row, ['notes' => 'Sensitive follow-up', 'assigned_to_user_id' => $owner->id]));
        $intent = RestraintSourceDelivery::where('purpose', 'followup_assignment')->sole();
        $this->service()->reassignFollowup($row, $this->actor, $this->command($row, ['followup_id' => $followup['followup_id'], 'assigned_to_user_id' => $next->id, 'reason' => 'Current executor changed']));
        app(RestraintSourceDeliveryService::class)->deliver($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertSame('assignment_inactive', $intent->fresh()->failure_code);
        $this->assertDatabaseMissing('notifications', ['id' => $intent->id]);
    }

    public function test_null_or_foreign_bridge_receipt_is_retryable_not_delivered_and_emits_no_narrative(): void
    {
        $bridge = Mockery::mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')->once()->withArgs(function ($type, $severity, $context): bool {
            $this->assertSame('restraint_event', $type);
            $this->assertArrayNotHasKey('description', $context);
            $this->assertArrayHasKey('source_revision', $context);

            return true;
        })->andReturnNull();
        $this->app->instance(ComprehensiveAlertBridgeService::class, $bridge);
        $this->app->forgetInstance(RestraintSourceDeliveryService::class);
        $this->app->forgetInstance(RestraintService::class);
        $row = $this->event(['injury_occurred' => true, 'injury_details' => 'Actual injury']);
        $intent = RestraintSourceDelivery::where('purpose', 'operational_bridge')->sole();
        try {
            app(RestraintSourceDeliveryService::class)->deliver($intent->id);
            $this->fail('Null bridge does not prove delivery.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $intent->fresh()->status);
            $this->assertNull($intent->fresh()->delivered_at);
            $this->assertNull($intent->fresh()->control_room_alert_id);
        }
    }

    public function test_due_reminder_is_deduplicated_by_recipient_nz_day_and_current_authorized_membership(): void
    {
        $row = $this->event();
        $owner = $this->staff(['restraints.view', 'clients.viewAny']);
        $this->service()->addFollowup($row, $this->actor, $this->command($row, ['notes' => 'Actual follow-up', 'assigned_to_user_id' => $owner->id, 'due_at' => now()->subHour()->toIso8601String()]));
        $delivery = app(RestraintSourceDeliveryService::class);
        $this->assertSame(1, $delivery->stageDueReminders());
        $this->assertSame(0, $delivery->stageDueReminders());
        $intent = RestraintSourceDelivery::where('purpose', 'due_reminder')->sole();
        $this->assertSame(now('Pacific/Auckland')->toDateString(), $intent->reminder_day->toDateString());
        $this->revoke($owner, 'clients.viewAny');
        $delivery->deliver($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['id' => $intent->id]);
    }

    public function test_legacy_later_injury_advances_truthful_revision_once_and_retains_active_operational_response(): void
    {
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id, 'injury_occurred' => false, 'within_support_plan' => false]));
        $service = app(RestraintSourceDeliveryService::class);
        $service->stageObserved($row);
        $first = RestraintSourceDelivery::where('restraint_event_id', $row->id)->where('purpose', 'operational_bridge')->sole();
        $service->deliver($first->id);
        $alert = ControlRoomAlert::findOrFail($first->fresh()->control_room_alert_id);
        $alert->updateQuietly(['status' => ControlRoomAlert::STATUS_ACK]);
        $this->assertSame('medium', $alert->fresh()->severity);
        $row->updateQuietly(['injury_occurred' => true, 'injury_details' => 'Actual later injury']);
        $key = (string) Str::uuid();
        $changes = ['injury_occurred' => true, 'injury_details' => 'Actual later injury'];
        $service->stageObserved($row, $changes, $key);
        $service->stageObserved($row, $changes, $key);
        $this->assertSame(2, $row->fresh()->source_revision);
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertNull($row->fresh()->source_schema_version);
        $history = RestraintEventReview::where('restraint_event_id', $row->id)->sole();
        $this->assertSame('legacy_source_change', $history->facts['kind']);
        $this->assertSame('not_retained', $history->facts['prior_values']);
        $this->assertNull($history->occurred_at);
        $next = RestraintSourceDelivery::where('restraint_event_id', $row->id)->where('purpose', 'operational_bridge')->where('id', '!=', $first->id)->sole();
        $this->assertSame(2, $next->membership[0]['accepted_source_revision']);
        $service->deliver($next->id);
        $this->assertSame('delivered', $next->fresh()->status);
        $this->assertSame($alert->id, $next->fresh()->control_room_alert_id);
        $this->assertSame(ControlRoomAlert::STATUS_ACK, $alert->fresh()->status);
        $this->assertSame('high', $alert->fresh()->severity);
        $this->assertSame(1, $alert->fresh()->context['creation_source_revision']);
        $this->assertSame(2, $alert->fresh()->context['source_revision']);
        $this->assertCount(2, $alert->fresh()->context['source_deliveries']);
    }

    public function test_insufficient_risk_bridge_receipt_is_attempted_once_and_never_claimed_as_delivered(): void
    {
        $bridge = Mockery::mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')->once()->andReturnUsing(fn ($type, $severity, $context) => (new ControlRoomAlert)->forceFill(['id' => 999, 'severity' => 'medium', 'client_id' => $context['client_id'], 'site_id' => $context['site_id'], 'context' => [...$context, 'source_deliveries' => [['source_revision' => $context['source_revision'], 'delivery_id' => $context['delivery_id'], 'severity' => 'high']]]]));
        $this->app->instance(ComprehensiveAlertBridgeService::class, $bridge);
        $this->app->forgetInstance(RestraintSourceDeliveryService::class);
        $this->app->forgetInstance(RestraintService::class);
        $row = $this->event(['injury_occurred' => true, 'injury_details' => 'Actual injury']);
        $intent = RestraintSourceDelivery::where('restraint_event_id', $row->id)->where('purpose', 'operational_bridge')->sole();
        try {
            app(RestraintSourceDeliveryService::class)->deliver($intent->id);
            $this->fail('A lower-risk response cannot confirm this injury source.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $intent->fresh()->status);
            $this->assertNull($intent->fresh()->delivered_at);
            $this->assertNull($intent->fresh()->control_room_alert_id);
        }
    }

    public function test_queue_failure_retains_committed_source_and_retryable_outbox_without_delivery_claim(): void
    {
        $row = $this->event();
        $intent = RestraintSourceDelivery::where('restraint_event_id', $row->id)->where('purpose', 'source_review')->sole();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        app(RestraintSourceDeliveryService::class)->queue($intent->id);
        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame('queue_unavailable', $intent->fresh()->failure_code);
        $this->assertNull($intent->fresh()->delivered_at);
        $this->assertTrue($intent->fresh()->next_attempt_at->isFuture());
        $this->assertSame(1, $row->fresh()->lock_version);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
    }
}
