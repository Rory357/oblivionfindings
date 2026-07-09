<?php

namespace App\Jobs;

use App\Domain\It\InboundEmailIngestor;
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

    public function handle(InboundEmailIngestor $ingestor): void
    {
        ItMailboxConnection::query()
            ->connected()
            ->get()
            ->each(fn (ItMailboxConnection $connection) => $this->poll($connection, $ingestor));
    }

    private function poll(ItMailboxConnection $connection, InboundEmailIngestor $ingestor): void
    {
        $mailbox = $connection->mailboxEmail();
        if (! $mailbox) {
            return;
        }

        // Both services expose the same listUnreadMessages/markRead pair over
        // the shared CalendarOAuthToken, so the poll below is provider-blind.
        $service = match ($connection->provider) {
            ItMailboxConnection::PROVIDER_MICROSOFT => new MicrosoftGraphService($connection),
            ItMailboxConnection::PROVIDER_GOOGLE => new GoogleGmailService($connection),
            default => null,
        };
        if (! $service) {
            return;
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
        } catch (\Throwable $e) {
            Log::error("IT mailbox poll failed for connection #{$connection->id}: {$e->getMessage()}");
            $connection->update([
                'status' => ItMailboxConnection::STATUS_ERROR,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);
        }
    }
}
