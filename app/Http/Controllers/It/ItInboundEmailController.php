<?php

namespace App\Http\Controllers\It;

use App\Domain\It\InboundEmailIngestor;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\IngestInboundEmailRequest;
use Illuminate\Http\JsonResponse;

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
    public function __invoke(IngestInboundEmailRequest $request, InboundEmailIngestor $ingestor): JsonResponse
    {
        $inbound = $ingestor->ingest($request->validated());

        return response()->json(array_filter([
            'status' => $inbound->status,
            'ticket' => $inbound->ticket?->reference,
        ]), 200);
    }
}
