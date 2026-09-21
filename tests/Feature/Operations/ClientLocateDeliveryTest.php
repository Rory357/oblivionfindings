<?php

namespace Tests\Feature\Operations;

use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAuditEvent;
use App\Models\Asset;
use App\Models\FleetTelemetryEvent;
use App\Services\Queclink\GovernedCommandLifecycleService;
use App\Services\Queclink\Listener\ConnectionState;
use App\Services\Queclink\Listener\FrameRouter;
use App\Services\Tracking\ClientLocationHistoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClientLocateFixture;
use Tests\Support\CommittedDatabaseTestCase;

class ClientLocateDeliveryTest extends CommittedDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
    }

    private function frame(array $f): array
    {
        return app(FrameRouter::class)->handleInbound('+RESP:GTHBD,8020090100,'.$f['providerDevice']->imei.',GV500CG,20230811075652,09CF$', new ConnectionState('192.0.2.10:54321'));
    }

    public function test_withdrawal_after_provider_enqueue_releases_no_command_and_fails_the_linked_lifecycle_atomically(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $f['consent']->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $this->assertSame(['+SACK:GTHBD,8020090100,09CF$'], $this->frame($f));
        $this->assertSame('failed', $f['pending']->fresh()->status);
        $this->assertNull($f['pending']->fresh()->sent_at);
        $this->assertSame(CommandStatus::Failed, $f['command']->fresh()->status);
        $this->assertSame(CommandAttemptStatus::Failed, $f['attempt']->fresh()->status);
        $this->assertTrue($f['command']->auditEvents()->where('action', 'provider_delivery_denied')->exists());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_context_stripping_and_governed_link_loss_cannot_fall_back_to_legacy_delivery(): void
    {
        foreach (['origin', 'both_links', 'one_link'] as $damage) {
            $f = ClientLocateFixture::awaitingDelivery();
            if ($damage === 'origin') {
                DB::table('device_command_requests')->where('id', $f['command']->id)->update(['origin_context' => null]);
            } else {
                DB::table('queclink_pending_commands')->where('id', $f['pending']->id)->update([
                    'device_command_request_id' => null,
                    ...($damage === 'both_links' ? ['device_command_attempt_id' => null] : []),
                ]);
            }
            $this->assertTrue($f['pending']->fresh()->was_governed);
            $this->assertSame(['+SACK:GTHBD,8020090100,09CF$'], $this->frame($f));
            $this->assertSame('failed', $f['pending']->fresh()->status);
            $this->assertNull($f['pending']->fresh()->sent_at);
        }
    }

    public function test_governed_provenance_is_hidden_and_cannot_be_downgraded(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $this->assertTrue($f['pending']->fresh()->was_governed);
        $this->assertArrayNotHasKey('was_governed', $f['pending']->toArray());
        $this->expectException(\LogicException::class);
        $f['pending']->forceFill(['was_governed' => false])->save();
    }

    public function test_ack_is_not_a_new_fix_and_exact_old_or_new_measurements_are_distinguished(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $this->assertContains($f['pending']->raw_command, $this->frame($f));
        $this->assertSame(CommandStatus::Running, $f['command']->fresh()->status);
        $lifecycle = app(GovernedCommandLifecycleService::class);
        $lifecycle->markAcknowledged($f['pending']->fresh(), '+ACK:synthetic$');
        $history = app(ClientLocationHistoryService::class);
        $this->assertNull($history->forLocateRequest($f['actor'], $f['client'], $f['command']->fresh(), $f['pending']->fresh()));
        $this->travel(5)->seconds();
        $asset = Asset::factory()->create(['client_id' => $f['client']->id, 'site_id' => $f['site']->id]);
        foreach ([false, true] as $new) {
            $event = FleetTelemetryEvent::query()->create([
                'device_id' => $f['device']->id, 'vendor' => 'queclink',
                'asset_id' => $asset->id,
                'occurred_at' => $new ? now()->subSecond() : $f['command']->created_at->subMinute(), 'received_at' => now(),
                'latitude' => -36.85, 'longitude' => 174.76, 'accuracy_m' => 8, 'consent_blocked' => false,
                'idempotency_key' => 'locate-observation-'.$f['command']->id.'-'.($new ? 'new' : 'old'),
            ]);
            // Exact provider correlation is the source; no latest-device fallback.
            $f['pending']->refresh()->update(['fulfilled_telemetry_event_id' => $event->id, 'fulfilled_at' => now()]);
            $point = $history->forLocateRequest($f['actor'], $f['client'], $f['command']->fresh(), $f['pending']->fresh());
            $this->assertSame($new, $point['is_new']);
            $this->assertSame($event->occurred_at->toISOString(), $point['timestamp']);
            $event->update(['occurred_at' => now()->subDays(31)]);
            $this->assertNull($history->forLocateRequest($f['actor'], $f['client'], $f['command']->fresh(), $f['pending']->fresh()));
        }
    }

    public function test_audit_failure_rolls_back_pending_request_and_attempt_before_any_bytes_are_returned(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $f['consent']->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        $event = 'eloquent.creating: '.DeviceCommandAuditEvent::class;
        Event::listen($event, function ($audit): void {
            if ($audit->action === 'provider_delivery_denied') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->frame($f);
            $this->fail('Audit failure must prevent returning outbound command bytes.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('queued', $f['pending']->fresh()->status);
        $this->assertNull($f['pending']->fresh()->sent_at);
        $this->assertSame(CommandStatus::Accepted, $f['command']->fresh()->status);
        $this->assertSame(CommandAttemptStatus::Accepted, $f['attempt']->fresh()->status);
        $this->assertFalse($f['command']->auditEvents()->where('action', 'provider_delivery_denied')->exists());
    }

    public function test_actual_fulfilment_and_expiry_keep_legal_canonical_transitions(): void
    {
        $f = ClientLocateFixture::awaitingDelivery();
        $this->frame($f);
        $this->travel(5)->seconds();
        $asset = Asset::factory()->create(['client_id' => $f['client']->id, 'site_id' => $f['site']->id]);
        $event = FleetTelemetryEvent::query()->create([
            'asset_id' => $asset->id, 'device_id' => $f['device']->id, 'vendor' => 'queclink',
            'occurred_at' => now(), 'received_at' => now(), 'latitude' => -36.85, 'longitude' => 174.76,
            'consent_blocked' => false, 'idempotency_key' => 'locate-fulfil-'.$f['command']->id,
        ]);
        $this->assertSame(1, app(GovernedCommandLifecycleService::class)->fulfilFromTelemetry($f['providerDevice'], $event->id));
        $this->assertSame($event->id, $f['pending']->fresh()->fulfilled_telemetry_event_id);
        $this->assertSame(CommandStatus::Reconciling, $f['command']->fresh()->status);
        $other = ClientLocateFixture::awaitingDelivery();
        $this->travel(4)->minutes();
        app(GovernedCommandLifecycleService::class)->expireStale();
        $this->assertSame('expired', $other['pending']->fresh()->status);
        $this->assertSame(CommandStatus::Expired, $other['command']->fresh()->status);
        $this->assertSame(CommandAttemptStatus::Expired, $other['attempt']->fresh()->status);
    }
}
