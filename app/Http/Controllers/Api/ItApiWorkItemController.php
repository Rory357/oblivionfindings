<?php

namespace App\Http\Controllers\Api;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\Api\LinkItApiWorkItemRequest;
use App\Http\Requests\It\Api\StoreItApiCommentRequest;
use App\Http\Requests\It\Api\StoreItApiWorkItemRequest;
use App\Http\Requests\It\Api\TransitionItApiWorkItemRequest;
use App\Http\Requests\It\Api\UpdateItApiWorkItemRequest;
use App\Http\Resources\ItApiWorkItemResource;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use DomainException;
use Illuminate\Http\Request;

class ItApiWorkItemController extends Controller
{
    public function __construct(private readonly ItApiWorkItemService $workItems) {}

    public function store(StoreItApiWorkItemRequest $request)
    {
        $ticket = $this->workItems->create($this->identity($request), $request->validated(), $this->apiRequest($request));

        return (new ItApiWorkItemResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $workItem): ItApiWorkItemResource
    {
        return new ItApiWorkItemResource($this->ticket($request, $workItem, 'work:read', false));
    }

    public function update(UpdateItApiWorkItemRequest $request, int $workItem)
    {
        try {
            return new ItApiWorkItemResource($this->workItems->update(
                $this->identity($request), $this->validatedTicket($request), $request->validated(), $this->apiRequest($request),
            ));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'update_rejected'], 422);
        }
    }

    public function related(LinkItApiWorkItemRequest $request, int $workItem): ItApiWorkItemResource
    {
        return new ItApiWorkItemResource($this->workItems->changeRelated(
            $this->identity($request), $workItem, $request->validated(), $this->apiRequest($request),
        ));
    }

    public function comment(StoreItApiCommentRequest $request, int $workItem)
    {
        $identity = $this->identity($request);
        try {
            $comment = $this->workItems->addPublicComment(
                $identity,
                $this->validatedTicket($request),
                (string) $request->validated('body'),
                $this->apiRequest($request),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'comment_rejected'], 422);
        }

        return response()->json(['data' => [
            'id' => $comment->id,
            'body' => $comment->body,
            'is_internal' => false,
            'created_at' => $comment->created_at?->toIso8601String(),
        ]], 201);
    }

    public function transition(TransitionItApiWorkItemRequest $request, int $workItem)
    {
        $identity = $this->identity($request);
        $ticket = $this->validatedTicket($request);
        $data = $request->validated();
        try {
            $ticket = $this->workItems->transition(
                $identity,
                $ticket,
                new ItTransitionInput(
                    actor: $identity->actor,
                    to: ItWorkflowState::from((string) $data['to']),
                    reason: $data['reason'] ?? null,
                    waitingParty: $data['waiting_party'] ?? null,
                    nextAction: $data['next_action'] ?? null,
                    resolutionCode: $data['resolution_code'] ?? null,
                    resolutionSummary: $data['resolution_summary'] ?? null,
                    source: 'service_api',
                    expectedVersion: (int) $data['expected_version'],
                    resolutionVerification: $data['resolution_verification'] ?? null,
                ),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'transition_denied',
            ], 422);
        }

        return new ItApiWorkItemResource($ticket);
    }

    private function identity(Request $request): ItServiceIdentity
    {
        /** @var ItServiceIdentity $identity */
        $identity = $request->attributes->get('it_service_identity');

        return $identity;
    }

    private function apiRequest(Request $request): ItApiRequest
    {
        $receipt = $request->attributes->get('it_api_request');
        abort_unless($receipt instanceof ItApiRequest, 500);

        return $receipt;
    }

    private function ticket(
        Request $request,
        int $id,
        string $ability,
        bool $forWork,
    ): ItTicket {
        $ticket = $this->workItems->authorizedTicket(
            $this->identity($request),
            $id,
            $ability,
            $forWork,
        );
        abort_unless($ticket, 404);

        return $ticket;
    }

    private function validatedTicket(Request $request): ItTicket
    {
        $ticket = $request->attributes->get('it_api_ticket');
        abort_unless($ticket instanceof ItTicket, 404);

        return $ticket;
    }
}
