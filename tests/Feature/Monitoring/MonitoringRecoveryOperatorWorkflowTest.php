<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Jobs\ReplayMonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringRecoveryRequest;
use App\Domain\Monitoring\Services\MonitoringRecoveryRequestService;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use UnexpectedValueException;

final class MonitoringRecoveryOperatorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        Queue::fake();
        config()->set('monitoring.signing', ['active_key_id' => 'recovery-workflow', 'keys' => ['recovery-workflow' => base64_encode(str_repeat("\x2a", SODIUM_CRYPTO_AUTH_KEYBYTES))]]);
    }

    private function fixture(bool $signed = true): array
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
        $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        $message = (string) Str::uuid();
        $at = CarbonImmutable::now()->utc();
        $bytes = $signed ? app(RuntimeEnvelopeCodec::class)->encode(new RuntimeEnvelope(schemaVersion: 1, messageId: $message, type: RuntimeMessageType::Event, source: 'collector:recovery-fixture', sequence: 2, occurredAt: $at, ingestedAt: $at, idempotencyKey: 'fixture:'.$message, traceId: (string) Str::uuid(), payload: ['site_id' => $site->id, 'private_fixture_marker' => 'PRIVATE-SIGNED-PAYLOAD'])) : 'PRIVATE-INVALID-ENVELOPE';
        $letter = MonitoringDeadLetter::query()->create(['message_id' => $message, 'consumer' => 'event-projector', 'source' => 'collector:recovery-fixture', 'sequence' => 2, 'idempotency_key' => 'fixture:'.$message, 'reason_code' => $signed ? 'sequence_gap' : 'invalid_signature', 'reason_message' => 'Safe bounded fixture reason.', 'envelope_bytes' => $bytes, 'site_id' => $site->id]);
        $input = ['request_uuid' => (string) Str::uuid(), 'expected_actor_id' => $actor->id, 'expected_site_id' => $site->id, 'review_token' => app(MonitoringRecoveryRequestService::class)->reviewToken($letter), 'reason' => 'Reviewed original delivery evidence'];

        return [$actor, $site, $letter, $input];
    }

    public function test_replay_receipt_recovery_and_exact_uuid_replay_record_one_owner_intent_and_dispatch_once(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture();
        $path = '/security-devices/monitoring/dead-letters/'.$letter->id.'/replay';
        $first = $this->actingAs($actor)->postJson($path, $input)->assertOk()->assertJsonPath('decision.confirms', 'replay_request_recorded')->assertJsonPath('decision.confirms_processing', false)->assertJsonPath('decision.confirms_device_command', false)->assertJsonPath('current.state', 'replay_requested');
        $this->actingAs($actor)->postJson($path, $input)->assertOk()->assertJsonPath('receipt.committed_at', $first->json('receipt.committed_at'));
        $this->actingAs($actor)->postJson('/security-devices/monitoring/dead-letters/requests/'.$input['request_uuid'].'/recover', Arr::only($input, ['expected_actor_id', 'expected_site_id']))->assertOk()->assertJsonPath('receipt.request_uuid', $input['request_uuid']);
        $this->assertSame(1, MonitoringRecoveryRequest::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.dead_letter.replay_requested')->count());
        Queue::assertPushed(ReplayMonitoringDeadLetter::class, 1);
        $this->assertNull($letter->fresh()->resolved_at);
        $this->assertStringNotContainsString('PRIVATE-SIGNED-PAYLOAD', $first->getContent());
        $this->assertStringNotContainsString($input['reason'], $first->getContent());
        $this->assertArrayNotHasKey('replay_intent_token', $first->json('current'));
    }

    public function test_discard_retains_evidence_and_a_closed_dialog_can_find_the_original_decision_without_another_mutation(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture(false);
        $original = $letter->envelope_bytes;
        $path = '/security-devices/monitoring/dead-letters/'.$letter->id.'/discard';
        $this->actingAs($actor)->postJson($path, $input)->assertOk()->assertJsonPath('decision.confirms', 'discard_recorded');
        $history = $this->actingAs($actor)->getJson('/security-devices/monitoring/dead-letters/requests?expected_actor_id='.$actor->id)->assertOk()->assertJsonCount(1, 'rows');
        $handle = $history->json('rows.0.receipt');
        $this->actingAs($actor)->postJson('/security-devices/monitoring/dead-letters/requests/'.$handle['request_uuid'].'/recover', ['expected_actor_id' => $actor->id, 'expected_site_id' => $site->id])->assertOk()->assertJsonPath('current.state', 'discarded');
        $this->actingAs($actor)->postJson($path, $input)->assertOk();
        $this->assertSame($original, $letter->fresh()->envelope_bytes);
        $this->assertSame(1, MonitoringDeadLetter::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'monitoring.dead_letter.discarded')->count());
        $this->assertStringNotContainsString($original, $history->getContent());
        Queue::assertNothingPushed();
    }

    public function test_changed_request_identity_and_stale_review_do_not_record_a_second_decision(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture();
        $path = '/security-devices/monitoring/dead-letters/'.$letter->id.'/replay';
        $this->actingAs($actor)->postJson($path, $input)->assertOk();
        $this->actingAs($actor)->postJson($path, [...$input, 'reason' => 'Different reviewed intent'])->assertStatus(409);
        $this->actingAs($actor)->postJson($path, [...$input, 'request_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->actingAs($actor)->postJson($path.'?reason=private', $input)->assertBadRequest();
        $this->actingAs($actor)->postJson($path, [...$input, 'unsupported' => 'private'])->assertBadRequest();
        $this->assertSame(1, MonitoringRecoveryRequest::query()->count());
        Queue::assertPushed(ReplayMonitoringDeadLetter::class, 1);
    }

    public function test_recovery_and_history_do_not_expose_an_original_actor_decision_after_scope_transfer(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture(false);
        $this->actingAs($actor)->postJson('/security-devices/monitoring/dead-letters/'.$letter->id.'/discard', $input)->assertOk();
        $this->assertContains($site->id, app(SecurityDevicesAccessService::class)->accessibleSiteIds($actor));
        $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        HrEmployeeProfile::query()->where('user_id', $actor->id)->update(['primary_site_id' => $other->id]);
        $this->actingAs($actor)->postJson('/security-devices/monitoring/dead-letters/requests/'.$input['request_uuid'].'/recover', Arr::only($input, ['expected_actor_id', 'expected_site_id']))->assertNotFound();
        $this->actingAs($actor)->getJson('/security-devices/monitoring/dead-letters/requests?expected_actor_id='.$actor->id)->assertOk()->assertJsonCount(0, 'rows');
        $this->assertSame(1, MonitoringRecoveryRequest::query()->count());
    }

    public function test_preloaded_permission_cannot_operate_legacy_or_recorded_recovery_after_revocation(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture(false);
        $this->assertTrue($actor->canDo('securityDevices.integrations.manage'));
        $permission = Permission::query()->where('key', 'securityDevices.integrations.manage')->firstOrFail();
        $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        try {
            app(MonitoringReplayService::class)->discard($actor, $letter, 'Reviewed legacy discard');
            $this->fail('Old role cache cannot grant authority.');
        } catch (AuthorizationException) {
        }
        try {
            app(MonitoringRecoveryRequestService::class)->execute($actor, $letter->id, 'discard', $input);
            $this->fail('Old role cache cannot create a recovery receipt.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertNull($letter->fresh()->resolved_at);
        $this->assertSame(0, MonitoringRecoveryRequest::query()->count());
    }

    public function test_receipt_failure_rolls_back_owner_mutation_audit_and_dispatch_and_committed_receipts_are_immutable(): void
    {
        [$actor, $site, $letter, $input] = $this->fixture();
        Event::listen('eloquent.updating: '.MonitoringRecoveryRequest::class, function ($receipt) use ($input): void {
            if ($receipt->request_uuid === $input['request_uuid']) {
                throw new \RuntimeException('Fixture receipt failure');
            }
        });
        try {
            app(MonitoringRecoveryRequestService::class)->execute($actor, $letter->id, 'replay', $input);
            $this->fail('No partial recovery write may survive.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Fixture receipt failure', $error->getMessage());
        }
        $this->assertNull($letter->fresh()->replay_requested_at);
        $this->assertSame(0, MonitoringRecoveryRequest::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'monitoring.dead_letter.replay_requested')->count());
        Queue::assertNothingPushed();
        $next = [...$input, 'request_uuid' => (string) Str::uuid()];
        app(MonitoringRecoveryRequestService::class)->execute($actor, $letter->id, 'replay', $next);
        $receipt = MonitoringRecoveryRequest::query()->firstOrFail();
        try {
            $receipt->forceFill(['operation' => 'discard'])->save();
            $this->fail('Committed identity is immutable.');
        } catch (UnexpectedValueException) {
        }
    }

    public function test_legacy_redirect_still_uses_the_existing_owner_without_claiming_a_new_uuid_receipt(): void
    {
        [$actor, $site, $letter] = $this->fixture(false);
        $this->actingAs($actor)->from('/security-devices/monitoring?tab=collection')->post('/security-devices/monitoring/dead-letters/'.$letter->id.'/discard', ['reason' => 'Reviewed original invalid evidence'])->assertRedirect('/security-devices/monitoring?tab=collection');
        $this->assertNotNull($letter->fresh()->resolved_at);
        $this->assertSame(0, MonitoringRecoveryRequest::query()->count());
    }
}
