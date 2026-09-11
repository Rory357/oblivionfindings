<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Services\ItTicketLinkService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\ReadTicketRelatedWorkRequest;
use App\Http\Requests\It\TicketRelationshipCommandRequest;
use App\Models\ItTicket;

class ItTicketRelationshipController extends Controller
{
    public function __construct(private readonly ItTicketLinkService $links) {}

    public function index(ReadTicketRelatedWorkRequest $request, ItTicket $ticket)
    {
        return response()->json(['data' => $this->links->relatedWork($ticket, $request->user(),
            (string) $request->input('search', ''), $request->integer('links_page', 1), $request->integer('candidates_page', 1))],
            200, ['Cache-Control' => 'no-store, private']);
    }

    public function command(TicketRelationshipCommandRequest $request, ItTicket $ticket, ?string $requestUuid = null)
    {
        $target = ItTicket::query()->findOrFail($request->integer('target_ticket_id'));
        try {
            $result = $requestUuid === null
                ? $this->links->changeRelated($ticket, $target, $request->user(), $request->validated())
                : $this->links->recoverRelated($ticket, $target, $request->user(),
                    [...$request->validated(), 'request_uuid' => $requestUuid], $request->isMethod('post'));
        } catch (ItTicketCommandConflict $exception) {
            return response()->json(['code' => 'command_conflict', 'message' => $exception->getMessage()],
                409, ['Cache-Control' => 'no-store, private']);
        }

        return response()->json($result, 200, ['Cache-Control' => 'no-store, private']);
    }
}
