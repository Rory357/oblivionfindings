<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Email-to-ticket webhook (§P-S4). A mail provider (Postmark / Mailgun / SES…)
 * POSTs a normalised inbound message here; we thread it onto the referenced
 * ticket or open a new one, and log the ingestion. Unauthenticated but
 * shared-secret gated — inert until IT_INBOUND_MAIL_SECRET is set (questions
 * #5). Registered in the API routes, so it is stateless: no session, no CSRF.
 *
 * Expected (provider-normalised) body: from, subject, text, message_id,
 * in_reply_to. A staff-only helpdesk: unknown senders are logged, never
 * auto-ticketed.
 */
class ItInboundEmailController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('it.inbound_mail.secret', '');
        abort_unless(
            $secret !== '' && hash_equals($secret, (string) $request->header('X-IT-Inbound-Secret')),
            403,
        );

        $data = $request->validate([
            'from' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'in_reply_to' => ['nullable', 'string', 'max:255'],
        ]);

        $sender = User::query()->where('email', $data['from'])->first();
        $tenantId = (int) ($sender?->organization_id ?? 1);
        $bodyText = trim((string) ($data['text'] ?? ''));
        $preview = Str::limit($bodyText, 500);

        // Staff-only helpdesk: an unknown sender is logged, never auto-ticketed.
        if (! $sender) {
            $this->logInbound($tenantId, null, $data, $preview, 'unmatched');

            return response()->json(['status' => 'unmatched'], 200);
        }

        $ticket = $this->matchTicket($tenantId, $data['subject'] ?? null);

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
                'title' => $this->titleFrom($data['subject'] ?? null, $sender),
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

        $this->logInbound($tenantId, $ticket->id, $data, $preview, 'processed');

        return response()->json(['status' => 'processed', 'ticket' => $ticket->reference], 200);
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

    /** @param  array<string, mixed>  $data */
    private function logInbound(int $tenantId, ?int $ticketId, array $data, string $preview, string $status): void
    {
        ItInboundEmail::create([
            'tenant_id' => $tenantId,
            'it_ticket_id' => $ticketId,
            'from_email' => $data['from'],
            'subject' => $data['subject'] ?? null,
            'message_id' => $data['message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'body_preview' => $preview,
            'status' => $status,
            'received_at' => now(),
        ]);
    }
}
