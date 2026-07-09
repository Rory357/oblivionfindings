<?php

namespace App\Http\Controllers\It;

use App\Domain\It\InboundEmailIngestor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email-to-ticket webhook (§P-S4) — the PUSH ingress. A mail provider POSTs a
 * normalised inbound message here; only the transport concerns live in this
 * controller (shared-secret gate + payload validation) — the actual ticketing
 * is InboundEmailIngestor, shared with the OAuth mailbox poller. Registered in
 * the API routes, so it is stateless: no session, no CSRF. Inert until
 * IT_INBOUND_MAIL_SECRET is set (questions #5).
 */
class ItInboundEmailController extends Controller
{
    public function __invoke(Request $request, InboundEmailIngestor $ingestor): JsonResponse
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

        $inbound = $ingestor->ingest($data);

        return response()->json(array_filter([
            'status' => $inbound->status,
            'ticket' => $inbound->ticket?->reference,
        ]), 200);
    }
}
