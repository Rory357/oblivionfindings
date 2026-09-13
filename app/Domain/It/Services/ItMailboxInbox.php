<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundAttachmentUnavailable;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\InboundEmailIngestor;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\MicrosoftGraphService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Bounded, restartable discovery and acknowledgement on the existing inbound records. */
final class ItMailboxInbox
{
    public function __construct(private readonly ItMailboxPollState $state) {}

    /** True only when the captured discovery chain and every staged acknowledgement finished. */
    public function drain(ItMailboxConnection $claim, GoogleGmailService|MicrosoftGraphService $provider, InboundEmailIngestor $ingestor): bool
    {
        $staging = app(ItInboundAttachmentStaging::class);
        $scope = $claim->mailboxScopeHash();
        $current = $this->state->withinClaim($claim, function (ItMailboxConnection $current) use ($scope) {
            if ($current->inbox_scan_scope !== $scope || $current->inbox_scan_before === null) {
                $current->forceFill([
                    'inbox_scan_scope' => $scope, 'inbox_scan_before' => now()->utc()->startOfSecond(),
                    'inbox_scan_cursor' => null, 'inbox_scan_cursor_hashes' => [], 'inbox_scan_complete' => false,
                ])->save();
            }

            return $current;
        });

        // No unread mutation until discovery finishes: our acknowledgements cannot shift offsets.
        for ($pageNumber = 0; ! $current->inbox_scan_complete && $pageNumber < 5; $pageNumber++) {
            $this->state->renew($claim);
            $page = $provider->discoverUnread((string) $claim->mailboxEmail(), $current->inbox_scan_before->copy()->utc(), $current->inbox_scan_cursor);
            $current = $this->state->withinClaim($claim, function (ItMailboxConnection $current) use ($scope, $page) {
                $hashes = $current->inbox_scan_cursor_hashes ?? [];
                if ($page->continuation !== null) {
                    $hash = hash('sha256', $page->continuation);
                    if (in_array($hash, $hashes, true) || count($hashes) >= 2000) {
                        throw new MailboxProviderFailure('invalid_response');
                    }
                    $hashes[] = $hash;
                }
                foreach (array_unique($page->remoteIds) as $id) {
                    $key = hash('sha256', $scope."\0".$id);
                    if (! ItInboundEmail::query()->where('transport_key', $key)->exists()) {
                        $receipt = new ItInboundEmail;
                        $receipt->forceFill([
                            'transport_key' => $key,
                            'it_mailbox_connection_id' => $current->id, 'mailbox_scope_hash' => $scope,
                            'remote_message_id' => $id, 'from_email' => '', 'status' => 'pending',
                        ])->save();
                    }
                }
                $current->forceFill([
                    'inbox_scan_cursor' => $page->continuation, 'inbox_scan_cursor_hashes' => $hashes,
                    'inbox_scan_complete' => $page->continuation === null,
                ])->save();

                return $current;
            });
        }
        if (! $current->inbox_scan_complete) {
            return false;
        }

        $receipts = $this->pending($scope)->where(fn ($query) => $query->whereNull('transport_retry_at')->orWhere('transport_retry_at', '<=', now()))
            ->orderBy('id')->limit(100)->get();
        $lastFailure = null;
        foreach ($receipts as $receipt) {
            $this->state->renew($claim);
            try {
                if ($receipt->status === 'pending') {
                    $hasStaging = false;
                    $this->state->withinClaim($claim, fn () => $receipt->forceFill([
                        'processing_attempts' => min(1000000, $receipt->processing_attempts + 1),
                    ])->save());
                    try {
                        $message = $provider->readMessage((string) $claim->mailboxEmail(), $receipt->remote_message_id, includeAttachments: true);
                        $files = $message['attachments'];
                        $hasStaging = $files !== [] || $receipt->attachment_manifest_hash !== null;
                        if ($hasStaging) {
                            $staging->receive($claim, $receipt, $files,
                                fn ($file) => $provider->readAttachmentContents((string) $claim->mailboxEmail(), $receipt->remote_message_id, $file));
                        }
                    } catch (ItInboundHeaderException|ItInboundContentException $failure) {
                        $this->state->withinClaim($claim, fn () => $ingestor->rejectMessage($receipt, $failure));
                        $message = null;
                    }
                    if ($message !== null) {
                        $attachmentContext = new ItAttachmentWriteContext;
                        $this->state->withinClaim($claim, function () use ($claim, $message, $receipt, $attachmentContext, $hasStaging, $staging, $ingestor): void {
                            $preparedFiles = $hasStaging ? $staging->filesForIngestion($claim, $receipt) : [];
                            $result = $ingestor->ingest($message, $receipt, $attachmentContext, $preparedFiles);
                            if ($hasStaging && in_array($result->status, ['processed', 'duplicate'], true)) {
                                $staging->finish($receipt);
                            }
                        }, $attachmentContext);
                    }
                }
                // Accepted receipt retries finish private cleanup without fetching or ingesting again.
                $receipt->refresh();
                if ($receipt->attachment_manifest_hash !== null && in_array($receipt->status, ['processed', 'duplicate'], true)) {
                    $cleanup = $staging->cleanupPending(receiptId: (int) $receipt->id);
                    if ($cleanup['failed'] > 0) {
                        throw new ItInboundAttachmentUnavailable('attachment_cleanup_pending');
                    }
                }
                // Ingestion and its receipt commit together. ACK retries never re-fetch or re-ingest.
                $this->state->withinClaim($claim, fn () => $receipt->forceFill([
                    'acknowledgement_attempts' => min(1000000, $receipt->acknowledgement_attempts + 1),
                ])->save());
                $provider->markRead((string) $claim->mailboxEmail(), $receipt->remote_message_id);
                $this->state->withinClaim($claim, fn () => $receipt->forceFill([
                    'acknowledged_at' => now(), 'transport_failure_code' => null, 'transport_retry_at' => null,
                ])->save());
            } catch (ItMailboxPollSuperseded $failure) {
                throw $failure;
            } catch (Throwable $failure) {
                $code = $failure instanceof MailboxProviderFailure ? $failure->reason
                    : ($failure instanceof ItInboundAttachmentUnavailable ? $failure->reason
                        : ($failure instanceof ProviderRateLimited ? 'rate_limited' : 'processing_failed'));
                $delay = min(3600, 60 * (2 ** min(6, max(0, max($receipt->processing_attempts, $receipt->acknowledgement_attempts) - 1))));
                if ($failure instanceof ProviderRateLimited) {
                    $delay = max($delay, $failure->retryAfterSeconds);
                }
                $this->state->withinClaim($claim, fn () => $receipt->refresh()->forceFill([
                    'transport_failure_code' => $code, 'transport_retry_at' => now()->addSeconds($delay),
                ])->save());
                // Respect provider-wide denial/throttling immediately; other message failures don't starve the batch.
                if (in_array($code, ['authentication', 'permission', 'configuration', 'rate_limited'], true)) {
                    throw $failure;
                }
                $lastFailure = $failure;
            }
        }
        if ($lastFailure !== null) {
            throw $lastFailure;
        }

        return ! $this->pending($scope)->exists();
    }

    private function pending(string $scope): Builder
    {
        return ItInboundEmail::query()->where('mailbox_scope_hash', $scope)->whereNull('acknowledged_at');
    }
}
