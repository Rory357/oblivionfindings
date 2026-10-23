<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentTriageDelivery;
use App\Models\Site;
use App\Notifications\AppEventNotification;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Incidents\IncidentRoutingService;
use App\Services\Incidents\IncidentTriageDeliveryService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;

final class IncidentRoutingDeliveryTest extends IncidentRoutingTestCase
{
    private function pending(): array
    {
        $this->settings($this->config(['routes' => []])); $root = $this->incident(); $result = $this->routed($root);
        return [$root, $result, IncidentTriageDelivery::where('recipient_role', 'triage')->sole()];
    }

    public function test_database_delivery_is_exact_repeat_safe_and_has_no_private_narrative_or_names(): void
    {
        [$root, , $delivery] = $this->pending(); $service = app(IncidentTriageDeliveryService::class);
        $first = $service->deliver($delivery->id); $this->assertTrue($first['confirmed']); $this->assertSame($first, $service->deliver($delivery->id));
        $row = DB::table('notifications')->where('id', $delivery->id)->sole();
        $this->assertSame(AppEventNotification::class, $row->type); $this->assertSame($this->triage->id, (int) $row->notifiable_id);
        $payload = json_decode($row->data, true); $this->assertSame('/incidents/'.$root->id, $payload['url']);
        $this->assertStringNotContainsString('Private synthetic', $row->data); $this->assertStringNotContainsString($this->reporter->name, $row->data);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_escalation_uses_only_configured_interval_and_does_not_run_early(): void
    {
        [, , $triage] = $this->pending(); $escalation = IncidentTriageDelivery::where('recipient_role', 'escalation')->sole();
        $service = app(IncidentTriageDeliveryService::class); $this->assertFalse($service->deliver($escalation->id)['confirmed']);
        $this->assertSame(now()->addMinutes(47)->format('c'), $escalation->due_at->format('c'));
        $service->deliver($triage->id); $this->travelTo(now()->addMinutes(47)); $this->assertTrue($service->deliver($escalation->id)['confirmed']);
    }

    public function test_recipient_role_site_hr_or_account_withdrawal_prevents_notification_without_changing_owner(): void
    {
        [$root, , $delivery] = $this->pending(); $this->triage->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->allow($this->triage, 'incidents.owner.assign', false); $result = app(IncidentTriageDeliveryService::class)->deliver($delivery->id);
        $this->assertFalse($result['confirmed']); $this->assertSame('skipped', $delivery->fresh()->status); $this->assertNull($root->fresh()->incident_owner_user_id);
        $this->assertSame(0, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_new_owner_supersedes_old_triage_and_replacement_never_revokes_historical_attribution(): void
    {
        [$root, , $delivery] = $this->pending();
        app(IncidentOwnershipService::class)->assign($this->manager, $root, $this->lead->id, 0, (string) Str::uuid(), null);
        $this->assertFalse(app(IncidentTriageDeliveryService::class)->deliver($delivery->id)['confirmed']);
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
    }

    public function test_transport_failure_preserves_durable_pending_work_and_physical_retry_identity(): void
    {
        [, , $delivery] = $this->pending(); $original = app(Dispatcher::class); $failed = Mockery::mock(Dispatcher::class);
        $failed->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic database notification failure')); app()->instance(Dispatcher::class, $failed);
        try { app(IncidentTriageDeliveryService::class)->deliver($delivery->id); $this->fail('Expected delivery failure.'); }
        catch (\RuntimeException $error) { $this->assertSame('Synthetic database notification failure', $error->getMessage()); }
        finally { app()->instance(Dispatcher::class, $original); }
        $this->assertSame('failed', $delivery->fresh()->status); $this->assertSame(0, DB::table('notifications')->where('id', $delivery->id)->count());
        $this->assertTrue(app(IncidentTriageDeliveryService::class)->deliver($delivery->id)['confirmed']);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_persistence_veto_rolls_back_notification_and_never_claims_delivered(): void
    {
        [, , $delivery] = $this->pending();
        foreach ([DatabaseNotification::class, IncidentTriageDelivery::class] as $class) {
            $this->veto($class, fn () => app(IncidentTriageDeliveryService::class)->deliver($delivery->id));
            $this->assertSame(0, DB::table('notifications')->where('id', $delivery->id)->count()); $this->assertNotSame('delivered', $delivery->fresh()->status);
        }
    }

    public function test_tampered_notification_or_delivery_recipient_cannot_be_confirmed(): void
    {
        [, , $delivery] = $this->pending(); $service = app(IncidentTriageDeliveryService::class); $service->deliver($delivery->id);
        DB::table('notifications')->where('id', $delivery->id)->update(['notifiable_id' => $this->reporter->id]);
        try { $service->deliver($delivery->id); $this->fail('Expected exact destination refusal.'); } catch (\LogicException) {}
        DB::table('incident_triage_deliveries')->where('id', $delivery->id)->update(['recipient_user_id' => $this->reporter->id]);
        $this->validation(fn () => $service->deliver($delivery->id), 'delivery');
    }

    public function test_manager_retry_requires_current_source_permission_and_no_foreign_site_grant(): void
    {
        [, , $delivery] = $this->pending(); $service = app(IncidentTriageDeliveryService::class);
        $this->denial(fn () => $service->deliver($delivery->id, $this->reporter));
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->denial(fn () => $service->deliver($delivery->id, $this->manager)); $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_authorised_retry_records_new_decision_and_supersedes_old_pending_recipient(): void
    {
        [$root, $result, $old] = $this->pending();
        $this->settings($this->config(), 1); $new = app(IncidentRoutingService::class)->retryForViewer($this->manager, $result['routing_intent_id']);
        $this->assertSame('assigned', $new['state']); $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
        $this->assertFalse(app(IncidentTriageDeliveryService::class)->deliver($old->id)['confirmed']);
    }
}
