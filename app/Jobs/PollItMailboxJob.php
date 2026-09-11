<?php

namespace App\Jobs;

use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItMailboxInbox;
use App\Domain\It\Services\ItMailboxPollingToken;
use App\Domain\It\Services\ItMailboxPollState;
use App\Models\ItMailboxConnection;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\MicrosoftGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * E4 — the email-in pull ingress (mirrors SyncCalendarJob). For every
 * connected support-mailbox connection: pull unread mail, feed each message
 * through InboundEmailIngestor (new ticket or threaded reply), then mark it
 * read so the next poll doesn't see it again. Messages whose message_id was
 * already ingested are skipped — a markRead that failed mid-poll must not
 * duplicate tickets. Per-connection failures stamp status/last_error and
 * never take down the other connections. Inert until a connection exists.
 */
class PollItMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $connectionId = null, public ?int $configurationVersion = null) {}

    public function handle(
        InboundEmailIngestor $ingestor,
        ?ItAutomationRunRecorder $recorder = null,
        ?ItMailboxPollState $state = null,
    ): void {
        // Keep the original direct-call contract used by operational tools
        // and tests while Laravel still injects the recorder in queued work.
        $recorder ??= app(ItAutomationRunRecorder::class);
        $state ??= app(ItMailboxPollState::class);
        $startedAt = microtime(true);
        $run = $recorder->begin('it.poll-mailbox', '0 * * * *');
        try {
            $connections = ItMailboxConnection::query()
                ->whereIn('status', [ItMailboxConnection::STATUS_CONNECTED, ItMailboxConnection::STATUS_ERROR])
                ->when($this->connectionId !== null, fn ($query) => $query->whereKey($this->connectionId))
                ->when($this->configurationVersion !== null, fn ($query) => $query->where('configuration_version', $this->configurationVersion))
                ->get();
            $failed = 0;
            $skipped = 0;
            $pending = 0;
            foreach ($connections as $connection) {
                $result = $this->poll($connection, $ingestor, $state);
                $failed += $result === false ? 1 : 0;
                $skipped += $result === null ? 1 : 0;
                $pending += $result === 'pending' ? 1 : 0;
            }
            $recorder->completeRun(
                $run,
                $failed > 0 ? 'failed' : 'succeeded',
                (int) round((microtime(true) - $startedAt) * 1000),
                $failed > 0 ? "{$failed} mailbox connection(s) failed." : null,
                ['connections' => $connections->count(), 'failed' => $failed, 'skipped' => $skipped, 'pending' => $pending],
            );
        } catch (Throwable $exception) {
            $recorder->completeRun(
                $run,
                'failed',
                (int) round((microtime(true) - $startedAt) * 1000),
                'Mailbox polling could not finish. Review connection recovery status.',
            );

            throw new RuntimeException('Mailbox polling could not finish. Review connection recovery status.');
        }
    }

    private function poll(ItMailboxConnection $connection, InboundEmailIngestor $ingestor, ItMailboxPollState $state): bool|string|null
    {
        $connection = $state->claim((int) $connection->id, $this->configurationVersion);
        if (! $connection) {
            return null;
        }

        try {
            $mailbox = $connection->mailboxEmail();
            if (! $mailbox) {
                throw new MailboxProviderFailure('configuration');
            }
            $token = new ItMailboxPollingToken($connection, $state);
            $service = match ($connection->provider) {
                ItMailboxConnection::PROVIDER_MICROSOFT => new MicrosoftGraphService($token),
                ItMailboxConnection::PROVIDER_GOOGLE => new GoogleGmailService($token),
                default => throw new MailboxProviderFailure('configuration'),
            };
            if (! (new ItMailboxInbox($state))->drain($connection, $service, $ingestor)) {
                $state->pause($connection);

                return 'pending';
            }

            $state->complete($connection);

            return true;
        } catch (ItMailboxPollSuperseded) {
            // Reconfiguration/disconnect or a replacement lease owns the next decision.
            return null;
        } catch (Throwable $e) {
            Log::error('IT mailbox poll failed.', [
                'connection_id' => $connection->id,
                'failure_code' => $e instanceof MailboxProviderFailure ? $e->reason
                    : ($e instanceof ProviderRateLimited ? 'rate_limited' : 'processing_failed'),
            ]);
            try {
                $state->fail($connection, $e);
            } catch (ItMailboxPollSuperseded) {
                return null;
            }

            return false;
        }
    }
}
