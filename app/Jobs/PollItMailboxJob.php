<?php

namespace App\Jobs;

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Services\GoogleGmailService;
use App\Services\MicrosoftGraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function handle(
        InboundEmailIngestor $ingestor,
        ?ItAutomationRunRecorder $recorder = null,
    ): void {
        // Keep the original direct-call contract used by operational tools
        // and tests while Laravel still injects the recorder in queued work.
        $recorder ??= app(ItAutomationRunRecorder::class);
        $startedAt = microtime(true);
        $run = $recorder->begin('it.poll-mailbox', '0 * * * *');
        try {
            $connections = ItMailboxConnection::query()
                ->connected()
                ->get();
            $failed = 0;
            foreach ($connections as $connection) {
                $failed += $this->poll($connection, $ingestor) ? 0 : 1;
            }
            $recorder->completeRun(
                $run,
                $failed > 0 ? 'failed' : 'succeeded',
                (int) round((microtime(true) - $startedAt) * 1000),
                $failed > 0 ? "{$failed} mailbox connection(s) failed." : null,
                ['connections' => $connections->count(), 'failed' => $failed],
            );
        } catch (Throwable $exception) {
            $recorder->completeRun(
                $run,
                'failed',
                (int) round((microtime(true) - $startedAt) * 1000),
                Str::limit($exception->getMessage(), 2000, ''),
            );

            throw $exception;
        }
    }

    private function poll(ItMailboxConnection $connection, InboundEmailIngestor $ingestor): bool
    {
        $mailbox = $connection->mailboxEmail();
        if (! $mailbox) {
            return false;
        }

        // Both services expose the same listUnreadMessages/markRead pair over
        // the shared CalendarOAuthToken, so the poll below is provider-blind.
        $service = match ($connection->provider) {
            ItMailboxConnection::PROVIDER_MICROSOFT => new MicrosoftGraphService($connection),
            ItMailboxConnection::PROVIDER_GOOGLE => new GoogleGmailService($connection),
            default => null,
        };
        if (! $service) {
            return false;
        }

        try {
            foreach ($service->listUnreadMessages($mailbox) as $message) {
                // Retry-safe: a message that was ingested but whose markRead
                // failed reappears unread — never ticket it twice.
                $alreadyIngested = ! empty($message['message_id'])
                    && ItInboundEmail::query()->where('message_id', $message['message_id'])->exists();

                if (! $alreadyIngested) {
                    $ingestor->ingest($message);
                }

                $service->markRead($mailbox, $message['remote_id']);
            }

            $connection->update(['last_polled_at' => now(), 'last_error' => null]);

            return true;
        } catch (Throwable $e) {
            Log::error("IT mailbox poll failed for connection #{$connection->id}: {$e->getMessage()}");
            $connection->update([
                'status' => ItMailboxConnection::STATUS_ERROR,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);

            return false;
        }
    }
}
