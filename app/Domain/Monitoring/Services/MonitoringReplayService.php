<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Exceptions\UnsupportedRuntimeContractVersion;
use App\Domain\Monitoring\Jobs\ReplayMonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringInbox;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UnexpectedValueException;

final class MonitoringReplayService
{
    public function __construct(
        private readonly RuntimeEnvelopeCodec $codec,
        private readonly SecurityDevicesAccessService $access,
        private readonly MonitoringEnvelopeConsumer $consumer,
        private readonly ?RuntimeEnvelopeHandlerRegistry $handlers = null,
    ) {}

    public function replay(User $actor, MonitoringDeadLetter $letter, string $reason): void
    {
        $reason = $this->validReason($reason);

        $claim = DB::transaction(function () use ($actor, $letter, $reason): array {
            $locked = MonitoringDeadLetter::query()->lockForUpdate()->findOrFail($letter->getKey());

            if ($locked->resolved_at !== null) {
                throw new UnexpectedValueException('Resolved dead letters cannot be replayed.');
            }

            $this->assertReplayable($actor, $locked);

            $replaceIntent = false;

            if ($locked->replay_requested_at === null) {
                $locked->forceFill([
                    'replay_requested_at' => now(),
                    'replay_requested_by_user_id' => $actor->id,
                    'replay_request_reason' => $reason,
                ])->save();

                AuditLogger::logOrFail('monitoring.dead_letter.replay_requested', $locked, [
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                    'reason_code' => $locked->reason_code,
                    'message_id' => $locked->message_id,
                ]);
                $replaceIntent = true;
            } elseif ($locked->replay_requested_by_user_id !== $actor->id
                || $locked->replay_request_reason === null) {
                $previousActorId = $locked->replay_requested_by_user_id;
                $previousReason = $locked->replay_request_reason;
                $locked->forceFill([
                    'replay_requested_at' => now(),
                    'replay_requested_by_user_id' => $actor->id,
                    'replay_request_reason' => $reason,
                ])->save();

                AuditLogger::logOrFail('monitoring.dead_letter.replay_taken_over', $locked, [
                    'actor_id' => $actor->id,
                    'reason' => $reason,
                    'previous_actor_id' => is_int($previousActorId) ? $previousActorId : null,
                    'previous_reason' => is_string($previousReason) ? mb_substr($previousReason, 0, 500) : null,
                    'reason_code' => $locked->reason_code,
                    'message_id' => $locked->message_id,
                ]);
                $replaceIntent = true;
            }

            $token = app(MonitoringDeliveryRecoveryService::class)->claimReplay($locked, $replaceIntent);

            return ['id' => (int) $locked->getKey(), 'token' => $token];
        }, 3);

        if (is_string($claim['token'])) {
            ReplayMonitoringDeadLetter::dispatch($claim['id'], $claim['token'])
                ->afterCommit()
                ->onConnection((string) config('monitoring.delivery.queue_connection', 'redis'))
                ->onQueue((string) config('monitoring.queues.orchestration', 'monitoring'));
        }
    }

    public function completeReplay(int $deadLetterId, string $intentToken): void
    {
        DB::transaction(function () use ($deadLetterId, $intentToken): void {
            $locked = MonitoringDeadLetter::query()->lockForUpdate()->findOrFail($deadLetterId);

            if ($locked->resolved_at !== null) {
                return;
            }

            if ($locked->replay_requested_at === null
                || ! is_string($locked->replay_intent_token)
                || ! hash_equals($locked->replay_intent_token, $intentToken)) {
                return;
            }

            if ($locked->replay_requested_by_user_id === null || $locked->replay_request_reason === null) {
                throw new RuntimeException('Replay intent requires an authorised actor and reason.');
            }

            $actor = User::query()->find($locked->replay_requested_by_user_id);

            if ($actor === null) {
                throw new RuntimeException('Replay intent actor is unavailable.');
            }
            $this->assertReplayable($actor, $locked);
            $this->consumer->consume(
                $locked->consumer,
                $locked->envelope_bytes,
                $locked->site_id,
            );

            $processed = MonitoringInbox::query()
                ->where('consumer', $locked->consumer)
                ->where('message_id', $locked->message_id)
                ->where('payload_hash', hash('sha256', $locked->envelope_bytes))
                ->whereNotNull('processed_at')
                ->exists();

            if (! $processed) {
                throw new RuntimeException('Replay delivery did not complete.');
            }

            $reason = $locked->replay_request_reason;
            $locked->forceFill([
                'replay_count' => $locked->replay_count + 1,
                'last_replayed_at' => now(),
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_reason' => $reason,
                'replay_requested_at' => null,
                'replay_requested_by_user_id' => null,
                'replay_request_reason' => null,
                'replay_intent_token' => null,
                'replay_dispatch_lease_until' => null,
            ])->save();

            AuditLogger::logOrFail('monitoring.dead_letter.replayed', $locked, [
                'actor_id' => $actor->id,
                'reason' => $reason,
                'reason_code' => $locked->reason_code,
                'message_id' => $locked->message_id,
            ]);
        }, 3);
    }

    public function discard(User $actor, MonitoringDeadLetter $letter, string $reason): void
    {
        $reason = $this->validReason($reason);

        DB::transaction(function () use ($actor, $letter, $reason): void {
            $locked = MonitoringDeadLetter::query()->lockForUpdate()->findOrFail($letter->getKey());

            if ($locked->resolved_at !== null) {
                throw new UnexpectedValueException('Dead letter is already resolved.');
            }

            if ($locked->replay_requested_at !== null) {
                throw new UnexpectedValueException('A pending replay must complete before discard.');
            }

            $this->authorise($actor, $locked, false);

            $locked->forceFill([
                'resolved_at' => now(),
                'resolved_by_user_id' => $actor->id,
                'resolution_reason' => $reason,
            ])->save();

            AuditLogger::logOrFail('monitoring.dead_letter.discarded', $locked, [
                'actor_id' => $actor->id,
                'reason' => $reason,
                'reason_code' => $locked->reason_code,
                'message_id' => $locked->message_id,
            ]);
        });
    }

    /**
     * Return only bounded operator-safe contract metadata. A valid envelope
     * that references an inaccessible canonical target is omitted entirely.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(User $actor, MonitoringDeadLetter $letter): ?array
    {
        try {
            $envelope = $this->authorise($actor, $letter, false);
        } catch (AuthorizationException|HttpExceptionInterface) {
            return null;
        }

        $supported = false;
        if ($envelope instanceof RuntimeEnvelope) {
            try {
                $this->registry()->for($envelope->type, $envelope->payloadVersion);
                $supported = true;
            } catch (UnsupportedRuntimeContractVersion|LogicException) {
                $supported = false;
            }
        }

        $replayableReason = in_array($letter->reason_code, [
            'sequence_gap',
            'handler_failed',
            'unsupported_version',
        ], true);
        $pending = $letter->replay_requested_at !== null;
        $canReplay = $envelope instanceof RuntimeEnvelope
            && $supported
            && $replayableReason
            && ! $pending;

        return [
            'schema_version' => $envelope?->schemaVersion,
            'payload_version' => $envelope?->payloadVersion,
            'message_type' => $envelope?->type->value,
            'can_replay' => $canReplay,
            'can_discard' => ! $pending,
            'pending_replay' => $pending,
            'operator_note' => match (true) {
                $pending => 'Replay is queued. Evidence remains locked until processing completes.',
                ! $envelope instanceof RuntimeEnvelope => 'Authentication or envelope validation failed. Preserve the evidence or discard it with a reason.',
                ! $supported => 'This signed contract is not supported by the deployed runtime.',
                ! $replayableReason => 'This failure is not safe to replay. Preserve or discard it after investigation.',
                default => 'Replay consumes the original signed bytes and does not re-run a device command.',
            },
        ];
    }

    private function assertReplayable(User $actor, MonitoringDeadLetter $letter): RuntimeEnvelope
    {
        if (! in_array($letter->reason_code, ['sequence_gap', 'handler_failed', 'unsupported_version'], true)) {
            throw new UnexpectedValueException('This dead-letter failure is not safe to replay.');
        }

        $envelope = $this->authorise($actor, $letter, true);
        if (! $envelope instanceof RuntimeEnvelope) {
            throw new UnexpectedValueException('Monitoring envelope is unavailable for replay.');
        }

        try {
            $this->registry()->for($envelope->type, $envelope->payloadVersion);
        } catch (UnsupportedRuntimeContractVersion|LogicException $exception) {
            throw new UnexpectedValueException(
                'This signed monitoring contract is not supported by the deployed runtime.',
                previous: $exception,
            );
        }

        return $envelope;
    }

    private function registry(): RuntimeEnvelopeHandlerRegistry
    {
        return $this->handlers ?? app(RuntimeEnvelopeHandlerRegistry::class);
    }

    private function authorise(
        User $actor,
        MonitoringDeadLetter $letter,
        bool $requiresValidEnvelope,
    ): ?RuntimeEnvelope {
        if (! $actor->canDo('securityDevices.integrations.manage')) {
            throw new AuthorizationException('You cannot operate monitoring dead letters.');
        }

        $accessibleSiteIds = $this->access->accessibleSiteIds($actor);

        if ($letter->site_id !== null
            && ! in_array($letter->site_id, $accessibleSiteIds, true)) {
            throw new AuthorizationException('The monitoring site is outside your access scope.');
        }

        try {
            $envelope = $this->codec->decode($letter->envelope_bytes);
        } catch (UnexpectedValueException $exception) {
            if ($requiresValidEnvelope) {
                throw $exception;
            }

            return null;
        }

        $payloadSiteId = $envelope->payload['site_id'] ?? null;

        if ($payloadSiteId !== null
            && (! is_int($payloadSiteId) || ! in_array($payloadSiteId, $accessibleSiteIds, true))) {
            throw new AuthorizationException('A referenced monitoring site is outside your access scope.');
        }

        $deviceId = $envelope->payload['device_id'] ?? null;

        if ($deviceId !== null) {
            if (! is_int($deviceId) || $deviceId < 1) {
                throw new AuthorizationException('The protected monitoring target is invalid.');
            }

            $device = Device::query()->find($deviceId);

            if ($device === null) {
                throw new AuthorizationException('The protected monitoring target is unavailable.');
            }

            $this->access->assertCanViewDevice($actor, $device);
        }

        return $envelope;
    }

    private function validReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new UnexpectedValueException('A bounded operational reason is required.');
        }

        return $reason;
    }
}
