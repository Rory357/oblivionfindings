<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItTicketMergeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\ReadTicketDuplicateSuggestionsRequest;
use App\Models\ItTicket;

class ItTicketDuplicateSuggestionController extends Controller
{
    public function __invoke(ReadTicketDuplicateSuggestionsRequest $request, ItTicketMergeService $service, ?ItTicket $ticket = null)
    {
        return response()->json(['viewer_user_id' => (int) $request->user()->id,
            'query_uuid' => $request->validated('query_uuid'), 'source_id' => $ticket?->id,
            'matches' => $service->duplicateSuggestions($request->user(), $request->validated(), $ticket)],
            200, ['Cache-Control' => 'no-store, private']);
    }
}
