<?php

namespace App\Services\HealthSafety;

use App\Jobs\DispatchLoneWorkerEmergency;
use App\Models\LoneWorkerEmergencyEpisode;
use App\Models\LoneWorkerSession;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Durable source intent converges through the existing Control Room pipeline. */
class LoneWorkerEmergencyDeliveryService
{
    public function __construct(private readonly LoneWorkerSessionAccessService $access, private readonly LoneWorkerSignalService $signals) {}

    public function stage(LoneWorkerSession $session, User $actor): LoneWorkerEmergencyEpisode
    {
        if (DB::transactionLevel() < 1 || $session->status !== 'emergency' || $session->emergency_episode_number < 1) {
            throw new \LogicException('Emergency delivery must be staged in its locked source command.');
        }
        $siteId = CurrentAuthorizationReads::within(fn ($reads) => $this->access->assertSource($session, $reads));
        $episode = LoneWorkerEmergencyEpisode::query()->create([
            'lone_worker_session_id' => $session->id, 'site_id' => $siteId, 'episode_number' => $session->emergency_episode_number,
            'initiated_by_user_id' => $actor->id, 'occurred_at' => $session->emergency_triggered_at,
            'status' => 'pending', 'signal_key' => hash('sha256', 'lone_worker:emergency:'.$session->id.':'.$session->emergency_episode_number), 'next_attempt_at' => now(),
        ]);
        DB::afterCommit(fn () => $this->queue($episode->id));

        return $episode;
    }

    public function queue(int $id): void
    {
        $initialAttempts = null;
        try {
            $initialAttempts = LoneWorkerEmergencyEpisode::query()->find($id)?->attempts;
            DispatchLoneWorkerEmergency::dispatch($id);
        } catch (Throwable $exception) {
            Log::warning('Lone-worker emergency dispatch unavailable', ['episode_id' => $id, 'exception_type' => $exception::class]);
            try {
                if (LoneWorkerEmergencyEpisode::query()->find($id)?->attempts === $initialAttempts) {
                    $this->failure($id, 'queue_unavailable');
                }
            } catch (Throwable $receiptError) {
                Log::warning('Lone-worker emergency failure receipt unavailable', ['episode_id' => $id, 'exception_type' => $receiptError::class]);
            }
        }
    }

    public function deliver(int $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $original = LoneWorkerEmergencyEpisode::query()->find($id);
                if (! $original) {
                    return;
                }
                $session = LoneWorkerSession::query()->whereKey($original->lone_worker_session_id)->lockForUpdate()->first();
                $episode = LoneWorkerEmergencyEpisode::query()->whereKey($id)->lockForUpdate()->first();
                if (! $episode || ! in_array($episode->status, ['pending', 'failed'], true) || $episode->next_attempt_at?->isFuture()) {
                    return;
                }
                if (! $session || $session->ended_at !== null || $session->status !== 'emergency' || (int) $session->emergency_episode_number !== (int) $episode->episode_number) {
                    $this->skip($episode, 'source_inactive');

                    return;
                }
                $worker = User::query()->find($session->user_id);
                if (! $worker) {
                    $this->skip($episode, 'source_authority_revoked');

                    return;
                }
                try {
                    $this->access->withCurrentViewer($worker, function ($current, $reads, $sites) use ($session, $episode): void {
                        $this->access->assertRecord($session, $current, $sites, false, true);
                        if ((int) $session->site->id !== (int) $episode->site_id) {
                            $this->skip($episode, 'source_authority_revoked');

                            return;
                        }
                        $canonicalSource = clone $session;
                        $canonicalSource->forceFill(['site_id' => $episode->site_id]);
                        $receipt = $this->signals->emitEmergencyEpisode($canonicalSource, $episode);
                        $episode->forceFill([...$receipt, 'attempts' => $episode->attempts + 1, 'failure_code' => null,
                            'next_attempt_at' => null, 'delivered_at' => $receipt['status'] === 'delivered' ? now() : null])->save();
                    });
                } catch (HttpException $exception) {
                    if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                        throw $exception;
                    }
                    $this->skip($episode, 'source_authority_revoked');
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->failure($id, $exception instanceof \DomainException ? 'correlation_conflict' : 'signal_delivery_failed');
            throw $exception;
        }
    }

    private function skip(LoneWorkerEmergencyEpisode $episode, string $code): void
    {
        $episode->forceFill(['status' => 'skipped', 'failure_code' => $code, 'next_attempt_at' => null, 'attempts' => $episode->attempts + 1])->save();
    }

    private function failure(int $id, string $code): void
    {
        DB::transaction(function () use ($id, $code): void {
            $episode = LoneWorkerEmergencyEpisode::query()->whereKey($id)->lockForUpdate()->first();
            if (! $episode || ! in_array($episode->status, ['pending', 'failed'], true)) {
                return;
            }
            $attempts = $episode->attempts + 1;
            $episode->forceFill(['status' => 'failed', 'failure_code' => $code, 'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** min(5, $attempts - 1))))])->save();
        }, 3);
    }

    public function recoverPending(int $limit = 30): int
    {
        $ids = LoneWorkerEmergencyEpisode::query()->whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $this->queue((int) $id);
        }

        return $ids->count();
    }
}
