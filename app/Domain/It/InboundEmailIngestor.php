<?php

namespace App\Domain\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Transport-agnostic, fail-closed email-to-ticket ingestion.
 *
 * Ticket references are global immutable identities. A reply is accepted only
 * after one exact reference and one current sender responsibility are proven.
 * Quarantined messages retain no body preview. Message-id locking closes the
 * retry race until Task 8 can safely add a global unique constraint after its
 * collision audit and normalization.
 */
class InboundEmailIngestor
{
    public function __construct(
        private readonly ItWorkAccessService $workAccess,
        private readonly ItTicketReferenceResolver $ticketReferences,
    ) {}

    /**
     * @param  array{from: string, subject?: string|null, text?: string|null, message_id?: string|null, in_reply_to?: string|null}  $message
     */
    public function ingest(array $message): ItInboundEmail
    {
        $message = $this->normalize($message);
        $messageId = $message['message_id'];

        if ($messageId === null) {
            return $this->quarantine($message, 'missing_message_id');
        }

        return Cache::lock('it-inbound-message:'.hash('sha256', $messageId), 30)
            ->block(5, function () use ($message, $messageId): ItInboundEmail {
                $existing = ItInboundEmail::query()->where('message_id', $messageId)->first();

                return $existing ?? $this->ingestOnce($message);
            });
    }

    /**
     * @param  array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}  $message
     */
    private function ingestOnce(array $message): ItInboundEmail
    {
        return DB::transaction(function () use ($message): ItInboundEmail {
            $sender = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($message['from'])])
                ->first();

            if (! $sender) {
                return $this->quarantine($message, 'sender_unknown');
            }
            if (! $this->senderIsActive($sender)) {
                return $this->quarantine($message, 'sender_inactive');
            }

            $references = $this->referencesFrom($message['subject']);
            if (count($references) > 1) {
                return $this->quarantine($message, 'reference_ambiguous');
            }

            if ($references !== []) {
                $resolution = $this->ticketReferences->resolve($references[0]);
                if ($resolution['failure'] !== null) {
                    return $this->quarantine($message, $resolution['failure']);
                }

                $ticket = $resolution['ticket'];
                if (! $ticket instanceof ItTicket) {
                    return $this->quarantine($message, 'reference_not_found');
                }
                $authorizationFailure = $this->replyAuthorizationFailure($sender, $ticket);
                if ($authorizationFailure !== null) {
                    return $this->quarantine($message, $authorizationFailure);
                }

                $ticket->comments()->create([
                    'author_user_id' => $sender->id,
                    'body' => $message['text'] !== '' ? $message['text'] : '(no message body)',
                    'is_internal' => false,
                ]);
                ItTicketEvent::record($ticket, 'email_received', $sender->id, [
                    'from_user_id' => $sender->id,
                    'message_id' => $message['message_id'],
                ]);

                return $this->log($ticket->id, $message, $this->preview($message['text']), 'processed');
            }

            if (! Gate::forUser($sender)->allows('create', ItTicket::class)) {
                return $this->quarantine($message, 'sender_not_allowed');
            }
            $siteId = $this->workAccess->defaultSiteId($sender);
            if ($siteId === null || ! $this->workAccess->canAssignScope($sender, $siteId, false)) {
                return $this->quarantine($message, 'sender_site_unresolved');
            }

            $ticket = ItTicket::createWithReference([
                'site_id' => $siteId,
                'is_organisation_wide' => false,
                'title' => $this->titleFrom($message['subject'], $sender),
                'description' => $message['text'] !== '' ? $message['text'] : null,
                'requester_user_id' => $sender->id,
                'category' => 'other',
                'requires_approval' => ItTicket::categoryNeedsApproval('other'),
                'priority' => 'normal',
                'source' => 'email',
                'status' => 'open',
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
            ItTicketEvent::record($ticket, 'created', $sender->id, [
                'source' => 'email',
                'message_id' => $message['message_id'],
            ]);

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
        return ItInboundEmail::query()->create([
            'it_ticket_id' => $ticketId,
            'from_email' => $message['from'],
            'subject' => $message['subject'],
            'message_id' => $message['message_id'],
            'in_reply_to' => $message['in_reply_to'],
            'body_preview' => $preview,
            'status' => $status,
            'quarantine_reason' => $quarantineReason,
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array{from: string, subject?: string|null, text?: string|null, message_id?: string|null, in_reply_to?: string|null}  $message
     * @return array{from: string, subject: string|null, text: string, message_id: string|null, in_reply_to: string|null}
     */
    private function normalize(array $message): array
    {
        $messageId = trim((string) ($message['message_id'] ?? ''));
        $inReplyTo = trim((string) ($message['in_reply_to'] ?? ''));

        return [
            'from' => mb_strtolower(trim((string) $message['from'])),
            'subject' => isset($message['subject']) ? Str::limit(trim((string) $message['subject']), 255, '') : null,
            'text' => trim((string) ($message['text'] ?? '')),
            'message_id' => $messageId !== '' ? Str::limit($messageId, 255, '') : null,
            'in_reply_to' => $inReplyTo !== '' ? Str::limit($inReplyTo, 255, '') : null,
        ];
    }
}
