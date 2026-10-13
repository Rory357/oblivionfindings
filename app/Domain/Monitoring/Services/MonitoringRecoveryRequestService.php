<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Models\MonitoringRecoveryRequest;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** One recorded owner decision; recovering it never repeats consumption or a device command. */
final readonly class MonitoringRecoveryRequestService
{
    public function __construct(private MonitoringReplayService $owner) {}

    public function execute(User $claimed, int $letterId, string $operation, array $input): array
    {
        $this->storage();
        abort_unless(in_array($operation, ['replay', 'discard'], true), 400, 'invalid_request');
        $uuid = strtolower($input['request_uuid']);
        $reason = trim($input['reason']);
        $requestHash = hash('sha256', json_encode([$letterId, $operation, $reason, (int) $input['expected_actor_id'], $input['expected_site_id'], $input['review_token']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($claimed, $letterId, $operation, $input, $uuid, $reason, $requestHash): array {
            [$actor, $letter] = $this->authority($claimed, $letterId, $input);
            $receipt = MonitoringRecoveryRequest::query()->useWritePdo()->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($receipt) {
                abort_unless($receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
                abort_unless($receipt->dead_letter_id === $letterId && $receipt->operation === $operation && hash_equals($receipt->request_hash, $requestHash), 409, 'This request belongs to a different recovery decision.');

                return $this->present($receipt, $actor, $letter);
            }
            abort_unless(hash_equals($this->reviewToken($letter), $input['review_token']), 409, 'This recovery record changed after review. Refresh Monitoring.');
            abort_if($letter->resolved_at !== null || $letter->replay_requested_at !== null, 409, 'This message already has a recovery decision. Refresh Monitoring.');
            $reserved = ['request_uuid' => $uuid, 'actor_user_id' => $actor->id, 'dead_letter_id' => $letterId, 'site_id' => $letter->site_id, 'operation' => $operation, 'request_hash' => $requestHash, 'evidence_hash' => $letter->evidence_fingerprint, 'status' => 'reserved', 'created_at' => now()];
            if (DB::table('monitoring_recovery_requests')->insertOrIgnore($reserved) === 0) {
                $receipt = MonitoringRecoveryRequest::query()->useWritePdo()->where('request_uuid', $uuid)->lockForUpdate()->firstOrFail();
                abort_unless($receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
                abort_unless($receipt->dead_letter_id === $letterId && $receipt->operation === $operation && hash_equals($receipt->request_hash, $requestHash), 409, 'This request belongs to a different recovery decision.');

                return $this->present($receipt, $actor, $letter);
            }
            if ($operation === 'replay') {
                $this->owner->replay($actor, $letter, $reason);
            } else {
                $this->owner->discard($actor, $letter, $reason);
            }
            $letter->refresh();
            $receipt = MonitoringRecoveryRequest::query()->where('request_uuid', $uuid)->lockForUpdate()->firstOrFail();
            $receipt->forceFill(['status' => 'committed', 'saved_acknowledgement' => ['dead_letter_id' => $letterId, 'site_id' => $letter->site_id, 'consumer' => $letter->consumer, 'message_reference' => substr($letter->message_id, -8), 'reason_code' => $letter->reason_code, 'confirms' => $operation === 'replay' ? 'replay_request_recorded' : 'discard_recorded', 'confirms_processing' => false, 'confirms_device_command' => false], 'committed_at' => now()])->save();

            return $this->present($receipt, $actor, $letter);
        }, 3);
    }

    public function recover(User $claimed, string $uuid, array $input): array
    {
        $this->storage();
        $probe = MonitoringRecoveryRequest::query()->useWritePdo()->where('request_uuid', strtolower($uuid))->first();
        abort_unless($probe && $probe->actor_user_id === (int) $claimed->id, 404, 'unavailable');

        return DB::transaction(function () use ($claimed, $probe, $input): array {
            [$actor, $letter] = $this->authority($claimed, $probe->dead_letter_id, $input);
            $receipt = MonitoringRecoveryRequest::query()->useWritePdo()->whereKey($probe->id)->lockForUpdate()->firstOrFail();

            return $this->present($receipt, $actor, $letter);
        }, 3);
    }

    public function history(User $claimed, int $expectedActor, ?int $before): array
    {
        $this->storage();

        return DB::transaction(function () use ($claimed, $expectedActor, $before): array {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $claimed->id, ['*']);
            abort_unless((int) $actor->id === $expectedActor && $actor->canDo('securityDevices.integrations.manage') && $actor->approved_at && $actor->email_verified_at && ! $actor->external_clinical_account, 404, 'unavailable');
            $candidates = MonitoringRecoveryRequest::query()->useWritePdo()->where('actor_user_id', $actor->id)->where('status', 'committed')->when($before !== null, fn ($query) => $query->where('id', '<', $before))->orderByDesc('id')->limit(26)->get(['id', 'request_uuid', 'site_id']);
            $page = $candidates->take(25);
            $rows = [];
            foreach ($page as $candidate) {
                try {
                    $rows[] = $this->recover($actor, $candidate->request_uuid, ['expected_actor_id' => $actor->id, 'expected_site_id' => $candidate->site_id]);
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 404 && $error->getStatusCode() !== 403) {
                        throw $error;
                    }
                } catch (ModelNotFoundException) {
                }
            }

            return ['actor_id' => (int) $actor->id, 'rows' => $rows, 'next_before_id' => $candidates->count() > 25 ? (int) $page->last()->id : null];
        }, 3);
    }

    private function authority(User $claimed, int $letterId, array $input): array
    {
        $letter = MonitoringDeadLetter::query()->useWritePdo()->whereKey($letterId)->lockForUpdate()->firstOrFail();
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $claimed->id, ['*']);
        abort_unless((int) $actor->id === (int) $input['expected_actor_id'] && $letter->site_id === $input['expected_site_id'], 404, 'unavailable');
        abort_unless($this->owner->inspect($actor, $letter) !== null, 404, 'unavailable');

        return [$actor, $letter];
    }

    private function present(MonitoringRecoveryRequest $receipt, User $actor, MonitoringDeadLetter $letter): array
    {
        abort_unless($receipt->actor_user_id === (int) $actor->id && $receipt->dead_letter_id === (int) $letter->id && $receipt->site_id === $letter->site_id && hash_equals($receipt->evidence_hash, $letter->evidence_fingerprint), 404, 'unavailable');
        abort_unless($receipt->status === 'committed' && $receipt->saved_acknowledgement && $receipt->committed_at, 503, 'The recovery decision is not confirmed.');

        return ['decision' => $receipt->saved_acknowledgement, 'receipt' => ['schema_version' => 1, 'owner' => 'Monitoring delivery recovery', 'request_uuid' => $receipt->request_uuid, 'actor_id' => $receipt->actor_user_id, 'operation' => $receipt->operation, 'dead_letter_id' => $receipt->dead_letter_id, 'site_id' => $receipt->site_id, 'committed_at' => $receipt->committed_at->utc()->toIso8601String()], 'current' => ['state' => $letter->resolved_at !== null ? ($letter->last_replayed_at !== null ? 'replay_processed' : 'discarded') : ($letter->replay_requested_at !== null ? 'replay_requested' : 'awaiting_decision'), 'replay_count' => (int) $letter->replay_count, 'resolved_at' => $letter->resolved_at?->utc()->toIso8601String()]];
    }

    public function reviewToken(MonitoringDeadLetter $letter): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503, 'Recovery review needs a configured application key.');

        return hash_hmac('sha256', json_encode(['delivery_recovery_review_v1', $letter->id, $letter->site_id, $letter->evidence_fingerprint, $letter->replay_requested_at?->toISOString(), $letter->replay_requested_by_user_id, $letter->replay_intent_token, $letter->resolved_at?->toISOString(), (int) $letter->replay_count], JSON_THROW_ON_ERROR), $key);
    }

    private function storage(): void
    {
        abort_unless(Schema::hasTable('monitoring_recovery_requests'), 503, 'Recovery confirmation needs an application update.');
    }
}
