<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundAttachmentUnavailable;
use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Models\ItMailboxConnection;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Persisted ownership and retry state on the canonical mailbox connection. */
final class ItMailboxPollState
{
    public function claim(int $connectionId, ?int $expectedVersion = null): ?ItMailboxConnection
    {
        return DB::transaction(function () use ($connectionId, $expectedVersion) {
            $connection = ItMailboxConnection::query()->whereKey($connectionId)->lockForUpdate()->first();
            if (! $connection || ! in_array($connection->status, ['connected', 'error'], true)
                || ($expectedVersion !== null && $connection->configuration_version !== $expectedVersion)
                || $connection->last_poll_failure_code === 'authentication'
                || $connection->next_poll_at?->isFuture()
                || ($connection->poll_claim_token !== null && $connection->poll_claim_expires_at?->isFuture())) {
                return null;
            }
            $connection->forceFill([
                'poll_claim_token' => (string) Str::uuid(),
                'poll_claim_expires_at' => now()->addMinutes(20),
                'last_poll_attempt_at' => now(),
            ])->save();

            return $connection;
        });
    }

    public function current(ItMailboxConnection $claim, bool $lock = false): ItMailboxConnection
    {
        $query = ItMailboxConnection::query()->whereKey($claim->id)
            ->where('poll_claim_token', $claim->poll_claim_token)
            ->where('configuration_version', $claim->configuration_version)
            ->where('poll_claim_expires_at', '>', now())
            ->whereIn('status', ['connected', 'error']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $claim->poll_claim_token !== null ? $query->first() : null;
        if (! $current || ! $current->poll_claim_expires_at?->isFuture()) {
            throw new ItMailboxPollSuperseded;
        }

        return $current;
    }

    /** Serialize local ingestion with disconnect/reconfiguration; never wrap provider HTTP here. */
    public function withinClaim(ItMailboxConnection $claim, Closure $action, ?ItAttachmentWriteContext $attachmentContext = null): mixed
    {
        if ($attachmentContext !== null) {
            return $attachmentContext->transaction(fn () => $action($this->current($claim, lock: true)));
        }

        return DB::transaction(fn () => $action($this->current($claim, lock: true)));
    }

    public function renew(ItMailboxConnection $claim): void
    {
        $this->withinClaim($claim, fn (ItMailboxConnection $current) => $current->forceFill([
            'poll_claim_expires_at' => now()->addMinutes(20),
        ])->save());
    }

    public function complete(ItMailboxConnection $claim): void
    {
        $this->withinClaim($claim, fn (ItMailboxConnection $current) => $current->forceFill([
            'status' => ItMailboxConnection::STATUS_CONNECTED,
            'last_polled_at' => now(), 'last_error' => null,
            'last_poll_failure_code' => null, 'consecutive_poll_failures' => 0,
            'next_poll_at' => null, 'poll_claim_token' => null, 'poll_claim_expires_at' => null,
            'inbox_scan_before' => null, 'inbox_scan_cursor' => null,
            'inbox_scan_cursor_hashes' => null, 'inbox_scan_complete' => false,
        ])->save());
    }

    /** Yield bounded work to the next scheduled/manual poll without claiming a complete inbox. */
    public function pause(ItMailboxConnection $claim): void
    {
        $this->withinClaim($claim, fn (ItMailboxConnection $current) => $current->forceFill([
            'poll_claim_token' => null, 'poll_claim_expires_at' => null, 'next_poll_at' => null,
            ...($current->inbox_scan_complete ? $this->freshScan() : []),
        ])->save());
    }

    private function freshScan(): array
    {
        return ['inbox_scan_before' => null, 'inbox_scan_cursor' => null,
            'inbox_scan_cursor_hashes' => null, 'inbox_scan_complete' => false];
    }

    /** No raw exception, message body, URL or credential is persisted. */
    public function fail(ItMailboxConnection $claim, Throwable $failure): void
    {
        $this->withinClaim($claim, function (ItMailboxConnection $current) use ($failure): void {
            $code = $failure instanceof MailboxProviderFailure ? $failure->reason
                : ($failure instanceof ItInboundAttachmentUnavailable ? $failure->reason
                    : ($failure instanceof ProviderRateLimited ? 'rate_limited' : 'processing_failed'));
            $message = $failure instanceof MailboxProviderFailure || $failure instanceof ProviderRateLimited || $failure instanceof ItInboundAttachmentUnavailable
                ? $failure->getMessage() : 'Mailbox processing could not finish. Review the failed poll before retrying.';
            $failures = min(1000, (int) $current->consecutive_poll_failures + 1);
            $delay = min(3600, 60 * (2 ** min(6, $failures - 1)));
            if ($failure instanceof ProviderRateLimited) {
                // HTTP boundary validates the interval before it reaches persisted scheduling.
                $delay = max($delay, $failure->retryAfterSeconds);
            }
            $current->forceFill([
                'status' => ItMailboxConnection::STATUS_ERROR, 'last_error' => $message,
                'last_poll_failure_code' => $code, 'consecutive_poll_failures' => $failures,
                'next_poll_at' => $code === 'authentication' ? null : now()->addSeconds($delay),
                'poll_claim_token' => null, 'poll_claim_expires_at' => null,
                // Finished discovery must not pin new mail behind one failed old message.
                // Invalid/expired cursors restart safely against existing unique receipts.
                ...($current->inbox_scan_complete || in_array($code, ['invalid_response', 'rejected'], true) ? $this->freshScan() : []),
            ])->save();
        });
    }
}
