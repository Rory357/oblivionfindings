<?php

namespace App\Domain\It;

use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Transport-agnostic email-to-ticket ingestion (§P-S4). Every ingress — the
 * push webhook (ItInboundEmailController) and the OAuth mailbox poller — feeds
 * a normalised message through here: match the sender to a staff user, thread
 * a reply onto the ticket referenced in the subject (or open a new
 * source=email ticket), and log every message to it_inbound_emails. A
 * staff-only helpdesk: unknown senders are logged, never auto-ticketed.
 */
class InboundEmailIngestor
{
    /**
     * @param  array{from: string, subject?: string|null, text?: string|null, message_id?: string|null, in_reply_to?: string|null}  $message
     */
    public function ingest(array $message): ItInboundEmail
    {
        $sender = User::query()->where('email', $message['from'])->first();
        $tenantId = (int) ($sender?->organization_id ?? 1);
        $bodyText = trim((string) ($message['text'] ?? ''));
        $preview = Str::limit($bodyText, 500);

        // Staff-only helpdesk: an unknown sender is logged, never auto-ticketed.
        if (! $sender) {
            return $this->log($tenantId, null, $message, $preview, 'unmatched');
        }

        $ticket = $this->matchTicket($tenantId, $message['subject'] ?? null);

        if ($ticket) {
            // A threaded reply from the requester — a public comment.
            $ticket->comments()->create([
                'tenant_id' => $ticket->tenant_id,
                'author_user_id' => $sender->id,
                'body' => $bodyText !== '' ? $bodyText : '(no message body)',
                'is_internal' => false,
            ]);
            ItTicketEvent::record($ticket, 'email_received', null, ['from_user_id' => $sender->id]);
        } else {
            $ticket = ItTicket::createWithReference([
                'tenant_id' => $tenantId,
                'title' => $this->titleFrom($message['subject'] ?? null, $sender),
                'description' => $bodyText !== '' ? $bodyText : null,
                'requester_user_id' => $sender->id,
                'category' => 'other',
                'requires_approval' => ItTicket::categoryNeedsApproval('other'),
                'priority' => 'normal',
                'source' => 'email',
                'status' => 'open',
            ]);
            $ticket->stampSlaDueDates();
            $ticket->save();
            ItTicketEvent::record($ticket, 'created', null, ['source' => 'email']);
        }

        return $this->log($tenantId, $ticket->id, $message, $preview, 'processed');
    }

    private function matchTicket(int $tenantId, ?string $subject): ?ItTicket
    {
        if (! $subject || ! preg_match('/IT-\d{4,}/i', $subject, $m)) {
            return null;
        }

        return ItTicket::query()
            ->forTenant($tenantId)
            ->where('reference', strtoupper($m[0]))
            ->first();
    }

    private function titleFrom(?string $subject, User $sender): string
    {
        $subject = trim((string) $subject);

        return $subject !== '' ? Str::limit($subject, 250, '') : 'Email from '.$sender->name;
    }

    /** @param  array<string, mixed>  $message */
    private function log(int $tenantId, ?int $ticketId, array $message, string $preview, string $status): ItInboundEmail
    {
        return ItInboundEmail::create([
            'tenant_id' => $tenantId,
            'it_ticket_id' => $ticketId,
            'from_email' => $message['from'],
            'subject' => $message['subject'] ?? null,
            'message_id' => $message['message_id'] ?? null,
            'in_reply_to' => $message['in_reply_to'] ?? null,
            'body_preview' => $preview,
            'status' => $status,
            'received_at' => now(),
        ]);
    }
}
