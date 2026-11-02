<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\IncidentTriageDelivery;
use App\Services\Incidents\IncidentRoutingService;
use App\Services\Incidents\IncidentTriageDeliveryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live clocks carry microseconds. Routing digests recorded_at/escalate_at to the microsecond, so every row in the
 * chain must store and read them back exactly, and a scheduled failure must never be silent.
 */
final class IncidentRoutingMicrosecondClockTest extends IncidentRoutingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T03:00:00.123456Z')->utc());
    }

    public function test_configured_routing_assigns_the_owner_and_keeps_microseconds_on_replay(): void
    {
        $this->settings(); $root = $this->incident();
        $id = app(IncidentRoutingService::class)->stageForSubmitted((int) $root->id, $this->reporter);
        $result = app(IncidentRoutingService::class)->consume($id);

        $this->assertSame('assigned', $result['state']); $this->assertTrue($result['owner_assigned']);
        $this->assertSame($this->lead->id, (int) $root->fresh()->incident_owner_user_id);
        $intent = IncidentRoutingIntent::findOrFail($id); $this->assertSame('assigned', $intent->state); $this->assertSame(1, $intent->attempts);

        $decision = IncidentRoutingDecision::where('intent_id', $id)->sole();
        $this->assertSame('123456', $decision->recorded_at->format('u'));
        $this->assertStringEndsWith('.123456', (string) DB::table('incident_routing_decisions')->where('id', $decision->id)->value('recorded_at'));
        app(IncidentRoutingService::class)->assertDecision($decision, $intent);
        $this->assertSame($result, app(IncidentRoutingService::class)->consume($id));
    }

    public function test_needs_owner_triage_and_escalation_deliveries_confirm_through_the_scheduled_pass(): void
    {
        $this->settings($this->config(['routes' => []])); $root = $this->incident();
        $result = $this->routed($root);
        $this->assertSame('needs_owner', $result['state']);

        $decision = IncidentRoutingDecision::where('intent_id', $result['routing_intent_id'])->sole();
        $this->assertSame('123456', $decision->escalate_at->format('u'));
        $triage = IncidentTriageDelivery::where('recipient_role', 'triage')->sole();
        $escalation = IncidentTriageDelivery::where('recipient_role', 'escalation')->sole();
        $this->assertTrue($triage->due_at->equalTo($decision->recorded_at)); $this->assertTrue($escalation->due_at->equalTo($decision->escalate_at));
        $this->assertNotNull($triage->created_at);

        $service = app(IncidentTriageDeliveryService::class);
        $this->travelTo(now()->addSecond());
        $this->assertSame(['attempted' => 1, 'confirmed' => 1, 'failed' => 0], $service->deliverDue());
        $this->assertSame('delivered', $triage->fresh()->status); $this->assertSame('pending', $escalation->fresh()->status);

        $this->travelTo(now()->addMinutes(47));
        $this->assertSame(['attempted' => 1, 'confirmed' => 1, 'failed' => 0], $service->deliverDue());
        $this->assertSame('delivered', $escalation->fresh()->status);
        $this->assertNull($root->fresh()->incident_owner_user_id);
    }

    public function test_sweep_logs_each_failed_intent_with_ids_and_reason_only(): void
    {
        $this->settings(); $root = $this->incident();
        $id = app(IncidentRoutingService::class)->stageForSubmitted((int) $root->id, $this->reporter);
        DB::table('incident_routing_intents')->where('id', $id)->update(['source_digest' => str_repeat('0', 64)]);
        Log::spy();

        $result = app(IncidentRoutingService::class)->sweep();

        $this->assertSame(1, $result['failed']);
        $this->assertSame('error', IncidentRoutingIntent::findOrFail($id)->state);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['stage'] === 'routing.consume'
            && $context['intent_id'] === $id && $context['incident_id'] === (int) $root->id
            && $context['reason'] === 'ValidationException: The original routing intent needs repair.'
            && ! str_contains(json_encode($context), 'Private synthetic'));
    }

    public function test_deliver_due_logs_each_failed_delivery_with_ids_and_reason_only(): void
    {
        $this->settings($this->config(['routes' => []])); $root = $this->incident();
        $result = $this->routed($root);
        $triage = IncidentTriageDelivery::where('recipient_role', 'triage')->sole();
        DB::table('incident_triage_deliveries')->where('id', $triage->id)->update(['recipient_user_id' => $this->lead->id]);
        $this->travelTo(now()->addSecond());
        Log::spy();

        $this->assertSame(['attempted' => 1, 'confirmed' => 0, 'failed' => 1], app(IncidentTriageDeliveryService::class)->deliverDue());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['stage'] === 'triage.deliver'
            && $context['delivery_id'] === $triage->id && $context['intent_id'] === $result['routing_intent_id']
            && $context['incident_id'] === (int) $root->id && str_starts_with($context['reason'], 'ValidationException: ')
            && ! str_contains(json_encode($context), 'Private synthetic'));
    }
}
