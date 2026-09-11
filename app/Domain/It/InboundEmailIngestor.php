<?php

namespace App\Domain\It;

use App\Domain\It\Data\ItScannedEmailAttachment;
use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Services\ItAttachmentWriteContext;
use App\Domain\It\Services\ItEmailContent;
use App\Domain\It\Services\ItEmailHeaders;
use App\Domain\It\Services\ItEmailMessageIdentifiers;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Transport-agnostic, fail-closed email-to-ticket ingestion.
 *
 * Ticket references are global immutable identities. A reply is accepted only
 * after one exact reference and one current sender responsibility are proven.
 * Quarantined messages retain no body preview. Full normalized message identity
 * is claimed in the existing inbound ledger in the same transaction as ingestion.
 */
class InboundEmailIngestor
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketReferenceResolver $ticketReferences,
        private readonly ItTicketIntakeService $intake,
        private readonly ItTicketInteractionService $interaction,
    ) {}

    /** A provider identified content requiring review on this owned discovery receipt. */
    public function rejectMessage(ItInboundEmail $receipt, ItInboundHeaderException|ItInboundContentException $failure): ItInboundEmail
    {
        if ($receipt->quarantine_retry_requested_at !== null) {
            return $this->rejectRetry($receipt, 'retry_message_invalid');
        }
        $message = $this->normalize(['from' => '']);
        $message['_receipt'] = $receipt;

        return $this->quarantine($message, $failure->reason);
    }

    /**
     * @param  array{from: string, subject?: string|null, text?: string|null, message_id?: string|null, in_reply_to?: string|null}  $message
     */
    public function ingest(array $message, ?ItInboundEmail $discovered = null, ?ItAttachmentWriteContext $attachmentContext = null, array $attachments = []): ItInboundEmail
    {
        $attachmentContext?->assertActive();
        if (! array_is_list($attachments) || count($attachments) > 5) {
            throw new \LogicException('Email files require a bounded prepared batch.');
        }
        $fileFingerprints = [];
        foreach ($attachments as $file) {
            if (! $file instanceof ItScannedEmailAttachment || $discovered === null || $attachmentContext === null
                || $file->inboundEmailId !== (int) $discovered->id) {
                throw new \LogicException('Email files require their canonical receipt and transaction owner.');
            }
            $file->scanEvidence();
            $fileFingerprints[] = [$file->getClientOriginalName(), $file->getSize(), $file->contentHash];
        }
        $message = $this->normalize($message);
        // Only a trusted server-side caller can supply a discovered canonical receipt.
        $message['_receipt'] = $discovered;
        $messageId = $message['message_id'];

        if ($message['_failure'] !== null) {
            if ($discovered?->quarantine_retry_requested_at !== null) {
                return $this->rejectRetry($discovered, 'retry_message_invalid');
            }

            return $this->quarantine($message, $message['_failure']);
        }
        if ($messageId === null) {
            if ($discovered?->quarantine_retry_requested_at !== null) {
                return $this->rejectRetry($discovered, 'retry_identity_changed');
            }

            return $this->quarantine($message, 'missing_message_id');
        }

        return DB::transaction(function () use ($message, $messageId, $attachmentContext, $attachments, $fileFingerprints): ItInboundEmail {
            $identity = (new ItEmailMessageIdentifiers)->hash($messageId);
            if (ItEmailDelivery::query()->where('rfc_message_id_hash', $identity)->exists()) {
                return $this->quarantine($message, 'automatic_message');
            }
            $identityContent = [
                $message['from'], $message['subject'], $message['text'],
                $message['parent_ids'], $message['reference_ids'],
            ];
            if ($fileFingerprints !== []) {
                $identityContent[] = $fileFingerprints;
            }
            $content = hash_hmac('sha256', json_encode($identityContent, JSON_THROW_ON_ERROR), (string) config('app.key'));
            $message['_identity'] = $identity;
            $message['_content'] = $content;
            $retry = $message['_receipt'];
            if ($retry?->quarantine_retry_requested_at !== null) {
                // Recovery is the same immutable receipt, never permission to ingest changed mail.
                if ($retry->status !== 'pending' || ! is_string($retry->message_identity_hash)
                    || ! hash_equals($retry->message_identity_hash, $identity)
                    || $retry->identity_claim_key !== $identity) {
                    return $this->rejectRetry($retry, 'retry_identity_changed');
                }
                if (! is_string($retry->message_content_hash) || ! hash_equals($retry->message_content_hash, $content)) {
                    return $this->rejectRetry($retry, 'retry_message_changed');
                }
                $owners = $this->identityOwners($identity);
                if ($owners->count() !== 1 || (int) $owners->first()->id !== (int) $retry->id) {
                    return $this->rejectRetry($retry, 'retry_identity_changed');
                }

                return $this->ingestOnce($message, $attachmentContext, $attachments);
            }
            $existing = $this->identityOwners($identity);
            if ($existing->count() > 1) {
                return $this->quarantine($message, 'message_id_collision');
            }
            if ($existing->isNotEmpty()) {
                return $this->duplicate($message, $existing->first());
            }
            $receipt = $message['_receipt'] ?? new ItInboundEmail;
            try {
                // A savepoint keeps unique-claim contention recoverable without swallowing
                // unrelated SQL failures or committing part of a mailbox claim transaction.
                DB::transaction(fn () => $receipt->forceFill([
                    'message_identity_hash' => $identity, 'identity_claim_key' => $identity,
                    'message_content_hash' => $content, 'normalized_message_id' => $messageId,
                    'parent_message_ids' => $message['parent_ids'],
                    'reference_message_ids' => $message['reference_ids'],
                    'from_email' => $message['from'], 'status' => 'pending',
                ])->save());
            } catch (UniqueConstraintViolationException $failure) {
                $existing = $this->identityOwners($identity);
                if ($existing->count() !== 1) {
                    throw $failure;
                }
                if ($receipt->exists) {
                    $receipt->refresh();
                }

                return $this->duplicate($message, $existing->first());
            }
            $message['_receipt'] = $receipt;

            return $this->ingestOnce($message, $attachmentContext, $attachments);
        }, attempts: 3);
    }

    private function identityOwners(string $identity): Collection
    {
        return ItInboundEmail::query()->where('message_identity_hash', $identity)
            ->where(fn ($query) => $query->whereNotNull('identity_claim_key')
                ->orWhere(fn ($legacy) => $legacy->whereNull('message_content_hash')->whereNull('duplicate_of_id')))
            ->lockForUpdate()->limit(2)->get();
    }

    private function duplicate(array $message, ItInboundEmail $existing): ItInboundEmail
    {
        if ($existing->status === 'pending') {
            throw new \RuntimeException('The original email processing outcome is not yet available.');
        }
        if ($existing->message_content_hash === null) {
            return $this->quarantine($message, 'legacy_message_identity_unverified');
        }
        if (! hash_equals($existing->message_content_hash, $message['_content'])) {
            return $this->quarantine($message, 'message_id_collision');
        }
        if ($existing->it_ticket_id !== null) {
            $sender = User::query()->whereRaw('LOWER(email) = ?', [$message['from']])->first();
            if (! $sender || ! $this->senderIsActive($sender) || ! $existing->ticket
                || ! $this->workAccess->canView($sender, $existing->ticket)) {
                return $this->quarantine($message, 'sender_unauthorized');
            }
        }
        if (! $message['_receipt']) {
            return $existing;
        }
        $receipt = $this->log($existing->it_ticket_id, $message, null, 'duplicate');
        $receipt->forceFill(['duplicate_of_id' => $existing->id, 'identity_claim_key' => null])->save();

        return $receipt;
    }

    /**
     * @param  array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}  $message
     */
    private function ingestOnce(array $message, ?ItAttachmentWriteContext $attachmentContext, array $attachments): ItInboundEmail
    {
        return DB::transaction(function () use ($message, $attachmentContext, $attachments): ItInboundEmail {
            $sender = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($message['from'])])
                ->first();

            if (! $sender) {
                return $this->quarantine($message, 'sender_unknown');
            }
            if (! $this->senderIsActive($sender)) {
                return $this->quarantine($message, 'sender_inactive');
            }
            $requestUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'oblivion-findings/it/inbound/'.$message['message_id'])->toString();

            $resolution = $this->ticketReferences->resolveInbound(
                $this->referencesFrom($message['subject']),
                array_values(array_unique([...$message['parent_ids'], ...$message['reference_ids']])),
                $sender,
            );
            if ($resolution['failure'] !== null) {
                return $this->quarantine($message, $resolution['failure']);
            }

            if ($resolution['ticket'] !== null) {
                $ticket = $resolution['ticket'];
                if (! $ticket instanceof ItTicket) {
                    return $this->quarantine($message, 'reference_not_found');
                }
                $ticket = ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                if ($ticket->isMerged()) {
                    // A merge won after reference resolution. Roll back the identity claim;
                    // the next attempt must resolve and authorize its new destination.
                    throw new \RuntimeException('The email reference changed during processing. Retry its original receipt.');
                }
                $authorizationFailure = $this->replyAuthorizationFailure($sender, $ticket);
                if ($authorizationFailure !== null) {
                    return $this->quarantine($message, $authorizationFailure);
                }

                if (! in_array($ticket->status, ItTicket::OPEN_STATUSES, true)
                    && (! in_array($ticket->status, ['resolved', 'closed'], true) || ! $sender->can('reopen', $ticket))) {
                    // DP04 preserves the existing requester window and staff authority.
                    // Other settled replies need the governed related-request decision.
                    return $this->quarantine($message, 'settled_reference_requires_related_request');
                }
                $this->interaction->addCommentCommand($ticket, $sender, [
                    'request_uuid' => $requestUuid,
                    'actor_user_id' => $sender->id,
                    'expected_version' => (int) $ticket->lock_version,
                    'body' => $message['text'] !== '' ? $message['text'] : '(no message body)',
                    'is_internal' => false,
                ], attachments: $attachments, channel: ItTicketCommandChannel::Email, attachmentContext: $attachmentContext);

                return $this->log($ticket->id, $message, $this->preview($message['text']), 'processed');
            }

            if (! Gate::forUser($sender)->allows('create', ItTicket::class)) {
                return $this->quarantine($message, 'sender_not_allowed');
            }
            $siteId = $this->workAccess->defaultSiteId($sender);
            if ($siteId === null || ! $this->workAccess->canAssignScope($sender, $siteId, false)) {
                return $this->quarantine($message, 'sender_site_unresolved');
            }

            $ticket = $this->intake->createCommand($sender, [
                'request_uuid' => $requestUuid,
                'site_id' => $siteId,
                'is_organisation_wide' => false,
                'title' => $this->titleFrom($message['subject'], $sender),
                'description' => $message['text'] !== '' ? $message['text'] : null,
                'requester_user_id' => $sender->id,
                'category' => 'other',
                'priority' => 'normal',
            ], attachments: $attachments, channel: ItTicketCommandChannel::Email, attachmentContext: $attachmentContext)->ticket;

            return $this->log($ticket->id, $message, $this->preview($message['text']), 'processed');
        });
    }

    private function replyAuthorizationFailure(User $sender, ItTicket $ticket): ?string
    {
        $participant = (int) $ticket->requester_user_id === (int) $sender->id
            || (int) $ticket->requested_for_user_id === (int) $sender->id;
        if ($participant && $this->workAccess->canView($sender, $ticket)) {
            return null;
        }

        if ($ticket->is_sensitive && ! $sender->canDo('it.viewSensitive')) {
            return 'sensitive_work';
        }

        $watcher = $ticket->watchers()->whereKey($sender->id)->exists();
        if ($watcher && $this->workAccess->canView($sender, $ticket)) {
            return null;
        }

        if ($this->workAccess->isResponsibleStaff($sender, $ticket)
            && $this->workAccess->canWork($sender, $ticket)) {
            return null;
        }

        if ($this->isExactConnectedMailboxPrincipal($sender)
            && $this->workAccess->canWork($sender, $ticket)) {
            return null;
        }

        return 'sender_unauthorized';
    }

    private function senderIsActive(User $sender): bool
    {
        if ($sender->approved_at === null) {
            return false;
        }

        $sender->loadMissing('hrEmployeeProfile');
        $profile = $sender->hrEmployeeProfile;
        if ($profile === null) {
            return true;
        }

        return $profile->is_active
            && ($profile->start_date === null || $profile->start_date->lte(today()))
            && ($profile->end_date === null || $profile->end_date->gte(today()));
    }

    private function isExactConnectedMailboxPrincipal(User $sender): bool
    {
        $email = mb_strtolower(trim((string) $sender->email));

        return ItMailboxConnection::query()
            ->connected()
            ->whereNotNull('access_token')
            ->where('created_by', $sender->id)
            ->whereIn('provider', [
                ItMailboxConnection::PROVIDER_GOOGLE,
                ItMailboxConnection::PROVIDER_MICROSOFT,
            ])
            ->where(function ($query) use ($email): void {
                $query->whereRaw('LOWER(account_email) = ?', [$email])
                    ->orWhereRaw('LOWER(mailbox_email) = ?', [$email]);
            })
            ->exists();
    }

    /** @return array<int, string> */
    private function referencesFrom(?string $subject): array
    {
        if (! is_string($subject) || preg_match_all('/\bIT-\d{4,}\b/i', $subject, $matches) < 1) {
            return [];
        }

        return collect($matches[0])
            ->map(fn (string $reference): string => strtoupper($reference))
            ->unique()
            ->values()
            ->all();
    }

    private function titleFrom(?string $subject, User $sender): string
    {
        $subject = trim((string) $subject);

        return $subject !== '' ? Str::limit($subject, 250, '') : 'Email from '.$sender->name;
    }

    private function preview(string $body): string
    {
        return Str::limit(trim($body), 500);
    }

    /**
     * @param  array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}  $message
     */
    private function quarantine(array $message, string $reason): ItInboundEmail
    {
        $references = array_slice($this->referencesFrom($message['subject']), 0, 3);
        $boundedEvidence = [
            ...$message,
            'subject' => $references !== [] ? implode(', ', $references) : null,
            'text' => '',
            'in_reply_to' => null,
        ];

        return $this->log(null, $boundedEvidence, null, 'quarantined', $reason);
    }

    /**
     * @param  array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}  $message
     */
    private function log(
        ?int $ticketId,
        array $message,
        ?string $preview,
        string $status,
        ?string $quarantineReason = null,
    ): ItInboundEmail {
        $receipt = $message['_receipt'] ?? new ItInboundEmail;
        $wasRetry = $receipt->quarantine_retry_requested_at !== null;
        $receipt->fill([
            'it_ticket_id' => $ticketId,
            'from_email' => $message['from'],
            'subject' => $message['subject'] !== null ? Str::limit($message['subject'], 255, '') : null,
            // Legacy columns are retained for compatibility; never use a truncated identity.
            'message_id' => $message['message_id'] !== null && strlen($message['message_id']) <= 255 ? $message['message_id'] : null,
            'in_reply_to' => $message['in_reply_to'] !== null && strlen($message['in_reply_to']) <= 255 ? $message['in_reply_to'] : null,
            'body_preview' => $preview,
            'status' => $status,
            'quarantine_reason' => $quarantineReason,
            'received_at' => $receipt->received_at ?? now(),
        ]);
        $receipt->forceFill([
            'message_identity_hash' => $message['_identity'] ?? null,
            'message_content_hash' => $message['_content'] ?? null,
            'normalized_message_id' => $message['message_id'],
            'parent_message_ids' => $status === 'quarantined' ? [] : $message['parent_ids'],
            'reference_message_ids' => $status === 'quarantined' ? [] : $message['reference_ids'],
        ])->save();
        if ($wasRetry) {
            $this->finishRetry($receipt);
        }

        return $receipt;
    }

    /** Preserve original identity evidence when a provider returns altered or invalid mail. */
    private function rejectRetry(ItInboundEmail $receipt, string $reason): ItInboundEmail
    {
        return DB::transaction(function () use ($receipt, $reason): ItInboundEmail {
            $receipt->forceFill(['status' => 'quarantined', 'quarantine_reason' => $reason,
                'it_ticket_id' => null, 'body_preview' => null])->save();
            $this->finishRetry($receipt);

            return $receipt;
        });
    }

    private function finishRetry(ItInboundEmail $receipt): void
    {
        $receipt->forceFill(['quarantine_retry_requested_at' => null,
            'quarantine_review_version' => $receipt->quarantine_review_version + 1])->save();
        AuditLogger::logOrFail('it.inbound_email.quarantine_retry_completed', $receipt, [
            'review_version' => $receipt->quarantine_review_version, 'status' => $receipt->status,
            'quarantine_reason' => $receipt->quarantine_reason,
        ]);
    }

    /**
     * @param  array{from: string, subject?: string|null, text?: string|null, message_id?: string|null, in_reply_to?: string|null}  $message
     * @return array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}
     */
    private function normalize(array $message): array
    {
        $parser = new ItEmailMessageIdentifiers;
        $messageId = null;
        $parents = [];
        $references = [];
        $failure = null;
        $fields = [
            'from' => $message['from'] ?? '',
            'subject' => $message['subject'] ?? null,
            'text' => $message['text'] ?? '',
        ];
        foreach ($fields as $key => $value) {
            if ($key === 'subject' && $value === null) {
                continue;
            }
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                $failure = 'invalid_message_fields';
                $fields[$key] = $key === 'subject' ? null : '';
            }
        }
        foreach (['from' => 255, 'subject' => ItEmailMessageIdentifiers::MAX_HEADER_BYTES, 'text' => 100000] as $key => $limit) {
            if (strlen($fields[$key] ?? '') > $limit) {
                $failure ??= match ($key) {
                    'subject' => 'message_headers_too_large',
                    'text' => 'message_body_too_large',
                    default => 'invalid_message_fields',
                };
                $fields[$key] = $key === 'subject' ? null : '';
            }
        }
        try {
            foreach (['message_id', 'in_reply_to', 'references', 'auto_submitted', 'content_type', 'return_path'] as $key) {
                if (isset($message[$key]) && ! is_string($message[$key])) {
                    throw new ItInboundHeaderException;
                }
            }
            $messageId = $parser->messageId($message['message_id'] ?? null);
            $parents = $parser->references($message['in_reply_to'] ?? null);
            $references = $parser->references($message['references'] ?? null);
            $classificationHeaders = [];
            foreach (['auto_submitted' => 'Auto-Submitted', 'content_type' => 'Content-Type', 'return_path' => 'Return-Path'] as $field => $name) {
                if (isset($message[$field])) {
                    $classificationHeaders[] = ['name' => $name, 'value' => $message[$field]];
                }
            }
            (new ItEmailContent)->assertHumanMessage((new ItEmailHeaders)->read($classificationHeaders));
        } catch (ItInboundHeaderException $exception) {
            $failure = $exception->reason;
        } catch (ItInboundContentException $exception) {
            $failure = $exception->reason;
        }

        return [
            'from' => mb_strtolower(trim($fields['from'])),
            // Keep all bounded subject content in the fingerprint; only display storage is shortened.
            'subject' => $fields['subject'] !== null ? trim($fields['subject']) : null,
            'text' => $failure === null ? trim($fields['text']) : '',
            'message_id' => $messageId,
            'in_reply_to' => $parents !== [] ? implode(' ', $parents) : null,
            'parent_ids' => $parents, 'reference_ids' => $references, '_failure' => $failure,
        ];
    }
}
